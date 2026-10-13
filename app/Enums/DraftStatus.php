<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Situação de um rascunho de laudo de imagem (Fase 5 — fluxo
 * secretária-rascunha/médico-emite). Emitir não muda este enum: o rascunho
 * só sai de Draft/Submitted pra Issued; quem assina de fato é sempre
 * MedicalRecordDocumentation (inalterado), nunca o rascunho.
 */
enum DraftStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Issued    = 'issued';
    case Discarded = 'discarded';

    public function label(): string
    {
        return __('eye_images.draft_status_' . $this->value);
    }
}
