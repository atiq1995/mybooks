<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Sales\Actions\ConvertSalesDocument;
use App\Domain\Sales\Actions\IssueSalesDocument;
use App\Domain\Sales\Actions\RecordCustomerPayment;
use App\Domain\Sales\Actions\SaveSalesDocument;
use App\Domain\Sales\Actions\VoidSalesDocument;
use App\Domain\Sales\Enums\SalesDocumentStatus;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Exceptions\SalesDocumentRefused;
use App\Domain\Sales\Models\SalesDocument;
use App\Domain\Tax\Models\Tax;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|---------------------------------------------------------------------------
| The sales lifecycle, end to end
|---------------------------------------------------------------------------
|
| Phase 3's exit criterion, asserted directly: estimate → sales order →
| invoice → payment posts correctly at every step. Against a real database,
| through the real Actions, with the resulting journal LINES checked rather
| than an HTTP status.
|
| The pure tests already prove the arithmetic and the posting rules. What
| these add is everything the pure tests cannot see: numbering, idempotency,
| the tax components resolving by date, a document's status moving with its
| balance, and — most of all — that the customer's balance and the ledger
| agree after each step.
|
| @see ACCOUNTING_RULES.md §4.1–§4.5, §6, §10
*/

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);
    $this->actor = User::factory()->create();

    $this->save = app(SaveSalesDocument::class);
    $this->issue = app(IssueSalesDocument::class);
    $this->void = app(VoidSalesDocument::class);
    $this->convert = app(ConvertSalesDocument::class);
    $this->pay = app(RecordCustomerPayment::class);

    $this->ar = ledgerAccount(SystemAccount::AccountsReceivable);
    $this->discounts = ledgerAccount(SystemAccount::TradeDiscounts);
    $this->returns = ledgerAccount(SystemAccount::SalesReturns);
    $this->gstOutput = ledgerAccount(SystemAccount::GstOutput);
    $this->advances = ledgerAccount(SystemAccount::CustomerAdvances);
    $this->wht = ledgerAccount(SystemAccount::WithholdingTaxReceivable);
    $this->fx = ledgerAccount(SystemAccount::FxGainLoss);
    $this->bank = ledgerAccount('1020');
    $this->revenue = Account::query()->where('code', '4010')->sole();

    // Created by PrepareLedger, which now seeds the Pakistan defaults.
    $this->gst = Tax::query()->where('code', 'GST18')->sole();

    $this->customer = Contact::query()->create([
        'kind' => ContactKind::Customer,
        'display_name' => 'Karachi Textiles',
        'email' => 'accounts@karachitextiles.test',
        'payment_terms_days' => 30,
        'is_tax_filer' => true,
    ]);

    $this->inYear = Carbon::parse('2026-09-15');

    /**
     * A one-line document, priced so the §4.1 worked example falls out.
     */
    $this->draft = fn (
        SalesDocumentType $type = SalesDocumentType::Invoice,
        string $price = '100000.00',
        ?string $discount = '5',
        ?string $taxId = null,
        ?Carbon $on = null,
    ): SalesDocument => $this->save->handle(
        type: $type,
        attributes: [
            'contact_id' => $this->customer->id,
            'issue_date' => ($on ?? $this->inYear)->toDateString(),
        ],
        lines: [
            [
                'description' => 'Cotton yarn, 40s count',
                'quantity' => '1',
                'unit_price' => $price,
                'discount_type' => $discount === null ? null : 'percentage',
                'discount_value' => $discount,
                'tax_id' => $taxId ?? $this->gst->id,
                'revenue_account_id' => $this->revenue->id,
            ],
        ],
        actor: $this->actor,
    );
});

// entryLines() now lives in tests/Pest.php: the purchase lifecycle asserts
// against it too, and a helper declared at the top level of a test file is
// global to the process — so the second suite to want it either cannot see it
// or collides with it, depending on how the parallel runner distributes files.

describe('a draft', function (): void {
    it('computes and stores every total from the tax engine', function (): void {
        $invoice = ($this->draft)();

        expect($invoice->status)->toBe(SalesDocumentStatus::Draft)
            ->and($invoice->number)->toBe('INV-000001')
            // The §4.1 figures, arrived at through the real tax tables.
            ->and($invoice->subtotal)->toBeDecimal('100000.0000')
            ->and($invoice->discount_total)->toBeDecimal('5000.0000')
            ->and($invoice->tax_total)->toBeDecimal('17100.0000')
            ->and($invoice->total)->toBeDecimal('112100.0000');
    });

    it('stores the tax breakdown per component, for the return', function (): void {
        $invoice = ($this->draft)();

        $breakdown = $invoice->lineTaxes()->get();

        expect($breakdown)->toHaveCount(1);
        expect($breakdown[0]->component_name)->toBe('GST')
            ->and($breakdown[0]->rate)->toBe('0.180000')
            ->and($breakdown[0]->taxable_amount)->toBeDecimal('95000.0000')
            ->and($breakdown[0]->tax_amount)->toBeDecimal('17100.0000')
            // Pointed at the liability account, so posting does not have to
            // look the component up again.
            ->and($breakdown[0]->account_id)->toBe($this->gstOutput->id);
    });

    it('takes the due date from the customer terms', function (): void {
        $invoice = ($this->draft)();

        // Thirty days, which is why terms live on a contact rather than being
        // typed on every invoice.
        expect($invoice->due_date?->toDateString())->toBe('2026-10-15');
    });

    it('copies the billing address rather than referencing it', function (): void {
        $this->customer->forceFill([
            'billing_address' => ['line1' => 'Plot 12, SITE', 'city' => 'Karachi'],
        ])->save();

        $invoice = ($this->draft)();

        // The customer moving must not change where last year's invoices went.
        $this->customer->forceFill([
            'billing_address' => ['line1' => 'Somewhere else', 'city' => 'Lahore'],
        ])->save();

        // Key order is jsonb's business, so the contents are what matter.
        expect($invoice->fresh()?->billing_address)
            ->toEqualCanonicalizing(['line1' => 'Plot 12, SITE', 'city' => 'Karachi']);
    });

    it('numbers each document type on its own sequence', function (): void {
        ($this->draft)(SalesDocumentType::Invoice);
        ($this->draft)(SalesDocumentType::Invoice);
        $estimate = ($this->draft)(SalesDocumentType::Estimate);

        expect($estimate->number)->toBe('EST-000001');
        expect(($this->draft)(SalesDocumentType::Invoice)->number)->toBe('INV-000003');
    });

    it('replaces lines wholesale when edited', function (): void {
        $invoice = ($this->draft)();

        $edited = $this->save->handle(
            type: SalesDocumentType::Invoice,
            attributes: [
                'contact_id' => $this->customer->id,
                'issue_date' => $this->inYear->toDateString(),
            ],
            lines: [
                [
                    'description' => 'Something else entirely',
                    'quantity' => '2',
                    'unit_price' => '500.00',
                    'tax_id' => $this->gst->id,
                    'revenue_account_id' => $this->revenue->id,
                ],
            ],
            document: $invoice,
            actor: $this->actor,
        );

        expect($edited->lines()->count())->toBe(1)
            ->and($edited->total)->toBeDecimal('1180.0000')
            // Same document, same number: editing a draft is not a new one.
            ->and($edited->number)->toBe('INV-000001');

        // The old breakdown went with the old lines.
        expect($edited->lineTaxes()->count())->toBe(1);
    });

    it('posts nothing at all', function (): void {
        ($this->draft)();

        // §6: a draft has no accounting effect.
        expect(JournalEntry::query()->count())->toBe(0);
    });

    it('refuses a customer who is archived', function (): void {
        $this->customer->forceFill(['archived_at' => now(), 'is_active' => false])->save();

        expect(fn () => ($this->draft)())
            ->toThrow(SalesDocumentRefused::class, 'archived');
    });

    it('refuses a contact who is only a vendor', function (): void {
        $vendor = Contact::query()->create([
            'kind' => ContactKind::Vendor,
            'display_name' => 'Paper Supplier',
        ]);

        expect(fn () => $this->save->handle(
            type: SalesDocumentType::Invoice,
            attributes: ['contact_id' => $vendor->id, 'issue_date' => $this->inYear->toDateString()],
            lines: [['description' => 'x', 'quantity' => '1', 'unit_price' => '1']],
            actor: $this->actor,
        ))->toThrow(SalesDocumentRefused::class, 'not marked as a customer');
    });
});

describe('issuing an invoice', function (): void {
    it('posts §4.1 exactly, line by line', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        expect($invoice->status)->toBe(SalesDocumentStatus::Sent)
            ->and($invoice->journal_entry_id)->not->toBeNull();

        $entry = JournalEntry::query()->findOrFail($invoice->journal_entry_id);
        $lines = entryLines($entry);

        // The four lines from the rule table, to the cent.
        expect($lines['1200'])->toBe(['debit' => '112100.0000', 'credit' => '0.0000'])
            ->and($lines['4900'])->toBe(['debit' => '5000.0000', 'credit' => '0.0000'])
            ->and($lines['4010'])->toBe(['debit' => '0.0000', 'credit' => '100000.0000'])
            ->and($lines['2300'])->toBe(['debit' => '0.0000', 'credit' => '17100.0000']);

        // And the entry names the invoice, so the ledger is traceable.
        expect($entry->source_type)->toBe('invoice')
            ->and($entry->source_id)->toBe($invoice->id)
            ->and($entry->memo)->toBe('INV-000001');
    });

    it('refuses to issue the same invoice twice', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        expect(fn () => $this->issue->handle($invoice->fresh(), $this->actor))
            ->toThrow(SalesDocumentRefused::class, 'already been issued');

        expect(JournalEntry::query()->count())->toBe(1);
    });

    it('refuses to edit an issued invoice', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        expect(fn () => $this->save->handle(
            type: SalesDocumentType::Invoice,
            attributes: [
                'contact_id' => $this->customer->id,
                'issue_date' => $this->inYear->toDateString(),
            ],
            lines: [['description' => 'changed', 'quantity' => '1', 'unit_price' => '1']],
            document: $invoice->fresh(),
            actor: $this->actor,
        ))->toThrow(SalesDocumentRefused::class, 'can no longer be edited');
    });

    it('refuses an invoice with no lines and one worth nothing', function (): void {
        $empty = $this->save->handle(
            type: SalesDocumentType::Invoice,
            attributes: [
                'contact_id' => $this->customer->id,
                'issue_date' => $this->inYear->toDateString(),
            ],
            lines: [],
            actor: $this->actor,
        );

        expect(fn () => $this->issue->handle($empty, $this->actor))
            ->toThrow(SalesDocumentRefused::class, 'nothing to issue');

        // A fully discounted invoice totals zero, which has no accounting
        // effect and which the ledger refuses anyway.
        $free = ($this->draft)(discount: '100');

        expect(fn () => $this->issue->handle($free, $this->actor))
            ->toThrow(SalesDocumentRefused::class, 'totals zero');
    });

    it('groups two lines on one revenue account into one journal line', function (): void {
        $invoice = $this->save->handle(
            type: SalesDocumentType::Invoice,
            attributes: [
                'contact_id' => $this->customer->id,
                'issue_date' => $this->inYear->toDateString(),
            ],
            lines: [
                [
                    'description' => 'First',
                    'quantity' => '1',
                    'unit_price' => '1000.00',
                    'revenue_account_id' => $this->revenue->id,
                ],
                [
                    'description' => 'Second',
                    'quantity' => '1',
                    'unit_price' => '2000.00',
                    'revenue_account_id' => $this->revenue->id,
                ],
            ],
            actor: $this->actor,
        );

        $issued = $this->issue->handle($invoice, $this->actor);
        $entry = JournalEntry::query()->findOrFail($issued->journal_entry_id);

        // Two document lines, two journal lines: receivable and revenue.
        expect($entry->lines()->count())->toBe(2);
        expect(entryLines($entry)['4010']['credit'])->toBeDecimal('3000.0000');
    });

    it('leaves the customer owing exactly the invoice total', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        expect($invoice->balanceDue())->toBeDecimal('112100.0000')
            ->and($this->customer->outstandingBalance())->toBeDecimal('112100.0000');

        // And the ledger agrees, which is the assertion that matters.
        expect($this->ar->balance())->toBeDecimal('112100.0000');
    });
});

describe('a commitment document', function (): void {
    it('issues an estimate without touching the ledger', function (): void {
        $estimate = $this->issue->handle(
            ($this->draft)(SalesDocumentType::Estimate),
            $this->actor,
        );

        expect($estimate->status)->toBe(SalesDocumentStatus::Sent)
            // §6: an estimate is never a financial event.
            ->and($estimate->journal_entry_id)->toBeNull()
            ->and(JournalEntry::query()->count())->toBe(0);
    });

    it('cannot acquire a journal entry, even by raw SQL', function (): void {
        $estimate = ($this->draft)(SalesDocumentType::Estimate);

        // The invoice's entry, planted on the estimate. The CHECK constraint
        // enforces §6 independently of the code.
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        expect(refused(fn () => DB::table('sales_documents')
            ->where('id', $estimate->id)
            ->update(['journal_entry_id' => $invoice->journal_entry_id])))
            ->toThrow(QueryException::class, 'commitments_never_post');
    });
});

describe('estimate → sales order → invoice', function (): void {
    it('walks the whole chain, posting only at the invoice', function (): void {
        $estimate = $this->issue->handle(
            ($this->draft)(SalesDocumentType::Estimate),
            $this->actor,
        );

        $order = $this->convert->handle(
            $estimate,
            SalesDocumentType::SalesOrder,
            $this->actor,
            $this->inYear,
        );

        expect($order->type)->toBe(SalesDocumentType::SalesOrder)
            ->and($order->number)->toBe('SAL-000001')
            ->and($order->status)->toBe(SalesDocumentStatus::Draft)
            ->and($order->converted_from_id)->toBe($estimate->id)
            // The figures survive the conversion.
            ->and($order->total)->toBeDecimal('112100.0000');

        // The estimate is now spoken for, so it cannot be converted twice by
        // accident.
        expect($estimate->fresh()?->status)->toBe(SalesDocumentStatus::Accepted);

        $this->issue->handle($order->fresh(), $this->actor);

        $invoice = $this->convert->handle(
            $order->fresh(),
            SalesDocumentType::Invoice,
            $this->actor,
            $this->inYear,
        );

        expect($invoice->converted_from_id)->toBe($order->id)
            ->and($order->fresh()?->status)->toBe(SalesDocumentStatus::Closed);

        // Nothing has posted yet: two commitments and a draft.
        expect(JournalEntry::query()->count())->toBe(0);

        $issued = $this->issue->handle($invoice, $this->actor);

        expect(JournalEntry::query()->count())->toBe(1);
        expect(entryLines(JournalEntry::query()->findOrFail($issued->journal_entry_id))['1200']['debit'])
            ->toBeDecimal('112100.0000');
    });

    it('refuses to convert an invoice backwards', function (): void {
        $invoice = ($this->draft)();

        expect(fn () => $this->convert->handle($invoice, SalesDocumentType::Estimate, $this->actor))
            ->toThrow(InvalidArgumentException::class, 'void it or credit it');
    });
});

describe('a customer payment', function (): void {
    it('settles an invoice in full and clears the receivable — §4.3', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        $payment = $this->pay->handle(
            contact: $this->customer,
            bankAccountId: $this->bank->id,
            amount: '112100.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$invoice->id => '112100.00'],
            actor: $this->actor,
        );

        expect($payment->number)->toBe('RCPT-000001');

        $entry = JournalEntry::query()->findOrFail($payment->journal_entry_id);
        $lines = entryLines($entry);

        expect($lines['1020'])->toBe(['debit' => '112100.0000', 'credit' => '0.0000'])
            ->and($lines['1200'])->toBe(['debit' => '0.0000', 'credit' => '112100.0000']);

        $invoice->refresh();

        expect($invoice->status)->toBe(SalesDocumentStatus::Paid)
            ->and($invoice->balanceDue())->toBeDecimal('0.0000')
            // The receivable is back to nothing, and the bank holds the money.
            ->and($this->ar->balance())->toBeDecimal('0.0000')
            ->and($this->bank->balance())->toBeDecimal('112100.0000');
    });

    it('records withholding as a receivable and still settles in full — §4.2', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        $payment = $this->pay->handle(
            contact: $this->customer,
            bankAccountId: $this->bank->id,
            amount: '112100.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$invoice->id => '112100.00'],
            withholdingAmount: '4000.00',
            actor: $this->actor,
        );

        expect($payment->amount_received)->toBeDecimal('108100.0000');

        $lines = entryLines(JournalEntry::query()->findOrFail($payment->journal_entry_id));

        expect($lines['1020']['debit'])->toBeDecimal('108100.0000')
            // An asset — advance tax paid on our behalf, recoverable.
            ->and($lines['1450']['debit'])->toBeDecimal('4000.0000')
            // The invoice is settled in FULL, not short by the withholding.
            ->and($lines['1200']['credit'])->toBeDecimal('112100.0000');

        expect($invoice->fresh()?->status)->toBe(SalesDocumentStatus::Paid)
            ->and($this->wht->balance())->toBeDecimal('4000.0000');
    });

    it('holds an overpayment as an advance — §4.4', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        $payment = $this->pay->handle(
            contact: $this->customer,
            bankAccountId: $this->bank->id,
            amount: '150000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$invoice->id => '112100.00'],
            actor: $this->actor,
        );

        expect($payment->unallocatedAmount())->toBeDecimal('37900.0000');

        $lines = entryLines(JournalEntry::query()->findOrFail($payment->journal_entry_id));

        // A liability: we owe goods, services or a refund.
        expect($lines['2200']['credit'])->toBeDecimal('37900.0000')
            ->and($lines['1200']['credit'])->toBeDecimal('112100.0000');

        expect($this->advances->balance())->toBeDecimal('37900.0000');
    });

    it('marks an invoice partially paid, then paid', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        $this->pay->handle(
            contact: $this->customer,
            bankAccountId: $this->bank->id,
            amount: '50000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$invoice->id => '50000.00'],
            actor: $this->actor,
        );

        expect($invoice->fresh()?->status)->toBe(SalesDocumentStatus::PartiallyPaid)
            ->and($invoice->fresh()?->balanceDue())->toBeDecimal('62100.0000');

        $this->pay->handle(
            contact: $this->customer,
            bankAccountId: $this->bank->id,
            amount: '62100.00',
            paymentDate: Carbon::parse('2026-10-15'),
            allocations: [$invoice->id => '62100.00'],
            actor: $this->actor,
        );

        expect($invoice->fresh()?->status)->toBe(SalesDocumentStatus::Paid)
            ->and($this->ar->balance())->toBeDecimal('0.0000');
    });

    it('settles two invoices with one receipt', function (): void {
        $first = $this->issue->handle(($this->draft)(price: '1000.00', discount: null), $this->actor);
        $second = $this->issue->handle(($this->draft)(price: '2000.00', discount: null), $this->actor);

        $this->pay->handle(
            contact: $this->customer,
            bankAccountId: $this->bank->id,
            amount: '3540.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$first->id => '1180.00', $second->id => '2360.00'],
            actor: $this->actor,
        );

        expect($first->fresh()?->status)->toBe(SalesDocumentStatus::Paid)
            ->and($second->fresh()?->status)->toBe(SalesDocumentStatus::Paid)
            ->and($this->ar->balance())->toBeDecimal('0.0000');
    });

    it('refuses to allocate more than the invoice owes', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        expect(fn () => $this->pay->handle(
            contact: $this->customer,
            bankAccountId: $this->bank->id,
            amount: '200000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$invoice->id => '200000.00'],
            actor: $this->actor,
        ))->toThrow(SalesDocumentRefused::class, 'has only 112100.0000 outstanding');
    });

    it('refuses to allocate to another customer invoice', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        $other = Contact::query()->create([
            'kind' => ContactKind::Customer,
            'display_name' => 'Somebody Else',
        ]);

        expect(fn () => $this->pay->handle(
            contact: $other,
            bankAccountId: $this->bank->id,
            amount: '100.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$invoice->id => '100.00'],
            actor: $this->actor,
        ))->toThrow(InvalidArgumentException::class, 'different customer');
    });

    it('refuses withholding greater than the payment', function (): void {
        expect(fn () => $this->pay->handle(
            contact: $this->customer,
            bankAccountId: $this->bank->id,
            amount: '100.00',
            paymentDate: Carbon::parse('2026-10-01'),
            withholdingAmount: '200.00',
            actor: $this->actor,
        ))->toThrow(InvalidArgumentException::class, 'share of the amount settled');
    });
});

describe('a credit note', function (): void {
    it('posts §4.5 and reduces what the customer owes', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        $creditNote = $this->save->handle(
            type: SalesDocumentType::CreditNote,
            attributes: [
                'contact_id' => $this->customer->id,
                'issue_date' => '2026-09-20',
            ],
            lines: [
                [
                    'description' => 'Returned: 10 rolls damaged',
                    'quantity' => '1',
                    'unit_price' => '10000.00',
                    'tax_id' => $this->gst->id,
                    'revenue_account_id' => $this->revenue->id,
                ],
            ],
            actor: $this->actor,
        );

        $creditNote->forceFill(['credits_document_id' => $invoice->id])->save();

        $issued = $this->issue->handle($creditNote->fresh(), $this->actor);

        $lines = entryLines(JournalEntry::query()->findOrFail($issued->journal_entry_id));

        // Sales returns, NOT revenue: a month of returns must not look like a
        // month of no sales.
        expect($lines['4800'])->toBe(['debit' => '10000.0000', 'credit' => '0.0000'])
            ->and($lines)->not->toHaveKey('4010')
            // The output tax genuinely reduces.
            ->and($lines['2300'])->toBe(['debit' => '1800.0000', 'credit' => '0.0000'])
            ->and($lines['1200'])->toBe(['debit' => '0.0000', 'credit' => '11800.0000']);

        $invoice->refresh();

        expect($invoice->amount_credited)->toBeDecimal('11800.0000')
            ->and($invoice->balanceDue())->toBeDecimal('100300.0000')
            // Credited, not paid: "cash received this month" stays right.
            ->and($invoice->amount_paid)->toBeDecimal('0.0000');

        expect($this->ar->balance())->toBeDecimal('100300.0000');
    });
});

describe('voiding', function (): void {
    it('reverses the entry and leaves both on the record', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        $voided = $this->void->handle($invoice, $this->actor, 'Raised against the wrong customer');

        expect($voided->status)->toBe(SalesDocumentStatus::Void)
            ->and($voided->void_journal_entry_id)->not->toBeNull()
            // The original entry survives. Corrections never erase history.
            ->and($voided->journal_entry_id)->not->toBeNull();

        expect(JournalEntry::query()->count())->toBe(2);

        // The receivable is back to nothing, by reversal rather than deletion.
        expect($this->ar->balance())->toBeDecimal('0.0000');

        $this->artisan('my-books:verify-ledger')->assertExitCode(0);
    });

    it('refuses to void an invoice with payments applied', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        $this->pay->handle(
            contact: $this->customer,
            bankAccountId: $this->bank->id,
            amount: '50000.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$invoice->id => '50000.00'],
            actor: $this->actor,
        );

        expect(fn () => $this->void->handle($invoice->fresh(), $this->actor))
            ->toThrow(SalesDocumentRefused::class, 'Remove or refund the payments first');
    });

    it('refuses to delete an issued invoice', function (): void {
        $invoice = $this->issue->handle(($this->draft)(), $this->actor);

        expect(fn () => $invoice->delete())
            ->toThrow(RuntimeException::class, 'Void it instead');
    });

    it('lets a draft be deleted outright', function (): void {
        // A draft never posted, so there is nothing to preserve.
        $draft = ($this->draft)();

        expect($draft->delete())->toBeTrue();
        expect(SalesDocument::query()->count())->toBe(0);
    });
});

describe('the ledger, after everything', function (): void {
    it('balances, and the receivable equals what customers owe', function (): void {
        /*
         * The property Phase 3 exists to guarantee: whatever sequence of
         * documents and payments happened, the AR control account equals the
         * sum of the outstanding invoices. If those two ever disagree, the
         * customer statement and the balance sheet tell different stories.
         */
        $paid = $this->issue->handle(($this->draft)(price: '1000.00', discount: null), $this->actor);
        $partly = $this->issue->handle(($this->draft)(price: '2000.00', discount: null), $this->actor);
        $open = $this->issue->handle(($this->draft)(price: '3000.00', discount: null), $this->actor);
        $voided = $this->issue->handle(($this->draft)(price: '4000.00', discount: null), $this->actor);

        $this->pay->handle(
            contact: $this->customer,
            bankAccountId: $this->bank->id,
            amount: '1180.00',
            paymentDate: Carbon::parse('2026-10-01'),
            allocations: [$paid->id => '1180.00'],
            actor: $this->actor,
        );

        $this->pay->handle(
            contact: $this->customer,
            bankAccountId: $this->bank->id,
            amount: '1000.00',
            paymentDate: Carbon::parse('2026-10-02'),
            allocations: [$partly->id => '1000.00'],
            actor: $this->actor,
        );

        $this->void->handle($voided, $this->actor);

        // 1,360 still owed on the partly paid one, plus 3,540 open.
        $expected = BigDecimal::of('1360.0000')->plus(BigDecimal::of('3540.0000'));

        expect($this->customer->outstandingBalance())->toBeDecimal((string) $expected->toScale(4))
            ->and($this->ar->balance())->toBeDecimal((string) $expected->toScale(4));

        $this->artisan('my-books:verify-ledger')->assertExitCode(0);
    });
});
