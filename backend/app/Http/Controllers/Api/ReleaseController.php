<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Release;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    /**
     * Notificación de versión para la taquilla instalada (REQ-B1, D1):
     * notify-only — nunca auto-instala ni expone URL de descarga.
     * GET /api/v1/update-check — público + throttle:30,1.
     */
    public function updateCheck(): JsonResponse
    {
        $release = Release::query()->current()->first();

        if (! $release) {
            return response()->json(['message' => 'No hay releases publicadas.'], 404);
        }

        return response()->json([
            'version' => $release->version,
            'sha256' => $release->sha256,
        ]);
    }

    /**
     * Feed público electron-updater (TQ-10, D1): sirve `latest.yml`, el .exe
     * y el .blockmap de la release vigente por nombre exacto. Whitelist
     * estricta contra la fila actual (D3): cualquier otro nombre → 404 sin
     * exponer el disco. `application/octet-stream` para que el encode
     * zstd/gzip de Caddy nunca comprima; BinaryFileResponse soporta Range/206
     * para la reanudación de descargas parciales del updater.
     * GET /api/v1/releases/feed/{file} — público + throttle:releases-feed.
     */
    public function feed(string $file): BinaryFileResponse
    {
        $release = Release::query()->current()->first();

        $permitidos = ['latest.yml'];
        if ($release) {
            $permitidos[] = $release->file_path;
            $permitidos[] = $release->file_path.'.blockmap';
        }

        if (! in_array($file, $permitidos, true) || ! Storage::disk('releases')->exists($file)) {
            abort(404);
        }

        return new BinaryFileResponse(Storage::disk('releases')->path($file), 200, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }
}
