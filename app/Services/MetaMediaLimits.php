<?php

// app/Services/MetaMediaLimits.php
//
// Central place for Meta's documented media constraints, so both the
// outbound send path (WhatsAppSenderService) and any upload UI can
// validate BEFORE attempting a request — failing fast with a clear error
// instead of letting Meta reject it after time was spent uploading.

namespace App\Services;

class MetaMediaLimits
{
    /**
     * [extension => [max_bytes, mime_types]].
     */
    private const LIMITS = [
        // Images — 5 MB
        'jpg' => ['max' => 5 * 1024 * 1024, 'mimes' => ['image/jpeg']],
        'jpeg' => ['max' => 5 * 1024 * 1024, 'mimes' => ['image/jpeg']],
        'png' => ['max' => 5 * 1024 * 1024, 'mimes' => ['image/png']],

        // Videos — 16 MB
        'mp4' => ['max' => 16 * 1024 * 1024, 'mimes' => ['video/mp4']],
        '3gp' => ['max' => 16 * 1024 * 1024, 'mimes' => ['video/3gpp']],

        // Audio — 16 MB
        'aac' => ['max' => 16 * 1024 * 1024, 'mimes' => ['audio/aac']],
        'mp3' => ['max' => 16 * 1024 * 1024, 'mimes' => ['audio/mpeg']],
        'amr' => ['max' => 16 * 1024 * 1024, 'mimes' => ['audio/amr']],
        'm4a' => ['max' => 16 * 1024 * 1024, 'mimes' => ['audio/mp4']],
        'ogg' => ['max' => 16 * 1024 * 1024, 'mimes' => ['audio/ogg']],

        // Documents — 100 MB
        'pdf' => ['max' => 100 * 1024 * 1024, 'mimes' => ['application/pdf']],
        'doc' => ['max' => 100 * 1024 * 1024, 'mimes' => ['application/msword']],
        'docx' => ['max' => 100 * 1024 * 1024, 'mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document']],
        'xls' => ['max' => 100 * 1024 * 1024, 'mimes' => ['application/vnd.ms-excel']],
        'xlsx' => ['max' => 100 * 1024 * 1024, 'mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']],
        'ppt' => ['max' => 100 * 1024 * 1024, 'mimes' => ['application/vnd.ms-powerpoint']],
        'pptx' => ['max' => 100 * 1024 * 1024, 'mimes' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation']],
        'txt' => ['max' => 100 * 1024 * 1024, 'mimes' => ['text/plain']],

        // Stickers
        'webp' => ['max' => 500 * 1024, 'mimes' => ['image/webp']], // animated ceiling; static is 100KB — checked separately if needed
    ];

    /**
     * @return string|null an error message if the file violates Meta's
     *                     limits, or null if it's within bounds
     */
    public static function validate(string $extension, int $sizeBytes): ?string
    {
        $extension = strtolower($extension);
        $limit = self::LIMITS[$extension] ?? null;

        if (!$limit) {
            return "File type .{$extension} is not supported by WhatsApp.";
        }

        if ($sizeBytes > $limit['max']) {
            $maxMb = round($limit['max'] / 1024 / 1024, 2);
            $actualMb = round($sizeBytes / 1024 / 1024, 2);

            return "File is {$actualMb}MB, which exceeds WhatsApp's {$maxMb}MB limit for .{$extension} files.";
        }

        return null;
    }
}