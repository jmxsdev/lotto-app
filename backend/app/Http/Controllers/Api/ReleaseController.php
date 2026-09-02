<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Release;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Emite una URL firmada de expiración corta (5 min) para el .exe (REQ-A2).
     * GET /api/v1/releases/download — auth:sanctum + roles del panel + throttle.
     */
    public function download(): JsonResponse
    {
        $release = Release::query()->current()->first();

        if (! $release) {
            return response()->json(['message' => 'No hay releases publicadas.'], 404);
        }

        $expiresAt = now()->addMinutes(5);

        $url = URL::temporarySignedRoute('releases.serve', $expiresAt, absolute: false);

        return response()->json([
            'url' => $url,
            'expires_at' => $expiresAt->toISOString(),
        ]);
    }

    /**
     * Sirve el .exe por streaming desde el volumen persistente (REQ-A4).
     * GET /api/v1/releases/serve — signed:relative + throttle (sin auth:
     * la firma ES la credencial).
     */
    public function serve(Request $request): StreamedResponse
    {
        $release = Release::query()->current()->first();

        if (! $release || ! Storage::disk('releases')->exists($release->file_path)) {
            abort(404);
        }

        $filename = 'Taquilla-Setup-'.$release->version.'.exe';

        return Storage::disk('releases')->download($release->file_path, $filename, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }
}
