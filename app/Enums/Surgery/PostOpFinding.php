<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Achado/complicação marcável na avaliação pós-operatória (lista fixa).
 */
enum PostOpFinding: string
{
    case CornealEdema             = 'corneal_edema';
    case AnteriorChamberReaction  = 'anterior_chamber_reaction';
    case OcularHypertension       = 'ocular_hypertension';
    case CapsuleOpacification     = 'capsule_opacification';
    case IolDislocation           = 'iol_dislocation';
    case EndophthalmitisSuspected = 'endophthalmitis_suspected';
    case MacularEdema             = 'macular_edema';
    case Other                    = 'other';

    public function label(): string
    {
        return __('surgery_postop.enums.post_op_finding.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }

    /** Achado que exige retorno em 24 h e destaca o caso. */
    public function isSevere(): bool
    {
        return in_array($this, [self::EndophthalmitisSuspected, self::IolDislocation], true);
    }

    /** @return list<string> códigos graves */
    public static function severeValues(): array
    {
        return array_values(array_map(fn (self $f) => $f->value, array_filter(self::cases(), fn (self $f) => $f->isSevere())));
    }
}
