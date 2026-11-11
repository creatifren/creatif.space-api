<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An upload or a Keep the media rules refuse. Renders as JSON with a
 * stable `reason` the app branches on:
 *
 * - 402 quota_exceeded (feature keep_quota | media_quota) with next_step
 *   upgrade_pro | upgrade_max | contact_sales
 * - 422 file_too_large (per-file cap), 422 unsupported_format (bytes are
 *   not an accepted format)
 * - 429 upload_limit — INTERNAL abuse limits; the message is generic on
 *   purpose and says nothing about the numbers.
 */
class MediaRefused extends RuntimeException
{
    public const UPLOAD_LIMIT_MESSAGE = 'Upload limit reached, try again tomorrow';

    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $reason,
        public readonly array $extra = [],
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }

    public static function quota(string $feature, string $nextStep, string $message): self
    {
        return new self($message, 402, 'quota_exceeded', ['feature' => $feature, 'next_step' => $nextStep]);
    }

    /**
     * @param  int|null  $maxBytes  only for a plan cap; the technical ceiling is never shown
     */
    public static function tooLarge(?int $maxBytes, ?string $nextStep): self
    {
        $message = $maxBytes === null
            ? 'This file is too large to upload.'
            : 'This file is larger than your plan allows for this kind of file ('.self::human($maxBytes).').';

        return new self($message, 422, 'file_too_large', array_filter([
            'feature' => 'file_size',
            'max_bytes' => $maxBytes,
            'next_step' => $maxBytes === null ? null : $nextStep,
        ], fn ($v) => $v !== null), 'size_bytes');
    }

    public static function unsupportedFormat(): self
    {
        return new self(
            'This file is not a supported format. Use JPEG, PNG, WebP, GIF, MP4 or MOV.',
            422, 'unsupported_format', [], 'file',
        );
    }

    public static function uploadLimit(): self
    {
        return new self(self::UPLOAD_LIMIT_MESSAGE, 429, 'upload_limit');
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'reason' => $this->reason,
            ...$this->extra,
            // The app's form layer reads `errors`; keep it for 422s.
            'errors' => $this->field === null ? null : [$this->field => [$this->getMessage()]],
        ], fn ($v) => $v !== null), $this->status);
    }

    private static function human(int $bytes): string
    {
        return $bytes >= 1024 ** 3 && $bytes % (1024 ** 3) === 0
            ? ($bytes / 1024 ** 3).' GB'
            : (int) round($bytes / 1024 ** 2).' MB';
    }
}
