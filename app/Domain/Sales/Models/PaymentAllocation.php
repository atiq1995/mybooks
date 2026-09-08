<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a payment was applied to one document.
 *
 * Realised FX belongs here rather than on the payment: a payment settling two
 * invoices issued at two different rates produces two different gains, and a
 * single figure on the payment could not say which was which.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $payment_id
 * @property string $sales_document_id
 * @property string $amount
 * @property string $amount_base
 * @property string $fx_gain_loss_base
 */
final class PaymentAllocation extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = ['sales_document_id', 'amount', 'amount_base', 'fx_gain_loss_base'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'string',
            'amount_base' => 'string',
            'fx_gain_loss_base' => 'string',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<SalesDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }
}
