<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreClientRequest — validazione creazione cliente.
 */
class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->tenant_id;
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'type'     => ['required', Rule::in([Client::TYPE_PERSON, Client::TYPE_COMPANY])],
            'tax_code' => ['nullable', 'string', 'max:32'],
            'vat'      => ['nullable', 'string', 'max:32'],
            'email'    => ['nullable', 'email', 'max:255'],
            'phone'    => ['nullable', 'string', 'max:40'],
            'notes'    => ['nullable', 'string', 'max:5000'],
        ];
    }
}
