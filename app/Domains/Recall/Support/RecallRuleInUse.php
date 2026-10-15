<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

use App\Domains\Recall\Models\{PatientRecall, RecallClinicProtocol};

/**
 * A regra que calculou este retorno vale AGORA? `patient_recalls.rule_suspended` é derivado: o avaliador o liga quando a
 * versão do protocolo sai de uso (suspensa, aposentada, sem integridade) e o desliga na retomada, mas só quando reavalia
 * o paciente (a outbox da governança o chama em poucos minutos). Contatar, adiar, dispensar ou agendar um retorno nessa
 * janela agiria sobre uma regra que a EasyEye já parou — então as ações da equipe e o agendamento conferem a versão VIVA
 * (a mesma conta do avaliador e do guard do convite: adoção ativa, versão em uso, íntegra e igual à adotada), além do flag.
 *
 * Retorno do médico e de curso intravítreo não têm protocolo: não há versão a conferir.
 */
final class RecallRuleInUse
{
    public static function for(PatientRecall $row): bool
    {
        if ($row->isDoctorOrder() || $row->isCourse() || $row->protocol_key === null) {
            return true;
        }

        $adoption = RecallClinicProtocol::query()->withoutGlobalScopes()
            ->where('entity_id', $row->entity_id)
            ->whereNull('deactivated_at')
            ->whereHas('protocol', static fn ($query) => $query->where('key', $row->protocol_key))
            ->with('version')
            ->first();

        return $adoption !== null && $adoption->inUse();
    }
}
