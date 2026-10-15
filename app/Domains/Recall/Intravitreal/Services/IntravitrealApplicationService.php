<?php

declare(strict_types=1);

namespace App\Domains\Recall\Intravitreal\Services;

use App\Domains\Recall\Intravitreal\Enums\{ApplicationStatus, AuthorizationStatus, CourseStatus};
use App\Domains\Recall\Intravitreal\Exceptions\IntravitrealException as Refused;
use App\Domains\Recall\Intravitreal\Models\{IntravitrealApplication, IntravitrealCourse};
use App\Domains\Recall\Intravitreal\Support\ApplicationBooking;
use App\Domains\Recall\Support\RecallLocks;
use App\Exceptions\ScheduleSlotUnavailableException;
use App\Jobs\NotifyScheduleChangeJob;
use App\Models\{Doctor, MedicalRecordProcedure, Schedule, StockLot};
use App\Notifications\ScheduleNotification;
use App\Services\{ScheduleSeriesService, ScheduleService};
use App\Support\CurrentDoctor;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A EXECUÇÃO de uma aplicação intravítrea (ADR 0002, item 10). Entra pelo `markDone` do procedimento do prontuário,
 * DENTRO da transação dele (prontuário de origem travado → procedimento travado → paciente → curso → aplicação):
 * a aplicação, o curso e o procedimento comitam juntos ou nenhum comita — nunca fica "procedimento executado" sem lote.
 *
 *  - lote e validade são OBRIGATÓRIOS e a validade não pode estar vencida (nenhuma exceção: medicamento vencido é risco
 *    ao paciente); vindo do estoque (`stock_lot_id`), o número e a validade são os do lote;
 *  - a autorização do convênio é POR aplicação: exigida e não aprovada (ou vencida) só passa com o aviso explícito
 *    (`authorization_override`) e a justificativa, que ficam gravados;
 *  - o médico decide o intervalo até a próxima aplicação (2 a 16 semanas — "trate e estenda") ou encerra o curso com
 *    motivo; a próxima fica planejada e o retorno do curso entra na lista de Retornos.
 *
 * Nada aqui escreve em `medical_record_procedures` além do que o ciclo de vida já escreve (prontuário assinado é
 * imutável): tudo vive nas tabelas do curso.
 */
final class IntravitrealApplicationService
{
    public const INTERVAL_MIN = 2;

    public const INTERVAL_MAX = 16;

    public function __construct(
        private readonly IntravitrealCourseService $courses,
        private readonly ScheduleSeriesService $series,
        private readonly ScheduleService $schedules,
    ) {
    }

    /**
     * @param array{
     *     lot_number?: ?string, lot_expiry?: ?string, stock_lot_id?: ?string,
     *     authorization_required?: bool, authorization_status?: ?string, authorization_number?: ?string,
     *     authorization_valid_until?: ?string, authorization_override?: bool, authorization_notes?: ?string,
     *     next_interval_weeks?: ?int, end_course?: ?string, end_reason?: ?string, notes?: ?string,
     *     drug_key?: ?string, drug_label?: ?string, regimen?: ?string, indication?: ?string
     * } $data
     *
     * @throws Refused
     */
    public function recordExecution(MedicalRecordProcedure $procedure, array $data, ?Doctor $performedBy, ?CarbonImmutable $now = null): IntravitrealApplication
    {
        $now ??= CarbonImmutable::now();
        $today = $now->startOfDay();

        // Quem chama (markDone) já travou o prontuário de origem e o procedimento; a trava do paciente vem depois.
        RecallLocks::patient((string) $procedure->patient_id);

        $application = IntravitrealApplication::query()->withoutGlobalScopes()->where('medical_record_procedure_id', $procedure->id)->lockForUpdate()->first();

        if ($application === null) {
            // Procedimento pedido antes do recurso (ou sem a solicitação do curso): o curso nasce aqui, da execução.
            $application = $this->courses->ensureForProcedure($procedure, (string) $procedure->eye, $data, $today);
            $application = IntravitrealApplication::query()->withoutGlobalScopes()->whereKey($application->id)->lockForUpdate()->firstOrFail();
        }

        $course = IntravitrealCourse::query()->withoutGlobalScopes()->whereKey($application->course_id)->lockForUpdate()->firstOrFail();

        if ($application->status !== ApplicationStatus::Planned) {
            throw Refused::make(Refused::NOT_PLANNED, 'Esta aplicação já foi registrada.');
        }

        if (! $course->status->isOpen()) {
            throw Refused::make(Refused::COURSE_CLOSED, 'O curso deste olho já foi encerrado.');
        }

        if ((string) $procedure->eye !== (string) $course->eye) {
            throw Refused::make(Refused::EYE_MISMATCH, 'O olho do procedimento não é o do curso.', 'eye');
        }

        [$lotNumber, $lotExpiry, $stockLotId] = $this->lot($procedure, $data, $today);
        $authorization                        = $this->authorization($data, $today);
        $interval                             = $this->interval($data);
        $end                                  = $this->ending($data);

        $application->forceFill([
            'status'                 => ApplicationStatus::Performed->value,
            'performed_at'           => $now,
            'performed_by_doctor_id' => $performedBy?->id ?? $procedure->doctor_id,
            'lot_number'             => $lotNumber,
            'lot_expiry'             => $lotExpiry,
            'stock_lot_id'           => $stockLotId,
            'decided_interval_weeks' => $end === null ? $interval : null,
            'notes'                  => ($data['notes'] ?? null) !== null ? mb_substr(trim((string) $data['notes']), 0, 1000) ?: null : null,
        ] + $authorization)->save();

        if ($end !== null) {
            $this->courses->endCourse($course, $end['status'], $end['reason'], $today);
            $course->forceFill(['last_applied_on' => $today->toDateString()])->save();

            return $application->refresh();
        }

        $next = $today->addWeeks((int) $interval);

        $course->forceFill([
            'last_applied_on'        => $today->toDateString(),
            'current_interval_weeks' => $interval,
            'next_due_on'            => $next->toDateString(),
        ])->save();

        // A próxima é a aplicação planejada que o curso já tem (outro pedido ainda não executado), com a data nova; só se
        // não houver nenhuma nasce outra.
        $this->courses->planNext($course, $next);

        return $application->refresh();
    }

    /**
     * O procedimento foi cancelado: a aplicação planejada que estava ligada a ele volta a ficar sem procedimento (o
     * paciente continua precisando da injeção — a próxima solicitação a reaproveita). Aplicação já realizada não muda.
     * Roda dentro da transação do cancelamento (prontuário → procedimento → paciente → aplicação).
     */
    public function releaseProcedure(MedicalRecordProcedure $procedure): void
    {
        RecallLocks::patient((string) $procedure->patient_id);

        IntravitrealApplication::query()->withoutGlobalScopes()
            ->where('medical_record_procedure_id', $procedure->id)
            ->where('status', ApplicationStatus::Planned->value)
            ->lockForUpdate()
            ->get()
            ->each(static fn (IntravitrealApplication $application) => $application->forceFill(['medical_record_procedure_id' => null])->save());
    }

    /**
     * Agenda a próxima aplicação planejada na série ABERTA do curso (acrescenta a sessão, com a mesma regra do
     * agendamento comum). Roda DEPOIS do commit da execução: a injeção já foi feita e registrada, então um horário que
     * não coube não desfaz nada — volta o motivo e a aplicação segue planejada, sem agendamento.
     *
     * Ordem de locks: cota do encaixe da âncora → paciente do recall (RecallLocks::patient) → curso → aplicação →
     * série → INSERT. A trava do PACIENTE vem antes de qualquer linha, como em recordExecution/close/ensureForProcedure:
     * esta operação travava o curso e SÓ DEPOIS a aplicação, e a execução (paciente → aplicação → curso) as travava ao
     * contrário — cada uma segurava a linha de que a outra precisava (40P01). A cota vem ANTES da trava do paciente
     * porque o agendamento de um retorno (SchedulesController::store, com `recall_id`) toma cota → paciente; em ordem
     * invertida as duas se esperavam, e a cota é re-entrante (addSession a toma de novo).
     *
     * A sessão nasce na agenda do médico da âncora (`addSession` copia dele), então o perfil médico só agenda aqui a
     * partir de uma âncora DA PRÓPRIA agenda — a regra da Agenda (ScheduleRequest, SchedulesController::addSeriesSession).
     * A conferência é na âncora escolhida sob as travas e ANTES de tocar a série dela. E, como no agendamento comum,
     * o paciente é avisado da criação (NotifyScheduleChangeJob) — depois do commit, para o worker achar a sessão.
     *
     * @return array{ok: bool, message: ?string, schedule_id: ?string}
     */
    public function scheduleNext(string $entityId, string $courseId, CarbonImmutable $dateTime): array
    {
        try {
            return DB::transaction(function () use ($entityId, $courseId, $dateTime): array {
                // Leitura SEM trava: só o paciente (que não muda) e a âncora provável, para tomar as travas na ordem
                // certa. Tudo o que decide é relido sob a trava abaixo.
                $probe = IntravitrealCourse::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereKey($courseId)->first();

                if ($probe === null) {
                    throw Refused::make(Refused::COURSE_CLOSED, 'O curso não está mais em andamento.');
                }

                $this->lockAnchorQuota($probe, Carbon::createFromInterface($dateTime));
                RecallLocks::patient((string) $probe->patient_id);

                $course = IntravitrealCourse::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereKey($courseId)->lockForUpdate()->first();

                if ($course === null || ! $course->status->isOpen()) {
                    throw Refused::make(Refused::COURSE_CLOSED, 'O curso não está mais em andamento.');
                }

                $application = IntravitrealApplication::query()->withoutGlobalScopes()
                    ->where('course_id', $course->id)
                    ->where('status', ApplicationStatus::Planned->value)
                    ->orderBy('sequence_no')
                    ->lockForUpdate()
                    ->first();

                if ($application === null) {
                    throw Refused::make(Refused::NOT_PLANNED, 'Não há aplicação planejada.');
                }

                // "Já agendada" só se a reserva ainda vale: agendamento cancelado/faltado/excluído deixa o id para trás e a
                // nova reserva o substitui — e tem de ser DESTE paciente (a Agenda pode ter passado o agendamento a outra
                // pessoa sem cancelá-lo).
                if ($application->schedule_id !== null && ApplicationBooking::isLive($entityId, (string) $course->patient_id, (string) $application->schedule_id)) {
                    throw Refused::make(Refused::ALREADY_SCHEDULED, 'A próxima aplicação já está agendada.');
                }

                $anchor = $this->anchor($course);

                if ($anchor === null) {
                    throw Refused::make(Refused::NO_REFERENCE_SCHEDULE, 'Não há agendamento de referência: agende pela Agenda.');
                }

                // Perfil médico agenda só na PRÓPRIA agenda (sem cadastro de médico, em nenhuma): a sessão herda o médico
                // da âncora, e nada da série dela é tocado antes disto.
                if (! CurrentDoctor::canAccessDoctorId((string) $anchor->doctor_id)) {
                    throw Refused::make(Refused::OTHER_DOCTOR_CALENDAR, 'A agenda de referência é de outro médico: agende pela Agenda, na sua agenda.');
                }

                if (! $anchor->series_id) {
                    $this->series->startOpenSeries($anchor);
                    $anchor->refresh();
                }

                $session = $this->series->addSession($anchor, Carbon::createFromInterface($dateTime));

                $application->forceFill(['schedule_id' => $session->id, 'planned_for' => $dateTime->toDateString()])->save();
                $course->forceFill(['series_id' => $anchor->series_id, 'next_due_on' => $dateTime->toDateString()])->save();

                // Mesmo aviso do agendamento comum e do acrescentar sessão: só sai se a transação comitar.
                NotifyScheduleChangeJob::dispatch((string) $session->id, ScheduleNotification::EVENT_CREATED)->afterCommit();

                return ['ok' => true, 'message' => null, 'schedule_id' => (string) $session->id];
            });
        } catch (Refused $e) {
            return ['ok' => false, 'message' => (string) __('recall_course.errors.' . $e->reason()), 'schedule_id' => null];
        } catch (ScheduleSlotUnavailableException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'schedule_id' => null];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'schedule_id' => null];
        }
    }

    // ── regras ──────────────────────────────────────────────────────────────

    /**
     * O agendamento de referência da série: a última sessão da série do curso; sem série, o agendamento do atendimento
     * em que a última aplicação foi feita.
     *
     * Só vale agendamento do PACIENTE do curso: `addSession` copia do âncora o paciente, o nome e os contatos, então um
     * agendamento que a Agenda passou para outra pessoa criaria a sessão (e a reserva da aplicação) para a pessoa errada.
     * Sem agendamento do paciente nas duas buscas, não há referência.
     */
    private function anchor(IntravitrealCourse $course): ?Schedule
    {
        if ($course->series_id !== null) {
            $last = $this->ownedBy($course)->where('series_id', $course->series_id)->orderByDesc('session_number')->first();

            if ($last !== null) {
                return $last;
            }
        }

        $performed = IntravitrealApplication::query()->withoutGlobalScopes()
            ->where('course_id', $course->id)
            ->where('status', ApplicationStatus::Performed->value)
            ->orderByDesc('sequence_no')
            ->first();

        // A reserva da própria aplicação e, na falta dela, o atendimento do procedimento: cada candidato passa pelo
        // mesmo crivo (um remarcado para outro paciente cai e o seguinte é tentado).
        foreach ([$performed?->schedule_id, $performed?->procedure?->medicalRecord?->schedule_id] as $scheduleId) {
            if ($scheduleId === null) {
                continue;
            }

            $schedule = $this->ownedBy($course)->whereKey($scheduleId)->first();

            if ($schedule !== null) {
                return $schedule;
            }
        }

        return null;
    }

    /** Agendamentos da clínica DESTE paciente (o de outro paciente, ou sem paciente cadastrado, nunca serve de modelo). */
    private function ownedBy(IntravitrealCourse $course): Builder
    {
        return Schedule::query()->withoutGlobalScopes()->where('entity_id', $course->entity_id)->where('patient_id', $course->patient_id);
    }

    /**
     * Toma a cota do encaixe da âncora antes da trava do paciente (ver a ordem de locks em scheduleNext). Só o encaixe
     * tem cota: `addSession` só a confere para ele. A âncora aqui é a PROVÁVEL (leitura sem trava); a sessão que entra
     * copia médico e encaixe da âncora, então ela e a do momento da gravação dão a mesma cota.
     */
    private function lockAnchorQuota(IntravitrealCourse $course, Carbon $at): void
    {
        $anchor = $this->anchor($course);

        if ($anchor?->is_fit_in) {
            $this->schedules->lockFitInQuota((string) $anchor->doctor_id, $at);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: string, 1: string, 2: ?string} número, validade (AAAA-MM-DD) e o lote do estoque, se veio de lá
     */
    private function lot(MedicalRecordProcedure $procedure, array $data, CarbonImmutable $today): array
    {
        $stockLotId = filled($data['stock_lot_id'] ?? null) ? (string) $data['stock_lot_id'] : null;

        if ($stockLotId !== null) {
            $lot = StockLot::query()->withoutGlobalScopes()->where('entity_id', $procedure->entity_id)->whereKey($stockLotId)->first();

            if ($lot === null || $lot->expiry_date === null) {
                throw Refused::make(Refused::LOT_NOT_FOUND, 'Lote do estoque não encontrado (ou sem validade).', 'stock_lot_id');
            }

            $number = (string) $lot->lot_number;
            $expiry = CarbonImmutable::parse($lot->expiry_date)->startOfDay();
        } else {
            $number = mb_substr(trim((string) ($data['lot_number'] ?? '')), 0, 60);

            if ($number === '' || blank($data['lot_expiry'] ?? null)) {
                throw Refused::make(Refused::LOT_REQUIRED, 'Informe o lote e a validade do medicamento.', 'lot_number');
            }

            $expiry = CarbonImmutable::parse((string) $data['lot_expiry'])->startOfDay();
        }

        if ($expiry->lessThan($today)) {
            throw Refused::make(Refused::LOT_EXPIRED, 'O lote está vencido: não aplique.', 'lot_expiry');
        }

        return [$number, $expiry->toDateString(), $stockLotId];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> colunas de autorização da aplicação
     */
    private function authorization(array $data, CarbonImmutable $today): array
    {
        $required = (bool) ($data['authorization_required'] ?? false);

        if (! $required) {
            return [
                'authorization_required'    => false,
                'authorization_status'      => AuthorizationStatus::NotRequired->value,
                'authorization_number'      => null,
                'authorization_valid_until' => null,
                'authorization_notes'       => null,
            ];
        }

        $status     = AuthorizationStatus::tryFrom((string) ($data['authorization_status'] ?? '')) ?? AuthorizationStatus::Pending;
        $validUntil = filled($data['authorization_valid_until'] ?? null) ? CarbonImmutable::parse((string) $data['authorization_valid_until'])->startOfDay() : null;
        $number     = filled($data['authorization_number'] ?? null) ? mb_substr(trim((string) $data['authorization_number']), 0, 60) : null;
        $notes      = filled($data['authorization_notes'] ?? null) ? mb_substr(trim((string) $data['authorization_notes']), 0, 1000) : null;

        // Vencida pela data, mesmo "aprovada": a autorização tem validade.
        if ($status === AuthorizationStatus::Approved && $validUntil !== null && $validUntil->lessThan($today)) {
            $status = AuthorizationStatus::Expired;
        }

        if ($status !== AuthorizationStatus::Approved) {
            $override = (bool) ($data['authorization_override'] ?? false);

            if (! $override || $notes === null || mb_strlen($notes) < 5) {
                throw Refused::make(Refused::AUTHORIZATION_MISSING, 'A autorização do convênio não está aprovada: confirme que vai aplicar assim mesmo e justifique.', 'authorization_status');
            }
        }

        return [
            'authorization_required'    => true,
            'authorization_status'      => $status->value,
            'authorization_number'      => $number,
            'authorization_valid_until' => $validUntil?->toDateString(),
            'authorization_notes'       => $notes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return int|null semanas até a próxima (nulo quando o curso termina)
     */
    private function interval(array $data): ?int
    {
        if (filled($data['end_course'] ?? null)) {
            return null;
        }

        $weeks = $data['next_interval_weeks'] ?? null;

        if ($weeks === null || $weeks === '') {
            throw Refused::make(Refused::INTERVAL_REQUIRED, 'Decida o intervalo até a próxima aplicação (ou encerre o curso).', 'next_interval_weeks');
        }

        $weeks = (int) $weeks;

        if ($weeks < self::INTERVAL_MIN || $weeks > self::INTERVAL_MAX) {
            throw Refused::make(Refused::INTERVAL_RANGE, 'O intervalo vai de ' . self::INTERVAL_MIN . ' a ' . self::INTERVAL_MAX . ' semanas.', 'next_interval_weeks');
        }

        return $weeks;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{status: CourseStatus, reason: string}|null
     */
    private function ending(array $data): ?array
    {
        $end = CourseStatus::tryFrom((string) ($data['end_course'] ?? ''));

        if ($end === null) {
            return null;
        }

        if (! in_array($end, [CourseStatus::Completed, CourseStatus::Discontinued], true)) {
            throw Refused::make(Refused::COURSE_CLOSED, 'O curso só pode ser concluído ou interrompido.', 'end_course');
        }

        $reason = trim((string) ($data['end_reason'] ?? ''));

        if (mb_strlen($reason) < 3) {
            throw Refused::make(Refused::END_REASON_REQUIRED, 'Informe o motivo de encerrar o curso.', 'end_reason');
        }

        return ['status' => $end, 'reason' => $reason];
    }
}
