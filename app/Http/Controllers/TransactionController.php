<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Client;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class TransactionController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * The client's movements, oldest first, which is the order the balance is
     * built up in.
     */
    public function index(Client $client): AnonymousResourceCollection
    {
        return TransactionResource::collection($client->transactions()->orderBy('id')->get());
    }

    /**
     * Records a movement, or rejects it for breaking an account rule.
     */
    public function store(StoreTransactionRequest $request, Client $client): JsonResponse
    {
        $transaction = $this->ledger->record($client, $request->toMovement());

        return TransactionResource::make($transaction)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
