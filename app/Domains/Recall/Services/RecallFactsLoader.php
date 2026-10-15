<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Engine\RecallFacts;
use App\Domains\Recall\Models\RecallExamMapping;
use App\Enums\{Eye, ScheduleSituation};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lê o prontuário de UM paciente e o devolve na forma que o motor entende (RecallFacts): só datas e olhos.
 * Sempre com `entity_id` explícito e sem apagados — o escopo de tenant é inerte em job e CLI, então nada aqui
 * confia nele. `patient_exams` não tem `entity_id`: a posse vem do paciente (quem chama já o conferiu na
 * clínica).
 */
final class RecallFactsLoader
{
    /**
     * @return array{facts: RecallFacts, follow_up: array{on: ?CarbonImmutable, days: ?int}, open_schedules: list<array{id: string, date: CarbonImmutable}>}
     */
    public function load(string $entityId, string $patientId, CarbonImmutable $today): array
    {
        $consultations = $this->consultations($entityId, $patientId, $today);

        return [
            'facts' => new RecallFacts(array_column($consultations, 'on'), $this->exams($entityId, $patientId, $today)),
            // O retorno que o médico pediu vale para o ATENDIMENTO MAIS RECENTE.
            'follow_up'      => $consultations === [] ? ['on' => null, 'days' => null] : ['on' => $consultations[0]['on'], 'days' => $consultations[0]['follow_up_days']],
            'open_schedules' => $this->openSchedules($entityId, $patientId, $today),
        ];
    }

    /**
     * Atendimentos (prontuários não apagados), do mais recente para o mais antigo. A data do atendimento é a do
     * agendamento vinculado (o dia em que o paciente foi atendido) e, sem agendamento — ou com um agendamento ainda
     * por vir —, a de criação do prontuário. Atendimento com data no futuro nunca conta como feito.
     *
     * @return list<array{on: CarbonImmutable, follow_up_days: ?int}>
     */
    private function consultations(string $entityId, string $patientId, CarbonImmutable $today): array
    {
        $rows = DB::table('medical_records as mr')
            ->leftJoin('schedules as s', static function ($join): void {
                $join->on('s.id', '=', 'mr.schedule_id')->whereNull('s.deleted_at');
            })
            ->where('mr.entity_id', $entityId)
            ->where('mr.patient_id', $patientId)
            ->whereNull('mr.deleted_at')
            ->get(['s.date_time as scheduled_at', 'mr.created_at', 'mr.follow_up_days']);

        $consultations = [];

        foreach ($rows as $row) {
            $on = $row->scheduled_at !== null ? CarbonImmutable::parse($row->scheduled_at)->startOfDay() : null;

            if ($on === null || $on->greaterThan($today)) {
                $on = CarbonImmutable::parse($row->created_at)->startOfDay();
            }

            if ($on->greaterThan($today)) {
                continue;
            }

            $consultations[] = ['on' => $on, 'follow_up_days' => $row->follow_up_days !== null ? (int) $row->follow_up_days : null, 'created_at' => (string) $row->created_at];
        }

        // Mais recente primeiro; no mesmo dia, o prontuário criado por último (é o que carrega o retorno pedido).
        usort($consultations, static fn (array $a, array $b): int => [$b['on']->timestamp, $b['created_at']] <=> [$a['on']->timestamp, $a['created_at']]);

        return array_map(static fn (array $row): array => ['on' => $row['on'], 'follow_up_days' => $row['follow_up_days']], $consultations);
    }

    /**
     * Exames ATIVOS do paciente nos tipos que a clínica mapeou para uma chave canônica. Um tipo pode valer para
     * mais de uma chave (um "OCT" que serve a mácula e ao nervo óptico): vira um fato por chave.
     *
     * @return list<array{key: string, eye: Eye, date: CarbonImmutable}>
     */
    private function exams(string $entityId, string $patientId, CarbonImmutable $today): array
    {
        $keysByType = RecallExamMapping::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->get(['exam_type_id', 'exam_key'])
            ->groupBy('exam_type_id')
            ->map(static fn ($rows): array => $rows->pluck('exam_key')->all());

        if ($keysByType->isEmpty()) {
            return [];
        }

        $rows = DB::table('patient_exams')
            ->where('patient_id', $patientId)
            ->where('active', true)
            ->whereIn('exam_id', $keysByType->keys()->all())
            ->selectRaw('exam_id, laterality, COALESCE(exam_performed_at, created_at) as performed_at')
            ->get();

        $facts = [];

        foreach ($rows as $row) {
            $date = CarbonImmutable::parse($row->performed_at)->startOfDay();

            if ($date->greaterThan($today)) {
                continue;
            }

            foreach ($keysByType->get($row->exam_id, []) as $key) {
                $facts[] = ['key' => (string) $key, 'eye' => Eye::fromLaterality($row->laterality !== null ? (int) $row->laterality : null), 'date' => $date];
            }
        }

        return $facts;
    }

    /**
     * Os agendamentos ABERTOS do paciente (de hoje em diante, ainda não atendidos, faltados nem cancelados), do mais
     * próximo ao mais distante.
     *
     * @return list<array{id: string, date: CarbonImmutable}>
     */
    private function openSchedules(string $entityId, string $patientId, CarbonImmutable $today): array
    {
        return DB::table('schedules')
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->whereNull('deleted_at')
            ->whereNotIn('situation', [ScheduleSituation::Attended->value, ScheduleSituation::NoShow->value, ScheduleSituation::Cancelled->value])
            ->where('date_time', '>=', $today)
            ->orderBy('date_time')
            ->limit(20)
            ->get(['id', 'date_time'])
            ->map(static fn ($row): array => ['id' => (string) $row->id, 'date' => CarbonImmutable::parse($row->date_time)->startOfDay()])
            ->all();
    }
}
