<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Documents\Exceptions\AttachmentRefused;
use App\Domain\Documents\Models\Attachment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Put a file in the bucket and index it.
 *
 * The file is inspected here rather than trusted from the request. A browser
 * sends the client's idea of the type and the name, and neither is evidence:
 * the type is read from the contents, and the name is kept only as a label
 * for the eventual download.
 *
 * The path is derived, never supplied. It is prefixed with the organisation
 * so that a bucket listing is segmented the same way the database is, and it
 * ends in a UUID so nothing is guessable and two files called `receipt.jpg`
 * cannot collide.
 *
 * @see docs/adr/0002-multi-tenancy.md
 */
final readonly class StoreAttachment
{
    /**
     * What may be attached.
     *
     * An allowlist of types, not a blocklist of extensions. A receipt is a
     * photograph, a scan or a PDF; anything else on a financial record is
     * either a mistake or an attempt at something.
     *
     * @var list<string>
     */
    public const array PERMITTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
        'application/pdf',
    ];

    /** Ten megabytes. A phone photograph of a receipt is under two. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private TenantContext $tenant,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        Model $attachable,
        UploadedFile $file,
        ?User $actor = null,
        string $disk = 's3',
    ): Attachment {
        $organization = $this->tenant->organization();

        if (! $file->isValid()) {
            throw AttachmentRefused::uploadFailed($file->getClientOriginalName());
        }

        $size = $file->getSize();

        if ($size === false || $size <= 0) {
            throw AttachmentRefused::empty($file->getClientOriginalName());
        }

        if ($size > self::MAX_BYTES) {
            throw AttachmentRefused::tooLarge($size, self::MAX_BYTES);
        }

        /*
         * The type from the CONTENTS, not from the request.
         *
         * `getClientMimeType()` is whatever the browser said, and a browser
         * will say anything. `getMimeType()` reads the file.
         */
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';

        if (! in_array($mimeType, self::PERMITTED_MIME_TYPES, strict: true)) {
            throw AttachmentRefused::unsupportedType($mimeType);
        }

        $checksum = hash_file('sha256', $file->getRealPath());

        if ($checksum === false) {
            throw AttachmentRefused::uploadFailed($file->getClientOriginalName());
        }

        /*
         * The same file, already on this record.
         *
         * Refused rather than stored twice, because on an expense a duplicate
         * receipt is usually a duplicate claim — and the person doing it
         * rarely knows they have.
         */
        $duplicate = Attachment::query()
            ->where('attachable_type', $attachable->getMorphClass())
            ->where('attachable_id', $attachable->getKey())
            ->where('checksum', $checksum)
            ->first();

        if ($duplicate !== null) {
            throw AttachmentRefused::alreadyAttached($duplicate->original_name);
        }

        /*
         * Concatenated rather than sprintf'd, because a model key is `mixed`
         * to static analysis — narrowing it here says out loud that a
         * non-scalar key would be a bug in the caller, not something to
         * stringify quietly.
         */
        $organizationId = $organization->id;
        $parentKey = $attachable->getKey();

        if (! is_string($parentKey) && ! is_int($parentKey)) {
            throw AttachmentRefused::uploadFailed($file->getClientOriginalName());
        }

        $path = 'organizations/'.$organizationId
            .'/'.Str::slug(class_basename($attachable))
            .'/'.$parentKey
            .'/'.Str::uuid7()
            .$this->extensionFor($mimeType);

        // Written before the row, so a failed upload leaves no index entry
        // pointing at nothing. The reverse would be worse: a listing showing
        // a receipt that cannot be opened.
        Storage::disk($disk)->put($path, $file->getContent(), 'private');

        try {
            return DB::transaction(function () use (
                $attachable,
                $file,
                $organization,
                $disk,
                $path,
                $mimeType,
                $size,
                $checksum,
                $actor,
            ): Attachment {
                $attachment = new Attachment;

                $attachment->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->getKey(),
                    'attachable_type' => $attachable->getMorphClass(),
                    'attachable_id' => $attachable->getKey(),
                    'disk' => $disk,
                    'path' => $path,
                    // Sanitised, because it becomes a Content-Disposition
                    // filename and a raw client string does not belong in a
                    // header.
                    'original_name' => $this->safeName($file->getClientOriginalName()),
                    'mime_type' => $mimeType,
                    'size_bytes' => $size,
                    'checksum' => $checksum,
                    'uploaded_by' => $actor?->getKey(),
                ])->save();

                $this->audit->record(
                    action: 'documents.attachment_added',
                    subject: $attachment,
                    description: sprintf(
                        'Attached %s to %s',
                        $attachment->original_name,
                        class_basename($attachable),
                    ),
                    new: [
                        'name' => $attachment->original_name,
                        'size' => $attachment->size_bytes,
                        'checksum' => $attachment->checksum,
                    ],
                    actor: $actor,
                );

                return $attachment;
            });
        } catch (\Throwable $exception) {
            // The row did not commit, so the object is unreferenced. Leaving
            // it would accumulate files nothing can reach or account for.
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    /**
     * A filename safe to put in a header and show in a list.
     *
     * Path separators and control characters removed rather than escaped: a
     * receipt called `../../etc/passwd` has no legitimate reading, and
     * neither does one with a newline in it.
     */
    private function safeName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\P{C}]+/u', '', $name) ?? $name;
        $name = trim(str_replace(['"', '\\', "\n", "\r", "\t"], '', $name));

        if ($name === '') {
            return 'attachment';
        }

        return mb_substr($name, 0, 255);
    }

    private function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/webp' => '.webp',
            'image/heic', 'image/heif' => '.heic',
            'application/pdf' => '.pdf',
            default => '',
        };
    }
}
