<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Models;

use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Sales\Models\Payment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a vendor payment was applied to one bill.
 *
 * Realised FX belongs here rather than on the payment: one payment settling
 * two bills booked at two different rates produces two different differences,
 * and a single figure on the payment could not say which was which.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $payment_id
 * @property string $purchase_document_id
 * @property string $amount
 * @property string $amount_base
 * @property string $fx_gain_loss_base
 */
final class PurchasePaymentAllocation extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = ['purchase_document_id', 'amount', 'amount_base', 'fx_gain_loss_base'];

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
     * The payment model is shared across both directions: `payments` carries
     * a `direction`, and everything else about it behaves the same whichever
     * way the money moved.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<PurchaseDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocument::class, 'purchase_document_id');
    }
}
