<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class TransactionValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each case is a payload that must be refused, and the field the complaint
     * belongs to.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'no type' => [['amount' => '10.00'], 'type'],
            'an unknown type' => [['type' => 'transfer', 'amount' => '10.00'], 'type'],
            'a type in the wrong case' => [['type' => 'Deposit', 'amount' => '10.00'], 'type'],

            'a deposit with no amount' => [['type' => 'deposit'], 'amount'],
            'an amount of zero' => [['type' => 'deposit', 'amount' => '0.00'], 'amount'],
            'a negative amount' => [['type' => 'deposit', 'amount' => '-10.00'], 'amount'],
            'an amount with three decimals' => [['type' => 'deposit', 'amount' => '10.005'], 'amount'],
            'an amount that is not a number' => [['type' => 'deposit', 'amount' => 'ten'], 'amount'],
            'an amount sent as a float' => [['type' => 'deposit', 'amount' => 10.55], 'amount'],
            'an amount beyond the storable range' => [['type' => 'deposit', 'amount' => '99999999999999999.00'], 'amount'],

            'a deposit carrying an instrument' => [['type' => 'deposit', 'amount' => '10.00', 'instrument' => 'AAPL'], 'instrument'],
            'a deposit carrying a quantity' => [['type' => 'deposit', 'amount' => '10.00', 'quantity' => 5], 'quantity'],
            'a withdrawal carrying a price' => [['type' => 'withdrawal', 'amount' => '10.00', 'price_per_unit' => '5.00'], 'price_per_unit'],

            'a purchase with no instrument' => [['type' => 'buy', 'quantity' => 1, 'price_per_unit' => '10.00'], 'instrument'],
            'a purchase with no quantity' => [['type' => 'buy', 'instrument' => 'AAPL', 'price_per_unit' => '10.00'], 'quantity'],
            'a purchase with no price' => [['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 1], 'price_per_unit'],
            'a purchase carrying an amount' => [['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 1, 'price_per_unit' => '10.00', 'amount' => '10.00'], 'amount'],

            'a fractional quantity' => [['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 1.5, 'price_per_unit' => '10.00'], 'quantity'],
            'a quantity of zero' => [['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 0, 'price_per_unit' => '10.00'], 'quantity'],
            'a negative quantity' => [['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => -1, 'price_per_unit' => '10.00'], 'quantity'],
            'a quantity as a word' => [['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 'five', 'price_per_unit' => '10.00'], 'quantity'],
            'a price of zero' => [['type' => 'buy', 'instrument' => 'AAPL', 'quantity' => 1, 'price_per_unit' => '0.00'], 'price_per_unit'],

            'a ticker with a space' => [['type' => 'buy', 'instrument' => 'AA PL', 'quantity' => 1, 'price_per_unit' => '10.00'], 'instrument'],
            'a ticker with punctuation' => [['type' => 'buy', 'instrument' => 'AAPL!', 'quantity' => 1, 'price_per_unit' => '10.00'], 'instrument'],
            'a ticker that is too long' => [['type' => 'buy', 'instrument' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'quantity' => 1, 'price_per_unit' => '10.00'], 'instrument'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_it_refuses_a_malformed_movement(array $payload, string $expectedField): void
    {
        $client = Client::factory()->create();

        $this->postJson("/api/clients/{$client->id}/transactions", $payload)
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors($expectedField);

        $this->assertDatabaseCount('transactions', 0);
    }

    /**
     * A rejected payload is a plain validation failure, so it carries no code.
     * That is what separates it from a movement that was well formed but broke
     * an account rule.
     */
    public function test_a_validation_failure_carries_no_rule_code(): void
    {
        $client = Client::factory()->create();

        $this->postJson("/api/clients/{$client->id}/transactions", ['type' => 'deposit', 'amount' => '0.00'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonMissingPath('code');
    }

    public function test_an_amount_sent_as_a_whole_json_number_is_accepted(): void
    {
        $client = Client::factory()->create();

        // Integers survive JSON exactly, unlike decimals, so there is no
        // reason to refuse them.
        $this->postJson("/api/clients/{$client->id}/transactions", ['type' => 'deposit', 'amount' => 1000])
            ->assertCreated()
            ->assertJsonPath('data.amount', '1000.00');
    }

    public function test_a_ticker_may_contain_a_dot_or_a_hyphen(): void
    {
        $client = Client::factory()->create();
        $this->postJson("/api/clients/{$client->id}/transactions", ['type' => 'deposit', 'amount' => '1000.00'])->assertCreated();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy', 'instrument' => 'BRK.B', 'quantity' => 1, 'price_per_unit' => '10.00',
        ])->assertCreated();

        $this->postJson("/api/clients/{$client->id}/transactions", [
            'type' => 'buy', 'instrument' => 'RDS-A', 'quantity' => 1, 'price_per_unit' => '10.00',
        ])->assertCreated();
    }

    public function test_a_client_needs_a_name(): void
    {
        $this->postJson('/api/clients', [])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('name');
    }

    public function test_two_clients_cannot_share_a_name(): void
    {
        Client::factory()->create(['name' => 'Ana']);

        $this->postJson('/api/clients', ['name' => 'Ana'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('name');
    }
}
