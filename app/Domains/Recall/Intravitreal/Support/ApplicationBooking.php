<?php

declare(strict_types=1);

namespace App\Domains\Recall\Intravitreal\Support;

use App\Domains\Recall\Intravitreal\Models\IntravitrealApplication;
use App\Enums\ScheduleSituation;
use App\Models\Schedule;
use App\Models\Scopes\EntityScope;
use Carbon\CarbonImmutable;

/**
 * A reserva da Agenda que uma aplicação planejada guarda (`schedule_id`) ainda VALE? Cancelar ou marcar falta no
 * agendamento não mexe na aplicação, então o id fica para trás: sem esta conferência a tela mostrava "agendada" para
 * sempre (e escondia o botão de agendar) e o agendamento da próxima era recusado como "já agendada" para sempre.
 *
 * Vale a reserva que existe (sem exclusão), é DESTE paciente e não foi cancelada nem faltada. Atendido vale: o
 * paciente veio e o registro da injeção é do prontuário. Que seja do paciente importa tanto quanto existir: a recepção
 * corrige o cadastro de um agendamento passando-o a outra pessoa SEM cancelá-lo, e ele continua ocupando a agenda — mas
 * já não é a reserva da aplicação deste paciente (a tela dele mostrava "agendada" e o agendamento da próxima era
 * recusado, sem ninguém reservado).
 *
 * A DATA também é da reserva: remarcar na Agenda muda só o agendamento, e a aplicação guarda a data de quando foi
 * reservada. Quem mostra ou calcula prazo usa o dia da reserva viva (`dayOf`) e, sem ela, o que está gravado na
 * aplicação/curso. É uma leitura; nada é gravado — o `schedule_id` velho só é trocado quando uma nova reserva é feita
 * (IntravitrealApplicationService::scheduleNext).
 */
final class ApplicationBooking
{
    /** @var list<ScheduleSituation> */
    private const RELEASED = [ScheduleSituation::Cancelled, ScheduleSituation::NoShow];

    public static function isLive(string $entityId, string $patientId, string $scheduleId): bool
    {
        return isset(self::live($entityId, $patientId, [$scheduleId])[$scheduleId]);
    }

    /**
     * Dos ids dados, as reservas que ainda são uma reserva viva DESTE paciente nesta clínica, com o dia de cada uma.
     *
     * @param iterable<string> $scheduleIds
     *
     * @return array<string, CarbonImmutable> id => início do dia da reserva
     */
    public static function live(string $entityId, string $patientId, iterable $scheduleIds): array
    {
        $ids = [];

        foreach ($scheduleIds as $id) {
            $ids[(string) $id] = true;
        }

        if ($ids === []) {
            return [];
        }

        // Só o escopo de tenant sai (depende da sessão): a exclusão (soft delete) continua valendo.
        return Schedule::query()->withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->whereIn('id', array_keys($ids))
            ->whereNotIn('situation', array_map(static fn (ScheduleSituation $situation): int => $situation->value, self::RELEASED))
            ->get(['id', 'date_time'])
            ->mapWithKeys(static fn (Schedule $schedule): array => [(string) $schedule->id => CarbonImmutable::parse($schedule->date_time)->startOfDay()])
            ->all();
    }

    /**
     * O dia que a reserva viva da aplicação PLANEJADA marca, ou nulo (sem reserva, ou a reserva já não vale).
     *
     * @param array<string, CarbonImmutable> $live o resultado de `live()` para as reservas das aplicações
     */
    public static function dayOf(?IntravitrealApplication $planned, array $live): ?CarbonImmutable
    {
        return $planned?->schedule_id !== null ? ($live[(string) $planned->schedule_id] ?? null) : null;
    }
}
