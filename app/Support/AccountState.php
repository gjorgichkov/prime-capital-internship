<?php

namespace App\Support;

use App\Models\Client;
use Illuminate\Support\Collection;

/**
 * A client's account as it stands right now: the answer to the brief's
 * "how much cash and which instruments does this client hold".
 *
 * Both figures are aggregated from the ledger when this is built, so it is a
 * snapshot of a moment rather than a stored total.
 */
final readonly class AccountState
{
    /**
     * @param  Collection<string, int>  $holdings  ticker => quantity held
     */
    public function __construct(
        public Client $client,
        public int $cashBalanceMinor,
        public Collection $holdings,
    ) {}
}
