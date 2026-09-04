<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            // A client that already has history cannot be deleted from under it.
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            // Case sensitive for the same reason as instrument below: it makes
            // the CHECK on this column exact, so the table cannot hold a
            // 'Deposit' that the application would fail to read back.
            $table->string('type', 16)->collation('utf8mb4_0900_as_cs');
            $table->unsignedBigInteger('amount_minor');
            // Case sensitive, so that telling PLT and plt apart never depends on
            // the server's collation default, which ignores case.
            $table->string('instrument', 20)->collation('utf8mb4_0900_as_cs')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedBigInteger('price_per_unit_minor')->nullable();
            // No updated_at: rows are only ever appended.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['client_id', 'instrument']);
        });

        // The schema builder has no check() method, so the invariants that hold
        // within a single row are declared here. Note that no CHECK can express
        // "cash never goes negative", because that is an aggregate over many
        // rows rather than a property of one; the write path takes a row lock
        // for those.
        DB::statement(<<<'SQL'
            ALTER TABLE transactions
                ADD CONSTRAINT transactions_type_valid
                    CHECK (type IN ('deposit', 'withdrawal', 'buy', 'sell')),
                ADD CONSTRAINT transactions_amount_positive
                    CHECK (amount_minor > 0),
                ADD CONSTRAINT transactions_shape_coherent
                    CHECK (
                        (
                            type IN ('buy', 'sell')
                            AND instrument IS NOT NULL
                            AND quantity IS NOT NULL
                            AND price_per_unit_minor IS NOT NULL
                            AND quantity > 0
                            AND price_per_unit_minor > 0
                        )
                        OR (
                            type IN ('deposit', 'withdrawal')
                            AND instrument IS NULL
                            AND quantity IS NULL
                            AND price_per_unit_minor IS NULL
                        )
                    ),
                ADD CONSTRAINT transactions_amount_matches_trade
                    CHECK (
                        type IN ('deposit', 'withdrawal')
                        OR amount_minor = quantity * price_per_unit_minor
                    )
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Dropping the table takes its CHECK constraints with it.
        Schema::dropIfExists('transactions');
    }
};
