<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\LockedMedicalRecordException;
use App\Models\MedicalRecord;

/**
 * Documento/anexo do prontuário (MedicalRecordDocumentation,
 * MedicalRecordFile) não é criado, alterado nem excluído quando o
 * prontuário dono está ASSINADO (CFM 2.227/2018 — Signable). Antes só a tela
 * desabilitava; a trava no model cobre todos os caminhos (quick actions,
 * documentação, tonometria, laudo do Gerenciador de Imagens, laudo de IA,
 * anexos). O LockedMedicalRecordException vira 403 traduzido
 * (bootstrap/app.php) quando o caller não trata.
 */
trait GuardedBySignedRecord
{
    public static function bootGuardedBySignedRecord(): void
    {
        $guard = static function ($model): void {
            $recordId = $model->medical_record_id ?? $model->getOriginal('medical_record_id');

            if ($recordId === null) {
                return;
            }

            $locked = (bool) MedicalRecord::query()
                ->withoutGlobalScopes()
                ->whereKey($recordId)
                ->value('is_locked');

            if ($locked) {
                throw new LockedMedicalRecordException(__('actions.medical_records.documentation_locked'));
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }
}
