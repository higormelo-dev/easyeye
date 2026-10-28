<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Entity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante que o usuário passou pela verificação 2FA na sessão atual,
 * QUANDO a entity ativa exige 2FA (decisão por empresa).
 *
 * Regras:
 *
 *  1) Sem usuário autenticado → passa (rotas auth lidam).
 *
 *  2) Rotas próprias do fluxo 2FA + logout → passam (catch-22).
 *
 *  3) Lê a entity ativa da sessão (selected_entity_id). Se não houver
 *     entity selecionada → passa (selectentity flow vai resolver).
 *
 *  4) Se entity.requires_two_factor = false → 2FA é OPCIONAL.
 *     Usuário pode habilitar via /security/two-factor/setup, mas não é
 *     bloqueado. Middleware libera.
 *
 *  5) Se entity.requires_two_factor = true:
 *      a) Usuário sem 2FA habilitado → redirect para setup (ou 423 JSON).
 *      b) Usuário com 2FA habilitado mas sessão não verificada → redirect
 *         para verify (ou 423 JSON).
 *      c) Sessão verificada → passa.
 *
 * Sobre a sessão verificada:
 *  - `two_factor_verified_at` é colocado pelo TwoFactorController após
 *    confirm() ou verifyStore() bem-sucedidos.
 *  - `session()->invalidate()` no logout limpa, exigindo nova verificação
 *    no próximo login (correto).
 *  - NÃO re-pedimos 2FA a cada request — vale enquanto a sessão vive.
 */
class EnsureTwoFactor
{
    private const SKIP_ROUTES = [
        'security.two-factor.setup',
        'security.two-factor.setup.store',
        'security.two-factor.confirm',
        'security.two-factor.verify',
        'security.two-factor.verify.store',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if ($routeName !== null && \in_array($routeName, self::SKIP_ROUTES, true)) {
            return $next($request);
        }

        $entityId = $request->session()->get('selected_entity_id');

        if (! $entityId) {
            // Sem entity ativa: fluxo de seleção de entity vai resolver.
            return $next($request);
        }

        // Cache por request para evitar SELECT extra em cada middleware hit.
        // Usa attributes (vive só durante o request).
        $entity = $request->attributes->get('_2fa_entity_cache');

        if ($entity === null) {
            $entity = Entity::query()->find($entityId);
            $request->attributes->set('_2fa_entity_cache', $entity ?? false);
        }

        if (! $entity || ! $entity->requiresTwoFactor()) {
            // Empresa não exige 2FA — passa direto.
            return $next($request);
        }

        // ── Empresa exige 2FA ────────────────────────────────────────────────

        if (! $user->hasTwoFactorEnabled()) {
            return $this->blockOrRedirect(
                $request,
                __('manager_hardening.two_factor_required_by_entity', ['entity' => $entity->name]),
                route('security.two-factor.setup'),
            );
        }

        if (! $request->session()->has('two_factor_verified_at')) {
            return $this->blockOrRedirect(
                $request,
                __('manager_hardening.two_factor_required_by_entity', ['entity' => $entity->name]),
                route('security.two-factor.verify'),
            );
        }

        return $next($request);
    }

    private function blockOrRedirect(Request $request, string $message, string $url): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message'        => $message,
                'two_factor_url' => $url,
            ], 423); // 423 Locked — comunica claramente que precisa de setup/verify
        }

        return redirect()->to($url);
    }
}
