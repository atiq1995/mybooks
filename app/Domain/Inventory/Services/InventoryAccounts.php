<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Item;
use App\Domain\Inventory\Models\StockMovement;

/**
 * Which accounts are inventory control accounts.
 *
 * One answer, in one place, because four callers ask the question and a
 * disagreement between any two of them is a hole in I10. A bill refuses to
 * cost anything but tracked stock to one; an expense refuses outright; the
 * expense screen leaves them out of its dropdown; the stock ledger uses the
 * set to decide which bill lines actually moved goods.
 *
 * The set is **exactly what `verify-ledger` reconciles**: every account an
 * item names, plus every account a movement recorded. Not more and not less,
 * because the guard and the checker have to agree — a guard that refuses more
 * blocks bookkeeping nothing was going to complain about, and one that
 * refuses less lets through what the checker will then report for ever.
 *
 * Which is why the system inventory account is NOT in the set by default. An
 * account that no item names and no stock has moved through is not an
 * inventory control account yet; it is an asset account with a suggestive
 * name, and a business running periodic inventory may legitimately post to it.
 * What must not happen is an item being pointed at an account that already
 * carries postings with no stock behind them — and that is refused where it
 * happens, on the item, rather than by forbidding the account to everyone
 * for ever.
 */
final readonly class InventoryAccounts
{
    /**
     * @return list<string> account ids
     */
    public function all(): array
    {
        /** @var list<string> $accounts */
        $accounts = Item::query()
            ->whereNotNull('inventory_account_id')
            ->distinct()
            ->pluck('inventory_account_id')
            ->all();

        /** @var list<string> $fromMovements */
        $fromMovements = StockMovement::query()
            ->whereNotNull('inventory_account_id')
            ->distinct()
            ->pluck('inventory_account_id')
            ->all();

        foreach ($fromMovements as $accountId) {
            if (! in_array($accountId, $accounts, true)) {
                $accounts[] = $accountId;
            }
        }

        return array_values($accounts);
    }

    public function has(?string $accountId): bool
    {
        return $accountId !== null && in_array($accountId, $this->all(), true);
    }
}
