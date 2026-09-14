<?php

declare(strict_types=1);

namespace App\Domain\Banking\Data;

use Illuminate\Support\Carbon;

/**
 * One transaction as it was read out of a statement file.
 *
 * Deliberately dumb: a parser's only job is to turn a file into these, with
 * no database access and no opinion about what any of it means. That is what
 * makes three very different formats testable against the same assertions.
 *
 * `amount` is a signed decimal STRING. Signed because every format expresses
 * direction that way and a boolean would need each parser to invent its own
 * convention; a string because a statement line for 1,234.56 that arrives as
 * a float has already lost the argument.
 */
final readonly class ParsedStatementLine
{
    public function __construct(
        public Carbon $date,
        public string $description,
        public string $amount,
        public ?string $reference = null,
        public ?string $payee = null,
        public ?string $balance = null,
    ) {}
}
