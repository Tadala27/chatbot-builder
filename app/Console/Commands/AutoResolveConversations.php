<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoResolveConversations extends Command
{
    protected $signature = 'conversations:auto-resolve
                               {--hours=24 : Inactivity threshold in hours}
                               {--dry-run  : List conversations that would be resolved without acting}';

    protected $description = 'Resolve conversations that have been inactive for the configured number of hours.';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subHours($hours);
        $total = 0;

        Tenant::query()->chunk(50, function ($tenants) use ($cutoff, $dryRun, &$total) {
            foreach ($tenants as $tenant) {
                if (!$tenant->is_active) {
                    continue;
                }

                tenancy()->initialize($tenant);

                try {
                    $query = \App\Models\Conversation::query()
                        ->whereNotIn('status', ['completed'])
                        ->where('last_message_at', '<', $cutoff);

                    if ($dryRun) {
                        $count = $query->count();
                        $this->line("[{$tenant->id}] Would resolve {$count} conversation(s).");
                        $total += $count;
                        continue;
                    }

                    $query->chunk(100, function ($conversations) use (&$total, $tenant) {
                        foreach ($conversations as $conversation) {
                            try {
                                $conversation->resolve();
                                ++$total;
                            } catch (\Throwable $e) {
                                Log::warning('Auto-resolve failed for conversation', [
                                    'tenant' => $tenant->id,
                                    'conversation_id' => $conversation->id,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    });
                } finally {
                    tenancy()->end();
                }
            }
        });

        $verb = $dryRun ? 'Would resolve' : 'Resolved';
        $this->info("{$verb} {$total} conversation(s) inactive for {$hours}+ hours.");

        return self::SUCCESS;
    }
}