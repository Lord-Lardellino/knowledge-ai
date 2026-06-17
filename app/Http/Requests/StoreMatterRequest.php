<?php

namespace App\Http\Requests;

use App\Models\Matter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreMatterRequest — validazione creazione/aggiornamento pratica.
 *
 * client_id / matter_type_id / party_ids vengono validati per esistenza con un
 * filtro tenant implicito (le regole 'exists' colpiscono solo righe del tenant
 * grazie al TenantScope? No: 'exists' bypassa Eloquent. Il controllo tenant è
 * fatto nel controller risolvendo i model via query scoped).
 */
class StoreMatterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->tenant_id;
    }

    public function rules(): array
    {
        return [
            'client_id'      => ['required', 'integer'],
            'matter_type_id' => ['nullable', 'integer'],
            'title'          => ['required', 'string', 'max:255'],
            'reference'      => ['nullable', 'string', 'max:255'],
            'status'         => ['required', Rule::in([
                Matter::STATUS_OPEN,
                Matter::STATUS_SUSPENDED,
                Matter::STATUS_CLOSED,
                Matter::STATUS_ARCHIVED,
            ])],
            'outcome'        => ['nullable', 'string', 'max:5000'],
            'value_cents'    => ['nullable', 'integer', 'min:0'],
            'opened_at'      => ['nullable', 'date'],
            'closed_at'      => ['nullable', 'date'],
            'notes'          => ['nullable', 'string', 'max:5000'],

            'parties'              => ['nullable', 'array'],
            'parties.*.party_id'   => ['required_with:parties', 'integer'],
            'parties.*.role'       => ['nullable', 'string', 'max:30'],
        ];
    }
}
