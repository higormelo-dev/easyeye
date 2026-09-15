<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegratorUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class IntegratorUpdatesController extends Controller
{
    /**
     * Último build publicado para a plataforma/arquitetura do cliente.
     *
     * Contrato do desktop (src/updater/mod.rs do integrator): {"data": null}
     * quando não há build — o cliente mostra "sem atualização publicada" —
     * ou {"data": {version, platform, arch, url, sha256, signature}}. O
     * cliente compara versões por semver localmente e baixa `url` SEM o
     * Bearer token, por isso a URL temporária assinada do S3.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', 'max:16'],
            'arch'     => ['required', 'string', 'max:16'],
        ]);

        $update = IntegratorUpdate::query()
            ->where('platform', $validated['platform'])
            ->where('arch', $validated['arch'])
            ->where('active', true)
            // Desempate por id: HasUuids gera UUIDv7 (ordenado no tempo),
            // então dois builds publicados no mesmo segundo ainda saem na
            // ordem de publicação.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $update) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'version'   => $update->version,
                'platform'  => $update->platform,
                'arch'      => $update->arch,
                'url'       => Storage::disk('s3')->temporaryUrl($update->archive, now()->addHour()),
                'sha256'    => $update->sha256,
                'signature' => $update->signature,
            ],
        ]);
    }
}
