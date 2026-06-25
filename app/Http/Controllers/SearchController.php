<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Document;
use App\Models\Matter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SearchController — ricerca generale rapida (appbar): documenti, pratiche, clienti.
 *
 * Ricerca testuale (ILIKE) leggera e gratuita — niente embedding per battitura.
 * Tutto tenant-scoped tramite i global scope dei model.
 */
class SearchController extends Controller
{
    public function global(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['documents' => [], 'matters' => [], 'clients' => []]);
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $legal = (bool) config('knowledge.legal.enabled');

        return response()->json([
            'documents' => Document::query()
                ->where('title', 'ILIKE', $like)
                ->orderByDesc('created_at')
                ->limit(6)
                ->get(['id', 'title', 'extension', 'status']),

            'matters' => $legal ? Matter::query()
                ->where('title', 'ILIKE', $like)
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get(['id', 'title']) : [],

            'clients' => $legal ? Client::query()
                ->where('name', 'ILIKE', $like)
                ->orderBy('name')
                ->limit(5)
                ->get(['id', 'name']) : [],
        ]);
    }
}
