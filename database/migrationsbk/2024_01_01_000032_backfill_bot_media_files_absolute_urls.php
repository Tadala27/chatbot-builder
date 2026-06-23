<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;


return new class extends Migration
{
    public function up(): void
    {
        $appUrl = rtrim(config('app.url', env('APP_URL', '')), '/');

        if (empty($appUrl)) {

            \Illuminate\Support\Facades\Log::warning(
                'bot_media_files URL backfill skipped: APP_URL is not set.'
            );
            return;
        }

        DB::table('bot_media_files')
            ->whereNull('deleted_at')
            ->where('url', 'not like', 'http://%')
            ->where('url', 'not like', 'https://%')
            ->chunkById(100, function ($rows) use ($appUrl) {
                foreach ($rows as $row) {
                    $absolute = $appUrl . '/' . ltrim($row->url, '/');
                    DB::table('bot_media_files')
                        ->where('id', $row->id)
                        ->update(['url' => $absolute]);
                }
            });


        DB::table('bot_media_files')
            ->whereNull('deleted_at')
            ->where('disk', 'local')
            ->update(['disk' => 'public']);
    }

    public function down(): void
    {
        // Intentionally not reversible — we don't know which records were changed.
    }
};
