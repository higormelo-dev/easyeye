<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\{Entity, EntityUser};
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Gerencia sessões de "usar como este" (impersonação) no painel do SaaS.
 *
 * Apenas admin e support da entity SaaS podem iniciar uma sessão.
 * O usuário pode encerrar a qualquer momento e retorna ao seu contexto original.
 *
 * Rotas:
 *   POST   /panel/manager/entities/{entity}/impersonate/{entityUser}  → store()
 *   DELETE /panel/manager/impersonate                                  → destroy()
 */
class ImpersonateController extends Controller
{
    /**
     * Inicia a sessão de impersonação.
     *
     * O EntityUser recebido é o vínculo do usuário-alvo na entity cliente.
     * O gate verifica que o solicitante é admin ou support da entity SaaS.
     */
    public function store(Entity $entity, EntityUser $entityUser): RedirectResponse
    {
        // Impersonação aninhada não é permitida
        if (session()->has('impersonating')) {
            return back()->withErrors([
                'impersonate' => __('actions.impersonate.already_active'),
            ]);
        }

        // Só entities clientes podem ter usuários impersonados
        abort_if($entity->isSaas(), 403, __('actions.impersonate.only_clients'));

        // O EntityUser deve pertencer à entity da rota (segurança)
        abort_if((string) $entityUser->entity_id !== (string) $entity->id, 404);

        // O vínculo deve estar ativo
        abort_if(! $entityUser->active, 403, __('actions.impersonate.user_inactive'));

        // O usuário real (ainda não houve troca) deve ser admin/support do SaaS
        // A SaaS entity é a entity ativa na sessão atual (antes da impersonação)
        $saasEntity = Entity::findOrFail(session('selected_entity_id'));

        Gate::authorize(EntityGate::SaasImpersonate->value, $saasEntity);

        // ── Salva o contexto original ────────────────────────────────────────
        session()->put('impersonating', [
            'entity_user_id'            => $entityUser->id,
            'impersonated_user_name'    => $entityUser->user->name,
            'impersonated_entity_name'  => $entity->name,
            'original_user_id'          => auth()->id(),
            'original_entity_user_id'   => session('selected_entity_user_id'),
            'original_entity_id'        => session('selected_entity_id'),
            'original_entity_user_rule' => session('selected_entity_user_rule'),
            'original_entity_is_client' => session('selected_entity_is_client'),
            'original_user_rule'        => session('user_rule'),
        ]);

        // ── Troca o contexto de sessão para a entity cliente ─────────────────
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
    public function destroy(): RedirectResponse
    {
        if (! session()->has('impersonating')) {
            return redirect()->route('panel.dashboard');
        }

        $original = session('impersonating');

        // ── Restaura o contexto do usuário real ──────────────────────────────
        session([
            'selected_entity_id'        => $original['original_entity_id'],
            'selected_entity_user_id'   => $original['original_entity_user_id'],
            'selected_entity_user_rule' => $original['original_entity_user_rule'],
            'selected_entity_is_client' => $original['original_entity_is_client'],
            'user_rule'                 => $original['original_user_rule'],
        ]);

        session()->forget('impersonating');

        return redirect()
            ->route('panel.manager.entities.index')
            ->with('success', __('actions.impersonate.ended'));
    }
}
