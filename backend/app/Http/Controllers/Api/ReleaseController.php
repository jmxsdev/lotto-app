<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Release;
use Illuminate\Http\JsonResponse;

class ReleaseController extends Controller
{
    /**
     * Única release publicada (D3: sin historial).
     * GET /api/v1/releases/latest — auth:sanctum + roles del panel.
     */
    public function latest(): JsonResponse
    {
        $release = Release::query()->current()->first();

        if (! $release) {
            return response()->json(['message' => 'No hay releases publicadas.'], 404);
        }

        return response()->json([
            'version' => $release->version,
            'sha256' => $release->sha256,
            'published_at' => $release->published_at,
        ]);
    }
}
