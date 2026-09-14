<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One occurrence of a recurring invoice, generated or attempted.
 *
 * The idempotency guard, and the history. A unique index on the template and
 * the scheduled date is what makes double-billing impossible: a scheduler
 * firing twice, a worker retried after a timeout, and somebody pressing
 * "generate now" mid-run are three different races, and only the database can
 * arbitrate between processes.
 *
 * A failed occurrence is RECORDED rather than retried silently. The customer
 * may have been archived or the period closed, and a scheduler that kept
 * trying every night would bury the reason under a thousand identical
 * failures.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $recurring_invoice_id
 * @property Carbon $scheduled_for
 * @property Carbon $ran_at
 * @property string|null $sales_document_id
 * @property string $outcome
 * @property string|null $failure_reason
 */
final class RecurringInvoiceRun extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'ran_at' => 'datetime',
        ];
    }

    public function succeeded(): bool
    {
        return $this->outcome === 'generated';
    }

    /**
     * @return BelongsTo<RecurringInvoice, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class, 'recurring_invoice_id');
    }

    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }
}
