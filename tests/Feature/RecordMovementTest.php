<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RecordMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deposit_adds_to_the_cash_balance(): void
    {
        $client = Client::factory()->create();

        $this->record($client, ['type' => 'deposit', 'amount' => '1000.00'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'deposit')
            ->assertJsonPath('data.amount', '1000.00');

        $this->assertCash($client, '1000.00');
    }

    public function test_a_withdrawal_takes_from_the_cash_balance(): void
    {
        $client = Client::factory()->create();
        $this->record($client, ['type' => 'deposit', 'amount' => '1000.00'])->assertCreated();

        $this->record($client, ['type' => 'withdrawal', 'amount' => '250.50'])->assertCreated();

        $this->assertCash($client, '749.50');
    }

    public function test_a_purchase_spends_cash_and_adds_to_holdings(): void
    {
        $client = Client::factory()->create();
        $this->record($client, ['type' => 'deposit', 'amount' => '1000.00'])->assertCreated();

        $this->record($client, [
            'type' => 'buy',
            'instrument' => 'AAPL',
            'quantity' => 5,
            'price_per_unit' => '100.00',
        ])
            ->assertCreated()
            // The cost is derived from quantity and price rather than sent,
            // so it cannot disagree with them.
            ->assertJsonPath('data.amount', '500.00');

        $this->assertCash($client, '500.00');
        $this->assertHoldings($client, [['instrument' => 'AAPL', 'quantity' => 5]]);
    }

    public function test_a_sale_returns_cash_and_reduces_holdings(): void
    {
        $client = $this->clientHolding('AAPL', 5, '100.00');

        $this->record($client, [
            'type' => 'sell',
            'instrument' => 'AAPL',
            'quantity' => 3,
            'price_per_unit' => '120.00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '360.00');

        $this->assertCash($client, '860.00');
        $this->assertHoldings($client, [['instrument' => 'AAPL', 'quantity' => 2]]);
    }

    /**
     * The brief is explicit that a sale need not match the purchase price, and
     * that no profit or loss is calculated from the difference.
     */
    public function test_a_sale_at_a_price_below_the_purchase_is_accepted(): void
    {
        $client = $this->clientHolding('AAPL', 5, '100.00');

        $this->record($client, [
            'type' => 'sell',
            'instrument' => 'AAPL',
            'quantity' => 5,
            'price_per_unit' => '40.00',
        ])->assertCreated();

        // 1000 - 500 spent, + 200 returned.
        $this->assertCash($client, '700.00');
        $this->assertHoldings($client, []);
    }

    public function test_the_worked_example_from_the_brief_ends_in_the_documented_state(): void
    {
        $client = Client::factory()->create(['name' => 'Ana']);

        $this->record($client, ['type' => 'deposit', 'amount' => '1000.00'])->assertCreated();
        $this->record($client, ['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 5, 'price_per_unit' => '100.00'])->assertCreated();
        $this->record($client, ['type' => 'sell', 'instrument' => 'AAPL', 'quantity' => 3, 'price_per_unit' => '120.00'])->assertCreated();

        $this->state($client)
            ->assertOk()
            ->assertJsonPath('data.name', 'Ana')
            ->assertJsonPath('data.cash_balance', '860.00')
            ->assertJsonPath('data.holdings', [['instrument' => 'AAPL', 'quantity' => 2]]);
    }

    public function test_an_instrument_sold_down_to_nothing_leaves_the_portfolio(): void
    {
        $client = $this->clientHolding('AAPL', 5, '100.00');
        $this->record($client, ['type' => 'buy', 'instrument' => 'MSFT', 'quantity' => 1, 'price_per_unit' => '200.00'])->assertCreated();

        $this->record($client, ['type' => 'sell', 'instrument' => 'AAPL', 'quantity' => 5, 'price_per_unit' => '100.00'])->assertCreated();

        // AAPL is absent rather than present with a quantity of zero.
        $this->assertHoldings($client, [['instrument' => 'MSFT', 'quantity' => 1]]);
    }

    public function test_holdings_are_reported_for_several_instruments(): void
    {
        $client = $this->clientHolding('MSFT', 2, '300.00');
        $this->record($client, ['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 1, 'price_per_unit' => '150.00'])->assertCreated();

        // Ordered by ticker, so the response is stable between requests.
        $this->assertHoldings($client, [
            ['instrument' => 'AAPL', 'quantity' => 1],
            ['instrument' => 'MSFT', 'quantity' => 2],
        ]);
    }

    public function test_a_ticker_is_matched_regardless_of_the_case_it_arrives_in(): void
    {
        $client = $this->clientHolding('AAPL', 5, '100.00');

        $this->record($client, ['type' => 'buy', 'instrument' => 'aapl', 'quantity' => 2, 'price_per_unit' => '100.00'])
            ->assertCreated()
            ->assertJsonPath('data.instrument', 'AAPL');

        // One holding of 7, not two holdings of 5 and 2.
        $this->assertHoldings($client, [['instrument' => 'AAPL', 'quantity' => 7]]);
    }

    public function test_one_clients_movements_do_not_touch_another(): void
    {
        $ana = Client::factory()->create();
        $bojan = Client::factory()->create();

        $this->record($ana, ['type' => 'deposit', 'amount' => '1000.00'])->assertCreated();
        $this->record($ana, ['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 2, 'price_per_unit' => '100.00'])->assertCreated();
        $this->record($bojan, ['type' => 'deposit', 'amount' => '25.00'])->assertCreated();

        $this->assertCash($ana, '800.00');
        $this->assertHoldings($ana, [['instrument' => 'AAPL', 'quantity' => 2]]);

        $this->assertCash($bojan, '25.00');
        $this->assertHoldings($bojan, []);
    }

    public function test_a_new_client_starts_with_no_cash_and_no_holdings(): void
    {
        $client = Client::factory()->create();

        $this->assertCash($client, '0.00');
        $this->assertHoldings($client, []);
    }

    public function test_the_audit_trail_lists_every_movement_oldest_first(): void
    {
        $client = Client::factory()->create();
        $this->record($client, ['type' => 'deposit', 'amount' => '1000.00'])->assertCreated();
        $this->record($client, ['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 5, 'price_per_unit' => '100.00'])->assertCreated();

        $this->getJson("/api/clients/{$client->id}/transactions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'deposit')
            ->assertJsonPath('data.0.amount', '1000.00')
            // Cash movements keep the instrument fields present and null, so
            // every entry has the same shape.
            ->assertJsonPath('data.0.instrument', null)
            ->assertJsonPath('data.0.quantity', null)
            ->assertJsonPath('data.0.price_per_unit', null)
            ->assertJsonPath('data.1.type', 'buy')
            ->assertJsonPath('data.1.instrument', 'AAPL')
            ->assertJsonPath('data.1.quantity', 5)
            ->assertJsonPath('data.1.price_per_unit', '100.00');
    }

    public function test_a_client_can_be_created_and_listed(): void
    {
        $this->postJson('/api/clients', ['name' => 'Ana'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ana');

        $this->postJson('/api/clients', ['name' => 'Bojan'])->assertCreated();

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonPath('data.1.name', 'Bojan');
    }

    public function test_an_unknown_client_is_reported_as_not_found(): void
    {
        $this->getJson('/api/clients/999')
            ->assertNotFound()
            ->assertJsonPath('message', 'No client exists for the given identifier.');
    }

    /**
     * A client funded with 1000.00 who has then bought the given holding.
     */
    protected function clientHolding(string $instrument, int $quantity, string $pricePerUnit): Client
    {
        $client = Client::factory()->create();

        $this->record($client, ['type' => 'deposit', 'amount' => '1000.00'])->assertCreated();
        $this->record($client, [
            'type' => 'buy',
            'instrument' => $instrument,
            'quantity' => $quantity,
            'price_per_unit' => $pricePerUnit,
        ])->assertCreated();

        return $client;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function record(Client $client, array $payload): TestResponse
    {
        return $this->postJson("/api/clients/{$client->id}/transactions", $payload);
    }

    protected function state(Client $client): TestResponse
    {
        return $this->getJson("/api/clients/{$client->id}");
    }

    protected function assertCash(Client $client, string $expected): void
    {
        $this->state($client)->assertOk()->assertJsonPath('data.cash_balance', $expected);
    }

    /**
     * @param  list<array{instrument: string, quantity: int}>  $expected
     */
    protected function assertHoldings(Client $client, array $expected): void
    {
        $this->state($client)->assertOk()->assertJsonPath('data.holdings', $expected);
    }
}
