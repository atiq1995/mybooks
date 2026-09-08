<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gives an organisation the two things it needs before anything can post: a
 * chart of accounts and an open financial year.
 *
 * Both in one transaction, because half a ledger is worse than none — an
 * organisation with accounts but no periods looks ready and refuses every
 * posting with an error about dates.
 *
 * Idempotent, so the onboarding wizard can be refreshed, resumed, or
 * double-submitted without producing a second chart or a duplicate year.
 *
 * @see ACCOUNTING_RULES.md §3, §7
 */
final readonly class PrepareLedger
{
    public function __construct(
        private CreateChartOfAccounts $createChartOfAccounts,
        private CreateFiscalYear $createFiscalYear,
    ) {}

    /**
     * @return array{accounts_created: int, fiscal_year: string}
     */
    public function handle(Organization $organization, ?User $actor = null): array
    {
        return DB::transaction(function () use ($organization, $actor): array {
            $created = $this->createChartOfAccounts->handle($organization, $actor);

            $year = $this->currentYear() ?? $this->createFiscalYear->handle($organization, actor: $actor);

            return [
                'accounts_created' => $created,
                'fiscal_year' => $year->label,
            ];
        });
    }

    /**
     * Whether this organisation can already post.
     *
     * Both halves are required. Accounts alone are not enough, which is why
     * this asks about periods too rather than counting accounts and assuming.
     */
    public function isReady(): bool
    {
        return Account::query()->postable()->exists()
            && FiscalYear::query()->exists();
    }

    /**
     * The financial year covering today, if one exists.
     *
     * Matched on dates rather than on a label, so an organisation that
     * back-filled last year does not get a second copy of this one.
     */
    private function currentYear(): ?FiscalYear
    {
        return FiscalYear::query()
            ->whereDate('starts_on', '<=', now())
            ->whereDate('ends_on', '>=', now())
            ->first();
    }
}
