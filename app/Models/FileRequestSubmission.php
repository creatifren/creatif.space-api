<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\FileRequestSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery against a request.
 *
 * @property int $id
 * @property string $ulid
 * @property int $file_request_id
 * @property string $sender_name
 * @property string $sender_email
 * @property string|null $message
 * @property list<array{name: string, provider_file_id: string}> $files
 * @property SubmissionStatus $status
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read FileRequest $fileRequest
 */
class FileRequestSubmission extends Model
{
    /** @use HasFactory<FileRequestSubmissionFactory> */
    use HasFactory;

    protected $fillable = [
        'file_request_id',
        'sender_name',
        'sender_email',
        'message',
        'files',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'files' => 'array',
            'status' => SubmissionStatus::class,
        ];
    }

    /**
     * Assign a public ULID on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $submission): void {
            $submission->ulid ??= (string) str()->ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<FileRequest, $this>
     */
    public function fileRequest(): BelongsTo
    {
        return $this->belongsTo(FileRequest::class);
    }
}
