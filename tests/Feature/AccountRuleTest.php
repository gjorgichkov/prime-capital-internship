<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The two rules the brief insists on: cash may never go negative, and a client
 * cannot sell what they do not hold. In both cases the movement has to be
 * refused outright and the account left exactly as it was.
 */
class AccountRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_withdrawal_beyond_the_balance_is_refused(): void
    {
        $client = $this->clientWithCash('100.00');
        $before = $this->snapshot($client);

        $this->record($client, ['type' => 'withdrawal', 'amount' => '100.01'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'insufficient_funds')
            ->assertJsonStructure(['message', 'code', 'errors' => ['amount']]);

        $this->assertSame($before, $this->snapshot($client), 'The account changed despite the movement being refused.');
    }

    public function test_a_purchase_beyond_the_available_cash_is_refused(): void
    {
        $client = $this->clientWithCash('500.00');
        $before = $this->snapshot($client);

        $this->record($client, [
            'type' => 'buy',
            'instrument' => 'AAPL',
            'quantity' => 6,
            'price_per_unit' => '100.00',
        ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'insufficient_funds')
            ->assertJsonStructure(['message', 'code', 'errors' => ['quantity']]);

        $this->assertSame($before, $this->snapshot($client), 'The account changed despite the movement being refused.');
    }

    public function test_selling_more_units_than_are_held_is_refused(): void
    {
        $client = $this->clientWithCash('1000.00');
        $this->record($client, ['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 2, 'price_per_unit' => '100.00'])->assertCreated();

        $before = $this->snapshot($client);

        $this->record($client, ['type' => 'sell', 'instrument' => 'AAPL', 'quantity' => 3, 'price_per_unit' => '100.00'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'insufficient_holdings')
            ->assertJsonStructure(['message', 'code', 'errors' => ['quantity']]);

        $this->assertSame($before, $this->snapshot($client), 'The account changed despite the movement being refused.');
    }

    public function test_selling_an_instrument_that_was_never_held_is_refused(): void
    {
        $client = $this->clientWithCash('1000.00');
        $before = $this->snapshot($client);

        $this->record($client, ['type' => 'sell', 'instrument' => 'TSLA', 'quantity' => 1, 'price_per_unit' => '100.00'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'insufficient_holdings');

        $this->assertSame($before, $this->snapshot($client));
    }

    public function test_selling_an_instrument_already_sold_off_is_refused(): void
    {
        $client = $this->clientWithCash('1000.00');
        $this->record($client, ['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 2, 'price_per_unit' => '100.00'])->assertCreated();
        $this->record($client, ['type' => 'sell', 'instrument' => 'AAPL', 'quantity' => 2, 'price_per_unit' => '100.00'])->assertCreated();

        $this->record($client, ['type' => 'sell', 'instrument' => 'AAPL', 'quantity' => 1, 'price_per_unit' => '100.00'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'insufficient_holdings');
    }

    public function test_a_refused_movement_leaves_no_trace_in_the_ledger(): void
    {
        $client = $this->clientWithCash('100.00');

        $this->record($client, ['type' => 'withdrawal', 'amount' => '999.00'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        // The rejection happens inside the recording transaction, so the
        // rollback leaves only the original deposit behind.
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseMissing('transactions', ['type' => 'withdrawal']);
    }

    public function test_withdrawing_the_entire_balance_is_allowed(): void
    {
        $client = $this->clientWithCash('100.00');

        $this->record($client, ['type' => 'withdrawal', 'amount' => '100.00'])->assertCreated();

        // The rule is that cash may not go negative, so landing exactly on
        // zero has to be permitted.
        $this->state($client)->assertJsonPath('data.cash_balance', '0.00');
    }

    public function test_spending_the_entire_balance_on_a_purchase_is_allowed(): void
    {
        $client = $this->clientWithCash('500.00');

        $this->record($client, ['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 5, 'price_per_unit' => '100.00'])
            ->assertCreated();

        $this->state($client)
            ->assertJsonPath('data.cash_balance', '0.00')
            ->assertJsonPath('data.holdings', [['instrument' => 'AAPL', 'quantity' => 5]]);
    }

    public function test_selling_exactly_the_units_held_is_allowed(): void
    {
        $client = $this->clientWithCash('1000.00');
        $this->record($client, ['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 3, 'price_per_unit' => '100.00'])->assertCreated();

        $this->record($client, ['type' => 'sell', 'instrument' => 'AAPL', 'quantity' => 3, 'price_per_unit' => '100.00'])
            ->assertCreated();

        $this->state($client)->assertJsonPath('data.holdings', []);
    }

    public function test_a_client_with_no_cash_cannot_withdraw_anything(): void
    {
        $client = Client::factory()->create();

        $this->record($client, ['type' => 'withdrawal', 'amount' => '0.01'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'insufficient_funds');
    }

    public function test_one_clients_cash_cannot_fund_another_clients_purchase(): void
    {
        $funded = $this->clientWithCash('1000.00');
        $broke = Client::factory()->create();

        $this->record($broke, ['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 1, 'price_per_unit' => '100.00'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'insufficient_funds');

        $this->state($funded)->assertJsonPath('data.cash_balance', '1000.00');
    }

    protected function clientWithCash(string $amount): Client
    {
        $client = Client::factory()->create();

        $this->record($client, ['type' => 'deposit', 'amount' => $amount])->assertCreated();

        return $client;
    }

    /**
     * Everything the API says about the account: its derived state and the
     * whole ledger behind it.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(Client $client): array
    {
        return [
            'state' => $this->state($client)->json('data'),
            'movements' => $this->getJson("/api/clients/{$client->id}/transactions")->json('data'),
        ];
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
        return $this->getJson("/api/clients/{$client->id}")->assertOk();
    }
}
