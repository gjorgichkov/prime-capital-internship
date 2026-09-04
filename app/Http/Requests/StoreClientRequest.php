<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Unique because a name is the only thing that identifies a client.
            'name' => ['required', 'string', 'max:255', Rule::unique('clients', 'name')],
        ];
    }
}
