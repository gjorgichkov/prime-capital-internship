<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The constraints are the safety net underneath the application logic, and
 * they hold for anything that writes to the table, including a migration, a
 * console command or a future code path that bypasses the service.
 *
 * Every test here writes straight to the table, so validation and
 * LedgerService are deliberately out of the picture. A constraint dropped by a
 * later migration would show up here rather than as corrupt data.
 */
class DatabaseConstraintTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rows that contradict themselves, each of which a CHECK has to refuse.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function incoherentRows(): array
    {
        return [
            'an unknown movement type' => [[
                'type' => 'transfer', 'amount_minor' => 100,
            ]],
            'a movement type in the wrong case' => [[
                'type' => 'DEPOSIT', 'amount_minor' => 100,
            ]],
            'an amount of zero' => [[
                'type' => 'deposit', 'amount_minor' => 0,
            ]],
            'a cash movement carrying an instrument' => [[
                'type' => 'deposit', 'amount_minor' => 100, 'instrument' => 'AAPL', 'quantity' => 1, 'price_per_unit_minor' => 100,
            ]],
            'a cash movement carrying only a quantity' => [[
                'type' => 'withdrawal', 'amount_minor' => 100, 'quantity' => 1,
            ]],
            'a trade with no instrument details at all' => [[
                'type' => 'buy', 'amount_minor' => 100,
            ]],
            'a trade with no quantity' => [[
                'type' => 'buy', 'amount_minor' => 100, 'instrument' => 'AAPL', 'price_per_unit_minor' => 100,
            ]],
            'a trade with no price' => [[
                'type' => 'sell', 'amount_minor' => 100, 'instrument' => 'AAPL', 'quantity' => 1,
            ]],
            'a trade of zero units' => [[
                'type' => 'buy', 'amount_minor' => 100, 'instrument' => 'AAPL', 'quantity' => 0, 'price_per_unit_minor' => 100,
            ]],
            'a trade whose amount contradicts its parts' => [[
                'type' => 'buy', 'amount_minor' => 500, 'instrument' => 'AAPL', 'quantity' => 3, 'price_per_unit_minor' => 100,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('incoherentRows')]
    public function test_the_database_refuses_an_incoherent_row(array $row): void
    {
        $client = Client::factory()->create();

        $failure = null;

        try {
            DB::table('transactions')->insert($row + ['client_id' => $client->getKey()]);
        } catch (QueryException $e) {
            $failure = $e->getMessage();
        }

        $this->assertNotNull($failure, 'The database accepted a row that contradicts itself.');
        $this->assertStringContainsString('Check constraint', $failure);
        $this->assertDatabaseEmpty('transactions');
    }

    public function test_the_column_type_refuses_a_negative_amount(): void
    {
        $client = Client::factory()->create();

        // Blocked by the column being UNSIGNED rather than by a CHECK, which
        // is why the CHECK only has to rule out zero.
        $this->expectException(QueryException::class);

        DB::table('transactions')->insert([
            'client_id' => $client->getKey(),
            'type' => 'deposit',
            'amount_minor' => -100,
        ]);
    }

    public function test_it_accepts_a_coherent_cash_movement_and_trade(): void
    {
        $client = Client::factory()->create();

        DB::table('transactions')->insert([
            'client_id' => $client->getKey(), 'type' => 'deposit', 'amount_minor' => 100_000,
        ]);

        DB::table('transactions')->insert([
            'client_id' => $client->getKey(), 'type' => 'buy', 'amount_minor' => 300, 'instrument' => 'PLT', 'quantity' => 3, 'price_per_unit_minor' => 100,
        ]);

        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_every_integrity_constraint_is_still_declared(): void
    {
        $declared = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'transactions')
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->pluck('CONSTRAINT_NAME')
            ->all();

        // Named individually, because the behavioural tests above cannot tell
        // which constraint refused a row: an unknown type, for instance, is
        // incoherent in shape as well.
        foreach ([
            'transactions_type_valid',
            'transactions_amount_positive',
            'transactions_shape_coherent',
            'transactions_amount_matches_trade',
        ] as $constraint) {
            $this->assertContains($constraint, $declared);
        }
    }

    public function test_a_client_with_movements_cannot_be_deleted(): void
    {
        $client = Client::factory()->create();
        DB::table('transactions')->insert([
            'client_id' => $client->getKey(), 'type' => 'deposit', 'amount_minor' => 100_000,
        ]);

        $failure = null;

        try {
            $client->delete();
        } catch (QueryException $e) {
            $failure = $e->getMessage();
        }

        $this->assertNotNull($failure, 'A client was deleted out from under their own ledger.');
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_the_created_at_stamp_is_set_by_the_database(): void
    {
        $client = Client::factory()->create();

        // The column defaults to the current timestamp, so a row written
        // outside Eloquent is still dated.
        DB::table('transactions')->insert([
            'client_id' => $client->getKey(), 'type' => 'deposit', 'amount_minor' => 100_000,
        ]);

        $this->assertNotNull(DB::table('transactions')->value('created_at'));
    }
}
