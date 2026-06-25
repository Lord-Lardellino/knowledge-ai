<?php

namespace App\Http\Controllers;

use App\Models\Matter;
use App\Services\Knowledge\DocumentChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ChatController — Chat AI sui documenti del tenant (RAG con citazioni).
 */
class ChatController extends Controller
{
    /** Elenco pratiche (tenant-scoped) per il filtro della chat. */
    public function matters(): JsonResponse
    {
        return response()->json(
            Matter::query()->orderBy('title')->get(['id', 'title'])
        );
    }

    public function ask(Request $request, DocumentChat $chat): JsonResponse
    {
        $validated = $request->validate([
            'question'           => ['required', 'string', 'max:2000'],
            'matter_id'          => ['nullable', 'integer'],
            'history'            => ['nullable', 'array', 'max:20'],
            'history.*.role'     => ['required', 'string', 'in:user,assistant'],
            'history.*.content'  => ['required', 'string', 'max:8000'],
        ]);

        $result = $chat->ask(
            tenantId: (int) $request->user()->tenant_id,
            question: $validated['question'],
            matterId: $validated['matter_id'] ?? null,
            history: $validated['history'] ?? [],
        );

        return response()->json($result);
    }
}
