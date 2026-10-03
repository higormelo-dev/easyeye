<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegratorUpdate;
use App\Services\IntegratorUpdateManifest;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

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
            'platform'        => ['required', 'string', 'max:16'],
            'arch'            => ['required', 'string', 'max:16'],
            'os_version'      => ['nullable', 'string', 'regex:/^\d{1,2}\.\d{1,2}(?:\.\d{1,5})?$/'],
            'current_version' => ['nullable', 'string', 'max:32'],
        ]);

        $integrator = $request->attributes->get('integrator');
        $update     = IntegratorUpdate::query()
            ->where('platform', $validated['platform'])
            ->where('arch', $validated['arch'])
            ->where('active', true)->where('channel', $integrator->update_channel ?? 'stable')->where('cohort', $integrator->update_cohort ?? 'all')
            // Desempate por id: HasUuids gera UUIDv7 (ordenado no tempo),
            // então dois builds publicados no mesmo segundo ainda saem na
            // ordem de publicação.
            ->orderByRaw('metadata IS NULL ASC')->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)->get();
        $legacy = $update->first(fn ($build) => ! $build->metadata);
        $update = $update->first(function ($build) use ($validated) {
            if (! $build->metadata) {
                return false;
            }

            try {
                app(IntegratorUpdateManifest::class)->validate($build->metadata, (string) $build->manifest_signature);
            } catch (InvalidArgumentException) {
                return false;
            }
            $os = $validated['os_version'] ?? null;

            return $os !== null && version_compare($os, $build->metadata['min_os_version'], '>=') && ($build->metadata['max_os_version'] === null || version_compare($os, $build->metadata['max_os_version'], '<='));
        });
        $update ??= $legacy;

        if (! $update) {
            return response()->json(['data' => null]);
        }
        $url        = Storage::disk('s3')->temporaryUrl($update->archive, now()->addHour());
        $urlTrusted = app(IntegratorUpdateManifest::class)->trustedAssetUrl($url);

        return response()->json([
            'data' => [
                'metadata'  => $update->metadata, 'manifest_signature' => $update->manifest_signature, 'metadata_verified' => $update->metadata !== null, 'installable' => $update->metadata !== null && $urlTrusted, 'blocked_reason' => $update->metadata === null ? 'manifest_metadata_unsigned' : ($urlTrusted ? null : 'artifact_url_untrusted'),
                'version'   => $update->version,
                'platform'  => $update->platform,
                'arch'      => $update->arch,
                'url'       => $urlTrusted ? $url : '',
                'sha256'    => $update->sha256,
                'signature' => $update->signature,
            ],
        ]);
    }
}
