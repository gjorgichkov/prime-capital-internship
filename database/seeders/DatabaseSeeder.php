<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Services\LedgerService;
use App\Support\Money;
use App\Support\Movement;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Every movement below is recorded through LedgerService rather than
     * inserted directly, so the seed data goes past the same rules and the
     * same locking as a request would. If a scenario here were impossible,
     * seeding would fail rather than quietly produce a state the API could
     * never reach.
     */
    public function run(LedgerService $ledger): void
    {
        // The brief's own worked example, kept verbatim so its numbers can be
        // compared against a live response. Ends at 860.00 cash and 2 AAPL.
        $ana = Client::create(['name' => 'Ana']);
        $ledger->record($ana, Movement::deposit(Money::fromDecimalString('1000.00')));
        $ledger->record($ana, Movement::buy('AAPL', 5, Money::fromDecimalString('100.00')));
        $ledger->record($ana, Movement::sell('AAPL', 3, Money::fromDecimalString('120.00')));

        // Two instruments, one of them sold off completely, so that an
        // instrument dropping out of the portfolio is visible in seeded data.
        // Ends at 2100.00 cash holding only MSFT; TSLA is gone.
        $bojan = Client::create(['name' => 'Bojan']);
        $ledger->record($bojan, Movement::deposit(Money::fromDecimalString('5000.00')));
        $ledger->record($bojan, Movement::buy('MSFT', 10, Money::fromDecimalString('300.00')));
        $ledger->record($bojan, Movement::buy('TSLA', 4, Money::fromDecimalString('250.00')));
        $ledger->record($bojan, Movement::sell('TSLA', 4, Money::fromDecimalString('275.00')));

        // The trivial case: cash, no instruments.
        $cvetanka = Client::create(['name' => 'Cvetanka']);
        $ledger->record($cvetanka, Movement::deposit(Money::fromDecimalString('750.00')));
    }
}
