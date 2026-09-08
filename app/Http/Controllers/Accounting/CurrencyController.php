<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Actions\RecordExchangeRate;
use App\Domain\Accounting\Actions\RevalueForeignCurrencyBalances;
use App\Domain\Accounting\Exceptions\MissingExchangeRate;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\ExchangeRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreExchangeRateRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exchange rates, and the period-end revaluation that uses them.
 *
 * The two live on one screen because they are the same job: a rate is only
 * interesting for what it does to a balance, and somebody about to revalue
 * needs to see whether the rates are actually there first.
 *
 * The revaluation is previewed before it posts — account by account, with the
 * rate used and the difference it makes. An adjustment that appears without
 * explanation is an adjustment nobody can check.
 *
 * @see ACCOUNTING_RULES.md §8
 */
final class CurrencyController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly RecordExchangeRate $recordExchangeRate,
        private readonly RevalueForeignCurrencyBalances $revalue,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize(Permission::AccountingView->value);

        $organization = $this->tenant->organization();
        $base = $organization->base_currency;

        $asOf = $this->date($request->query('as_of')) ?? Carbon::now();

        return Inertia::render('Accounting/Currencies', [
            'baseCurrency' => $base,
            'rates' => ExchangeRate::query()
                ->orderByDesc('effective_on')
                ->orderBy('from_currency')
                ->limit(200)
                ->get()
                ->map(fn (ExchangeRate $rate): array => [
                    'id' => $rate->id,
                    'from_currency' => $rate->from_currency,
                    'to_currency' => $rate->to_currency,
                    'rate' => $rate->rate,
                    'effective_on' => $rate->effective_on->toDateString(),
                    'source' => $rate->source,
                ])
                ->values()
                ->all(),

            // Which currencies this organisation actually holds, so the form
            // can suggest them rather than offering all 180.
            'foreignCurrencies' => Account::query()
                ->postable()
                ->whereNotNull('currency')
                ->where('currency', '!=', $base)
                ->distinct()
                ->orderBy('currency')
                ->pluck('currency')
                ->all(),

            'revaluation' => $this->preview($asOf, $base),

            'filters' => ['as_of' => $asOf->toDateString()],

            'can' => [
                'manage_rates' => $request->user()?->can(Permission::SettingsAccounting->value) ?? false,
                'revalue' => $request->user()?->can(Permission::AccountingPost->value) ?? false,
            ],
        ]);
    }

    public function storeRate(StoreExchangeRateRequest $request): RedirectResponse
    {
        $this->authorize(Permission::SettingsAccounting->value);

        $rate = $this->recordExchangeRate->handle(
            from: (string) $request->string('from_currency'),
            to: (string) $request->string('to_currency'),
            rate: (string) $request->string('rate'),
            effectiveOn: Carbon::parse((string) $request->string('effective_on')),
            actor: $request->user(),
        );

        return back()->with('success', sprintf(
            '1 %s = %s %s from %s.',
            $rate->from_currency,
            $rate->rate,
            $rate->to_currency,
            $rate->effective_on->toDateString(),
        ));
    }

    /**
     * Post the revaluation, and its reversal for the next period.
     */
    public function revalue(Request $request): RedirectResponse
    {
        $this->authorize(Permission::AccountingPost->value);

        $request->validate(['as_of' => ['required', 'date']]);

        $asOf = Carbon::parse($request->string('as_of')->toString());

        try {
            $result = $this->revalue->handle($asOf, $request->user());
        } catch (MissingExchangeRate|PostingRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $entry = $result['entry'];
        $reversal = $result['reversal'];

        // Both or neither: the pair is written in one transaction, so a
        // present adjustment always has its reversal.
        if ($entry === null || $reversal === null) {
            return back()->with(
                'success',
                'Nothing needed revaluing at '.$asOf->toDateString().
                ' — every foreign balance is already carried at that day\'s rate.',
            );
        }

        return back()->with('success', sprintf(
            'Revalued %d balance(s): %s, reversed by %s on the following day.',
            count($result['adjustments']),
            $entry->entry_no,
            $reversal->entry_no,
        ));
    }

    /**
     * What revaluation WOULD do, without doing it.
     *
     * A missing rate is reported rather than thrown: the page's whole purpose
     * is to let somebody see that a rate is missing and record it.
     *
     * @return array{as_of: string, adjustments: list<array<string, string>>, error: string|null}
     */
    private function preview(Carbon $asOf, string $base): array
    {
        try {
            return [
                'as_of' => $asOf->toDateString(),
                'adjustments' => $this->revalue->adjustmentsAt($asOf, $base),
                'error' => null,
            ];
        } catch (MissingExchangeRate $exception) {
            return [
                'as_of' => $asOf->toDateString(),
                'adjustments' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
