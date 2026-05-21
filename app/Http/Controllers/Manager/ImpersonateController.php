<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\{Entity, EntityUser};
use App\Services\Audit\AuditLogger;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\Gate;

/**
 * Gerencia sessões de "usar como este" (impersonação) no painel do SaaS.
 *
 * Hardening LGPD/CFM:
 *  - Toda transição (start/end) emite evento estruturado em `audit_logs`
 *    via AuditLogger, capturando ator real (admin), alvo (paciente do
 *    sistema impersonado), entity da clínica e contexto da sessão.
 *  - Impersonação aninhada bloqueada.
 *  - Só entities clientes podem ser impersonadas (não a SaaS).
 */
class ImpersonateController extends Controller
{
    public function __construct(
        protected AuditLogger $audit,
    ) {
    }

    /**
     * Inicia a sessão de impersonação. O EntityUser recebido é o vínculo
     * do usuário-alvo na entity cliente. O gate verifica que o solicitante
     * é admin ou support da entity SaaS.
     */
    public function store(Entity $entity, EntityUser $entityUser, Request $request): RedirectResponse
    {
        if (session()->has('impersonating')) {
            return back()->withErrors([
                'impersonate' => __('actions.impersonate.already_active'),
            ]);
        }

        abort_if($entity->isSaas(), 403, __('actions.impersonate.only_clients'));
        abort_if((string) $entityUser->entity_id !== (string) $entity->id, 404);
        abort_if(! $entityUser->active, 403, __('actions.impersonate.user_inactive'));

        // O usuário real (ainda não houve troca) deve ser admin/support do SaaS
        $saasEntity = Entity::findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::SaasImpersonate->value, $saasEntity);

        // ── AUDIT: registra início antes da troca de contexto ───────────────
        // Carregamos o relacionamento user explicitamente para garantir que
        // o nome do impersonado esteja no log mesmo após a troca de sessão.
        $entityUser->loadMissing('user');
        $this->audit->recordImpersonationStart($entity, $entityUser, $request);

        session()->forget('url.intended');

        session()->put('impersonating', [
            'entity_user_id'            => $entityUser->id,
            'impersonated_user_name'    => $entityUser->user->name,
            'impersonated_entity_name'  => $entity->name,
            'original_user_id'          => auth()->id(),
            'original_user_name'        => auth()->user()->name,
            'original_entity_user_id'   => session('selected_entity_user_id'),
            'original_entity_id'        => session('selected_entity_id'),
            'original_entity_user_rule' => session('selected_entity_user_rule'),
            'original_entity_is_client' => session('selected_entity_is_client'),
            'original_user_rule'        => session('user_rule'),
        ]);

        session([
            'selected_entity_id'        => $entity->id,
            'selected_entity_user_id'   => $entityUser->id,
            'selected_entity_user_rule' => $entityUser->rule,
            'selected_entity_is_client' => true,
            'user_rule'                 => $entityUser->rule,
        ]);

        return redirect()
            ->route('panel.dashboard')
            ->with('success', __('actions.impersonate.started', [
                'name' => $entityUser->user->name,
            ]));
    }

    /**
     * Encerra a sessão de impersonação e restaura o contexto original.
     */
    public function destroy(Request $request): RedirectResponse
    {
        if (! session()->has('impersonating')) {
            return redirect()->route('panel.dashboard');
        }

        $original = session('impersonating');

        // Restaura o contexto do usuário real ANTES do audit log, para que
        // o evento de fim seja registrado com o ator correto (admin real).
        session([
            'selected_entity_id'        => $original['original_entity_id'],
            'selected_entity_user_id'   => $original['original_entity_user_id'],
            'selected_entity_user_rule' => $original['original_entity_user_rule'],
            'selected_entity_is_client' => $original['original_entity_is_client'],
            'user_rule'                 => $original['original_user_rule'],
        ]);

        // ── AUDIT: registra fim depois da restauração ───────────────────────
        $this->audit->recordImpersonationEnd($original, $request);

        session()->forget('impersonating');

        return redirect()
            ->route('manager.entities.index')
            ->with('success', __('actions.impersonate.ended'));
    }
}
