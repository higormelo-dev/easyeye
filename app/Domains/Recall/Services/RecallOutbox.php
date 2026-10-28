<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A OUTBOX do recall (ADR 0002, item 7): o ato clínico que pode mudar um retorno (atendimento, exame, agendamento,
 * medicação, paciente) marca o paciente em `recall_dirty_patients` na MESMA transação em que grava — então a marca
 * nasce e morre junto com o ato (rollback desfaz as duas). Quem reavalia é `recall:evaluate-dirty`, em segundo plano.
 *
 * Marcar NUNCA pode quebrar o fluxo clínico: o INSERT roda num savepoint e qualquer falha é reportada e engolida (a
 * conciliação noturna cobre o que a marca deixar de pegar). Idempotente: marcar de novo só atualiza a hora.
 */
final class RecallOutbox
{
    public static function mark(string $entityId, string $patientId, string $reason, ?CarbonInterface $at = null): void
    {
        try {
            DB::transaction(static function () use ($entityId, $patientId, $reason, $at): void {
                DB::statement(
                    'INSERT INTO recall_dirty_patients (entity_id, patient_id, reason, marked_at) VALUES (?, ?, ?, ?) '
                    . 'ON CONFLICT (entity_id, patient_id) DO UPDATE SET reason = EXCLUDED.reason, marked_at = EXCLUDED.marked_at',
                    [$entityId, $patientId, mb_substr($reason, 0, 30), $at ?? now()],
                );
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Marca todo paciente que tem retorno (aberto ou adiado) calculado por esta VERSÃO do protocolo: ela saiu de uso
     * (suspensa, aposentada) ou voltou. `rule_suspended` é persistido e as telas de Retornos, a ação da equipe e o
     * agendamento leem esse flag; sem esta marca ele só mudava na próxima ação do paciente ou na conciliação da noite.
     * Roda na transação da governança (a marca nasce e morre junto com a mudança de estado).
     */
    public static function markVersion(string $versionId, string $reason): void
    {
        try {
            DB::transaction(static function () use ($versionId, $reason): void {
                $rows = DB::table('patient_recalls')
                    ->where('protocol_version_id', $versionId)
                    ->whereIn('lifecycle', ['open', 'snoozed'])
                    // Sem DISTINCT de propósito: ele fixa os parâmetros como `text` e o INSERT … SELECT recusa a coluna
                    // de data (o erro seria engolido abaixo). Repetidos (vários retornos do mesmo paciente) o
                    // `ON CONFLICT DO NOTHING` do insertOrIgnoreUsing já descarta.
                    ->selectRaw('entity_id, patient_id, ?, ?', [mb_substr($reason, 0, 30), now()]);

                DB::table('recall_dirty_patients')->insertOrIgnoreUsing(['entity_id', 'patient_id', 'reason', 'marked_at'], $rows);
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Marca a clínica inteira (mudou a adoção do protocolo, o mapeamento de exames ou a configuração): todo
     * paciente que PODE ter retorno (RecallCandidates) entra na fila. Quem já estava marcado fica como está.
     */
    public static function markClinic(string $entityId, string $reason): void
    {
        try {
            DB::transaction(static function () use ($entityId, $reason): void {
                $candidates = RecallCandidates::patients($entityId)->selectRaw('p.entity_id, p.id, ?, ?', [mb_substr($reason, 0, 30), now()]);

                DB::table('recall_dirty_patients')->insertOrIgnoreUsing(['entity_id', 'patient_id', 'reason', 'marked_at'], $candidates);
            });
        } catch (Throwable $e) {
            report($e);
        }
    }
}
