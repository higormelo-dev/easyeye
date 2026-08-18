<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Enums\AI\AiRunMode;
use App\Enums\{ClientRule, FeatureKey};
use App\Services\FeatureGateService;

/**
 * Constrói o prop `aiAssistant` compartilhado globalmente via Inertia
 * (HandleInertiaRequests) para o widget flutuante do Assistente Virtual.
 *
 * Diferente de `MedicalRecordsController::buildAiProps()` /
 * `EyeImagesController` (que montavam o bloco `ai` manualmente e duplicado
 * em cada controller — só para os workflows record_assist/eye_image_analysis
 * daquela tela específica), este serviço é a ÚNICA fonte para o widget global,
 * que precisa estar disponível em QUALQUER tela do painel (Agenda, Pacientes,
 * Gerenciador de Imagens, Prontuário, ...) sem cada controller replicar a
 * lógica de feature-gate + urls + labels.
 */
class AiAssistantWidgetPropsBuilder
{
    public function __construct(
        private readonly FeatureGateService $featureGate,
        private readonly AiCreditWalletService $wallet,
        private readonly AiQuotaService $quotaService,
        private readonly AiProviderSettings $providerSettings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $entityId, ?string $userRule): array
    {
        // Escopo: assistente é ferramenta médica (dose/conduta/laudo) — mesmo
        // recorte de "isDoctor" já usado no prontuário e no AiAssistantPanel
        // inline. Outros perfis (secretária/financeiro/admin) não veem o widget.
        if ($userRule !== ClientRule::Doctor->value) {
            return ['enabled' => false];
        }

        if (! $this->featureGate->can($entityId, FeatureKey::HasAiChatAssistant)) {
            return ['enabled' => false];
        }

        // BUGFIX: 'economy' NÃO é sempre válido — com 2+ provedores habilitados
        // (padrão de config: openai+anthropic+gemini), AiProviderSettings::
        // availableModes() exclui Economy e exige no mínimo Validated (regra de
        // negócio já existente, mesma usada pelo painel estruturado). Hardcode
        // de 'economy' aqui derrubaria toda mensagem do chat com 422
        // "Este modo de IA não está disponível no plano atual." Usa sempre o
        // modo mais barato REALMENTE disponível.
        $availableModes = $this->providerSettings->availableModes();
        $mode           = ($availableModes[0] ?? AiRunMode::Economy)->value;

        return [
            'enabled'  => true,
            'balance'  => $this->wallet->balance($entityId),
            'quota'    => $this->quotaService->currentMonthSnapshot($entityId),
            'workflow' => 'assistant_chat',
            'mode'     => $mode,
            'urls'     => [
                'store'   => route('panel.ai-runs.store'),
                'show'    => route('panel.ai-runs.show', ['aiRun' => '__ID__']),
                'approve' => route('panel.ai-runs.approve', ['aiRun' => '__ID__']),
            ],
            't' => trans('ai.chat_widget'),
        ];
    }
}
