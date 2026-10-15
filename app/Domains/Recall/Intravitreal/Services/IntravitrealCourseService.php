<?php

declare(strict_types=1);

namespace App\Domains\Recall\Intravitreal\Services;

use App\Domains\Recall\Intravitreal\Enums\{ApplicationStatus, AuthorizationStatus, CourseRegimen, CourseStatus};
use App\Domains\Recall\Intravitreal\Exceptions\IntravitrealException as Refused;
use App\Domains\Recall\Intravitreal\Models\{IntravitrealApplication, IntravitrealCourse};
use App\Domains\Recall\Intravitreal\Support\ApplicationBooking;
use App\Domains\Recall\Support\RecallLocks;
use App\Models\{MedicalRecordProcedure, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * O curso de injeções intravítreas (anti-VEGF) de um olho: abrir (ao solicitar o procedimento), consultar e encerrar.
 * A EXECUÇÃO de cada aplicação (lote, autorização, intervalo decidido) é do IntravitrealApplicationService, chamado
 * pelo `markDone` do procedimento.
 *
 * Tudo sob a trava do PACIENTE do recall (RecallLocks::patient — a mesma do avaliador e das ações da equipe): dois
 * cliques simultâneos nunca abrem dois cursos do mesmo olho, e a reavaliação do retorno vê o curso já gravado.
 */
final class IntravitrealCourseService
{
    /** O curso do olho para o procedimento solicitado: acha o aberto ou abre; vincula a aplicação ao procedimento. */
    public function ensureForProcedure(MedicalRecordProcedure $procedure, string $eye, array $course, ?CarbonImmutable $today = null): IntravitrealApplication
    {
        if (! in_array($eye, ['right', 'left'], true)) {
            throw Refused::make(Refused::EYE_REQUIRED, 'Escolha OD ou OE: cada olho tem o seu curso.', 'eye');
        }

        $today = ($today ?? CarbonImmutable::now())->startOfDay();

        return DB::transaction(function () use ($procedure, $eye, $course, $today): IntravitrealApplication {
            RecallLocks::patient((string) $procedure->patient_id);

            $existing = IntravitrealApplication::query()->withoutGlobalScopes()->where('medical_record_procedure_id', $procedure->id)->lockForUpdate()->first();

            if ($existing !== null) {
                return $existing;
            }

            $current = IntravitrealCourse::query()->withoutGlobalScopes()
                ->where('entity_id', $procedure->entity_id)
                ->where('patient_id', $procedure->patient_id)
                ->where('eye', $eye)
                ->open()
                ->lockForUpdate()
                ->first() ?? $this->open($procedure, $eye, $course, $today);

            // Já há uma aplicação PLANEJADA sem procedimento (a que o curso preparou): é a desta solicitação.
            $planned = IntravitrealApplication::query()->withoutGlobalScopes()
                ->where('course_id', $current->id)
                ->where('status', ApplicationStatus::Planned->value)
                ->whereNull('medical_record_procedure_id')
                ->orderBy('sequence_no')
                ->lockForUpdate()
                ->first();

            if ($planned !== null) {
                $planned->forceFill(['medical_record_procedure_id' => $procedure->id])->save();

                return $planned;
            }

            return $this->plan($current, $procedure->id, $today);
        });
    }

    /**
     * Abre o curso de um olho. A droga é obrigatória (a lista está em config/recall.php; "outra" pede o nome).
     *
     * @param array{drug_key?: ?string, drug_label?: ?string, regimen?: ?string, indication?: ?string} $data
     */
    private function open(MedicalRecordProcedure $procedure, string $eye, array $data, CarbonImmutable $today): IntravitrealCourse
    {
        $drugs = (array) config('recall.intravitreal.drugs', []);
        $key   = (string) ($data['drug_key'] ?? '');

        if (! array_key_exists($key, $drugs)) {
            throw Refused::make(Refused::DRUG_REQUIRED, 'Escolha a droga do curso.', 'drug_key');
        }

        $label = $key === 'other' ? mb_substr(trim((string) ($data['drug_label'] ?? '')), 0, 120) : (string) __('recall_course.drugs.' . $key, [], 'pt_BR');

        if ($label === '') {
            throw Refused::make(Refused::DRUG_REQUIRED, 'Informe o nome da droga.', 'drug_label');
        }

        $regimen = CourseRegimen::tryFrom((string) ($data['regimen'] ?? '')) ?? CourseRegimen::LoadingTae;

        return IntravitrealCourse::query()->withoutGlobalScopes()->create([
            'entity_id'             => $procedure->entity_id,
            'patient_id'            => $procedure->patient_id,
            'eye'                   => $eye,
            'drug_key'              => $key,
            'drug_label'            => $label,
            'indication'            => ($data['indication'] ?? null) !== null ? mb_substr(trim((string) $data['indication']), 0, 120) ?: null : null,
            'regimen'               => $regimen->value,
            'loading_doses_planned' => (int) config('recall.intravitreal.loading_doses', 3),
            'min_interval_weeks'    => (int) config('recall.intravitreal.interval_weeks.min', 4),
            'max_interval_weeks'    => (int) config('recall.intravitreal.interval_weeks.max', 16),
            'status'                => CourseStatus::Active->value,
            'started_on'            => $today->toDateString(),
            'responsible_doctor_id' => $procedure->doctor_id,
        ]);
    }

    /** Planeja a próxima aplicação do curso (sequência seguinte), opcionalmente já ligada a um procedimento. */
    public function plan(IntravitrealCourse $course, ?string $procedureId, CarbonImmutable $plannedFor): IntravitrealApplication
    {
        $sequence = (int) IntravitrealApplication::query()->withoutGlobalScopes()->where('course_id', $course->id)->max('sequence_no') + 1;

        return IntravitrealApplication::query()->withoutGlobalScopes()->create([
            'entity_id'                   => $course->entity_id,
            'patient_id'                  => $course->patient_id,
            'course_id'                   => $course->id,
            'sequence_no'                 => $sequence,
            'phase'                       => $this->phase($course, $sequence),
            'status'                      => ApplicationStatus::Planned->value,
            'medical_record_procedure_id' => $procedureId,
            'planned_for'                 => $plannedFor->toDateString(),
            'eye'                         => $course->eye,
            'drug_label'                  => $course->drug_label,
            'authorization_status'        => AuthorizationStatus::NotRequired->value,
        ]);
    }

    /** Ataque (as primeiras doses do esquema com ataque) ou manutenção. */
    public function phase(IntravitrealCourse $course, int $sequence): string
    {
        return $course->regimen === CourseRegimen::LoadingTae && $sequence <= (int) $course->loading_doses_planned
            ? IntravitrealApplication::PHASE_LOADING
            : IntravitrealApplication::PHASE_MAINTENANCE;
    }

    /**
     * Encerra o curso do olho (concluído ou interrompido), com motivo; a aplicação planejada que sobrar é cancelada e
     * o retorno do curso sai da lista na próxima avaliação.
     *
     * @throws Refused
     */
    public function close(string $entityId, string $courseId, CourseStatus $status, string $reason, ?User $actor = null, ?CarbonImmutable $today = null): IntravitrealCourse
    {
        $reason = trim($reason);

        if (! in_array($status, [CourseStatus::Completed, CourseStatus::Discontinued], true)) {
            throw Refused::make(Refused::COURSE_CLOSED, 'O curso só pode ser concluído ou interrompido.');
        }

        if (mb_strlen($reason) < 3) {
            throw Refused::make(Refused::END_REASON_REQUIRED, 'Informe o motivo.', 'reason');
        }

        $today = ($today ?? CarbonImmutable::now())->startOfDay();

        return DB::transaction(function () use ($entityId, $courseId, $status, $reason, $today): IntravitrealCourse {
            $patientId = DB::table('intravitreal_courses')->where('id', $courseId)->where('entity_id', $entityId)->whereNull('deleted_at')->value('patient_id');

            if (! is_string($patientId)) {
                throw Refused::make(Refused::COURSE_NOT_FOUND, 'Curso não encontrado nesta clínica.');
            }

            RecallLocks::patient($patientId);

            $course = IntravitrealCourse::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereKey($courseId)->lockForUpdate()->first();

            if ($course === null) {
                throw Refused::make(Refused::COURSE_NOT_FOUND, 'Curso não encontrado nesta clínica.');
            }

            if (! $course->status->isOpen()) {
                throw Refused::make(Refused::COURSE_CLOSED, 'Este curso já foi encerrado.');
            }

            $this->endCourse($course, $status, $reason, $today);

            return $course->refresh();
        });
    }

    /** Fecha o curso e cancela a aplicação ainda planejada (quem chama já tem a trava do paciente). */
    public function endCourse(IntravitrealCourse $course, CourseStatus $status, string $reason, CarbonImmutable $today): void
    {
        IntravitrealApplication::query()->withoutGlobalScopes()
            ->where('course_id', $course->id)
            ->where('status', ApplicationStatus::Planned->value)
            ->get()
            ->each(static fn (IntravitrealApplication $application) => $application->forceFill(['status' => ApplicationStatus::Cancelled->value])->save());

        $course->forceFill([
            'status'      => $status->value,
            'ended_on'    => $today->toDateString(),
            'end_reason'  => mb_substr($reason, 0, 500),
            'next_due_on' => null,
        ])->save();
    }

    /**
     * Os cursos do paciente para a tela, do mais recente para o mais antigo, com a aplicação planejada e as últimas
     * executadas. Dado de saúde: quem chama registra o acesso.
     *
     * @return list<array<string, mixed>>
     */
    public function forPatient(string $entityId, string $patientId, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();

        $courses = IntravitrealCourse::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->orderByRaw("CASE WHEN status IN ('active', 'paused') THEN 0 ELSE 1 END")
            ->orderByDesc('started_on')
            ->with(['applications' => static fn ($query) => $query->withoutGlobalScopes()], 'responsibleDoctor.person:id,full_name')
            ->get();

        // Uma consulta para todas as reservas das aplicações planejadas: a tela só mostra "agendada" se ela ainda vale
        // (e é deste paciente), e a data é a da reserva — remarcar na Agenda não mexe na aplicação.
        $live = ApplicationBooking::live($entityId, $patientId, $courses
            ->flatMap(static fn (IntravitrealCourse $course) => $course->applications)
            ->filter(static fn (IntravitrealApplication $application): bool => $application->status === ApplicationStatus::Planned && $application->schedule_id !== null)
            ->map(static fn (IntravitrealApplication $application): string => (string) $application->schedule_id));

        return $courses->map(function (IntravitrealCourse $course) use ($today, $live): array {
            $applications = $course->applications;
            $planned      = $applications->first(static fn (IntravitrealApplication $application): bool => $application->status === ApplicationStatus::Planned);
            $performed    = $applications->filter(static fn (IntravitrealApplication $application): bool => $application->status === ApplicationStatus::Performed);
            // A próxima aplicação vale no dia da reserva viva; sem ela, no que está gravado no curso.
            $nextDue = ApplicationBooking::dayOf($planned, $live) ?? $course->next_due_on?->startOfDay();

            return [
                'id'                 => (string) $course->id,
                'eye'                => (string) $course->eye,
                'drug'               => (string) $course->drug_label,
                'indication'         => $course->indication,
                'regimen'            => $course->regimen->value,
                'regimen_label'      => $course->regimen->label(),
                'status'             => $course->status->value,
                'status_label'       => $course->status->label(),
                'open'               => $course->status->isOpen(),
                'started_on'         => $course->started_on?->toDateString(),
                'ended_on'           => $course->ended_on?->toDateString(),
                'end_reason'         => $course->end_reason,
                'last_applied_on'    => $course->last_applied_on?->toDateString(),
                'next_due_on'        => $nextDue?->toDateString(),
                'days_to_next'       => $nextDue !== null ? (int) $today->diffInDays($nextDue, false) : null,
                'interval_weeks'     => $course->current_interval_weeks,
                'doctor'             => $course->responsibleDoctor?->person?->full_name,
                'applications_count' => $performed->count(),
                'planned'            => $planned !== null ? $this->presentApplication($planned, $live) : null,
                'last_applications'  => $performed->sortByDesc('sequence_no')->take(5)->map(fn (IntravitrealApplication $application): array => $this->presentApplication($application))->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * @param array<string, CarbonImmutable>|null $liveBookings reservas ainda vivas DO PACIENTE (ApplicationBooking::live), com o dia de cada uma; a aplicação PLANEJADA cuja reserva caiu aparece sem agendamento e a que tem reserva viva, no dia dela
     *
     * @return array<string, mixed>
     */
    private function presentApplication(IntravitrealApplication $application, ?array $liveBookings = null): array
    {
        $scheduleId = $application->schedule_id !== null ? (string) $application->schedule_id : null;
        $plannedFor = $application->planned_for;

        if ($scheduleId !== null && $liveBookings !== null && $application->status === ApplicationStatus::Planned) {
            $plannedFor = $liveBookings[$scheduleId] ?? $plannedFor;
            $scheduleId = isset($liveBookings[$scheduleId]) ? $scheduleId : null;
        }

        return [
            'id'                   => (string) $application->id,
            'sequence_no'          => $application->sequence_no,
            'phase'                => $application->phase,
            'status'               => $application->status->value,
            'status_label'         => $application->status->label(),
            'planned_for'          => $plannedFor?->toDateString(),
            'performed_at'         => $application->performed_at?->toIso8601String(),
            'schedule_id'          => $scheduleId,
            'lot_number'           => $application->lot_number,
            'lot_expiry'           => $application->lot_expiry?->toDateString(),
            'authorization_status' => $application->authorization_status->value,
            'authorization_label'  => $application->authorization_status->label(),
            'authorization_number' => $application->authorization_number,
            'authorization_until'  => $application->authorization_valid_until?->toDateString(),
            'decided_interval'     => $application->decided_interval_weeks,
        ];
    }
}
