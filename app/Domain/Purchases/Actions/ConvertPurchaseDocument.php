<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Actions;

use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Purchases\Enums\PurchaseDocumentStatus;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use App\Domain\Purchases\Exceptions\PurchaseDocumentRefused;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Purchases\Models\PurchaseDocumentLine;
use App\Domain\Purchases\Services\PurchaseDocumentCalculator;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Purchase order → bill.
 *
 * A copy, not a transformation. The order keeps its number, its lines and its
 * history; the bill is a fresh draft pointing back at where it came from, so
 * "what did we order against this bill" stays answerable.
 *
 * The copy is a DRAFT, and here that is not merely prudent — it is the point.
 * A purchase order says what we asked for; a bill says what the vendor
 * charged, and the difference between the two is the single most useful thing
 * anybody checks in accounts payable. Approving the bill as a side effect of
 * conversion would post whatever the order said and skip that check entirely.
 *
 * The vendor's own reference is deliberately NOT copied: the order has our
 * number, the bill will have theirs, and inventing one would defeat the
 * duplicate-bill guard that depends on it.
 *
 * @see ACCOUNTING_RULES.md §6
 */
final readonly class ConvertPurchaseDocument
{
    public function __construct(
        private DocumentNumberGenerator $numbers,
        private PurchaseDocumentCalculator $calculator,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        PurchaseDocument $source,
        PurchaseDocumentType $to,
        ?User $actor = null,
        ?Carbon $issueDate = null,
    ): PurchaseDocument {
        $this->assertConvertible($source, $to);

        $issueDate ??= Carbon::now();

        $contact = Contact::query()->findOrFail($source->contact_id);

        return DB::transaction(function () use (
            $source,
            $to,
            $actor,
            $issueDate,
            $contact,
        ): PurchaseDocument {
            $document = new PurchaseDocument;

            $document->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $source->organization_id,
                'type' => $to,
                'number' => $this->numbers->next($to->sequenceKey(), $issueDate),
                'contact_id' => $source->contact_id,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $to->hasDueDate()
                    ? $contact->dueDateFrom($issueDate)->toDateString()
                    : null,
                'expires_on' => null,
                // Ours travels; theirs does not. See the class docblock.
                'reference' => $source->reference,
                'vendor_reference' => null,
                'converted_from_id' => $source->id,
                'status' => PurchaseDocumentStatus::Draft,
                'currency' => $source->currency,
                'exchange_rate' => $source->exchange_rate,
                'prices_include_tax' => $source->prices_include_tax,
                'discount_type' => $source->discount_type,
                'discount_value' => $source->discount_value,
                'notes' => $source->notes,
                'terms' => $source->terms,
                'billing_address' => $source->billing_address,
                'shipping_address' => $source->shipping_address,
                'created_by' => $actor?->getKey(),
            ])->save();

            foreach ($source->lines()->get() as $line) {
                /** @var PurchaseDocumentLine $line */
                $copy = new PurchaseDocumentLine;

                $copy->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $document->organization_id,
                    'purchase_document_id' => $document->id,
                    'line_no' => $line->line_no,
                    'item_id' => $line->item_id,
                    /*
                     * The line's own copies, not the item's current values.
                     * The order was placed at a price, and the bill starts
                     * from that price so that a change in it is visible
                     * rather than absorbed.
                     */
                    'description' => $line->description,
                    'unit' => $line->unit,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_type' => $line->discount_type,
                    'discount_value' => $line->discount_value,
                    'tax_id' => $line->tax_id,
                    'debit_account_id' => $line->debit_account_id,
                    'tax_is_claimable' => $line->tax_is_claimable,
                    'project_id' => $line->project_id,
                    'warehouse_id' => $line->warehouse_id,
                ])->save();
            }

            /*
             * Recalculated against the NEW issue date, so an order placed in
             * June and billed in July is taxed at July's rate.
             */
            $this->calculator->recalculate($document);

            // The order is now spoken for, which is what stops it being
            // billed twice by accident.
            if ($source->type === PurchaseDocumentType::PurchaseOrder) {
                $source->forceFill(['status' => PurchaseDocumentStatus::Closed])->save();
            }

            $this->audit->record(
                action: 'purchases.document_converted',
                subject: $document,
                description: sprintf(
                    'Converted %s %s to %s %s',
                    $source->type->label(),
                    $source->number,
                    $to->label(),
                    $document->number,
                ),
                new: [
                    'from' => $source->number,
                    'from_type' => $source->type->value,
                    'to' => $document->number,
                    'to_type' => $to->value,
                    'total' => $document->total,
                ],
                actor: $actor,
            );

            return $document->refresh();
        });
    }

    /**
     * Which conversions make sense.
     *
     * Forwards only, and there is exactly one: an order becomes a bill. A
     * bill does not become an order, and a vendor credit converts to nothing
     * — going backwards would mean un-posting a liability, which is what a
     * void is for.
     */
    private function assertConvertible(PurchaseDocument $source, PurchaseDocumentType $to): void
    {
        $permitted = match ($source->type) {
            PurchaseDocumentType::PurchaseOrder => [PurchaseDocumentType::Bill],
            PurchaseDocumentType::Bill, PurchaseDocumentType::VendorCredit => [],
        };

        if (! in_array($to, $permitted, strict: true)) {
            throw new \InvalidArgumentException(sprintf(
                'A %s cannot be converted to a %s.%s',
                $source->type->label(),
                $to->label(),
                $source->type === PurchaseDocumentType::Bill
                    ? ' To undo a bill, void it or raise a vendor credit.'
                    : '',
            ));
        }

        if ($source->status->isVoid()) {
            throw PurchaseDocumentRefused::cannotCredit(
                $source->number,
                $source->status->label(),
            );
        }

        if ($source->lines()->count() === 0) {
            throw PurchaseDocumentRefused::hasNoLines(
                $source->type->label(),
                $source->number,
            );
        }
    }
}
