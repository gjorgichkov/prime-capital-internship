<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Client;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    /**
     * The client's movements, oldest first, which is the order the balance is
     * built up in.
     */
    public function index(Client $client): AnonymousResourceCollection
    {
        return TransactionResource::collection($client->transactions()->orderBy('id')->get());
    }
}
