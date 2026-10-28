<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{UrgentCarePriority, UrgentCareReason, UrgentCareStatus};
use App\Exceptions\UrgentCareUnavailableException;
use App\Http\Requests\UrgentCareEntryRequest;
use App\Models\{Covenant, Doctor, Entity, UrgentCareEntry};
use App\Services\ClinicalSafety\PatientClinicalSafetyService;
use App\Services\UrgentCare\UrgentCareService;
use App\Support\{CurrentDoctor, PatientAccess};
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Pronto atendimento (Fase 1 / B1) — fila de demanda espontânea do dia.
 *
 * Rota no grupo da Agenda (entity.role:admin,doctor,secretary). Tenant pela
 * sessão (binding do item já é da clínica); escopo de médico pela regra única
 * App\Support\CurrentDoctor: médico vê/mexe só nos itens dele e nos sem
 * médico preferido, e perfil médico sem cadastro não vê nada (aviso).
 * "Atender" é ato médico: só perfil médico com cadastro ativo (o prontuário
 * que ele abre é doctor-only).
 */
class UrgentCareController extends Controller
{
    public function __construct(private readonly UrgentCareService $service)
    {
    }

    public function index(): InertiaResponse
    {
        $entityId      = (string) session('selected_entity_id');
        $isDoctor      = CurrentDoctor::isDoctorProfile();
        $doctor        = CurrentDoctor::doctor();
        $doctorMissing = $isDoctor && ! $doctor;
        $scope         = $isDoctor ? ($doctor ? (string) $doctor->id : null) : null;
        $phones        = app(PatientAccess::class)->current();

        return Inertia::render('Panel/UrgentCare/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('urgent_care.title'), 'url' => '#', 'active' => true],
            ],
            'queue'  => fn () => $doctorMissing ? [] : $this->queueRows($entityId, $scope, $phones),
            'closed' => fn () => $doctorMissing ? [] : $this->service->closedToday($entityId, $scope)
                ->map(fn (UrgentCareEntry $e) => $this->service->toRow($e))
                ->values()
                ->all(),
            'doctors'   => fn () => $doctorMissing ? [] : $this->activeDoctors($entityId),
            'covenants' => fn () => Covenant::query()
                ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'reasons'            => UrgentCareReason::options(),
            'priorities'         => UrgentCarePriority::options(),
            'legalPriorityAge'   => UrgentCarePriority::LEGAL_PRIORITY_AGE,
            'canAttend'          => CurrentDoctor::canIssue(),
            'currentDoctorId'    => $doctor ? (string) $doctor->id : null,
            'isDoctor'           => $isDoctor,
            'doctorMissing'      => $doctorMissing,
            'doctorMissingText'  => CurrentDoctor::missingMessage(),
            'callPanelEnabled'   => (bool) Entity::query()->whereKey($entityId)->value('call_panel_enabled'),
            'canRegisterPatient' => $phones,
            'urls'               => [
                'store'          => route('panel.urgent-care.store'),
                'patient_search' => route('panel.patients.search'),
                'patient_quick'  => route('panel.patients.quick'),
                'schedules'      => route('panel.schedules.index'),
            ],
            't' => trans('urgent_care'),
        ]);
    }

    /**
     * Fila do dia com o alerta de alergia por paciente (Fase 2 / A1) — uma
     * consulta para a fila inteira; só para quem tem acesso a Pacientes.
     *
     * @return list<array<string, mixed>>
     */
    private function queueRows(string $entityId, ?string $scope, bool $patientAccess): array
    {
        $queue = $this->service->queue($entityId, $scope)->values();
        $flags = $patientAccess
            ? app(PatientClinicalSafetyService::class)->compactFor($entityId, $queue->pluck('patient_id')->filter()->all())
            : [];

        return $queue
            ->map(fn (UrgentCareEntry $e, int $i) => [
                ...$this->service->toRow($e, $i + 1),
                'phone'   => $patientAccess ? UrgentCareService::phoneOf($e) : null,
                'allergy' => $flags[(string) $e->patient_id] ?? null,
            ])
            ->all();
    }

    public function store(UrgentCareEntryRequest $request): RedirectResponse
    {
        abort_if(CurrentDoctor::isMissing(), 403, CurrentDoctor::missingMessage());

        try {
            $entry = $this->service->add((string) session('selected_entity_id'), $request->validated());
        } catch (UrgentCareUnavailableException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('urgent_care.added', ['name' => $entry->patient?->person?->full_name ?? '']));
    }

    public function update(UrgentCareEntryRequest $request, UrgentCareEntry $urgentCareEntry): RedirectResponse
    {
        $this->authorizeEntry($urgentCareEntry);

        return $this->run(fn () => $this->service->update($urgentCareEntry, $request->validated()), __('urgent_care.updated'));
    }

    public function call(UrgentCareEntry $urgentCareEntry): RedirectResponse
    {
        $this->authorizeEntry($urgentCareEntry);

        // Mesmo recurso do "Chamar paciente" da Agenda: só com o painel ativo.
        abort_unless((bool) Entity::query()->whereKey($urgentCareEntry->entity_id)->value('call_panel_enabled'), 404);

        $doctorName = CurrentDoctor::doctor()?->user_name
            ?? $urgentCareEntry->preferredDoctor?->entityUser?->user?->name;

        return $this->run(
            fn () => $this->service->call($urgentCareEntry, $doctorName, session('selected_entity_user_id')),
            __('urgent_care.called'),
        );
    }

    /**
     * "Atender": agendamento de urgência agora (fora do teto de encaixes) e o
     * prontuário normal do agendamento (SchedulesController::attend).
     */
    public function attend(UrgentCareEntry $urgentCareEntry): RedirectResponse
    {
        $this->authorizeEntry($urgentCareEntry);

        $doctor = CurrentDoctor::issuer();
        abort_unless(CurrentDoctor::isDoctorProfile() && $doctor !== null, 403, __('urgent_care.attend_doctor_only'));

        try {
            $schedule = $this->service->attend($urgentCareEntry, $doctor, session('selected_entity_user_id'));
        } catch (UrgentCareUnavailableException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('panel.schedules.attend', $schedule);
    }

    public function close(Request $request, UrgentCareEntry $urgentCareEntry): RedirectResponse
    {
        $this->authorizeEntry($urgentCareEntry);

        $validated = $request->validate([
            'status' => ['required', 'integer', Rule::in([UrgentCareStatus::Left->value, UrgentCareStatus::Removed->value])],
            'reason' => ['required', 'string', 'max:500'],
        ], [], [
            'status' => __('urgent_care.field_close_status'),
            'reason' => __('urgent_care.field_close_reason'),
        ]);

        $status = UrgentCareStatus::from((int) $validated['status']);

        return $this->run(
            fn () => $this->service->close($urgentCareEntry, $status, trim($validated['reason'])),
            __('urgent_care.closed_' . $status->key()),
        );
    }

    // ── internos ─────────────────────────────────────────────────────────────

    /**
     * Item da clínica (binding) e, no perfil médico, dele ou sem médico
     * preferido — perfil médico sem cadastro não mexe em nada.
     */
    private function authorizeEntry(UrgentCareEntry $entry): void
    {
        abort_if((string) $entry->entity_id !== (string) session('selected_entity_id'), 404);

        if (! CurrentDoctor::isDoctorProfile()) {
            return;
        }

        $doctor = CurrentDoctor::doctor();
        abort_if($doctor === null, 403, CurrentDoctor::missingMessage());
        abort_unless($this->service->isVisibleTo($entry, (string) $doctor->id), 403, __('urgent_care.not_yours'));
    }

    /** Mudança de andamento com o aviso traduzido quando o item já saiu da fila. */
    private function run(callable $change, string $success): RedirectResponse
    {
        try {
            $change();
        } catch (UrgentCareUnavailableException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    /** @return list<array{id: string, name: string}> */
    private function activeDoctors(string $entityId): array
    {
        return Doctor::query()
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->join('users', 'users.id', '=', 'entity_users.user_id')
            ->where('entity_users.entity_id', $entityId)
            ->where('doctors.active', true)
            ->orderBy('users.name')
            ->get(['doctors.id', 'users.name'])
            ->map(fn ($d) => ['id' => (string) $d->id, 'name' => (string) $d->name])
            ->all();
    }
}
