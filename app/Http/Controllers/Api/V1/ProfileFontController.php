<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Handle;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profile → Appearance → "Upload your own" display font.
 *
 * One font per account, at a path derived from the user — nothing about
 * where it lives is ever taken from the client, so the public route below
 * can only ever stream a font somebody uploaded through here.
 */
class ProfileFontController extends Controller
{
    /** Magic bytes → the Content-Type the font is served with. */
    private const FORMATS = [
        'wOF2' => 'font/woff2',
        'wOFF' => 'font/woff',
        "\x00\x01\x00\x00" => 'font/ttf',
        'true' => 'font/ttf',
        'OTTO' => 'font/otf',
    ];

    public static function path(User $user): string
    {
        return 'fonts/'.$user->ulid.'/display';
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'font' => ['required', 'file', 'max:2048', 'extensions:woff2,woff,ttf,otf'],
        ]);

        $user = $request->user();
        abort_unless(
            Workspace::owner($user)->plan()->feature('custom_fonts'),
            403,
            'Uploading a font is part of Premium.',
        );

        // The extension is the browser's word; the first four bytes are the file's.
        $file = $request->file('font');
        $mime = self::FORMATS[(string) file_get_contents($file->getRealPath(), false, null, 0, 4)] ?? null;
        if ($mime === null) {
            throw ValidationException::withMessages(['font' => 'That file is not a font.']);
        }

        Storage::disk('s3')->putFileAs(
            dirname(self::path($user)),
            $file,
            basename(self::path($user)),
            ['ContentType' => $mime],
        );

        // Bumps the URL so a replaced font is not served from a cache.
        return response()->json(['data' => ['version' => now()->timestamp]], 201);
    }

    /**
     * The font, for creatif.space/{handle}. Public, and outside /api so the
     * single-origin CORS config does not apply: the page that loads it may
     * sit on another host, and a font is useless to a page that may not read
     * it cross-origin.
     */
    public function show(string $handle): Response
    {
        $user = Handle::query()
            ->where('name', Handle::normalize($handle))
            ->whereNotNull('user_id')
            ->first()?->user;

        $path = $user === null ? null : self::path($user);
        $disk = Storage::disk('s3');

        abort_if(
            $path === null
                || ($user->profile?->appearance['font'] ?? null) !== 'Upload your own'
                || ! Workspace::owner($user)->plan()->feature('custom_fonts')
                || ! $disk->exists($path),
            404,
        );

        return $disk->response($path, null, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
