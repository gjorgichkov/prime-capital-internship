<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Services\LedgerService;
use App\Support\Movement;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Both account rules are read-then-write decisions, so without a lock two
 * simultaneous requests could each read a balance of 500 and each approve a
 * 400 purchase, leaving -300. These tests use two genuinely separate database
 * connections to show the lock is real rather than assumed.
 *
 * RefreshDatabase is deliberately not used: it wraps each test in its own
 * transaction, which would interfere with the locking being measured.
 */
class ConcurrentWriteTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_second_connection_cannot_take_the_client_lock_while_it_is_held(): void
    {
        $client = Client::factory()->create();
        $other = $this->otherConnection();

        $failure = null;

        DB::beginTransaction();

        try {
            Client::whereKey($client->getKey())->lockForUpdate()->firstOrFail();

            try {
                $other->table('clients')->where('id', $client->getKey())->lockForUpdate()->first();
            } catch (QueryException $e) {
                $failure = $e->getMessage();
            }
        } finally {
            DB::rollBack();
        }

        $this->assertNotNull(
            $failure,
            'A second connection took the lock while it was held, so writes to one account are not serialised.',
        );
        $this->assertStringContainsString('Lock wait timeout exceeded', $failure);
    }

    public function test_a_lock_on_one_client_does_not_hold_up_another(): void
    {
        $locked = Client::factory()->create();
        $unrelated = Client::factory()->create();
        $other = $this->otherConnection();

        DB::beginTransaction();

        try {
            Client::whereKey($locked->getKey())->lockForUpdate()->firstOrFail();

            // Succeeds because InnoDB locks the row rather than the table, so
            // one busy account never delays the rest.
            $row = $other->table('clients')->where('id', $unrelated->getKey())->lockForUpdate()->first();

            $this->assertNotNull($row);
        } finally {
            DB::rollBack();
        }
    }

    public function test_the_lock_is_released_once_the_transaction_ends(): void
    {
        $client = Client::factory()->create();
        $other = $this->otherConnection();

        DB::beginTransaction();
        Client::whereKey($client->getKey())->lockForUpdate()->firstOrFail();
        DB::rollBack();

        $row = $other->table('clients')->where('id', $client->getKey())->lockForUpdate()->first();

        $this->assertNotNull($row, 'The lock outlived its transaction.');
    }

    /**
     * The serialisation guarantee itself: while one connection holds a
     * client's row, a movement for that client cannot be recorded.
     *
     * Worth knowing what this does and does not show. It does not isolate the
     * explicit lockForUpdate() in the write path, because the foreign key on
     * the inserted row needs a shared lock on the same parent row and would
     * block here too. test_recording_a_movement_locks_the_client_row below
     * pins the explicit lock; this one pins the observable behaviour.
     */
    public function test_a_movement_cannot_be_recorded_while_the_client_is_held(): void
    {
        $client = Client::factory()->create();
        $ledger = app(LedgerService::class);
        $other = $this->otherConnection();

        // Another connection is holding this client's row.
        $other->beginTransaction();
        $other->table('clients')->where('id', $client->getKey())->lockForUpdate()->first();

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $failure = null;

        try {
            $ledger->record($client, Movement::deposit(100_00));
        } catch (QueryException $e) {
            $failure = $e->getMessage();
        } finally {
            $other->rollBack();
            DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }

        $this->assertNotNull($failure, 'A movement was recorded while another connection held the client.');
        $this->assertStringContainsString('Lock wait timeout exceeded', $failure);

        // Nothing was written, because the failure happened inside the
        // recording transaction.
        $this->assertDatabaseCount('transactions', 0);
    }

    /**
     * Pins the explicit row lock in the write path, which the behavioural test
     * above cannot distinguish from the foreign key's own locking.
     */
    public function test_recording_a_movement_locks_the_client_row(): void
    {
        $client = Client::factory()->create();
        $ledger = app(LedgerService::class);

        DB::enableQueryLog();

        try {
            $ledger->record($client, Movement::deposit(100_00));

            $statements = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }

        $lockedTheClient = array_filter(
            $statements,
            fn (string $sql): bool => str_contains($sql, 'from `clients`') && str_contains($sql, 'for update'),
        );

        $this->assertNotEmpty($lockedTheClient, 'Recording a movement did not lock the client row.');
    }

    /**
     * The subtlety that makes the lock actually work.
     *
     * MySQL defaults to REPEATABLE READ, where a plain SELECT is served from a
     * snapshot fixed at the transaction's first read. A balance check built on
     * a plain SELECT could therefore return stale data even while correctly
     * holding the client lock: a lock that looks right and silently is not.
     * Reading the aggregate FOR UPDATE avoids that, and this test fails if
     * that ever regresses to a plain read.
     */
    public function test_the_rule_check_sees_what_another_connection_has_committed(): void
    {
        $client = Client::factory()->create();
        $ledger = app(LedgerService::class);
        $other = $this->otherConnection();

        DB::beginTransaction();

        try {
            // Fix a snapshot, as any earlier read in the same transaction would.
            $this->assertSame(0, $ledger->cashBalanceMinor($client));

            // A different connection deposits 1000.00 and commits it.
            $other->table('transactions')->insert([
                'client_id' => $client->getKey(),
                'type' => 'deposit',
                'amount_minor' => 100_000,
                'created_at' => now(),
            ]);

            // The plain aggregate still reports the balance from the snapshot.
            $this->assertSame(0, $ledger->cashBalanceMinor($client));

            // The withdrawal is nonetheless allowed, which is only possible
            // because the rule check reads the balance FOR UPDATE and so sees
            // the deposit. A plain read would refuse this wrongly.
            $ledger->record($client, Movement::withdrawal(100_000));
        } finally {
            DB::rollBack();
        }
    }

    /**
     * A second connection to the same database, with its own transaction and
     * its own locks.
     */
    protected function otherConnection(): ConnectionInterface
    {
        $name = 'concurrent';

        config()->set(
            "database.connections.{$name}",
            config('database.connections.'.config('database.default')),
        );

        DB::purge($name);

        $connection = DB::connection($name);

        // Fail immediately rather than waiting out the 50 second default, so a
        // blocked lock shows up as a fast, clear failure.
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return $connection;
    }
}
