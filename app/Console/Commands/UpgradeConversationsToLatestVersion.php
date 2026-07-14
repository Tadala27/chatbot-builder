<?php

namespace App\Console\Commands;

use App\Models\BotVersion;
use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpgradeConversationsToLatestVersion extends Command
{
    protected $signature = 'conversations:upgrade-versions {--dry-run : Show what would be upgraded without making changes}';
    protected $description = 'Upgrade active conversations to the latest published bot version';

    public function handle(): void
    {
        $dryRun = $this->option('dry-run');

        // Get all active conversations
        $conversations = Conversation::where('status', 'active')
            ->whereNotNull('bot_id')
            ->get();

        $this->info("Found {$conversations->count()} active conversations");

        $upgraded = 0;
        $skipped = 0;

        foreach ($conversations as $conversation) {
            // Check if there's a newer published version
            $latestVersion = BotVersion::where('bot_id', $conversation->bot_id)
                ->where('status', 'published')
                ->latest('published_at')
                ->first();

            if (!$latestVersion) {
                ++$skipped;
                continue;
            }

            if ($conversation->bot_version_id === $latestVersion->id) {
                ++$skipped;
                continue;
            }

            if ($dryRun) {
                $this->line("Would upgrade conversation {$conversation->id} from version {$conversation->bot_version_id} to {$latestVersion->id}");
            } else {
                $conversation->upgradeToLatestVersion();
                $this->info("Upgraded conversation {$conversation->id} to version {$latestVersion->id}");
            }

            ++$upgraded;
        }

        $this->info("Done. Upgraded: {$upgraded}, Skipped: {$skipped}");

        Log::info('Conversation version upgrade completed', [
            'upgraded' => $upgraded,
            'skipped' => $skipped,
            'dry_run' => $dryRun,
        ]);
    }
}
