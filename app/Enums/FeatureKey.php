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
    case MaxUsers    = 'max_users';
    case MaxPatients = 'max_patients';
    case MaxDoctors  = 'max_doctors';

    // Features booleanas
    case HasAiExamAssistant   = 'has_ai_exam_assistant';
    case HasAiReportDrafting  = 'has_ai_report_drafting';

    // Limites mensais de créditos IA (integer, 0 = ilimitado)
    case AiMonthlyCredits = 'ai_monthly_credits';

    public function label(): string
    {
        return match ($this) {
            self::MaxUsers           => __('subscriptions.features.max_users'),
            self::MaxPatients        => __('subscriptions.features.max_patients'),
            self::MaxDoctors         => __('subscriptions.features.max_doctors'),
            self::HasAiExamAssistant => __('subscriptions.features.has_ai_exam_assistant'),
            self::HasAiReportDrafting=> __('subscriptions.features.has_ai_report_drafting'),
            self::AiMonthlyCredits   => __('subscriptions.features.ai_monthly_credits'),
        };
    }

    /** True = chave representa um valor booleano (0/1). */
    public function isBoolean(): bool
    {
        return in_array($this, [
            self::HasAiExamAssistant,
            self::HasAiReportDrafting,
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
        return in_array($this, [self::AiMonthlyCredits]);
    }
}
