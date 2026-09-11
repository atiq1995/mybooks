<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Purchases\Actions\ApprovePurchaseDocument;
use App\Domain\Purchases\Actions\SavePurchaseDocument;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Sales\Actions\IssueSalesDocument;
use App\Domain\Sales\Actions\SaveSalesDocument;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Models\SalesDocument;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bring one unpaid invoice or bill across from a previous system.
 *
 * A real document, not a lump. It has a number, a customer, a due date, and
 * it ages exactly like any other — which is the whole point: a single figure
 * in the receivables control account cannot be aged, chased, or reconciled to
 * anybody, and the first thing a migrated business wants is a chasing list
 * that matches the one they had last week.
 *
 * What differs is only the contra side. §4.13 sends it to opening balance
 * equity rather than to revenue, because the sale happened in the old system
 * and was reported there — so recognising it again here would overstate
 * revenue by the whole receivable and make the first period's profit fiction.
 *
 * That is expressed by pointing the line at opening balance equity and going
 * through the ORDINARY posting path. It is not a trick: §4.13 says the contra
 * side of an opening balance is that account, and an opening invoice is an
 * opening balance that happens to have a customer's name on it. The reward is
 * that nothing in the sales or purchase modules needs a special case —
 * numbering, ageing, statements, part-payment and voiding all work because it
 * is genuinely the same document.
 *
 * TAX IS NOT CARRIED ACROSS. The output tax on that invoice was reported in
 * the old system's return; charging it again here would put it in this
 * period's return too. What comes across is the gross the customer owes.
 *
 * @see ACCOUNTING_RULES.md §4.13
 */
final readonly class EnterOpeningDocument
{
    public function __construct(
        private SaveSalesDocument $saveSalesDocument,
        private IssueSalesDocument $issueSalesDocument,
        private SavePurchaseDocument $savePurchaseDocument,
        private ApprovePurchaseDocument $approvePurchaseDocument,
    ) {}

    /**
     * An unpaid invoice a customer still owes.
     */
    public function invoice(
        Contact $customer,
        string $amount,
        Carbon $issuedOn,
        ?Carbon $dueOn = null,
        ?string $reference = null,
        ?User $actor = null,
        bool $allowClosedPeriod = false,
        ?Carbon $openingDate = null,
    ): SalesDocument {
        $equityId = $this->equityAccountId();

        /*
         * Two dates, and they are usually different.
         *
         * The invoice was issued in June; the books start in July. The
         * document keeps June, because that is what its ageing and the
         * customer's statement depend on — an invoice re-dated to the
         * migration day would show as current when it is six weeks overdue.
         * The ENTRY lands on the opening date, because posting into a period
         * this system does not keep would restate a year already filed.
         */
        $openingDate ??= $issuedOn;

        return DB::transaction(function () use (
            $customer,
            $amount,
            $issuedOn,
            $dueOn,
            $reference,
            $actor,
            $allowClosedPeriod,
            $equityId,
            $openingDate,
        ): SalesDocument {
            $invoice = $this->saveSalesDocument->handle(
                type: SalesDocumentType::Invoice,
                attributes: [
                    'contact_id' => $customer->getKey(),
                    'issue_date' => $issuedOn->toDateString(),
                    'due_date' => ($dueOn ?? $customer->dueDateFrom($issuedOn))->toDateString(),
                    'reference' => $reference,
                    'notes' => 'Opening balance carried forward from a previous system.',
                ],
                lines: [[
                    'description' => 'Opening balance',
                    'quantity' => '1',
                    'unit_price' => $amount,
                    // No tax: it was reported in the old system's return.
                    'tax_id' => null,
                    // §4.13's contra side.
                    'revenue_account_id' => $equityId,
                ]],
                actor: $actor,
            );

            $invoice->forceFill(['is_opening_balance' => true])->save();

            return $this->issueSalesDocument->handle(
                document: $invoice->refresh(),
                actor: $actor,
                allowClosedPeriod: $allowClosedPeriod,
                postingDate: $openingDate,
            );
        });
    }

    /**
     * An unpaid bill we still owe a vendor.
     */
    public function bill(
        Contact $vendor,
        string $amount,
        Carbon $issuedOn,
        ?Carbon $dueOn = null,
        ?string $vendorReference = null,
        ?User $actor = null,
        bool $allowClosedPeriod = false,
        ?Carbon $openingDate = null,
    ): PurchaseDocument {
        $equityId = $this->equityAccountId();

        // Same two dates as an opening invoice, for the same reason.
        $openingDate ??= $issuedOn;

        return DB::transaction(function () use (
            $vendor,
            $amount,
            $issuedOn,
            $dueOn,
            $vendorReference,
            $actor,
            $allowClosedPeriod,
            $equityId,
            $openingDate,
        ): PurchaseDocument {
            $bill = $this->savePurchaseDocument->handle(
                type: PurchaseDocumentType::Bill,
                attributes: [
                    'contact_id' => $vendor->getKey(),
                    'issue_date' => $issuedOn->toDateString(),
                    'due_date' => ($dueOn ?? $vendor->dueDateFrom($issuedOn))->toDateString(),
                    'vendor_reference' => $vendorReference,
                    'notes' => 'Opening balance carried forward from a previous system.',
                ],
                lines: [[
                    'description' => 'Opening balance',
                    'quantity' => '1',
                    'unit_price' => $amount,
                    // No tax: the input tax was claimed in the old system.
                    'tax_id' => null,
                    'debit_account_id' => $equityId,
                ]],
                actor: $actor,
            );

            $bill->forceFill(['is_opening_balance' => true])->save();

            return $this->approvePurchaseDocument->handle(
                document: $bill->refresh(),
                actor: $actor,
                allowClosedPeriod: $allowClosedPeriod,
                postingDate: $openingDate,
            );
        });
    }

    private function equityAccountId(): string
    {
        $id = Account::query()
            ->where('system_role', SystemAccount::OpeningBalanceEquity->value)
            ->value('id');

        if (! is_string($id)) {
            throw PostingRefused::missingSystemAccount(
                SystemAccount::OpeningBalanceEquity->value,
            );
        }

        return $id;
    }
}
