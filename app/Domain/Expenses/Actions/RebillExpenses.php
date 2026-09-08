<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Expenses\Exceptions\ExpenseRefused;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseLine;
use App\Domain\Sales\Actions\SaveSalesDocument;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Models\SalesDocument;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Put billable expenses onto a draft invoice for the customer.
 *
 * Several at once, into ONE invoice. That is how the work actually arrives —
 * a month of travel and materials on one job, billed together — and one
 * invoice per expense would be unusable for both sides.
 *
 * A DRAFT invoice, and this is the important part. What to charge for a
 * rebilled cost is a commercial decision: at cost, with a markup, or rounded
 * to what was agreed. Issuing it automatically would post revenue at a figure
 * nobody chose, and the person who has to defend the invoice is not the
 * person who typed the expense.
 *
 * The amount carried across is the CAPITALISED cost — net plus any tax that
 * could not be reclaimed. Rebilling the net alone would silently absorb the
 * blocked tax as a loss, which is exactly the figure the expense screens work
 * to keep visible.
 *
 * @see ACCOUNTING_RULES.md §4.1, §4.6
 */
final readonly class RebillExpenses
{
    /**
     * No tenant context of its own: everything it touches is already scoped
     * by the global Eloquent scope, and `SaveSalesDocument` resolves the
     * organisation itself.
     */
    public function __construct(
        private SaveSalesDocument $saveSalesDocument,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  list<Expense>  $expenses  all for the same customer
     */
    public function handle(
        array $expenses,
        ?User $actor = null,
        ?Carbon $issueDate = null,
        ?string $revenueAccountId = null,
    ): SalesDocument {
        if ($expenses === []) {
            throw new \InvalidArgumentException('Choose at least one expense to rebill.');
        }

        $issueDate ??= Carbon::now();

        $contactId = null;

        foreach ($expenses as $expense) {
            if (! $expense->is_billable || $expense->billable_contact_id === null) {
                throw ExpenseRefused::notBillable($expense->number);
            }

            if ($expense->billed_document_id !== null) {
                // firstOrFail, not first: the foreign key is nullOnDelete,
                // so a non-null id means the invoice is there — and if it
                // somehow is not, that is a broken link worth an exception
                // rather than a message naming "an invoice".
                throw ExpenseRefused::alreadyBilled(
                    $expense->number,
                    $expense->billedDocument()->firstOrFail()->number,
                );
            }

            if (! $expense->status->isPosted()) {
                throw ExpenseRefused::notSubmitted(
                    $expense->number,
                    $expense->status->label(),
                );
            }

            $contactId ??= $expense->billable_contact_id;

            /*
             * One customer per invoice. Mixing two customers' costs onto one
             * invoice would bill each of them for the other's, and there is
             * no reading of the request where that is what was meant.
             */
            if ($expense->billable_contact_id !== $contactId) {
                throw new \InvalidArgumentException(
                    'Those expenses are billable to different customers. Rebill one '.
                    'customer at a time.'
                );
            }
        }

        /** @var string $contactId */
        $contact = Contact::query()->findOrFail($contactId);

        return DB::transaction(function () use (
            $expenses,
            $contact,
            $actor,
            $issueDate,
            $revenueAccountId,
        ): SalesDocument {
            $lines = [];

            foreach ($expenses as $expense) {
                foreach ($expense->lines()->get() as $line) {
                    /** @var ExpenseLine $line */
                    $lines[] = [
                        // The expense's own number in the description, so the
                        // customer's query about a charge has an answer.
                        'description' => sprintf(
                            '%s (%s%s)',
                            $line->description,
                            $expense->number,
                            $expense->merchant === null ? '' : ", {$expense->merchant}",
                        ),
                        'quantity' => '1',
                        // At cost, including tax that could not be reclaimed.
                        'unit_price' => $line->capitalisedCost(),
                        'revenue_account_id' => $revenueAccountId,
                    ];
                }
            }

            $invoice = $this->saveSalesDocument->handle(
                type: SalesDocumentType::Invoice,
                attributes: [
                    'contact_id' => $contact->getKey(),
                    'issue_date' => $issueDate->toDateString(),
                    'notes' => 'Expenses rebilled at cost. Check the amounts before issuing.',
                ],
                lines: $lines,
                actor: $actor,
            );

            foreach ($expenses as $expense) {
                $expense->forceFill(['billed_document_id' => $invoice->getKey()])->save();

                $this->audit->record(
                    action: 'expenses.rebilled',
                    subject: $expense,
                    description: sprintf(
                        'Added expense %s to draft invoice %s for %s',
                        $expense->number,
                        $invoice->number,
                        $contact->display_name,
                    ),
                    new: [
                        'expense' => $expense->number,
                        'invoice' => $invoice->number,
                        'amount' => $expense->total,
                    ],
                    actor: $actor,
                );
            }

            return $invoice->refresh();
        });
    }
}
