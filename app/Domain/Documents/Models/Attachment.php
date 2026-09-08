<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A file attached to a record.
 *
 * The row is the index; the bytes live in object storage. Everything the
 * application needs to list, describe or serve the file is here, so a listing
 * screen never touches the bucket.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $attachable_type
 * @property string $attachable_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum
 * @property string|null $uploaded_by
 * @property Carbon $created_at
 */
final class Attachment extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    /**
     * Nothing. Every field is decided by the upload action from the file
     * itself — a mass-assigned path or checksum would be a claim about a file
     * nobody inspected.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * An attachment is never edited.
     *
     * A different file is a different attachment: replacing the bytes under a
     * row would leave the checksum, size and name describing something that
     * is no longer there, and a receipt that can be swapped is not evidence.
     */
    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new \RuntimeException(
                'An attachment cannot be changed. Delete it and upload the replacement, '.
                'so the record says what actually happened.'
            );
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Whether a browser can show this inline rather than download it. */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /**
     * The size, for a human.
     *
     * Kept out of the frontend because it is the sort of formatting that ends
     * up implemented three times with three different rounding rules.
     */
    public function humanSize(): string
    {
        $bytes = $this->size_bytes;

        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
