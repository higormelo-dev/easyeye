<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

use Illuminate\Support\Facades\DB;

/**
 * As travas do recall que mais de um caminho toma. Ordem global (ADR 0002, item 7): …→ paciente (avaliação) →
 * condições → recalls (id crescente) → convites de recall; os convites de WhatsApp tomam a da CLÍNICA antes
 * de tudo (clinicInvites). Quem escreve linha de retorno de um paciente (o
 * avaliador, uma ação da equipe, o convite) toma a trava do PACIENTE primeiro, antes de ler qualquer coisa:
 * duas escritas do mesmo paciente nunca se cruzam, e o que uma lê é o que a outra já gravou. O mesmo vale para o curso
 * de injeções (execução, abertura, encerramento e o agendamento da próxima aplicação).
 *
 * A cota do encaixe da Agenda vem ANTES desta (cota → paciente do recall → linhas): é a ordem de `store()` ao atender um
 * retorno, e quem precisa das duas (o agendamento da próxima aplicação do curso) toma a cota primeiro.
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

    /**
     * Trava de transação por CLÍNICA para o mapeamento de exames: salvar troca o CONJUNTO inteiro, e um `FOR UPDATE`
     * sobre as linhas que existem não trava nada quando não existe nenhuma (a 1ª configuração) — duas trocas
     * simultâneas liam o conjunto vazio, as iguais batiam no índice único e as disjuntas deixavam a UNIÃO gravada. Quem
     * lê o conjunto para substituí-lo (`ExamMappingService::sync`) toma esta ANTES de ler qualquer coisa; só ele a toma e
     * ela não espera nenhuma outra, então não participa de ciclo. O avaliador lê o mapeamento sem trava (a conta do
     * paciente), e o que `sync` grava marca a clínica na outbox DEPOIS desta.
     */
    public static function examMappings(string $entityId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', ['easyeye.recall.exam_mappings', $entityId]);
        }
    }
}
