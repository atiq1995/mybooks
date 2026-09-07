<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Organizations\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One account in the chart of accounts.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property NormalBalance $normal_balance
 * @property SystemAccount|null $system_role
 * @property string|null $parent_id
 * @property string|null $currency
 * @property bool $is_active
 * @property bool $is_header
 * @property Carbon|null $archived_at
 */
final class Account extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'subtype',
        'normal_balance',
        'parent_id',
        'currency',
        'is_active',
        'is_header',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            // Deliberately NOT fillable: a system role is assigned when the
            // chart is created and never by a request.
            'system_role' => SystemAccount::class,
            'is_active' => 'boolean',
            'is_header' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * Can this account receive a posting?
     *
     * Headings cannot: a posting to a heading makes its subtotal — the sum of
     * its children — disagree with itself.
     */
    public function acceptsPostings(): bool
    {
        return $this->is_active && ! $this->is_header && $this->archived_at === null;
    }

    public function isSystemAccount(): bool
    {
        return $this->system_role !== null;
    }

    /**
     * The account's balance, as a decimal string, signed by its normal
     * balance so a positive figure always means "as expected".
     *
     * Summed from journal lines rather than a cached column: the ledger is the
     * source of truth, and a cached balance is a second truth that can drift.
     */
    public function balance(?Carbon $asOf = null): string
    {
        $query = $this->journalLines()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted');

        if ($asOf !== null) {
            $query->where('journal_entries.entry_date', '<=', $asOf->toDateString());
        }

        /** @var object{debits: string|null, credits: string|null} $totals */
        $totals = $query
            ->selectRaw('COALESCE(SUM(journal_lines.debit_base), 0) AS debits')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_base), 0) AS credits')
            ->first();

        return $this->normal_balance->signedBalance(
            (string) ($totals->debits ?? '0'),
            (string) ($totals->credits ?? '0'),
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('is_header', false)
            ->whereNull('archived_at');
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
