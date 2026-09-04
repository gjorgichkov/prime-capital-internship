<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Client;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes a client's account.
 *
 * Nothing about the account is stored as a running total. Cash and holdings
 * are aggregated from the movements every time they are asked for, so there is
 * no second copy of the truth that could drift away from the ledger.
 */
class LedgerService
{
    /**
     * The client's cash balance, in minor units.
     */
    public function cashBalanceMinor(Client $client): int
    {
        return (int) $this->cashBalanceQuery($client)->value('balance');
    }

    /**
     * The instruments the client currently holds, as ticker => quantity,
     * ordered by ticker.
     *
     * @return Collection<string, int>
     */
    public function holdings(Client $client): Collection
    {
        return $this->holdingsQuery($client)
            ->pluck('quantity', 'instrument')
            ->map(fn (mixed $quantity): int => (int) $quantity);
    }

    /**
     * The number of units of one instrument the client holds.
     */
    public function heldUnits(Client $client, string $instrument): int
    {
        return (int) $this->unitsQuery($client, $instrument)->value('quantity');
    }

    protected function cashBalanceQuery(Client $client): Builder
    {
        [$sum, $bindings] = $this->signedSum('amount_minor', fn (TransactionType $type): int => $type->cashDirection());

        return $this->ledger($client)->selectRaw("{$sum} AS balance", $bindings);
    }

    protected function holdingsQuery(Client $client): Builder
    {
        [$sum, $bindings] = $this->signedSum('quantity', fn (TransactionType $type): int => $type->unitDirection());

        return $this->ledger($client)
            ->whereNotNull('instrument')
            ->selectRaw("instrument, {$sum} AS quantity", $bindings)
            ->groupBy('instrument')
            // An instrument sold down to nothing leaves the portfolio instead
            // of lingering as a zero row.
            ->havingRaw("{$sum} > 0", $bindings)
            ->orderBy('instrument');
    }

    protected function unitsQuery(Client $client, string $instrument): Builder
    {
        [$sum, $bindings] = $this->signedSum('quantity', fn (TransactionType $type): int => $type->unitDirection());

        return $this->ledger($client)
            ->where('instrument', $instrument)
            ->selectRaw("{$sum} AS quantity", $bindings);
    }

    protected function ledger(Client $client): Builder
    {
        return DB::table('transactions')->where('client_id', $client->getKey());
    }

    /**
     * Builds a signed SUM over the ledger, taking each movement's sign from
     * the enum rather than repeating it in SQL. Because every case is listed,
     * a movement type added later cannot be silently left out of the total.
     *
     * The CAST is not decoration: the amount and quantity columns are
     * UNSIGNED, and MySQL would otherwise evaluate `-1 * amount_minor` in
     * unsigned arithmetic and raise an out-of-range error instead of going
     * negative.
     *
     * @param  Closure(TransactionType): int  $direction
     * @return array{string, list<int|string>}
     */
    protected function signedSum(string $column, Closure $direction): array
    {
        $branches = [];
        $bindings = [];

        foreach (TransactionType::cases() as $type) {
            $branches[] = "WHEN ? THEN ? * CAST({$column} AS SIGNED)";
            $bindings[] = $type->value;
            $bindings[] = $direction($type);
        }

        return ['COALESCE(SUM(CASE type '.implode(' ', $branches).' END), 0)', $bindings];
    }
}
