<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Sales\Enums\SalesDocumentStatus;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Exceptions\SalesDocumentRefused;
use App\Domain\Sales\Models\SalesDocument;
use App\Domain\Sales\Models\SalesDocumentLine;
use App\Domain\Sales\Services\SalesDocumentCalculator;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Estimate → sales order → invoice.
 *
 * A copy, not a transformation. The source document keeps its number, its
 * lines and its history; the new one is a fresh draft that points back at
 * where it came from. That is why the workflow can be walked in both
 * directions afterwards — "what did we quote for this invoice" is a question
 * people genuinely ask.
 *
 * The copy is a DRAFT. Converting a quote into an invoice does not issue it:
 * the numbers are usually right but the dates are not, and posting revenue
 * as a side effect of a conversion is the kind of surprise that costs trust.
 *
 * Lines are copied with their computed figures intact and then recalculated,
 * because the new document's issue date may resolve different tax rates than
 * the quote's did. A quote from June converted in July should charge July's
 * rate — the quote was an offer, not a fixed price.
 *
 * @see ACCOUNTING_RULES.md §6
 */
final readonly class ConvertSalesDocument
{
    public function __construct(
        private DocumentNumberGenerator $numbers,
        private SalesDocumentCalculator $calculator,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        SalesDocument $source,
        SalesDocumentType $to,
        ?User $actor = null,
        ?Carbon $issueDate = null,
    ): SalesDocument {
        $this->assertConvertible($source, $to);

        $issueDate ??= Carbon::now();

        // Loaded rather than reached through the relation, so the type is
        // known and a missing contact fails here rather than mid-copy.
        $contact = Contact::query()->findOrFail($source->contact_id);

        return DB::transaction(function () use ($source, $to, $actor, $issueDate, $contact): SalesDocument {
            $document = new SalesDocument;

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
                'expires_on' => $to === SalesDocumentType::Estimate
                    ? $issueDate->copy()->addDays(30)->toDateString()
                    : null,
                'reference' => $source->reference,
                // The chain, walkable in both directions.
                'converted_from_id' => $source->id,
                'status' => SalesDocumentStatus::Draft,
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
                /** @var SalesDocumentLine $line */
                $copy = new SalesDocumentLine;

                $copy->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $document->organization_id,
                    'sales_document_id' => $document->id,
                    'line_no' => $line->line_no,
                    'item_id' => $line->item_id,
                    /*
                     * The line's own copies, not the item's current values. A
                     * quote for a price that has since risen converts at the
                     * price that was quoted — changing it silently would be
                     * the worst possible behaviour here.
                     */
                    'description' => $line->description,
                    'unit' => $line->unit,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_type' => $line->discount_type,
                    'discount_value' => $line->discount_value,
                    'tax_id' => $line->tax_id,
                    'revenue_account_id' => $line->revenue_account_id,
                    'project_id' => $line->project_id,
                    'warehouse_id' => $line->warehouse_id,
                ])->save();
            }

            /*
             * Recalculated against the NEW issue date, so a June quote
             * converted in July charges July's rate. The quote was an offer,
             * not a fixed tax treatment.
             */
            $this->calculator->recalculate($document);

            // The source is now spoken for. An accepted estimate and a closed
            // order both mean "this has moved on", which is what stops it
            // being converted twice by accident.
            if ($source->type === SalesDocumentType::Estimate) {
                $source->forceFill(['status' => SalesDocumentStatus::Accepted])->save();
            } elseif ($source->type === SalesDocumentType::SalesOrder && $to === SalesDocumentType::Invoice) {
                $source->forceFill(['status' => SalesDocumentStatus::Closed])->save();
            }

            $this->audit->record(
                action: 'sales.document_converted',
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
     * Forwards only. An invoice does not become a quote, and a credit note
     * converts to nothing — going backwards would mean un-posting revenue,
     * which is what a void is for.
     */
    private function assertConvertible(SalesDocument $source, SalesDocumentType $to): void
    {
        $permitted = match ($source->type) {
            SalesDocumentType::Estimate => [SalesDocumentType::SalesOrder, SalesDocumentType::Invoice],
            SalesDocumentType::SalesOrder => [SalesDocumentType::Invoice],
            SalesDocumentType::Invoice, SalesDocumentType::CreditNote => [],
        };

        if (! in_array($to, $permitted, strict: true)) {
            throw new \InvalidArgumentException(sprintf(
                'A %s cannot be converted to a %s.%s',
                $source->type->label(),
                $to->label(),
                $source->type === SalesDocumentType::Invoice
                    ? ' To undo an invoice, void it or credit it.'
                    : '',
            ));
        }

        if ($source->status->isVoid()) {
            throw SalesDocumentRefused::cannotCredit($source->number, $source->status->label());
        }

        if ($source->lines()->count() === 0) {
            throw SalesDocumentRefused::hasNoLines($source->type->label(), $source->number);
        }
    }

    public static function permission(): Permission
    {
        return Permission::SalesCreate;
    }
}
