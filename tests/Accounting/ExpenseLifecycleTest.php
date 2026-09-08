<?php

declare(strict_types=1);

use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contacts\Enums\ContactKind;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Documents\Actions\StoreAttachment;
use App\Domain\Documents\Exceptions\AttachmentRefused;
use App\Domain\Expenses\Actions\ApproveExpense;
use App\Domain\Expenses\Actions\RebillExpenses;
use App\Domain\Expenses\Actions\RejectExpense;
use App\Domain\Expenses\Actions\SaveExpense;
use App\Domain\Expenses\Actions\SubmitExpense;
use App\Domain\Expenses\Actions\VoidExpense;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Exceptions\ExpenseRefused;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\MileageRate;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Tax\Models\Tax;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|---------------------------------------------------------------------------
| The expense lifecycle, end to end
|---------------------------------------------------------------------------
|
| Phase 5's exit criterion, asserted directly: a receipt-attached expense
| posts with the correct tax treatment and routes through approval, and
| non-claimable input tax is capitalised rather than made a receivable.
|
| Against a real database, through the real Actions, with the resulting
| journal LINES checked. The receipt goes to a faked disk — the point being
| tested is that an expense with one attached behaves differently from one
| without, not that S3 works.
|
| @see ACCOUNTING_RULES.md §4.6, §4.8, §6, §10
*/

beforeEach(function (): void {
    Storage::fake('s3');

    $this->organization = Organization::factory()->create();
    $this->year = withLedger($this->organization, 2026);

    // Two people, because the approval rule is that they must differ.
    $this->claimant = User::factory()->create(['name' => 'Ayesha Malik']);
    $this->approver = User::factory()->create(['name' => 'Bilal Ahmed']);

    $this->save = app(SaveExpense::class);
    $this->submit = app(SubmitExpense::class);
    $this->approve = app(ApproveExpense::class);
    $this->reject = app(RejectExpense::class);
    $this->void = app(VoidExpense::class);
    $this->rebill = app(RebillExpenses::class);
    $this->attach = app(StoreAttachment::class);

    $this->gstInput = ledgerAccount(SystemAccount::GstInput);
    $this->reimbursements = ledgerAccount(SystemAccount::EmployeeReimbursements);
    $this->bank = ledgerAccount('1020');
    $this->travel = Account::query()->where('code', '6500')->sole();
    $this->supplies = Account::query()->where('code', '6300')->sole();
    $this->inventory = ledgerAccount(SystemAccount::Inventory);

    $this->gst = Tax::query()->where('code', 'GST18')->sole();

    $this->inYear = Carbon::parse('2026-09-15');

    /**
     * A one-line expense: 10,000 of travel at 18%, paid by the company.
     */
    $this->draft = fn (
        string $price = '10000.00',
        bool $claimable = true,
        string $mode = 'company',
        ?string $accountId = null,
        bool $billable = false,
        ?string $billTo = null,
    ): Expense => $this->save->handle(
        attributes: [
            'expense_date' => $this->inYear->toDateString(),
            'merchant' => 'Careem',
            'payment_mode' => $mode,
            'paid_through_account_id' => $mode === 'company' ? $this->bank->id : null,
            'reimburse_user_id' => $mode === 'reimbursable' ? $this->claimant->id : null,
            'is_billable' => $billable,
            'billable_contact_id' => $billTo,
        ],
        lines: [
            [
                'description' => 'Airport transfers, client visit',
                'quantity' => '1',
                'unit_price' => $price,
                'tax_id' => $this->gst->id,
                'debit_account_id' => $accountId ?? $this->travel->id,
                'tax_is_claimable' => $claimable,
            ],
        ],
        actor: $this->claimant,
    );
});

describe('a draft expense', function (): void {
    it('computes and stores every total, including what is recoverable', function (): void {
        $expense = ($this->draft)();

        expect($expense->number)->toBe('EXP-000001')
            ->and($expense->status)->toBe(ExpenseStatus::Draft)
            ->and($expense->subtotal)->toBeDecimal('10000.0000')
            ->and($expense->tax_total)->toBeDecimal('1800.0000')
            ->and($expense->tax_claimable_total)->toBeDecimal('1800.0000')
            ->and($expense->total)->toBeDecimal('11800.0000')
            // Nothing posted: a draft has no accounting effect.
            ->and($expense->journal_entry_id)->toBeNull()
            ->and($expense->approved_at)->toBeNull();
    });

    it('states nothing recoverable when the line’s tax is blocked', function (): void {
        $expense = ($this->draft)(claimable: false);

        expect($expense->tax_total)->toBeDecimal('1800.0000')
            ->and($expense->tax_claimable_total)->toBeDecimal('0.0000')
            ->and($expense->taxCapitalised())->toBeDecimal('1800.0000')
            // The line carries the tax in its cost.
            ->and($expense->lines()->sole()->capitalisedCost())->toBeDecimal('11800.0000');
    });

    it('extracts the net where the receipt total includes tax', function (): void {
        /*
         * The common case on this side: somebody hands over a receipt for
         * 11,800 and that IS the total. The net is extracted rather than tax
         * added, so the figure on the screen equals the figure on the paper.
         */
        $expense = $this->save->handle(
            attributes: [
                'expense_date' => $this->inYear->toDateString(),
                'payment_mode' => 'company',
                'paid_through_account_id' => $this->bank->id,
                'prices_include_tax' => true,
            ],
            lines: [[
                'description' => 'Fuel',
                'quantity' => '1',
                'unit_price' => '11800.00',
                'tax_id' => $this->gst->id,
                'debit_account_id' => $this->travel->id,
            ]],
            actor: $this->claimant,
        );

        expect($expense->total)->toBeDecimal('11800.0000')
            ->and($expense->subtotal)->toBeDecimal('10000.0000')
            ->and($expense->tax_total)->toBeDecimal('1800.0000');
    });

    it('refuses a company-paid expense with no account, and a reimbursable one with no payee', function (): void {
        $base = [
            'expense_date' => $this->inYear->toDateString(),
            'payment_mode' => 'company',
        ];

        $lines = [[
            'description' => 'Something',
            'quantity' => '1',
            'unit_price' => '100.00',
            'debit_account_id' => $this->supplies->id,
        ]];

        expect(fn () => $this->save->handle($base, $lines, actor: $this->claimant))
            ->toThrow(ExpenseRefused::class, 'which account the money came out of');

        expect(fn () => $this->save->handle(
            [...$base, 'payment_mode' => 'reimbursable'],
            $lines,
            actor: $this->claimant,
        ))->toThrow(ExpenseRefused::class, 'who is being reimbursed');
    });

    it('cannot store a half-filled payment mode even by direct assignment', function (): void {
        // The constraint, independent of the action: a reimbursable expense
        // with a bank account would leave the posting rule guessing.
        $expense = ($this->draft)();

        expect(fn () => $expense->forceFill([
            'payment_mode' => 'reimbursable',
        ])->save())->toThrow(QueryException::class, 'payment_source_matches_mode');
    });

    it('is deleted outright, because nothing has posted', function (): void {
        ($this->draft)()->delete();

        expect(Expense::query()->count())->toBe(0);
    });
});

describe('mileage', function (): void {
    beforeEach(function (): void {
        $rate = new MileageRate;

        $rate->forceFill([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->organization->id,
            'name' => 'Standard',
            'unit' => 'km',
            'rate' => '25.0000',
            'effective_from' => '2026-07-01',
            'is_default' => true,
        ])->save();
    });

    it('claims a distance at the rate in force, and copies it onto the line', function (): void {
        $expense = $this->save->handle(
            attributes: [
                'expense_date' => $this->inYear->toDateString(),
                'payment_mode' => 'reimbursable',
                'reimburse_user_id' => $this->claimant->id,
            ],
            lines: [[
                'kind' => 'mileage',
                'description' => 'Karachi to Hyderabad and back',
                'distance' => '320',
                'debit_account_id' => $this->travel->id,
            ]],
            actor: $this->claimant,
        );

        $line = $expense->lines()->sole();

        // 320 km at 25 — the same multiplication as a quantity at a price,
        // which is why mileage needs no special case in the tax engine.
        expect($line->kind)->toBe('mileage')
            ->and($line->unit)->toBe('km')
            ->and($line->quantity)->toBeDecimal('320.000000')
            ->and($line->unit_price)->toBeDecimal('25.0000')
            ->and($expense->total)->toBeDecimal('8000.0000')
            // The rate it came from is recorded for the audit trail.
            ->and($line->mileage_rate_id)->not->toBeNull();
    });

    it('claims an older journey at the rate that was in force then', function (): void {
        /*
         * The whole reason the rate is dated and copied. A rate raised in
         * October must not restate a September journey — and storing the
         * figure claimed is the only way to guarantee that, whatever anybody
         * later does to the rate table.
         */
        $raised = new MileageRate;

        $raised->forceFill([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->organization->id,
            'name' => 'Raised',
            'unit' => 'km',
            'rate' => '30.0000',
            'effective_from' => '2026-10-01',
        ])->save();

        $september = $this->save->handle(
            attributes: [
                'expense_date' => '2026-09-15',
                'payment_mode' => 'reimbursable',
                'reimburse_user_id' => $this->claimant->id,
            ],
            lines: [[
                'kind' => 'mileage',
                'description' => 'September journey',
                'distance' => '100',
                'debit_account_id' => $this->travel->id,
            ]],
            actor: $this->claimant,
        );

        $october = $this->save->handle(
            attributes: [
                'expense_date' => '2026-10-15',
                'payment_mode' => 'reimbursable',
                'reimburse_user_id' => $this->claimant->id,
            ],
            lines: [[
                'kind' => 'mileage',
                'description' => 'October journey',
                'distance' => '100',
                'debit_account_id' => $this->travel->id,
            ]],
            actor: $this->claimant,
        );

        expect($september->total)->toBeDecimal('2500.0000')
            ->and($october->total)->toBeDecimal('3000.0000');
    });

    it('refuses a mileage claim in a unit with no rate', function (): void {
        expect(fn () => $this->save->handle(
            attributes: [
                'expense_date' => $this->inYear->toDateString(),
                'payment_mode' => 'reimbursable',
                'reimburse_user_id' => $this->claimant->id,
            ],
            lines: [[
                'kind' => 'mileage',
                'description' => 'A journey in miles',
                'distance' => '100',
                'unit' => 'mi',
                'debit_account_id' => $this->travel->id,
            ]],
            actor: $this->claimant,
        ))->toThrow(ExpenseRefused::class, 'No mileage rate is set for mi');
    });

    it('resolves one rate per unit even when an old one was left open', function (): void {
        /*
         * The same trap the tax components had: a superseding rate written
         * without closing the old one. Two open rates must not mean two
         * answers to "the rate on this date" — the later one wins.
         */
        $raised = new MileageRate;

        $raised->forceFill([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->organization->id,
            'name' => 'Raised, old one left open',
            'unit' => 'km',
            'rate' => '30.0000',
            'effective_from' => '2026-08-01',
        ])->save();

        expect(MileageRate::inForce(Carbon::parse('2026-09-15'), 'km')?->rate)
            ->toBeDecimal('30.0000');
    });
});

describe('the approval workflow', function (): void {
    it('goes draft → submitted → approved, and only posts at the end', function (): void {
        $expense = ($this->draft)();

        expect($expense->status)->toBe(ExpenseStatus::Draft);

        $expense = $this->submit->handle($expense, $this->claimant);

        expect($expense->status)->toBe(ExpenseStatus::Submitted)
            ->and($expense->submitted_by)->toBe($this->claimant->id)
            // Submitting claims the money; it does not recognise it.
            ->and($expense->journal_entry_id)->toBeNull();

        $expense = $this->approve->handle($expense, $this->approver);

        expect($expense->status)->toBe(ExpenseStatus::Approved)
            ->and($expense->approved_by)->toBe($this->approver->id)
            ->and($expense->journal_entry_id)->not->toBeNull();
    });

    it('refuses to let the claimant approve their own expense', function (): void {
        /*
         * The rule that makes the approval step mean anything. Without it the
         * workflow is two clicks by the same person, which is a formality
         * that makes the books look reviewed when they are not.
         */
        $expense = $this->submit->handle(($this->draft)(), $this->claimant);

        expect(fn () => $this->approve->handle($expense, $this->claimant))
            ->toThrow(ExpenseRefused::class, 'somebody else has to approve it');

        expect($expense->refresh()->status)->toBe(ExpenseStatus::Submitted);
    });

    it('permits self-approval only when explicitly allowed', function (): void {
        // The one-person business, where there is nobody else to ask.
        $expense = $this->submit->handle(($this->draft)(), $this->claimant);

        $expense = $this->approve->handle(
            $expense,
            $this->claimant,
            allowSelfApproval: true,
        );

        expect($expense->status)->toBe(ExpenseStatus::Approved);
    });

    it('refuses to approve something that was never submitted', function (): void {
        expect(fn () => $this->approve->handle(($this->draft)(), $this->approver))
            ->toThrow(ExpenseRefused::class, 'has to be submitted first');
    });

    it('sends an expense back with a reason, and editing returns it to draft', function (): void {
        $expense = $this->submit->handle(($this->draft)(), $this->claimant);

        $expense = $this->reject->handle($expense, 'No receipt attached', $this->approver);

        expect($expense->status)->toBe(ExpenseStatus::Rejected)
            ->and($expense->rejection_reason)->toBe('No receipt attached')
            // Nothing posted, so nothing to reverse.
            ->and($expense->journal_entry_id)->toBeNull();

        // Editing it clears the rejection: otherwise the reviewer's note
        // would be about a version that no longer exists.
        $expense = $this->save->handle(
            attributes: [
                'expense_date' => $this->inYear->toDateString(),
                'payment_mode' => 'company',
                'paid_through_account_id' => $this->bank->id,
            ],
            lines: [[
                'description' => 'Airport transfers, receipt now attached',
                'quantity' => '1',
                'unit_price' => '10000.00',
                'tax_id' => $this->gst->id,
                'debit_account_id' => $this->travel->id,
            ]],
            expense: $expense,
            actor: $this->claimant,
        );

        expect($expense->status)->toBe(ExpenseStatus::Draft)
            ->and($expense->rejection_reason)->toBeNull();
    });

    it('refuses a rejection with no reason', function (): void {
        $expense = $this->submit->handle(($this->draft)(), $this->claimant);

        expect(fn () => $this->reject->handle($expense, '   ', $this->approver))
            ->toThrow(InvalidArgumentException::class, 'Say why');
    });

    it('refuses to submit an expense worth nothing', function (): void {
        $expense = $this->save->handle(
            attributes: [
                'expense_date' => $this->inYear->toDateString(),
                'payment_mode' => 'company',
                'paid_through_account_id' => $this->bank->id,
            ],
            lines: [[
                'description' => 'Nothing at all',
                'quantity' => '1',
                'unit_price' => '0',
                'debit_account_id' => $this->supplies->id,
            ]],
            actor: $this->claimant,
        );

        expect(fn () => $this->submit->handle($expense, $this->claimant))
            ->toThrow(ExpenseRefused::class, 'totals zero');
    });
});

describe('receipts', function (): void {
    it('requires one before submission when the organisation says so', function (): void {
        $expense = ($this->draft)();

        expect(fn () => $this->submit->handle($expense, $this->claimant, requireReceipt: true))
            ->toThrow(ExpenseRefused::class, 'no receipt attached');

        $this->attach->handle(
            $expense,
            UploadedFile::fake()->create('receipt.jpg', 20),
            $this->claimant,
        );

        // Phase 5's exit criterion: a receipt-attached expense routes through
        // approval and posts.
        $expense = $this->submit->handle($expense->refresh(), $this->claimant, requireReceipt: true);

        expect($expense->status)->toBe(ExpenseStatus::Submitted)
            ->and($expense->hasReceipt())->toBeTrue();

        $expense = $this->approve->handle($expense, $this->approver);

        expect($expense->status)->toBe(ExpenseStatus::Approved)
            ->and($expense->journal_entry_id)->not->toBeNull();
    });

    it('stores the file privately, under a per-organisation prefix', function (): void {
        $expense = ($this->draft)();

        $attachment = $this->attach->handle(
            $expense,
            UploadedFile::fake()->create('taxi.png', 20),
            $this->claimant,
        );

        Storage::disk('s3')->assertExists($attachment->path);

        expect($attachment->path)->toStartWith("organizations/{$this->organization->id}/")
            ->and($attachment->mime_type)->toBe('image/png')
            ->and($attachment->original_name)->toBe('taxi.png')
            // The checksum is what makes a duplicate answerable.
            ->and($attachment->checksum)->toHaveLength(64);
    });

    it('refuses the same file twice on one expense', function (): void {
        /*
         * A duplicate receipt is usually a duplicate claim, and the person
         * uploading it rarely knows.
         */
        $expense = ($this->draft)();
        $file = UploadedFile::fake()->create('receipt.jpg', 20);

        $this->attach->handle($expense, $file, $this->claimant);

        expect(fn () => $this->attach->handle(
            $expense,
            UploadedFile::fake()->create('receipt.jpg', 20),
            $this->claimant,
        ))->toThrow(AttachmentRefused::class, 'already attached');
    });

    it('refuses a file that is not a receipt', function (): void {
        $expense = ($this->draft)();

        // An allowlist, not a blocklist: anything but a photograph, a scan
        // or a PDF on a financial record is a mistake or an attempt.
        expect(fn () => $this->attach->handle(
            $expense,
            UploadedFile::fake()->create('macros.xlsm', 20, 'application/vnd.ms-excel.sheet.macroEnabled.12'),
            $this->claimant,
        ))->toThrow(AttachmentRefused::class, 'cannot be attached');
    });

    it('cannot be edited once stored', function (): void {
        // A receipt whose bytes can be swapped under the row is not
        // evidence: the checksum, size and name would describe something
        // that is no longer there.
        $expense = ($this->draft)();

        $attachment = $this->attach->handle(
            $expense,
            UploadedFile::fake()->create('receipt.jpg', 20),
            $this->claimant,
        );

        expect(fn () => $attachment->forceFill(['original_name' => 'other.jpg'])->save())
            ->toThrow(RuntimeException::class, 'cannot be changed');
    });
});

describe('approving an expense', function (): void {
    it('posts §4.8 exactly for a company-paid expense', function (): void {
        $expense = $this->approve->handle(
            $this->submit->handle(($this->draft)(), $this->claimant),
            $this->approver,
        );

        $lines = entryLines($expense->journalEntry()->sole());

        expect($lines['6500']['debit'])->toBeDecimal('10000.0000')
            ->and($lines['1400']['debit'])->toBeDecimal('1800.0000')
            ->and($lines['1020']['credit'])->toBeDecimal('11800.0000');

        expect($this->travel->balance())->toBeDecimal('10000.0000')
            ->and($this->gstInput->balance())->toBeDecimal('1800.0000')
            ->and(BigDecimal::of($this->bank->balance())->abs())->toBeDecimal('11800.0000');
    });

    it('credits the person for a reimbursable expense, not the bank', function (): void {
        $expense = $this->approve->handle(
            $this->submit->handle(($this->draft)(mode: 'reimbursable'), $this->claimant),
            $this->approver,
        );

        $lines = entryLines($expense->journalEntry()->sole());

        expect($lines['6500']['debit'])->toBeDecimal('10000.0000')
            ->and($lines['2150']['credit'])->toBeDecimal('11800.0000')
            // Nothing left the bank: nobody has been paid back yet.
            ->and($lines)->not->toHaveKey('1020');

        expect(BigDecimal::of($this->reimbursements->balance())->abs())
            ->toBeDecimal('11800.0000')
            ->and($this->bank->balance())->toBeDecimal('0.0000');
    });

    it('capitalises blocked input tax into the cost', function (): void {
        $expense = $this->approve->handle(
            $this->submit->handle(($this->draft)(claimable: false, accountId: $this->supplies->id), $this->claimant),
            $this->approver,
        );

        $lines = entryLines($expense->journalEntry()->sole());

        expect($lines['6300']['debit'])->toBeDecimal('11800.0000')
            ->and($lines['1020']['credit'])->toBeDecimal('11800.0000')
            ->and($lines)->not->toHaveKey('1400');

        // Phase 5's second exit criterion, asserted against the ledger.
        expect($this->gstInput->balance())->toBeDecimal('0.0000');
    });

    it('debits an asset where what was bought is still worth something', function (): void {
        $expense = $this->approve->handle(
            $this->submit->handle(($this->draft)(accountId: $this->inventory->id), $this->claimant),
            $this->approver,
        );

        expect(entryLines($expense->journalEntry()->sole())['1300']['debit'])
            ->toBeDecimal('10000.0000');
    });

    it('refuses to approve twice', function (): void {
        $expense = $this->approve->handle(
            $this->submit->handle(($this->draft)(), $this->claimant),
            $this->approver,
        );

        expect(fn () => $this->approve->handle($expense, $this->approver))
            ->toThrow(ExpenseRefused::class, 'already been approved');
    });

    it('refuses to edit an approved expense', function (): void {
        $expense = $this->approve->handle(
            $this->submit->handle(($this->draft)(), $this->claimant),
            $this->approver,
        );

        expect(fn () => $this->save->handle(
            attributes: [
                'expense_date' => $this->inYear->toDateString(),
                'payment_mode' => 'company',
                'paid_through_account_id' => $this->bank->id,
            ],
            lines: [[
                'description' => 'Changed my mind',
                'quantity' => '1',
                'unit_price' => '1.00',
                'debit_account_id' => $this->supplies->id,
            ]],
            expense: $expense,
            actor: $this->claimant,
        ))->toThrow(ExpenseRefused::class, 'can no longer be edited');
    });

    it('refuses to delete an approved expense', function (): void {
        $expense = $this->approve->handle(
            $this->submit->handle(($this->draft)(), $this->claimant),
            $this->approver,
        );

        expect(fn () => $expense->delete())
            ->toThrow(RuntimeException::class, 'Void it instead');
    });
});

describe('voiding', function (): void {
    it('reverses the entry and keeps both on the record', function (): void {
        $expense = $this->approve->handle(
            $this->submit->handle(($this->draft)(), $this->claimant),
            $this->approver,
        );

        $original = $expense->journal_entry_id;

        $expense = $this->void->handle($expense, $this->approver, 'Personal, not business');

        expect($expense->status)->toBe(ExpenseStatus::Void)
            ->and($expense->journal_entry_id)->toBe($original)
            ->and($expense->void_journal_entry_id)->not->toBeNull()
            // The approval survives: somebody did approve this before it was
            // withdrawn, and erasing that would be a different claim.
            ->and($expense->approved_at)->not->toBeNull();

        expect($this->travel->balance())->toBeDecimal('0.0000')
            ->and($this->gstInput->balance())->toBeDecimal('0.0000')
            ->and($this->bank->balance())->toBeDecimal('0.0000');
    });

    it('refuses to void an expense already rebilled to a customer', function (): void {
        $customer = Contact::query()->create([
            'kind' => ContactKind::Customer,
            'display_name' => 'Karachi Textiles',
            'payment_terms_days' => 30,
        ]);

        $expense = $this->approve->handle(
            $this->submit->handle(
                ($this->draft)(billable: true, billTo: $customer->id),
                $this->claimant,
            ),
            $this->approver,
        );

        $this->rebill->handle([$expense->refresh()], $this->approver, $this->inYear);

        // The customer would be invoiced for a cost the books say never
        // happened, and the invoice is the harder of the two to unwind.
        expect(fn () => $this->void->handle($expense->refresh(), $this->approver))
            ->toThrow(ExpenseRefused::class, 'already been rebilled');
    });
});

describe('billable expenses', function (): void {
    beforeEach(function (): void {
        $this->customer = Contact::query()->create([
            'kind' => ContactKind::Customer,
            'display_name' => 'Karachi Textiles',
            'payment_terms_days' => 30,
        ]);
    });

    it('appears on the to-bill list only once approved', function (): void {
        $expense = ($this->draft)(billable: true, billTo: $this->customer->id);

        // Billable, but not yet a cost the books have recognised.
        expect(Expense::query()->awaitingRebill()->count())->toBe(0);

        $this->approve->handle(
            $this->submit->handle($expense, $this->claimant),
            $this->approver,
        );

        expect(Expense::query()->awaitingRebill()->count())->toBe(1);
    });

    it('creates a DRAFT invoice at cost, and stamps the expense', function (): void {
        $expense = $this->approve->handle(
            $this->submit->handle(
                ($this->draft)(billable: true, billTo: $this->customer->id),
                $this->claimant,
            ),
            $this->approver,
        );

        $invoice = $this->rebill->handle([$expense->refresh()], $this->approver, $this->inYear);

        /*
         * A draft, at cost. What to charge for a rebilled cost is a
         * commercial decision — at cost, with a markup, or at what was
         * agreed — and issuing it automatically would post revenue at a
         * figure nobody chose.
         */
        expect($invoice->status->isEditable())->toBeTrue()
            ->and($invoice->journal_entry_id)->toBeNull()
            ->and($invoice->contact_id)->toBe($this->customer->id)
            // The claimable case: cost is the net, since the tax comes back.
            ->and($invoice->subtotal)->toBeDecimal('10000.0000');

        $expense->refresh();

        expect($expense->billed_document_id)->toBe($invoice->id)
            ->and($expense->isAwaitingRebill())->toBeFalse();

        // The expense number travels into the description, so the customer's
        // query about a charge has an answer.
        expect($invoice->lines()->sole()->description)->toContain('EXP-000001');
    });

    it('rebills the blocked tax too, because it is part of the cost', function (): void {
        /*
         * Rebilling the net alone would silently absorb the unrecoverable
         * tax as a loss — which is the exact figure the expense screens work
         * to keep visible.
         */
        $expense = $this->approve->handle(
            $this->submit->handle(
                ($this->draft)(claimable: false, billable: true, billTo: $this->customer->id),
                $this->claimant,
            ),
            $this->approver,
        );

        $invoice = $this->rebill->handle([$expense->refresh()], $this->approver, $this->inYear);

        expect($invoice->subtotal)->toBeDecimal('11800.0000');
    });

    it('refuses to rebill the same expense twice', function (): void {
        $expense = $this->approve->handle(
            $this->submit->handle(
                ($this->draft)(billable: true, billTo: $this->customer->id),
                $this->claimant,
            ),
            $this->approver,
        );

        $this->rebill->handle([$expense->refresh()], $this->approver, $this->inYear);

        expect(fn () => $this->rebill->handle([$expense->refresh()], $this->approver, $this->inYear))
            ->toThrow(ExpenseRefused::class, 'already been rebilled');
    });

    it('refuses to mix two customers onto one invoice', function (): void {
        $other = Contact::query()->create([
            'kind' => ContactKind::Customer,
            'display_name' => 'Lahore Mills',
        ]);

        $first = $this->approve->handle(
            $this->submit->handle(
                ($this->draft)(billable: true, billTo: $this->customer->id),
                $this->claimant,
            ),
            $this->approver,
        );

        $second = $this->approve->handle(
            $this->submit->handle(
                ($this->draft)(billable: true, billTo: $other->id),
                $this->claimant,
            ),
            $this->approver,
        );

        // There is no reading of the request where billing each customer for
        // the other's costs is what was meant.
        expect(fn () => $this->rebill->handle(
            [$first->refresh(), $second->refresh()],
            $this->approver,
            $this->inYear,
        ))->toThrow(InvalidArgumentException::class, 'different customers');
    });

    it('puts several expenses for one customer onto one invoice', function (): void {
        $first = $this->approve->handle(
            $this->submit->handle(
                ($this->draft)(billable: true, billTo: $this->customer->id),
                $this->claimant,
            ),
            $this->approver,
        );

        $second = $this->approve->handle(
            $this->submit->handle(
                ($this->draft)(price: '2000.00', billable: true, billTo: $this->customer->id),
                $this->claimant,
            ),
            $this->approver,
        );

        $invoice = $this->rebill->handle(
            [$first->refresh(), $second->refresh()],
            $this->approver,
            $this->inYear,
        );

        // One invoice, two lines. A month of costs on one job is billed
        // together, which is how the work actually arrives.
        expect($invoice->lines()->count())->toBe(2)
            ->and($invoice->subtotal)->toBeDecimal('12000.0000');
    });
});

describe('the whole chain', function (): void {
    it('leaves the ledger verifiable after receipt → submit → approve', function (): void {
        $expense = ($this->draft)();

        $this->attach->handle(
            $expense,
            UploadedFile::fake()->create('receipt.jpg', 20),
            $this->claimant,
        );

        $this->approve->handle(
            $this->submit->handle($expense->refresh(), $this->claimant, requireReceipt: true),
            $this->approver,
        );

        // A second, reimbursable and with blocked tax, so both halves of
        // §4.8 and both halves of §4.6 are in the same set of books.
        $this->approve->handle(
            $this->submit->handle(
                ($this->draft)(claimable: false, mode: 'reimbursable', accountId: $this->supplies->id),
                $this->claimant,
            ),
            $this->approver,
        );

        $this->artisan('my-books:verify-ledger', [
            '--organization' => $this->organization->slug,
        ])->assertExitCode(0);

        expect($this->travel->balance())->toBeDecimal('10000.0000')
            ->and($this->supplies->balance())->toBeDecimal('11800.0000')
            // Only the claimable one reached the receivable.
            ->and($this->gstInput->balance())->toBeDecimal('1800.0000')
            ->and(BigDecimal::of($this->bank->balance())->abs())->toBeDecimal('11800.0000')
            ->and(BigDecimal::of($this->reimbursements->balance())->abs())->toBeDecimal('11800.0000');
    });
});
