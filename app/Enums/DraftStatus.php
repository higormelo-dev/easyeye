<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Situação de um rascunho de laudo de imagem (Fase 5 — fluxo
 * secretária-rascunha/médico-emite). Emitir não muda este enum: o rascunho
 * só sai de Draft/Submitted/Returned pra Issued; quem assina de fato é sempre
 * MedicalRecordDocumentation (inalterado), nunca o rascunho.
 *
 * Returned: o médico devolveu ao técnico com um motivo (Submitted → Returned);
 * o técnico corrige e reenvia (Returned → Submitted).
 */
enum DraftStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Returned  = 'returned';
    case Issued    = 'issued';
    case Discarded = 'discarded';

    public function label(): string
    {
        return __('eye_images.draft_status_' . $this->value);
    }
}
