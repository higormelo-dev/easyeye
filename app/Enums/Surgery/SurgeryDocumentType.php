<?php

declare(strict_types=1);

namespace App\Enums\Surgery;

/**
 * Tipo de documento congelado do módulo cirúrgico (surgery_document_issues).
 */
enum SurgeryDocumentType: string
{
    case Consent        = 'consent';
    case PreOpGuidance  = 'pre_op_guidance';
    case ImplantRecord  = 'implant_record';
    case PostOpGuidance = 'post_op_guidance';
    case Discharge      = 'discharge';

    public function label(): string
    {
        return __('surgery_enums.surgery_document_type.' . $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> valor => rótulo no idioma atual */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** Só o ato do médico emite estes (os demais a secretária também prepara). */
    public function requiresDoctor(): bool
    {
        return in_array($this, [self::ImplantRecord, self::Discharge], true);
    }
}
