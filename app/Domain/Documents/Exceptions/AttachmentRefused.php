<?php

declare(strict_types=1);

namespace App\Domain\Documents\Exceptions;

use RuntimeException;

/**
 * A file could not be attached.
 *
 * Every message here is shown to somebody holding a phone with a photograph
 * of a receipt on it, so each one says what to do next rather than what went
 * wrong internally.
 */
final class AttachmentRefused extends RuntimeException
{
    public static function uploadFailed(string $name): self
    {
        return new self(
            "{$name} did not arrive intact. Try again — if it keeps failing, the file may ".
            'be larger than the connection will carry in one go.'
        );
    }

    public static function empty(string $name): self
    {
        return new self("{$name} is empty, so there is nothing to attach.");
    }

    public static function tooLarge(int $size, int $limit): self
    {
        return new self(
            'That file is '.self::mb($size).' and the limit is '.self::mb($limit).
            '. A photograph of a receipt is usually under 2 MB — if yours is much '.
            'larger, your camera is saving at full resolution and can be turned down.'
        );
    }

    public static function unsupportedType(string $mimeType): self
    {
        return new self(
            "A {$mimeType} cannot be attached to a financial record. Attach a ".
            'photograph, a scan or a PDF.'
        );
    }

    /**
     * The duplicate guard.
     *
     * Named, because the person uploading almost never knows they already
     * have — and on an expense a duplicate receipt is usually a duplicate
     * claim.
     */
    public static function alreadyAttached(string $existingName): self
    {
        return new self(
            "That exact file is already attached, as {$existingName}. If this is a ".
            'different receipt, check you picked the right one.'
        );
    }

    public static function notEditable(string $status): self
    {
        return new self(
            "This expense is {$status}, so its receipts can no longer be changed. ".
            'A receipt is part of the record once the expense has been approved.'
        );
    }

    private static function mb(int $bytes): string
    {
        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
