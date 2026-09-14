<?php

declare(strict_types=1);

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Data\ParsedStatementLine;
use App\Domain\Banking\Exceptions\StatementUnreadable;

/**
 * Turn the contents of a statement file into transactions.
 *
 * One method, no state, no database. A parser that needed the database would
 * be deciding what a line MEANS, and that decision belongs to a person with
 * `banking.reconcile`, not to a file reader.
 */
interface StatementParser
{
    /**
     * @param  string  $contents  the raw file, as uploaded
     * @param  string  $filename  used only in error messages
     * @return list<ParsedStatementLine>
     *
     * @throws StatementUnreadable
     */
    public function parse(string $contents, string $filename): array;
}
