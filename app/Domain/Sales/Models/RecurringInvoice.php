<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use App\Domain\Sales\Enums\RecurrenceFrequency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A standing instruction that produces invoices.
 *
 * Not an invoice: no number, no total, no balance, and it never posts. Every
 * figure is computed on the document it generates, at that document's own
 * date — so a rate change between today and the next run applies to the next
 * invoice and not to this template.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $contact_id
 * @property RecurrenceFrequency $frequency
 * @property int $interval
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property int|null $max_occurrences
 * @property Carbon|null $next_run_on
 * @property Carbon|null $last_run_on
 * @property int $occurrences_generated
 * @property string $status
 * @property bool $auto_issue
 * @property int $payment_terms_days
 * @property string $currency
 * @property string $exchange_rate
 * @property bool $prices_include_tax
 * @property string|null $discount_type
 * @property string|null $discount_value
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $terms
 */
final class RecurringInvoice extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'name',
        'frequency',
        'interval',
        'starts_on',
        'ends_on',
        'max_occurrences',
        'auto_issue',
        'payment_terms_days',
        'prices_include_tax',
        'discount_type',
        'discount_value',
        'reference',
        'notes',
        'terms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => RecurrenceFrequency::class,
            'interval' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'next_run_on' => 'date',
            'last_run_on' => 'date',
            'max_occurrences' => 'integer',
            'occurrences_generated' => 'integer',
            'auto_issue' => 'boolean',
            'payment_terms_days' => 'integer',
            'prices_include_tax' => 'boolean',
            // Decimal strings, like everywhere else.
            'exchange_rate' => 'string',
            'discount_value' => 'string',
        ];
    }

    /**
     * @return HasMany<RecurringInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RecurringInvoiceLine::class)->orderBy('line_no');
    }

    /**
     * @return HasMany<RecurringInvoiceRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(RecurringInvoiceRun::class)->latest('scheduled_for');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isEnded(): bool
    {
        return $this->status === 'ended';
    }

    /**
     * Whether this occurrence is the last one the agreement allows.
     *
     * Both limits, because they are different agreements: "twelve invoices"
     * and "until next March" can currently coincide and still mean different
     * things when the schedule is edited.
     */
    public function hasReachedItsEnd(Carbon $nextDate): bool
    {
        if ($this->ends_on !== null && $nextDate->greaterThan($this->ends_on)) {
            return true;
        }

        return $this->max_occurrences !== null
            && $this->occurrences_generated >= $this->max_occurrences;
    }

    /**
     * How the schedule reads.
     */
    public function describeSchedule(): string
    {
        return $this->frequency->describe($this->interval);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query, Carbon $on): Builder
    {
        return $query->where('status', 'active')
            ->whereNotNull('next_run_on')
            ->whereDate('next_run_on', '<=', $on->toDateString());
    }
}
