<?php

namespace App\Http\Controllers;

use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PartyController — anagrafica controparti (verticale legale).
 *
 * Query scoped per tenant (HasTenant su Party). Endpoint JSON per i select.
 */
class PartyController extends Controller
{
    /** Lista controparti del tenant corrente. */
    public function index(): JsonResponse
    {
        $parties = Party::query()
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        return response()->json($parties);
    }

    /** Crea una nuova controparte. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'type'  => ['required', Rule::in([Party::TYPE_PERSON, Party::TYPE_COMPANY])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $party = Party::create([
            'tenant_id' => $request->user()->tenant_id,
            ...$validated,
        ]);

        return response()->json($party, 201);
    }
}
