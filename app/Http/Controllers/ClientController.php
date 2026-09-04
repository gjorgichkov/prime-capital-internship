<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\ClientStateResource;
use App\Models\Client;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ClientController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(): AnonymousResourceCollection
    {
        return ClientResource::collection(Client::orderBy('name')->get());
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = Client::create($request->validated());

        return ClientResource::make($client)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * The client's current cash and holdings, both aggregated from the ledger.
     */
    public function show(Client $client): ClientStateResource
    {
        return ClientStateResource::make($this->ledger->state($client));
    }
}
