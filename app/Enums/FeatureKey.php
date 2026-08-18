<?php

namespace App\Enums;

/**
 * Chaves de features/limites de plano.
 *
 * Tipo integer: 0 significa ilimitado.
 * Tipo boolean: '1' = habilitado, '0' = desabilitado.
 */
enum FeatureKey: string
{
    // Limites quantitativos (integer, 0 = ilimitado)
    case MaxUsers     = 'max_users';
    case MaxPatients  = 'max_patients';
    case MaxDoctors   = 'max_doctors';
    case MaxStorageGB = 'max_storage_gb'; // Armazenamento total em GB (0 = ilimitado)

    // Features booleanas
    case HasAiExamAssistant    = 'has_ai_exam_assistant';
    case HasAiReportDrafting   = 'has_ai_report_drafting';
    case HasAiConsensus        = 'has_ai_consensus';
    case HasAiEyeImageAnalysis = 'has_ai_eye_image_analysis';
    // Assistente virtual flutuante (chat livre, disponível em qualquer tela do
    // painel) — independente das outras flags de IA porque é um produto à
    // parte (chat de apoio geral, não workflow de laudo/prontuário estruturado).
    case HasAiChatAssistant    = 'has_ai_chat_assistant';
    case HasApiIntegrator      = 'has_api_integrator';
    case HasOwnPaymentGateways = 'has_own_payment_gateways';

    // Limites mensais de créditos IA (integer, 0 = ilimitado)
    case AiMonthlyCredits = 'ai_monthly_credits';

    // Limite mensal de envios de exames pela API — store + update contam (integer, 0 = ilimitado)
    case ApiMonthlyExamSends = 'api_monthly_exam_sends';

    public function label(): string
    {
        return match ($this) {
            self::MaxUsers              => __('subscriptions.features.max_users'),
            self::MaxPatients           => __('subscriptions.features.max_patients'),
            self::MaxDoctors            => __('subscriptions.features.max_doctors'),
            self::MaxStorageGB          => __('subscriptions.features.max_storage_gb'),
            self::HasAiExamAssistant    => __('subscriptions.features.has_ai_exam_assistant'),
            self::HasAiReportDrafting   => __('subscriptions.features.has_ai_report_drafting'),
            self::HasAiConsensus        => __('subscriptions.features.has_ai_consensus'),
            self::HasAiEyeImageAnalysis => __('subscriptions.features.has_ai_eye_image_analysis'),
            self::HasAiChatAssistant    => __('subscriptions.features.has_ai_chat_assistant'),
            self::HasApiIntegrator      => __('subscriptions.features.has_api_integrator'),
            self::HasOwnPaymentGateways => __('subscriptions.features.has_own_payment_gateways'),
            self::AiMonthlyCredits      => __('subscriptions.features.ai_monthly_credits'),
            self::ApiMonthlyExamSends   => __('subscriptions.features.api_monthly_exam_sends'),
        };
    }

    /** True = chave representa um valor booleano (0/1). */
    public function isBoolean(): bool
    {
        return in_array($this, [
            self::HasAiExamAssistant,
            self::HasAiReportDrafting,
            self::HasAiConsensus,
            self::HasAiEyeImageAnalysis,
            self::HasAiChatAssistant,
            self::HasApiIntegrator,
            self::HasOwnPaymentGateways,
        ]);
    }

    /** True = chave representa um limite numérico (0 = ilimitado). */
    public function isNumeric(): bool
    {
        return ! $this->isBoolean();
    }

    /** True = limite deve ser redefinido mensalmente (créditos IA). */
    public function isMonthlyReset(): bool
    {
        return in_array($this, [self::AiMonthlyCredits, self::ApiMonthlyExamSends]);
    }
}
