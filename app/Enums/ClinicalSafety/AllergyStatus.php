<?php

declare(strict_types=1);

namespace App\Enums\ClinicalSafety;

/**
 * Situação da alergia (A1): a recepção só registra "informada pelo
 * paciente"; o médico com cadastro ativo confirma ou descarta. Informada e
 * confirmada são ATIVAS (alertam e entram na checagem da receita);
 * descartada fica só no histórico.
 */
enum AllergyStatus: string
{
    case Reported  = 'reported';
    case Confirmed = 'confirmed';
    case Discarded = 'discarded';

    public function label(): string
    {
        return __('clinical_safety.allergy_statuses.' . $this->value);
    }

    public function isActive(): bool
    {
        return $this !== self::Discarded;
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Reported->value, self::Confirmed->value];
    }
}
