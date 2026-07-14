<?php

namespace App\Services\Bot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WhatsAppRateLimiter
{
    /**
     * Default per-second ceiling. Configure per-account in the whatsapp_accounts
     * table once you know your quality tier.
     */
    private const DEFAULT_RATE_PER_SECOND = 20;

    /**
     * Max burst size (tokens in the bucket). Allows short bursts above the
     * steady rate without blocking. Should be >= DEFAULT_RATE_PER_SECOND.
     */
    private const DEFAULT_BURST = 40;

    /**
     * Max time we'll wait for a token before giving up.
     */
    private const MAX_WAIT_SECONDS = 5;

    /**
     * Attempt to acquire a token. Returns true on success.
     *
     * Uses an atomic Redis/Cache lock with the token-bucket algorithm:
     * each account has a bucket that refills at `rate` tokens per second,
     * capped at `burst`. Each send consumes 1 token.
     *
     * If no tokens are available, we sleep briefly and retry up to
     * MAX_WAIT_SECONDS. If still blocked, we return false — the caller can
     * either drop the message, queue it for later, or throw.
     */
    public function tryAcquire(string $accountId, ?int $rate = null, ?int $burst = null): bool
    {
        $rate = $rate ?? self::DEFAULT_RATE_PER_SECOND;
        $burst = $burst ?? self::DEFAULT_BURST;
        $key = "wa_bucket:{$accountId}";

        $deadline = microtime(true) + self::MAX_WAIT_SECONDS;

        while (microtime(true) < $deadline) {
            if ($this->consumeToken($key, $rate, $burst)) {
                return true;
            }
            // Sleep for one token's worth of time
            usleep((int) (1_000_000 / $rate));
        }

        Log::warning('WhatsApp rate limiter: no tokens after wait', [
            'account_id' => $accountId,
            'rate' => $rate,
            'burst' => $burst,
        ]);

        return false;
    }

    /**
     * Atomic token consumption — uses Lua script when Redis is the cache driver,
     * falls back to a lock-guarded update otherwise.
     */
    private function consumeToken(string $key, int $rate, int $burst): bool
    {
        // Fast path: Redis with Lua (atomic check-and-decrement)
        if (config('cache.default') === 'redis') {
            return $this->consumeTokenRedis($key, $rate, $burst);
        }

        // Generic path: lock + update (works on any cache driver)
        return Cache::lock("{$key}:lock", 5)->block(2, function () use ($key, $rate, $burst) {
            $state = Cache::get($key, ['tokens' => (float) $burst, 'updated' => microtime(true)]);

            $now = microtime(true);
            $delta = $now - $state['updated'];

            // Refill bucket
            $tokens = min($burst, $state['tokens'] + $delta * $rate);

            if ($tokens < 1) {
                // Persist the refilled state anyway
                Cache::put($key, ['tokens' => $tokens, 'updated' => $now], 60);

                return false;
            }

            --$tokens;
            Cache::put($key, ['tokens' => $tokens, 'updated' => $now], 60);

            return true;
        });
    }

    /**
     * Redis-native token bucket using EVAL for atomicity.
     * This is what you want in production — no lock contention.
     */
    private function consumeTokenRedis(string $key, int $rate, int $burst): bool
    {
        $redis = Cache::store('redis')->getRedis();

        $lua = <<<'LUA'
local key   = KEYS[1]
local rate  = tonumber(ARGV[1])
local burst = tonumber(ARGV[2])
local now   = tonumber(ARGV[3])

local data    = redis.call('HMGET', key, 'tokens', 'updated')
local tokens  = tonumber(data[1])
local updated = tonumber(data[2])

if tokens == nil then
  tokens  = burst
  updated = now
end

local delta = math.max(0, now - updated)
tokens = math.min(burst, tokens + delta * rate)

if tokens < 1 then
  redis.call('HMSET', key, 'tokens', tokens, 'updated', now)
  redis.call('EXPIRE', key, 60)
  return 0
end

tokens = tokens - 1
redis.call('HMSET', key, 'tokens', tokens, 'updated', now)
redis.call('EXPIRE', key, 60)
return 1
LUA;

        $result = $redis->eval($lua, 1, $key, $rate, $burst, microtime(true));

        return (int) $result === 1;
    }

    /**
     * For debugging — how many tokens are currently available?
     */
    public function availableTokens(string $accountId): float
    {
        $state = Cache::get("wa_bucket:{$accountId}");
        if (!$state) {
            return (float) self::DEFAULT_BURST;
        }

        $delta = microtime(true) - $state['updated'];
        $tokens = $state['tokens'] + $delta * self::DEFAULT_RATE_PER_SECOND;

        return min((float) self::DEFAULT_BURST, $tokens);
    }
}

// =============================================================================
// HOW TO USE — in WhatsAppMessageService::sendRequest()
// =============================================================================
//
// Inject the limiter in the constructor:
//
//   public function __construct(private WhatsAppRateLimiter $rateLimiter) {
//       $apiVersion = config('services.whatsapp.api_version', 'v21.0');
//       $this->client = new Client([...]);
//   }
//
// Then in sendRequest():
//
//   private function sendRequest(WhatsappAccount $account, array $payload): array
//   {
//       $this->assertAccessToken($account);
//
//       if (!$this->rateLimiter->tryAcquire($account->id)) {
//           throw new \RuntimeException(
//               "Rate limit exceeded for account {$account->id}. Try again later."
//           );
//       }
//
//       // ... existing code ...
//   }
//
// If you want to customize the rate per account (e.g., pull from the account's
// quality_tier column):
//
//   $rate  = match($account->quality_tier) {
//       'high'    => 80,
//       'medium'  => 40,
//       default   => 20,
//   };
//   $this->rateLimiter->tryAcquire($account->id, $rate, $rate * 2);

// =============================================================================
// WHY NOT USE Laravel's built-in RateLimiter?
// =============================================================================
// Laravel's RateLimiter::attempt() is a fixed-window counter — it allows
// 20 requests in a 60-second window, then blocks until the window resets.
// That creates bursts at window boundaries and doesn't model WhatsApp's
// per-second cap well.
//
// The token bucket above is continuous: if you're sending slowly, tokens
// accumulate up to `burst`; if you spike, you use the burst and then throttle
// back to the steady rate.
