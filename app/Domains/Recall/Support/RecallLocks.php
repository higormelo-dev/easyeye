<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

use Illuminate\Support\Facades\DB;

/**
 * As travas do recall que mais de um caminho toma. Ordem global (ADR 0002, item 7): …→ paciente (avaliação) →
 * condições → recalls (id crescente) → convites de recall; os convites de WhatsApp tomam a da CLÍNICA antes
 * de tudo (clinicInvites). Quem escreve linha de retorno de um paciente (o
 * avaliador, uma ação da equipe, o convite) toma a trava do PACIENTE primeiro, antes de ler qualquer coisa:
 * duas escritas do mesmo paciente nunca se cruzam, e o que uma lê é o que a outra já gravou.
 */
final class RecallLocks
{
    /** Trava de transação por paciente (só PostgreSQL; nos demais bancos a transação basta). Re-entrante na sessão. */
    public static function patient(string $patientId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', ['easyeye.recall.patient', $patientId]);
        }
    }

    /**
     * Trava de transação por CLÍNICA para os convites de WhatsApp: o teto diário e o ramp-up são da clínica inteira,
     * então duas ondas simultâneas (rodada automática e clique de uma pessoa) não podem contar o mesmo "hoje". Vem
     * ANTES da trava do paciente (clínica → paciente); o avaliador e as ações da equipe só tomam a do paciente, então
     * não há ciclo.
     */
    public static function clinicInvites(string $entityId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', ['easyeye.recall.invites', $entityId]);
        }
    }
}
