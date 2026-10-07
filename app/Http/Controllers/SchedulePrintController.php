<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, MedicalSpecialty};
use App\Models\{ClinicResource, Doctor, Entity, People, Schedule, ScheduleEvent};
use App\Services\DataAccessLogService;
use App\Services\Schedules\{AgendaFilters, AgendaQueryService};
use App\Support\{BrazilianFormat, CurrentDoctor, PatientAccess, ScheduleShift};
use App\Traits\LogsDataAccess;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Carbon\{Carbon, CarbonInterface};
use Illuminate\Http\{Request, Response};

/**
 * Impressão a partir da Agenda (PDF):
 *
 *  - Agenda do dia com os MESMOS filtros da tela (médico, turno, chip de
 *    situação, especialidade, sala, busca): horário, paciente, idade,
 *    convênio/plano, tipo, situação, encaixe, sessão, sala e — só para quem
 *    tem acesso a Pacientes — telefone.
 *  - Ficha do paciente do agendamento: identificação, contato,
 *    convênio/plano/carteirinha e os dados do agendamento.
 *
 * Tenant pela sessão (binding do agendamento já é por clínica), escopo de
 * médico por CurrentDoctor (médico só imprime o que é dele) e log de acesso
 * sensível (LGPD art. 37) — um registro por agendamento impresso.
 */
class SchedulePrintController extends Controller
{
    use LogsDataAccess;

    public function agenda(Request $request, AgendaQueryService $agenda, DataAccessLogService $accessLog): Response
    {
        abort_if(CurrentDoctor::isMissing(), 403, CurrentDoctor::missingMessage());

        $entityId = (string) session('selected_entity_id');
        $entity   = Entity::query()->findOrFail($entityId);
        $filters  = AgendaFilters::fromRequest($request, $entityId, CurrentDoctor::doctor(), 'day');
        $phones   = app(PatientAccess::class)->current();

        $schedules = $agenda->schedules($filters);
        $events    = $agenda->events($filters);
        $blocks    = $filters->status === 'blocked' ? $agenda->blocks($filters) : collect();

        $rows = $schedules->map(fn (Schedule $s) => $this->agendaRow($s, $phones))->toBase()
            ->merge($events->map(fn (ScheduleEvent $e) => $this->eventRow($e))->toBase())
            ->merge($blocks->map(fn (array $b) => [
                'kind'  => 'block',
                'sort'  => $b['sort_time'],
                'time'  => $b['starts_at_fmt'] . '–' . $b['ends_at_fmt'],
                'title' => $b['title'],
                'note'  => $b['doctor_name'] ?? $b['resource_name'],
            ]))
            ->sortBy('sort')
            ->values();

        // Quem imprimiu, quando e quais agendamentos (com o paciente) — mesmo
        // registro de acesso sensível das demais leituras do prontuário.
        $accessLog->logMany($schedules, DataAccessPurpose::Report);

        $data = [
            'entity'      => $entity,
            'rows'        => $rows,
            'withPhones'  => $phones,
            'withDoctor'  => $filters->doctorId === null,
            'heading'     => $this->agendaHeading($filters),
            'filterLines' => $this->filterLines($filters),
            'total'       => $schedules->count(),
            'generatedAt' => now(),
            'printedBy'   => auth()->user()?->name,
        ];

        return SnappyPdf::loadView('pdf.schedule_agenda', $data)
            ->setPaper('A4')
            ->setOrientation('landscape')
            ->setOption('margin-top', '1cm')
            ->setOption('margin-bottom', '1cm')
            ->setOption('margin-left', '1cm')
            ->setOption('margin-right', '1cm')
            ->setOption('encoding', 'UTF-8')
            ->inline('agenda-' . $filters->date->format('Y-m-d') . '.pdf');
    }

    public function patientSheet(Schedule $schedule): Response
    {
        abort_if((string) $schedule->entity_id !== (string) session('selected_entity_id'), 403, __('schedules.unauthorized'));
        abort_if(CurrentDoctor::isMissing(), 403, CurrentDoctor::missingMessage());
        abort_unless(CurrentDoctor::canAccessDoctorId($schedule->doctor_id), 403, __('schedules.unauthorized'));
        // Ficha = cadastro do paciente: mesma regra de acesso de Pacientes.
        abort_unless(app(PatientAccess::class)->current(), 403, __('schedules.unauthorized'));
        abort_unless($schedule->patient_id, 404);

        $schedule->load(['patient.person', 'patient.covenant', 'patient.covenantPlan', 'doctor.entityUser.user', 'covenant', 'visitType', 'resources']);
        $patient = $schedule->patient;
        abort_if($patient === null || (string) $patient->entity_id !== (string) $schedule->entity_id, 404);

        $this->logAccess($patient, DataAccessPurpose::Administrative, patientId: (string) $patient->id);

        $locale = app()->getLocale();
        $person = $patient->person;

        $data = [
            'entity'  => Entity::query()->findOrFail($schedule->entity_id),
            'patient' => [
                'name'        => $person?->full_name ?? $schedule->full_name,
                'code'        => $patient->code,
                'birth_date'  => $person?->birth_date ? Carbon::parse($person->birth_date)->locale($locale)->isoFormat('L') : null,
                'age'         => $this->age($person?->birth_date),
                'gender'      => $person?->gender !== null && in_array((int) $person->gender, [0, 1], true) ? __('schedules.sheet_gender_' . (int) $person->gender) : null,
                'cpf'         => $person?->national_registry ? BrazilianFormat::cpf($person->national_registry) : null,
                'rg'          => $person?->state_registry,
                'mother'      => $person?->mother_name,
                'cellphone'   => BrazilianFormat::phone($person?->cellphone),
                'telephone'   => BrazilianFormat::phone($person?->telephone),
                'email'       => $person?->email,
                'address'     => $this->address($person),
                'covenant'    => $patient->covenant?->name,
                'plan'        => $patient->covenantPlan?->name,
                'card_number' => $patient->card_number,
            ],
            'schedule' => [
                'code'      => $schedule->code,
                'date_time' => $schedule->date_time->copy()->locale($locale)->isoFormat('dddd, LL') . ' — ' . $schedule->date_time->format('H:i'),
                'doctor'    => $schedule->doctor?->entityUser?->user?->name,
                'record'    => $schedule->doctor?->record,
                'covenant'  => $schedule->covenant?->name,
                'visit'     => $schedule->visitType?->name,
                'specialty' => $schedule->specialty_area?->label(),
                'situation' => $schedule->situation->label(),
                'fit_in'    => (bool) $schedule->is_fit_in,
                'urgent'    => $schedule->isUrgentCare(),
                'session'   => $schedule->session_total ? $schedule->session_number . '/' . $schedule->session_total : null,
                'resources' => $schedule->resources->pluck('name')->implode(', '),
                'phone'     => BrazilianFormat::phone($schedule->cellphone ?: $schedule->telephone),
                'notes'     => $schedule->notes,
            ],
            'generatedAt' => now(),
            'printedBy'   => auth()->user()?->name,
        ];

        return SnappyPdf::loadView('pdf.schedule_patient_sheet', $data)
            ->setPaper('A4')
            ->setOrientation('portrait')
            ->setOption('margin-top', '1.2cm')
            ->setOption('margin-bottom', '1.2cm')
            ->setOption('margin-left', '1.2cm')
            ->setOption('margin-right', '1.2cm')
            ->setOption('encoding', 'UTF-8')
            ->inline('ficha-' . $patient->code . '.pdf');
    }

    /** @return array<string, mixed> */
    private function agendaRow(Schedule $s, bool $withPhones): array
    {
        $patient = $s->patient;
        $person  = $patient?->person;
        // Plano do cadastro só quando o convênio do agendamento é o do cadastro.
        $plan = $patient && $s->covenant_id && (string) $patient->covenant_id === (string) $s->covenant_id
            ? $patient->covenantPlan?->name
            : null;

        return [
            'kind'      => 'schedule',
            'sort'      => $s->date_time->toISOString(),
            'time'      => $s->date_time->format('H:i'),
            'name'      => $s->full_name ?? $person?->full_name ?? '—',
            'code'      => $patient?->code,
            'age'       => $this->age($person?->birth_date),
            'covenant'  => trim(implode(' · ', array_filter([$s->covenant?->name, $plan]))) ?: null,
            'visit'     => $s->visitType?->name,
            'situation' => $s->situation->label(),
            'fit_in'    => (bool) $s->is_fit_in,
            'urgent'    => $s->isUrgentCare(),
            'session'   => $s->session_total ? $s->session_number . '/' . $s->session_total : null,
            'resources' => $s->resources->pluck('name')->implode(', '),
            'doctor'    => $s->doctor?->entityUser?->user?->name,
            'phone'     => $withPhones ? BrazilianFormat::phone($s->cellphone ?: $person?->cellphone ?: $s->telephone ?: $person?->telephone) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function eventRow(ScheduleEvent $e): array
    {
        return [
            'kind'  => 'event',
            'sort'  => $e->starts_at->toISOString(),
            'time'  => $e->starts_at->format('H:i') . '–' . $e->ends_at->format('H:i'),
            'title' => $e->title,
            'note'  => $e->doctor?->entityUser?->user?->name,
        ];
    }

    /** Data por extenso no idioma + médico + turno. */
    private function agendaHeading(AgendaFilters $filters): array
    {
        $doctor = $filters->doctorId
            ? Doctor::query()->with('entityUser.user')->find($filters->doctorId)?->entityUser?->user?->name
            : null;

        return [
            'date'   => $filters->date->copy()->locale(app()->getLocale())->isoFormat('dddd, LL'),
            'doctor' => $doctor ?? __('schedules.print_all_doctors'),
            'shift'  => $filters->shift() ? ScheduleShift::label($filters->shift()) : __('schedules.print_all_shifts'),
        ];
    }

    /** @return list<string> filtros aplicados, em texto */
    private function filterLines(AgendaFilters $filters): array
    {
        return array_values(array_filter([
            $filters->status ? __('schedules.print_filter_status', ['value' => __('schedules.counter_' . $filters->status)]) : null,
            $filters->specialty ? __('schedules.print_filter_specialty', ['value' => MedicalSpecialty::from($filters->specialty)->label()]) : null,
            $filters->resourceId ? __('schedules.print_filter_resource', ['value' => (string) ClinicResource::query()->whereKey($filters->resourceId)->value('name')]) : null,
            $filters->search !== '' ? __('schedules.print_filter_search', ['value' => $filters->search]) : null,
        ]));
    }

    private function age(mixed $birthDate): ?int
    {
        if (blank($birthDate)) {
            return null;
        }

        $birth = $birthDate instanceof CarbonInterface ? $birthDate : Carbon::parse($birthDate);

        return $birth->isFuture() ? null : (int) $birth->diffInYears(now());
    }

    private function address(?People $person): ?string
    {
        if ($person === null) {
            return null;
        }

        $parts = array_filter([
            trim(implode(', ', array_filter([$person->address, $person->number]))),
            $person->complement,
            $person->district,
            trim(implode('/', array_filter([$person->city, $person->state]))),
            $person->zipcode ? 'CEP ' . BrazilianFormat::cep($person->zipcode) : null,
        ]);

        return $parts === [] ? null : implode(' — ', $parts);
    }
}
