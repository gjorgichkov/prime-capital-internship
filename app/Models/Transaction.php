<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    /**
     * Movements are appended and never modified, so there is no column to
     * touch on update.
     */
    const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'amount_minor',
        'instrument',
        'quantity',
        'price_per_unit_minor',
    ];

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount_minor' => 'integer',
            'quantity' => 'integer',
            'price_per_unit_minor' => 'integer',
        ];
    }
}
