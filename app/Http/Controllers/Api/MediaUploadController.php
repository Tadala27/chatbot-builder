<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotMediaFile;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaUploadController extends Controller
{
    // ── Size limits per WhatsApp media type (bytes) ───────────────────────────
    private const MAX_SIZES = [
        'image'    =>   5 * 1024 * 1024,  //   5 MB
        'video'    =>  16 * 1024 * 1024,  //  16 MB
        'audio'    =>  16 * 1024 * 1024,  //  16 MB
        'document' => 100 * 1024 * 1024,  // 100 MB
    ];

    // ── Allowed MIME types per WhatsApp category (per Meta documentation) ─────
    private const ALLOWED_MIMES = [
        'document' => [
            'text/plain',                                                                  // .txt
            'application/vnd.ms-excel',                                                    // .xls
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',           // .xlsx
            'application/msword',                                                          // .doc
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',    // .docx
            'application/vnd.ms-powerpoint',                                               // .ppt
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',  // .pptx
            'application/pdf',                                                             // .pdf
        ],
        'audio' => [
            'audio/aac',   // .aac
            'audio/amr',   // .amr
            'audio/mpeg',  // .mp3
            'audio/mp4',   // .m4a
            'audio/ogg',   // .ogg – OPUS codecs only; enforced at playback by WhatsApp
        ],
        'image' => [
            'image/jpeg',  // .jpg / .jpeg
            'image/png',   // .png
        ],
        'video' => [
            'video/3gpp',  // .3gp
            'video/mp4',   // .mp4 – H.264 video + AAC audio required by WhatsApp
        ],
    ];

    // ── Human-readable extension hints ────────────────────────────────────────
    private const EXTENSION_HINTS = [
        'document' => '.txt, .xls, .xlsx, .doc, .docx, .ppt, .pptx, .pdf',
        'audio'    => '.aac, .amr, .mp3, .m4a, .ogg (OPUS codec only)',
        'image'    => '.jpg, .jpeg, .png',
        'video'    => '.3gp, .mp4 (H.264 video + AAC audio)',
    ];

    // =========================================================================
    // POST /api/media/upload
    // Multipart body: file, type (image|video|audio|document), bot_id
    // =========================================================================

    public function upload(Request $request): JsonResponse
    {
        Log::debug("Here is my request", $request->all());
        $request->validate([
            'file'   => 'required|file',
            'type'   => 'required|in:image,video,audio,document',
            'bot_id' => 'required|integer|exists:bots,id',
        ]);

        $tenant = Tenant::current();

        // Ensure the bot belongs to the current tenant
        $bot = Bot::where('id', $request->bot_id)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $file      = $request->file('file');
        $type      = $request->input('type');
        $mime      = $file->getMimeType() ?? '';
        $size      = $file->getSize();

        // ── MIME validation ───────────────────────────────────────────────────
        $allowed = self::ALLOWED_MIMES[$type] ?? [];

        if (!in_array($mime, $allowed, true)) {
            return response()->json([
                'message' => "Invalid file type for {$type}. Allowed formats: "
                    . (self::EXTENSION_HINTS[$type] ?? 'unknown'),
            ], 422);
        }

        // ── Size validation ───────────────────────────────────────────────────
        $maxBytes = self::MAX_SIZES[$type] ?? (5 * 1024 * 1024);

        if ($size > $maxBytes) {
            $maxMb = round($maxBytes / 1024 / 1024);
            return response()->json([
                'message' => "File too large. Maximum size for {$type} is {$maxMb} MB.",
            ], 422);
        }

        // ── Store the file ────────────────────────────────────────────────────
        $originalFilename = $file->getClientOriginalName();
        $extension        = $file->getClientOriginalExtension();
        $storedFilename   = Str::uuid() . '.' . $extension;
        $storagePath      = "bot-media/{$bot->id}/{$storedFilename}";
        $disk             = config('filesystems.default', 'public');

        Storage::disk($disk)->putFileAs(
            "bot-media/{$bot->id}",
            $file,
            $storedFilename
        );

        $url = Storage::disk($disk)->url($storagePath);

        // ── Persist record ────────────────────────────────────────────────────
        $media = BotMediaFile::create([
            'tenant_id'         => $tenant->id,
            'user_id'           => auth()->id(),
            'bot_id'            => $bot->id,
            'original_filename' => $originalFilename,
            'stored_filename'   => $storedFilename,
            'disk'              => $disk,
            'path'              => $storagePath,
            'url'               => $url,
            'media_type'        => $type,
            'mime_type'         => $mime,
            'size'              => $size,
        ]);

        return response()->json([
            'id'       => $media->id,
            'url'      => $url,
            'filename' => $originalFilename,  // ← auto-populates "Document Filename" on the frontend
            'type'     => $type,
            'size'     => $size,
            'mime'     => $mime,
        ], 201);
    }

    // =========================================================================
    // DELETE /api/media/{media}
    // Soft-deletes the record and removes the file from disk.
    // =========================================================================

    public function destroy(BotMediaFile $media): JsonResponse
    {
        $tenant = Tenant::current();

        // Double-check tenant ownership (route model binding already fetches by PK)
        if ($media->tenant_id !== $tenant->id) {
            abort(403, 'Unauthorized');
        }

        // Remove the physical file
        if (Storage::disk($media->disk)->exists($media->path)) {
            Storage::disk($media->disk)->delete($media->path);
        }

        $media->delete(); // soft-delete keeps the audit trail

        return response()->json(['message' => 'Media deleted.']);
    }

    // =========================================================================
    // GET /api/bots/{bot}/media
    // Lists all media files for a bot under the current tenant.
    // Useful for a reusable media library picker later.
    // =========================================================================

    public function index(Bot $bot): JsonResponse
    {
        $tenant = Tenant::current();

        if ($bot->tenant_id !== $tenant->id) {
            abort(403, 'Unauthorized');
        }

        $files = BotMediaFile::where('tenant_id', $tenant->id)
            ->where('bot_id', $bot->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get(['id', 'original_filename', 'url', 'media_type', 'mime_type', 'size', 'created_at']);

        return response()->json(['files' => $files]);
    }
}
