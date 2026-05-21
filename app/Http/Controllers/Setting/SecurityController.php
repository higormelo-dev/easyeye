<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\{Auth, Gate};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Configurações de segurança da empresa (entity) ativa.
 *
 * Quem pode acessar:
 *  - Admin da entity (gate 'entity.manage-users' ou equivalente).
 *  - Admin SaaS (saas.admin), que pode operar sobre QUALQUER entity via
 *    impersonação ou via rota do Manager.
 *
 * Decisões aqui são auditadas com reason obrigatório:
 *  - Ativar 2FA obrigatório → exige reason (LGPD).
 *  - Desativar 2FA obrigatório → exige reason (perigoso, regredimos
 *    a postura de segurança da empresa).
 *
 * Comportamento ao ativar:
 *  - Imediatamente força os usuários no próximo request a setup/verify.
 *  - O admin que ativa, se ainda não tem 2FA, é redirecionado para
 *    setup no próximo request (middleware EnsureTwoFactor cuida).
 */
class SecurityController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Tela de configurações de segurança da empresa atual.
     */
    public function index(Request $request): InertiaResponse
    {
        $entity = $this->resolveActiveEntity($request);

        Gate::authorize(EntityGate::ManageSecuritySettings->value, $entity);

        return Inertia::render('Panel/Settings/Security/Index', [
            'entity' => [
                'id'                    => $entity->id,
                'name'                  => $entity->name,
                'code'                  => $entity->code,
                'requires_two_factor'   => (bool) $entity->requires_two_factor,
                'two_factor_enabled_at' => $entity->two_factor_enabled_at?->format('d/m/Y H:i'),
                'two_factor_enabled_by' => $entity->twoFactorEnabledByUser?->name,
            ],
            'currentUser' => [
                'has_two_factor' => Auth::user()?->hasTwoFactorEnabled() ?? false,
                'setup_url'      => route('security.two-factor.setup'),
            ],
            't' => trans('manager_hardening'),
        ]);
    }

    /**
     * Liga/desliga a exigência de 2FA para todos os usuários da empresa.
     * Exige reason por LGPD/CFM — auditor precisa entender a decisão.
     */
    public function toggleTwoFactor(Request $request): JsonResponse|RedirectResponse
    {
        $entity = $this->resolveActiveEntity($request);

        Gate::authorize(EntityGate::ManageSecuritySettings->value, $entity);

        $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason'  => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $enabled = $request->boolean('enabled');
        $reason  = trim((string) $request->input('reason'));

        $oldEnabled = (bool) $entity->requires_two_factor;

        // Idempotência: ignorar se já está no estado pedido.
        if ($oldEnabled === $enabled) {
            return $this->respond($request, [
                'message' => $enabled
                    ? __('manager_hardening.entity_2fa_enabled')
                    : __('manager_hardening.entity_2fa_disabled'),
            ]);
        }

        $entity->update([
            'requires_two_factor'   => $enabled,
            'two_factor_enabled_at' => $enabled ? now() : null,
            'two_factor_enabled_by' => $enabled ? Auth::id() : null,
        ]);

        // Audit estruturado com reason — chave para responder à pergunta
        // "por que essa empresa virou opt-in/out de 2FA, e quem decidiu?".
        $this->audit->recordAdminAction(
            event: $enabled ? 'entity.two_factor.enable' : 'entity.two_factor.disable',
            targetEntityId: (string) $entity->id,
            targetUserId: null,
            auditableType: 'entity',
            auditableId: (string) $entity->id,
            reason: $reason,
            newValues: ['requires_two_factor' => $enabled],
            request: $request,
            oldValues: ['requires_two_factor' => $oldEnabled],
        );

        return $this->respond($request, [
            'message' => $enabled
                ? __('manager_hardening.entity_2fa_enabled')
                : __('manager_hardening.entity_2fa_disabled'),
        ]);
    }

    /**
     * Resolve a entity da sessão (clínica logada).
     * Para o admin SaaS impersonando, retorna a entity da clínica impersonada.
     */
    private function resolveActiveEntity(Request $request): Entity
    {
        $entityId = $request->session()->get('selected_entity_id');

        abort_if($entityId === null, 403);

        return Entity::query()->with('twoFactorEnabledByUser')->findOrFail($entityId);
    }

    private function respond(Request $request, array $payload): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson() || $request->hasHeader('X-Inertia')) {
            return response()->json($payload);
        }

        return back()->with('success', $payload['message'] ?? '');
    }
}
