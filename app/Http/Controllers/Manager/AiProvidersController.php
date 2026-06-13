<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Domains\AI\Services\AiProviderSettings;
use App\Enums\AI\{AiProvider, AiRunMode};
use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response as InertiaResponse};
use Ramsey\Uuid\Uuid;

/**
 * Configuração global (dono do SaaS) de QUAIS provedores de IA o sistema pode
 * usar e em que ORDEM de prioridade. A partir do conjunto ativo derivam-se os
 * papéis (gerador/revisor/adjudicador) e os modos disponíveis (economia/
 * validado/consenso). Persistido em system_settings via AiProviderSettings.
 */
class AiProvidersController extends Controller
{
    public function __construct(
        private readonly AiProviderSettings $providerSettings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): InertiaResponse
    {
        $this->authorizeSaas();

        return Inertia::render('Panel/Manager/AiProviders/Index', [
            'providers'    => $this->providerCards(),
            'modes'        => $this->modeCards(),
            'enabledCount' => $this->providerSettings->count(),
            't'            => trans('manager_ai'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeSaas();

        $allCodes = array_map(static fn (AiProvider $p) => $p->value, AiProvider::cases());

        $validated = $request->validate([
            'providers'   => ['present', 'array'],
            'providers.*' => ['string', 'distinct', Rule::in($allCodes)],
        ]);

        $codes = array_values($validated['providers']);

        if ($codes === []) {
            return response()->json(['message' => __('manager_ai.error_empty')], 422);
        }

        // Não permite habilitar provedor sem credencial/modelo configurados.
        $missing = array_values(array_filter(
            $codes,
            fn (string $code) => ! $this->providerSettings->isConfigured($code),
        ));

        if ($missing !== []) {
            $labels = implode(', ', array_map(
                static fn (string $c) => AiProvider::from($c)->label(),
                $missing,
            ));

            return response()->json([
                'message' => __('manager_ai.error_unconfigured', ['providers' => $labels]),
            ], 422);
        }

        $old = $this->providerSettings->enabledCodes();

        $this->providerSettings->setEnabledCodes($codes);

        $this->audit->recordAdminAction(
            event: 'manager.ai_providers.update',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'system_setting',
            // auditable_id é uuid; deriva um UUID estável da chave do setting.
            auditableId: Uuid::uuid5(Uuid::NAMESPACE_OID, AiProviderSettings::SETTING_KEY)->toString(),
            reason: __('manager_ai.audit_reason'),
            newValues: ['providers' => $codes],
            request: $request,
            oldValues: ['providers' => $old],
        );

        return response()->json([
            'message'      => __('manager_ai.saved'),
            'providers'    => $this->providerCards(),
            'modes'        => $this->modeCards(),
            'enabledCount' => $this->providerSettings->count(),
        ]);
    }

    /**
     * Lista de TODOS os provedores conhecidos com estado atual (ativo, ordem,
     * configurado, modelo). A ordem reflete a prioridade salva; provedores
     * inativos vão para o fim.
     *
     * @return list<array<string, mixed>>
     */
    private function providerCards(): array
    {
        $enabled = $this->providerSettings->enabledCodes();
        $order   = array_flip($enabled);

        $cards = array_map(function (AiProvider $p) use ($order): array {
            $code = $p->value;

            return [
                'code'       => $code,
                'label'      => $p->label(),
                'enabled'    => isset($order[$code]),
                'order'      => $order[$code] ?? null,
                'configured' => $this->providerSettings->isConfigured($code),
                'model'      => $this->providerSettings->model($code),
            ];
        }, AiProvider::cases());

        // Ativos primeiro (na ordem de prioridade), depois os inativos.
        usort($cards, static function (array $a, array $b): int {
            if ($a['enabled'] !== $b['enabled']) {
                return $a['enabled'] ? -1 : 1;
            }

            return ($a['order'] ?? PHP_INT_MAX) <=> ($b['order'] ?? PHP_INT_MAX);
        });

        return $cards;
    }

    /**
     * Modos com indicação de disponibilidade conforme o nº de provedores ativos.
     *
     * @return list<array<string, mixed>>
     */
    private function modeCards(): array
    {
        $labels = [
            AiRunMode::Economy->value   => __('manager_ai.mode_economy'),
            AiRunMode::Validated->value => __('manager_ai.mode_validated'),
            AiRunMode::Consensus->value => __('manager_ai.mode_consensus'),
        ];

        return array_map(fn (AiRunMode $mode) => [
            'value'     => $mode->value,
            'label'     => $labels[$mode->value],
            'available' => $this->providerSettings->isModeAvailable($mode),
            'needs'     => match ($mode) {
                AiRunMode::Economy   => 1,
                AiRunMode::Validated => 2,
                AiRunMode::Consensus => 3,
            },
        ], AiRunMode::cases());
    }

    private function authorizeSaas(): void
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::SaasAdminPanel->value, $entity);
    }
}
