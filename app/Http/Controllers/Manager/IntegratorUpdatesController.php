<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\IntegratorUpdate;
use App\Services\IntegratorUpdatePublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response as InertiaResponse};
use InvalidArgumentException;

/**
 * Publicação de builds do EasyEye Integrator (auto-atualização) pelo admin
 * do SaaS, sem terminal: sobe o MSI + cola a assinatura que o
 * scripts/sign-update.sh (repositório do integrator) imprimiu. A chave
 * privada de assinatura nunca passa por aqui — só a assinatura pronta.
 */
class IntegratorUpdatesController extends Controller
{
    public function __construct(private readonly IntegratorUpdatePublisher $publisher)
    {
    }

    public function index(): InertiaResponse
    {
        return Inertia::render('Panel/Manager/IntegratorUpdates/Index', [
            'updates' => IntegratorUpdate::query()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // MSI não tem MIME confiável entre navegadores (application/x-msi,
            // octet-stream, msdownload…) — valida extensão + tamanho.
            'file'      => ['required', 'file', 'max:262144'],
            'version'   => ['required', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+$/'],
            'platform'  => ['required', Rule::in(IntegratorUpdatePublisher::PLATFORMS)],
            'arch'      => ['required', Rule::in(IntegratorUpdatePublisher::ARCHS)],
            'signature' => ['required', 'string', 'max:120'],
        ], [
            'version.regex' => 'Versão deve ser semver (ex.: 0.2.0).',
        ]);

        $file      = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['msi', 'exe', 'appimage', 'dmg', 'deb'], true)) {
            return back()->withErrors(['file' => 'Arquivo deve ser um instalador (.msi, .exe, .AppImage, .dmg, .deb).']);
        }

        try {
            $update = $this->publisher->publish(
                localPath: $file->getRealPath(),
                fileName: $file->getClientOriginalName(),
                version: $validated['version'],
                platform: $validated['platform'],
                arch: $validated['arch'],
                signature: trim($validated['signature']),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['signature' => $e->getMessage()]);
        }

        return back()->with(
            'success',
            "Atualização {$update->version} ({$update->platform}/{$update->arch}) publicada.",
        );
    }

    /**
     * Ativa/desativa um build. Sem delete físico: histórico de versões
     * publicadas é rastro de auditoria do que já foi distribuído às clínicas.
     */
    public function update(Request $request, IntegratorUpdate $integratorUpdate): RedirectResponse
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);

        $integratorUpdate->update(['active' => $validated['active']]);

        return back()->with('success', $validated['active'] ? 'Build reativado.' : 'Build desativado.');
    }
}
