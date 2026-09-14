<?php

declare(strict_types=1);

namespace App\Domain\Banking\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money moved between two of our own accounts.
 *
 * A transfer is the one banking document that posts, and §4.9's entry is as
 * small as an entry gets: debit where it landed, credit where it left. It is
 * never income or expense on either side — a business is no richer for having
 * moved its own money, and booking a transfer as revenue is one of the
 * classic ways a set of books overstates a year.
 *
 * Where the two accounts hold different currencies, both amounts are recorded
 * as they actually happened, and any base-currency difference between them is
 * an FX gain or loss.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property Carbon $transfer_date
 * @property string $from_account_id
 * @property string $to_account_id
 * @property string $currency
 * @property string $amount
 * @property string $exchange_rate
 * @property string $destination_currency
 * @property string $amount_received
 * @property string $destination_exchange_rate
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $journal_entry_id
 * @property string|null $void_journal_entry_id
 * @property Carbon|null $voided_at
 */
final class BankTransfer extends Model
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
            'transfer_date' => 'date',
            'voided_at' => 'datetime',
            'amount' => 'string',
            'amount_received' => 'string',
            'exchange_rate' => 'string',
            'destination_exchange_rate' => 'string',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isCrossCurrency(): bool
    {
        return $this->currency !== $this->destination_currency;
    }
}
