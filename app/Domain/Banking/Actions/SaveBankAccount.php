<?php

declare(strict_types=1);

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Models\Account;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Banking\Enums\BankAccountKind;
use App\Domain\Banking\Exceptions\BankingRefused;
use App\Domain\Banking\Models\BankAccount;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create or update a bank, cash or credit-card account.
 *
 * The consequential part is the pairing with a ledger account, and it is
 * checked three ways: the account must be one that can hold a balance, its
 * type must agree with the kind (a credit card is a liability), and no other
 * bank account may already be using it.
 *
 * The ledger account itself is never created here. A chart of accounts is a
 * deliberate structure, and a banking screen quietly adding 1030, 1040 and
 * 1050 to it is how one stops being deliberate.
 */
final readonly class SaveBankAccount
{
    public function __construct(
        private TenantContext $tenant,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        array $attributes,
        ?BankAccount $bankAccount = null,
        ?User $actor = null,
    ): BankAccount {
        $organization = $this->tenant->organization();

        $kind = BankAccountKind::from(self::text($attributes, 'kind'));
        $account = Account::query()->findOrFail(self::text($attributes, 'account_id'));

        if ($account->is_header) {
            throw BankingRefused::accountNotPostable($account->name);
        }

        if ($account->type->value !== $kind->requiredAccountType()) {
            throw BankingRefused::accountTypeMismatch(
                $kind->label(),
                $kind->requiredAccountType(),
                $account->type->value,
            );
        }

        $taken = BankAccount::query()
            ->where('account_id', $account->getKey())
            ->when($bankAccount !== null, fn ($query) => $query->whereKeyNot($bankAccount?->getKey()))
            ->exists();

        if ($taken) {
            throw BankingRefused::accountAlreadyAttached($account->name);
        }

        return DB::transaction(function () use (
            $attributes,
            $bankAccount,
            $account,
            $kind,
            $actor,
            $organization,
        ): BankAccount {
            $isNew = $bankAccount === null;

            if ($isNew) {
                $bankAccount = new BankAccount;

                $bankAccount->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->getKey(),
                    'created_by' => $actor?->getKey(),
                ]);
            }

            /** @var BankAccount $bankAccount */
            $bankAccount->forceFill([
                'account_id' => $account->getKey(),
                'name' => self::text($attributes, 'name'),
                'bank_name' => self::optionalText($attributes, 'bank_name'),
                /*
                 * Masked on the way in, never on the way out. Whatever was
                 * typed, only the last four digits are ever stored — a full
                 * account number is a payment instruction, it is not needed
                 * to reconcile anything, and keeping it would put it in every
                 * backup and export the business ever makes.
                 */
                'account_number_masked' => self::mask(
                    self::optionalText($attributes, 'account_number'),
                ),
                'branch' => self::optionalText($attributes, 'branch'),
                'kind' => $kind,
                // The ledger account's own currency wins where it has one:
                // that is what its balance is denominated in.
                'currency' => $account->currency
                    ?? self::optionalText($attributes, 'currency')
                    ?? $organization->base_currency,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
                'is_primary' => (bool) ($attributes['is_primary'] ?? false),
                'notes' => self::optionalText($attributes, 'notes'),
            ])->save();

            if ($bankAccount->is_primary) {
                // One primary account. Two would each be "the" account money
                // defaults to, and the form would pick whichever came back.
                BankAccount::query()
                    ->whereKeyNot($bankAccount->getKey())
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $this->audit->record(
                action: $isNew ? 'banking.account_created' : 'banking.account_updated',
                subject: $bankAccount,
                description: sprintf(
                    '%s %s "%s" on %s %s',
                    $isNew ? 'Added' : 'Updated',
                    mb_strtolower($kind->label()),
                    $bankAccount->name,
                    $account->code,
                    $account->name,
                ),
                new: [
                    'name' => $bankAccount->name,
                    'kind' => $kind->value,
                    'account' => $account->code,
                    'currency' => $bankAccount->currency,
                    'is_active' => $bankAccount->is_active,
                ],
                actor: $actor,
            );

            return $bankAccount->refresh();
        });
    }

    /**
     * Archive an account without losing what it has already seen.
     *
     * Never a delete: its statement lines, matches and reconciliations are
     * the evidence for periods that are already closed.
     */
    public function archive(BankAccount $bankAccount, ?User $actor = null): BankAccount
    {
        return DB::transaction(function () use ($bankAccount, $actor): BankAccount {
            $bankAccount->forceFill([
                'is_active' => false,
                'is_primary' => false,
                'archived_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'banking.account_archived',
                subject: $bankAccount,
                description: "Archived {$bankAccount->name}",
                actor: $actor,
            );

            return $bankAccount->refresh();
        });
    }

    /**
     * The last four digits, and a marker for what was dropped.
     */
    private static function mask(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9A-Za-z]/', '', $number) ?? '';

        if ($digits === '') {
            return null;
        }

        return mb_strlen($digits) <= 4
            ? $digits
            : '••••'.mb_substr($digits, -4);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function text(array $attributes, string $key): string
    {
        $value = $attributes[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function optionalText(array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
