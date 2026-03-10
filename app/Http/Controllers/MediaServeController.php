<?php

namespace App\Http\Controllers;

use App\Models\BotMediaFile;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;


class MediaServeController extends Controller
{
    public function serve(string $storedFilename): StreamedResponse|Response
    {
        $media = BotMediaFile::where('stored_filename', $storedFilename)
            ->whereNull('deleted_at')
            ->first();

        if (!$media) {
            abort(404);
        }

        $disk = $media->disk ?? 'public';
        $path = $media->path;

        if (!Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        $mimeType = $media->mime_type ?? 'application/octet-stream';
        $filename = $media->original_filename ?? $storedFilename;
        $size     = Storage::disk($disk)->size($path);

        return response()->stream(
            function () use ($disk, $path) {
                $stream = Storage::disk($disk)->readStream($path);
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type'           => $mimeType,
                'Content-Length'         => $size,
                'Content-Disposition'    => 'inline; filename="' . addslashes($filename) . '"',
                'Cache-Control'          => 'public, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
