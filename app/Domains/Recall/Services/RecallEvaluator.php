<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Engine\{RecallCondition, RecallFacts, RecallItem, RecallRuleEngine};
use App\Domains\Recall\Enums\{ConditionStatus, RecallLifecycle, RecallTaskKind};
use App\Domains\Recall\Intravitreal\Enums\{ApplicationStatus, CourseStatus};
use App\Domains\Recall\Intravitreal\Models\{IntravitrealApplication, IntravitrealCourse};
use App\Domains\Recall\Intravitreal\Support\ApplicationBooking;
use App\Domains\Recall\Models\{PatientCondition, PatientRecall, PatientRecallEvent, RecallClinicProtocol, RecallClinicSettings};
use App\Domains\Recall\Support\RecallLocks;
use App\Enums\Eye;
use App\Models\Scopes\EntityScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * O avaliador do recall (ADR 0002, item 7): para UM paciente de UMA clínica, lê o prontuário, aplica os protocolos
 * que a clínica adotou e grava o resultado em `patient_recalls` — idempotente (rodar duas vezes sem mudança não grava
 * nada) e sempre derivado: a tabela pode ser recalculada a qualquer hora.
 *
 * Tudo numa transação sob `pg_advisory_xact_lock` do paciente, TOMADO ANTES de ler: dois avaliadores (o evento e a
 * conciliação) nunca se cruzam, e o que um lê é o que o outro já gravou. O que a clínica decidiu à mão (adiar,
 * dispensar) sobrevive à reavaliação; só um ATO NOVO registrado (o ciclo avança) reabre o retorno.
 */
class RecallEvaluator
{
    /** Sobe quando uma mudança de código altera o resultado: a conciliação reavalia o que está em versão antiga. */
    public const VERSION = 1;

    public function __construct(
        private readonly RecallFactsLoader $loader,
        private readonly RecallRuleEngine $engine,
        private readonly ?ConditionDetector $detector = null,
    ) {
    }

    /**
     * @return array{created: int, updated: int, closed: int, unchanged: int}
     */
    public function evaluatePatient(string $entityId, string $patientId, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->startOfDay();

        return DB::transaction(function () use ($entityId, $patientId, $today): array {
            RecallLocks::patient($patientId);

            /** @var Collection<int, PatientRecall> $existing */
            $existing = PatientRecall::query()->withoutGlobalScopes()->where('entity_id', $entityId)->where('patient_id', $patientId)->get();

            if (! $this->patientExists($entityId, $patientId)) {
                return $this->closeAll($existing, 'patient_removed');
            }

            $adoptions = $this->adoptions($entityId);

            // As condições que o prontuário/medicação já sustentam entram ANTES da conta (mesma trava, mesma transação).
            $this->detectConditions($entityId, $patientId, $adoptions, $today);

            return $this->reconcile($existing, $this->plan($entityId, $patientId, $existing, $adoptions, $today), $entityId, $patientId, $today);
        });
    }

    /**
     * As adoções ativas da clínica, por chave de protocolo.
     *
     * @return Collection<string, RecallClinicProtocol>
     */
    private function adoptions(string $entityId): Collection
    {
        return RecallClinicProtocol::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->whereNull('deactivated_at')
            ->with(['protocol', 'version'])
            ->get()
            ->keyBy(static fn (RecallClinicProtocol $adoption): string => (string) $adoption->protocol->key);
    }

    /**
     * O conteúdo normalizado da versão que a clínica adotou, ou nulo quando a adoção NÃO pode calcular: versão
     * suspensa/aposentada/fora de uso, sem integridade ou diferente da que a clínica adotou (o freio de mão).
     *
     * @return array<string, mixed>|null
     */
    private function usableContent(RecallClinicProtocol $adoption): ?array
    {
        return $adoption->usableContent();
    }

    /**
     * @param Collection<string, RecallClinicProtocol> $adoptions
     */
    private function detectConditions(string $entityId, string $patientId, Collection $adoptions, CarbonImmutable $today): void
    {
        $contents = [];

        foreach ($adoptions as $key => $adoption) {
            $content = $this->usableContent($adoption);

            if ($content !== null) {
                $contents[(string) $key] = $content;
            }
        }

        ($this->detector ?? app(ConditionDetector::class))->detect($entityId, $patientId, $contents, $today);
    }

    // ── o que DEVERIA existir ───────────────────────────────────────────────

    /**
     * @param Collection<int, PatientRecall> $existing
     *
     * @return array{items: list<array{item: RecallItem, protocol_key: ?string, version_id: ?string}>, suspended: array<string, true>, unadopted: array<string, true>, active: array<string, true>, open_schedules: list<array{id: string, date: CarbonImmutable}>, course_bookings: array<string, string>, doctor_order_ended: ?string}
     */
    private function plan(string $entityId, string $patientId, Collection $existing, Collection $adoptions, CarbonImmutable $today): array
    {
        $settings = RecallClinicSettings::forEntity($entityId);
        $loaded   = $this->loader->load($entityId, $patientId, $today);
        $facts    = $this->withExternalActs($loaded['facts'], $existing);

        $conditions = PatientCondition::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->where('status', ConditionStatus::Active->value)
            ->get();

        $contents  = [];
        $items     = [];
        $suspended = [];
        $unadopted = [];
        $active    = [];
        $caps      = [];

        foreach ($conditions as $condition) {
            $active[(string) $condition->id] = true;

            $adoption = $adoptions->get($condition->protocol_key);

            if ($adoption === null) {
                // A clínica não adotou (ou desativou) este protocolo: nada a calcular; as linhas antigas se encerram.
                $unadopted[(string) $condition->id] = true;

                continue;
            }

            // Versão suspensa/aposentada, sem integridade ou diferente da que a clínica adotou: o freio de mão —
            // nenhuma regra nova é aplicada e as linhas existentes ficam paradas até a retomada.
            $content = $contents[(string) $adoption->id] ??= $this->usableContent($adoption);

            if ($content === null) {
                $suspended[(string) $condition->id] = true;

                continue;
            }

            $version = $adoption->version;

            $core = new RecallCondition(
                (string) $condition->id,
                (string) $condition->protocol_key,
                $condition->eye,
                $condition->started_on !== null ? CarbonImmutable::parse($condition->started_on)->startOfDay() : null,
                $content,
                (array) $adoption->parameters,
            );

            foreach ($this->engine->evaluateCondition($core, $facts, $today) as $item) {
                $items[] = ['item' => $item, 'protocol_key' => (string) $condition->protocol_key, 'version_id' => (string) $version->id];
            }

            if ($loaded['follow_up']['on'] !== null && ($cap = $this->engine->consultationCap($core, $loaded['follow_up']['on'])) !== null) {
                $caps[] = $cap;
            }
        }

        [$order, $orderEnded] = $settings->doctor_order_enabled ? $this->doctorOrder($loaded['follow_up'], $caps, $existing, $today) : [null, 'doctor_order_disabled'];

        if ($order !== null) {
            $items[] = ['item' => $order, 'protocol_key' => null, 'version_id' => null];
        }

        // O tratamento com injeções intravítreas: a próxima aplicação de cada curso ATIVO vira retorno, com ou sem
        // protocolo adotado (o intervalo é o que o médico decidiu na última aplicação). O agendamento do retorno do curso é a
        // reserva da PRÓPRIA aplicação planejada, quando ainda é um agendamento aberto deste paciente — conferida sem o teto
        // da lista `open_schedules` (a série pode estar longe, atrás de muitas consultas mais próximas).
        $courses  = $this->courseItems($entityId, $patientId);
        $open     = $this->loader->openAmong($entityId, $patientId, array_values(array_filter(array_column($courses, 'booking'))), $today);
        $bookings = [];

        foreach ($courses as $course) {
            $items[] = ['item' => $course['item'], 'protocol_key' => null, 'version_id' => null];

            if ($course['booking'] !== null && in_array($course['booking'], $open, true)) {
                $bookings[$course['item']->taskKey] = $course['booking'];
            }
        }

        return [
            'items'              => $items,
            'suspended'          => $suspended,
            'unadopted'          => $unadopted,
            'active'             => $active,
            'open_schedules'     => $loaded['open_schedules'],
            'course_bookings'    => $bookings,
            'doctor_order_ended' => $orderEnded,
        ];
    }

    /**
     * Um item por curso ATIVO com próxima aplicação. Curso pausado, concluído, interrompido, perdido ou excluído não gera
     * item: a linha que existia se encerra (`course_ended`). Junto de cada item vai a reserva da Agenda que a aplicação
     * PLANEJADA do curso guarda (`booking`, ainda não conferida: pode estar cancelada ou no passado) — a mais antiga, a
     * mesma que o agendamento da próxima aplicação usa.
     *
     * @return list<array{item: RecallItem, booking: ?string}>
     */
    private function courseItems(string $entityId, string $patientId): array
    {
        $operational = (array) config('recall.intravitreal.operational');
        $items       = [];

        $courses = IntravitrealCourse::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->whereNull('deleted_at')
            ->where('status', CourseStatus::Active->value)
            ->whereNotNull('next_due_on')
            ->orderBy('eye')
            ->get();

        $planned = $this->plannedApplications($entityId, $patientId, $courses);
        $booked  = $this->bookedDays($entityId, $patientId, $planned);

        foreach ($courses as $course) {
            $schedule = $planned->get((string) $course->id)?->schedule_id;

            $items[] = [
                'item' => $this->engine->courseItem(
                    (string) $course->id,
                    Eye::from((string) $course->eye),
                    (string) $course->drug_label,
                    $course->last_applied_on !== null ? CarbonImmutable::parse($course->last_applied_on) : null,
                    CarbonImmutable::parse($course->started_on),
                    // O prazo é o dia da reserva viva (a Agenda pode tê-la remarcado: o curso guarda a data de quando foi
                    // reservada); sem reserva, o que o curso tem gravado.
                    $booked[(string) $course->id] ?? CarbonImmutable::parse($course->next_due_on),
                    $course->current_interval_weeks !== null ? (int) $course->current_interval_weeks : null,
                    $operational,
                ),
                'booking' => $schedule !== null ? (string) $schedule : null,
            ];
        }

        return $items;
    }

    /**
     * A aplicação PLANEJADA de cada curso: a de menor sequência (a mesma que a tela do curso mostra e que o agendamento da
     * próxima usa). Aplicação excluída não conta (o escopo de exclusão fica; só o de tenant sai).
     *
     * @param Collection<int, IntravitrealCourse> $courses
     *
     * @return Collection<string, IntravitrealApplication> id do curso => aplicação planejada
     */
    private function plannedApplications(string $entityId, string $patientId, Collection $courses): Collection
    {
        if ($courses->isEmpty()) {
            return new Collection();
        }

        return IntravitrealApplication::query()->withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->whereIn('course_id', $courses->modelKeys())
            ->where('status', ApplicationStatus::Planned->value)
            ->orderBy('sequence_no')
            ->get()
            ->unique('course_id')
            ->keyBy(static fn (IntravitrealApplication $application): string => (string) $application->course_id);
    }

    /**
     * O dia da reserva VIVA da aplicação planejada de cada curso (só dos que têm uma). Reserva cancelada, faltada,
     * excluída ou passada a outro paciente não conta: o prazo volta ao que o curso tem gravado.
     *
     * @param Collection<string, IntravitrealApplication> $planned o resultado de `plannedApplications()`
     *
     * @return array<string, CarbonImmutable> id do curso => dia da reserva
     */
    private function bookedDays(string $entityId, string $patientId, Collection $planned): array
    {
        $live = ApplicationBooking::live($entityId, $patientId, $planned->pluck('schedule_id')->filter()->all());

        return $planned
            ->map(static fn (IntravitrealApplication $application): ?CarbonImmutable => ApplicationBooking::dayOf($application, $live))
            ->filter()
            ->all();
    }

    /**
     * O retorno pedido pelo médico no último atendimento — a menos que o paciente já tenha voltado "fora" depois
     * dele (a clínica marcou como feito em outro lugar). Devolve o item (ou nulo) e, quando não há item, o motivo
     * pelo qual a linha que existia se encerra.
     *
     * @param array{on: ?CarbonImmutable, days: ?int} $followUp
     * @param list<CarbonImmutable>                   $caps
     * @param Collection<int, PatientRecall>          $existing
     *
     * @return array{0: ?RecallItem, 1: ?string}
     */
    private function doctorOrder(array $followUp, array $caps, Collection $existing, CarbonImmutable $today): array
    {
        if ($followUp['on'] === null || $followUp['days'] === null) {
            return [null, 'doctor_order_ended'];
        }

        $external = $existing->first(static fn (PatientRecall $row): bool => $row->isDoctorOrder())?->external_done_at;

        if ($external !== null && CarbonImmutable::parse($external)->startOfDay()->greaterThan($followUp['on'])) {
            return [null, 'done_elsewhere'];
        }

        $item = $this->engine->doctorOrder(
            $followUp['on'],
            $followUp['days'],
            $caps === [] ? null : min($caps),
            (array) config('recall.operational'),
            $today,
        );

        return [$item, $item === null ? 'doctor_order_ended' : null];
    }

    /**
     * "Feito fora": a clínica registrou que o paciente fez a consulta/o exame em outro serviço numa data. Vira fato
     * do motor, então o retorno se re-ancora nessa data como se o ato tivesse sido registrado aqui.
     *
     * @param Collection<int, PatientRecall> $existing
     */
    private function withExternalActs(RecallFacts $facts, Collection $existing): RecallFacts
    {
        $consultations = $facts->consultations;
        $exams         = $facts->exams;

        foreach ($existing as $row) {
            if ($row->external_done_at === null || $row->isDoctorOrder() || $row->isCourse()) {
                continue;
            }

            $date = CarbonImmutable::parse($row->external_done_at)->startOfDay();

            if ($row->kind === RecallTaskKind::Exam->value && $row->exam_key !== null) {
                // O olho é o do PRÓPRIO retorno: "feito fora" no OD não pode cobrir o OE que segue atrasado. Sem olho
                // gravado (exame que não é lateral), o ato vale para os dois.
                $exams[] = ['key' => (string) $row->exam_key, 'eye' => $row->eye ?? Eye::Both, 'date' => $date];
            } else {
                $consultations[] = $date;
            }
        }

        return new RecallFacts($consultations, $exams);
    }

    // ── gravar a diferença ──────────────────────────────────────────────────

    /**
     * @param Collection<int, PatientRecall>                                                                                                                                                                                                                                                                                            $existing
     * @param array{items: list<array{item: RecallItem, protocol_key: ?string, version_id: ?string}>, suspended: array<string, true>, unadopted: array<string, true>, active: array<string, true>, open_schedules: list<array{id: string, date: CarbonImmutable}>, course_bookings: array<string, string>, doctor_order_ended: ?string} $plan
     *
     * @return array{created: int, updated: int, closed: int, unchanged: int}
     */
    private function reconcile(Collection $existing, array $plan, string $entityId, string $patientId, CarbonImmutable $today): array
    {
        $stats  = ['created' => 0, 'updated' => 0, 'closed' => 0, 'unchanged' => 0];
        $byKey  = $existing->keyBy(fn (PatientRecall $row): string => $this->key($row->source, $row->patient_condition_id, $row->task_key));
        $kept   = [];
        $openId = array_column($plan['open_schedules'], 'id');

        foreach ($plan['items'] as $planned) {
            /** @var RecallItem $item */
            $item   = $planned['item'];
            $key    = $this->key($item->source, $item->conditionId, $item->taskKey);
            $row    = $byKey->get($key);
            $kept[] = $key;

            $attributes = $this->attributes($item, $planned['protocol_key'], $planned['version_id'], $row?->scheduled_schedule_id, $plan['open_schedules'], $openId, $plan['course_bookings'][$item->taskKey] ?? null);

            if ($row === null) {
                $this->create($item, $attributes, $entityId, $patientId);
                $stats['created']++;

                continue;
            }

            $this->update($row, $item, $attributes, $today) ? $stats['updated']++ : $stats['unchanged']++;
        }

        foreach ($existing as $row) {
            if (in_array($this->key($row->source, $row->patient_condition_id, $row->task_key), $kept, true)) {
                continue;
            }

            if ($row->patient_condition_id !== null && isset($plan['suspended'][$row->patient_condition_id])) {
                $this->suspend($row) ? $stats['updated']++ : $stats['unchanged']++;

                continue;
            }

            $this->close($row, $this->endReason($row, $plan)) ? $stats['closed']++ : $stats['unchanged']++;
        }

        return $stats;
    }

    /**
     * @param list<array{id: string, date: CarbonImmutable}> $openSchedules
     * @param list<string>                                   $openIds
     * @param ?string                                        $courseBooking a reserva ABERTA da aplicação planejada do curso (só para o retorno de curso)
     *
     * @return array<string, mixed>
     */
    private function attributes(RecallItem $item, ?string $protocolKey, ?string $versionId, ?string $currentScheduleId, array $openSchedules, array $openIds, ?string $courseBooking): array
    {
        // O vínculo com um agendamento (feito pela tela de Retornos) sobrevive enquanto ele estiver aberto. Senão: o
        // retorno de um CURSO liga SÓ à reserva da própria aplicação planejada — "o próximo agendamento aberto do
        // paciente" ligaria ao mesmo horário o retorno de OUTRO olho (ou de uma consulta qualquer) e o tiraria de "a
        // contatar" e do convite; os demais retornos (consulta, exame) ligam ao próximo agendamento aberto do paciente.
        $scheduleId = $currentScheduleId !== null && in_array($currentScheduleId, $openIds, true)
            ? $currentScheduleId
            : ($item->source === RecallItem::SOURCE_COURSE ? $courseBooking : ($openSchedules[0]['id'] ?? null));

        return [
            'source'                => $item->source,
            'patient_condition_id'  => $item->conditionId,
            'protocol_key'          => $protocolKey,
            'protocol_version_id'   => $versionId,
            'task_key'              => $item->taskKey,
            'kind'                  => $item->kind,
            'exam_key'              => $item->examKey,
            'eye'                   => $item->eye?->value,
            'last_done_at'          => $item->lastDoneAt?->toDateString(),
            'anchor_at'             => $item->anchor?->toDateString(),
            'due_at'                => $item->dueAt?->toDateString(),
            'invite_from'           => $item->inviteFrom?->toDateString(),
            'overdue_from'          => $item->overdueFrom?->toDateString(),
            'lost_from'             => $item->lostFrom?->toDateString(),
            'rule_suspended'        => false,
            'scheduled_schedule_id' => $item->completed || $item->needsInfo ? null : $scheduleId,
            'evaluator_version'     => self::VERSION,
            'meta'                  => $item->meta,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function create(RecallItem $item, array $attributes, string $entityId, string $patientId): void
    {
        $lifecycle = $item->needsInfo ? RecallLifecycle::NeedsInfo : ($item->completed ? RecallLifecycle::Closed : RecallLifecycle::Open);

        $row = PatientRecall::query()->withoutGlobalScopes()->create($attributes + [
            'entity_id'     => $entityId,
            'patient_id'    => $patientId,
            'lifecycle'     => $lifecycle->value,
            'closed_reason' => $item->completed ? 'completed' : null,
        ]);

        $this->event($row, 'calculated', ['due_at' => $attributes['due_at'], 'lifecycle' => $lifecycle->value]);
    }

    /**
     * Atualiza o que mudou; devolve se gravou algo. Reavaliar sem mudança NÃO grava (nem `updated_at`).
     *
     * @param array<string, mixed> $attributes
     */
    private function update(PatientRecall $row, RecallItem $item, array $attributes, CarbonImmutable $today): bool
    {
        $before       = $row->due_at?->toDateString();
        $wasLifecycle = $row->lifecycle;
        $wasSuspended = $row->rule_suspended;
        $advanced     = $item->lastDoneAt !== null && ($row->last_done_at === null || $item->lastDoneAt->greaterThan(CarbonImmutable::parse($row->last_done_at)->startOfDay()));

        [$lifecycle, $closedReason] = $this->nextLifecycle($row, $item, $advanced, $today);

        $row->fill($attributes + ['lifecycle' => $lifecycle->value, 'closed_reason' => $closedReason]);

        if ($lifecycle === RecallLifecycle::Open && ($advanced || $wasLifecycle !== RecallLifecycle::Open)) {
            // Ciclo novo (o paciente voltou) ou o retorno voltou a valer: o que foi decidido para o ciclo anterior sai.
            $row->snoozed_until    = null;
            $row->dismissed_reason = null;
            $row->contact_count    = $advanced ? 0 : $row->contact_count;
        }

        // O "feito fora" vale para o ciclo em que foi registrado: um ato POSTERIOR a ele (aqui ou fora) o aposenta.
        if ($advanced && $row->external_done_at !== null && $item->lastDoneAt !== null && CarbonImmutable::parse($row->external_done_at)->startOfDay()->lessThan($item->lastDoneAt)) {
            $row->external_done_at = null;
            $row->external_done_by = null;
        }

        if (! $row->isDirty()) {
            return false;
        }

        $row->save();

        match (true) {
            $advanced                                                              => $this->event($row, 'cycle_completed', ['last_done_at' => $attributes['last_done_at'], 'due_at' => $attributes['due_at']]),
            $wasLifecycle !== $lifecycle && $lifecycle === RecallLifecycle::Closed => $this->event($row, 'closed', ['reason' => $closedReason]),
            $wasLifecycle !== $lifecycle                                           => $this->event($row, 'reopened', ['from' => $wasLifecycle->value, 'to' => $lifecycle->value]),
            $wasSuspended && ! $row->rule_suspended                                => $this->event($row, 'resumed', []),
            $before !== $attributes['due_at']                                      => $this->event($row, 'dates_changed', ['from' => $before, 'to' => $attributes['due_at']]),
            default                                                                => null,
        };

        return true;
    }

    /**
     * @return array{0: RecallLifecycle, 1: ?string}
     */
    private function nextLifecycle(PatientRecall $row, RecallItem $item, bool $advanced, CarbonImmutable $today): array
    {
        if ($item->needsInfo) {
            return [RecallLifecycle::NeedsInfo, null];
        }

        if ($item->completed) {
            return [RecallLifecycle::Closed, 'completed'];
        }

        // Tinha parado (falta de dado, encerrado) e voltou a ter data; ou o paciente voltou (ciclo novo).
        if ($advanced || in_array($row->lifecycle, [RecallLifecycle::NeedsInfo, RecallLifecycle::Closed], true)) {
            return [RecallLifecycle::Open, null];
        }

        // O adiamento venceu: volta sozinho para a lista.
        if ($row->lifecycle === RecallLifecycle::Snoozed && $row->snoozed_until !== null && CarbonImmutable::parse($row->snoozed_until)->startOfDay()->lessThanOrEqualTo($today)) {
            return [RecallLifecycle::Open, null];
        }

        // Aberto, adiado ou dispensado: o que a clínica decidiu para ESTE ciclo continua.
        return [$row->lifecycle, $row->closed_reason];
    }

    /**
     * Por que uma linha que existia deixou de ter origem: o retorno do médico some por um dos motivos dele; a
     * linha de protocolo, porque a clínica desativou o protocolo, porque a tarefa saiu (desligada ou removida na
     * versão adotada) ou porque a condição acabou (alta, descartada, removida).
     *
     * @param array{unadopted: array<string, true>, active: array<string, true>, doctor_order_ended: ?string} $plan
     */
    private function endReason(PatientRecall $row, array $plan): string
    {
        if ($row->isDoctorOrder()) {
            return $plan['doctor_order_ended'] ?? 'doctor_order_ended';
        }

        // O curso acabou (concluído, interrompido, perdido), foi pausado ou já não tem próxima aplicação.
        if ($row->isCourse()) {
            return 'course_ended';
        }

        return match (true) {
            isset($plan['unadopted'][(string) $row->patient_condition_id]) => 'protocol_deactivated',
            isset($plan['active'][(string) $row->patient_condition_id])    => 'task_disabled',
            default                                                        => 'condition_ended',
        };
    }

    /** A versão do protocolo foi suspensa: o retorno fica parado (e sem convite) até a retomada. */
    private function suspend(PatientRecall $row): bool
    {
        if ($row->rule_suspended) {
            return false;
        }

        $row->forceFill(['rule_suspended' => true])->save();
        $this->event($row, 'suspended', []);

        return true;
    }

    private function close(PatientRecall $row, string $reason): bool
    {
        if ($row->lifecycle === RecallLifecycle::Closed && $row->closed_reason === $reason) {
            return false;
        }

        $row->forceFill(['lifecycle' => RecallLifecycle::Closed->value, 'closed_reason' => $reason, 'rule_suspended' => false, 'scheduled_schedule_id' => null])->save();
        $this->event($row, 'closed', ['reason' => $reason]);

        return true;
    }

    /**
     * @param Collection<int, PatientRecall> $rows
     *
     * @return array{created: int, updated: int, closed: int, unchanged: int}
     */
    private function closeAll(Collection $rows, string $reason): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'closed' => 0, 'unchanged' => 0];

        foreach ($rows as $row) {
            $this->close($row, $reason) ? $stats['closed']++ : $stats['unchanged']++;
        }

        return $stats;
    }

    /** @param array<string, mixed> $meta */
    private function event(PatientRecall $row, string $event, array $meta): void
    {
        PatientRecallEvent::query()->withoutGlobalScopes()->create([
            'entity_id'   => $row->entity_id,
            'patient_id'  => $row->patient_id,
            'recall_id'   => $row->id,
            'event'       => $event,
            'actor_label' => 'system',
            'meta'        => $meta === [] ? null : $meta,
        ]);
    }

    private function key(string $source, ?string $conditionId, string $taskKey): string
    {
        return $source . '|' . ($conditionId ?? '-') . '|' . $taskKey;
    }

    private function patientExists(string $entityId, string $patientId): bool
    {
        return DB::table('patients')->where('id', $patientId)->where('entity_id', $entityId)->whereNull('deleted_at')->exists();
    }
}
