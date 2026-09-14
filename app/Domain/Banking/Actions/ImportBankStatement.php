<?php

declare(strict_types=1);

namespace App\Domain\Banking\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Banking\Data\ParsedStatementLine;
use App\Domain\Banking\Enums\StatementFormat;
use App\Domain\Banking\Enums\StatementLineStatus;
use App\Domain\Banking\Exceptions\BankingRefused;
use App\Domain\Banking\Exceptions\StatementUnreadable;
use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Models\BankStatementImport;
use App\Domain\Banking\Models\BankStatementLine;
use App\Domain\Banking\Services\StatementParserFactory;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read a statement file into statement lines. Nothing else.
 *
 * §8: **import is not posting.** Not one journal line results from this, and
 * that is what makes it safe to give a bookkeeper `banking.import` without
 * `banking.reconcile` — they can get the bank's data into the system, and
 * somebody else decides what it means.
 *
 * Importing the same file twice is a no-op, and the mechanism is a unique
 * index rather than a check in PHP. Each line's fingerprint hashes its
 * content plus which occurrence of that content it is within the account, so
 * two genuinely identical transactions on one day both survive while a
 * re-imported file collides on every row. A check in PHP could not do this
 * safely: two uploads racing would both find nothing and both insert.
 */
final readonly class ImportBankStatement
{
    public function __construct(
        private TenantContext $tenant,
        private StatementParserFactory $parsers,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  string  $contents  the uploaded file, as bytes
     */
    public function handle(
        BankAccount $bankAccount,
        string $contents,
        string $filename,
        ?StatementFormat $format = null,
        ?User $actor = null,
    ): BankStatementImport {
        if (! $bankAccount->kind->hasStatements()) {
            throw BankingRefused::statementsNotSupported($bankAccount->kind->label());
        }

        $format ??= $this->parsers->detect($filename, $contents);

        // Parsing happens OUTSIDE the transaction: a malformed file is the
        // common case, and it should not have opened one.
        $parsed = $this->parsers->for($format)->parse($contents, $filename);

        if ($parsed === []) {
            throw StatementUnreadable::empty($filename);
        }

        $organization = $this->tenant->organization();

        return DB::transaction(function () use (
            $bankAccount,
            $parsed,
            $contents,
            $filename,
            $format,
            $actor,
            $organization,
        ): BankStatementImport {
            $import = new BankStatementImport;

            $dates = array_map(
                static fn (ParsedStatementLine $line): string => $line->date->toDateString(),
                $parsed,
            );

            $import->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $organization->getKey(),
                'bank_account_id' => $bankAccount->getKey(),
                'format' => $format,
                'filename' => mb_substr($filename, 0, 255),
                'file_hash' => hash('sha256', $contents),
                'statement_start' => min($dates),
                'statement_end' => max($dates),
                'rows_total' => count($parsed),
                'rows_imported' => 0,
                'rows_duplicate' => 0,
                'imported_by' => $actor?->getKey(),
            ])->save();

            $imported = 0;
            $duplicates = 0;

            /*
             * How many of this exact line the account already holds. The
             * counter is per fingerprint-without-occurrence, which is what
             * lets a genuine second identical transaction through while a
             * re-imported file collides.
             */
            $seenInThisFile = [];

            foreach ($parsed as $line) {
                $base = implode('|', [
                    $line->date->toDateString(),
                    $line->amount,
                    mb_strtolower(trim($line->description)),
                    mb_strtolower(trim($line->reference ?? '')),
                ]);

                $occurrence = ($seenInThisFile[$base] ?? 0) + 1;
                $seenInThisFile[$base] = $occurrence;

                $fingerprint = BankStatementLine::fingerprintFor(
                    organization: $organization,
                    bankAccountId: $bankAccount->id,
                    date: $line->date->toDateString(),
                    amount: $line->amount,
                    description: $line->description,
                    reference: $line->reference,
                    occurrence: $occurrence,
                );

                $inserted = DB::table('bank_statement_lines')->insertOrIgnore([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->getKey(),
                    'bank_account_id' => $bankAccount->getKey(),
                    'import_id' => $import->getKey(),
                    'transaction_date' => $line->date->toDateString(),
                    'description' => $line->description,
                    'reference' => $line->reference,
                    'payee' => $line->payee,
                    'amount' => $line->amount,
                    'statement_balance' => $line->balance,
                    'fingerprint' => $fingerprint,
                    'status' => StatementLineStatus::Unmatched->value,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                if ($inserted === 1) {
                    $imported++;
                } else {
                    $duplicates++;
                }
            }

            $import->forceFill([
                'rows_imported' => $imported,
                'rows_duplicate' => $duplicates,
            ])->save();

            $this->audit->record(
                action: 'banking.statement_imported',
                subject: $import,
                description: sprintf(
                    'Imported %s into %s — %d new, %d already present',
                    $filename,
                    $bankAccount->name,
                    $imported,
                    $duplicates,
                ),
                new: [
                    'format' => $format->value,
                    'rows_total' => count($parsed),
                    'rows_imported' => $imported,
                    'rows_duplicate' => $duplicates,
                    'period' => [min($dates), max($dates)],
                ],
                actor: $actor,
            );

            return $import->refresh();
        });
    }
}
