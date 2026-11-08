<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, EntityGate, LgpdRequestStatus, LgpdRequestType};
use App\Http\Requests\Lgpd\{StoreLgpdRequestRequest, UpdateLgpdRequestRequest};
use App\Models\{Entity, EntityUser, LgpdRequest, Patient};
use App\Services\LgpdService;
use App\Traits\LogsDataAccess;
use Illuminate\Http\{RedirectResponse, Response};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\ValidationException;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Pedidos de titulares (LGPD arts. 18 e 19) recebidos pela clínica, que é a
 * controladora dos dados dos pacientes: registrar com a data em que chegaram
 * (o prazo de 15 dias para confirmação e acesso conta dela — art. 19, II),
 * acompanhar o prazo, concluir com a resposta dada ou recusar com a
 * justificativa, e baixar os dados do paciente vinculado (art. 18, II e V).
 * O próprio registro é a prova do atendimento (art. 37). Só administrador.
 */
class LgpdRequestsController extends Controller
{
    use LogsDataAccess;

    private const PER_PAGE = 20;

    public function __construct(private readonly LgpdService $lgpd)
    {
    }

    public function index(): InertiaResponse
    {
        $this->authorizeClinic();

        $entityId = (string) session('selected_entity_id');
        $locale   = app()->getLocale();
        $date     = fn (?Carbon $at) => $at?->locale($locale)->isoFormat('L');

        $requests = LgpdRequest::query()
            ->where('entity_id', $entityId)
            ->with(['patient.person:id,full_name', 'respondedBy.user:id,name'])
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN 0 ELSE 1 END', [LgpdRequestStatus::Pending->value, LgpdRequestStatus::InProgress->value])
            ->orderBy('deadline_at')
            ->orderByDesc('requested_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (LgpdRequest $r) => [
                'id'               => $r->id,
                'requester_name'   => $r->requester_name,
                'requester_email'  => $r->requester_email,
                'type'             => $r->request_type->value,
                'status'           => $r->status->value,
                'open'             => $r->status->isOpen(),
                'overdue'          => $r->status->isOpen() && $r->deadline_at?->isPast(),
                'description'      => $r->description,
                'response'         => $r->response,
                'rejection_reason' => $r->rejection_reason,
                'requested_at'     => $date($r->requested_at),
                'deadline_at'      => $date($r->deadline_at),
                'responded_at'     => $date($r->responded_at),
                'responded_by'     => $r->respondedBy?->user?->name,
                'patient'          => $r->patient ? ['code' => $r->patient->code, 'name' => $r->patient->person?->full_name] : null,
                'export_url'       => $r->patient_id ? route('panel.reports.compliance.lgpd-requests.export', $r) : null,
            ]);

        return Inertia::render('Panel/Compliance/LgpdRequests', [
            'requests' => $requests,
            'counts'   => [
                'open'    => LgpdRequest::query()->where('entity_id', $entityId)->whereIn('status', [LgpdRequestStatus::Pending, LgpdRequestStatus::InProgress])->count(),
                'overdue' => $this->lgpd->overdueRequests($entityId)->count(),
            ],
            'types' => array_map(fn (LgpdRequestType $type) => $type->value, LgpdRequestType::cases()),
            'today' => now()->toDateString(),
            't'     => trans('lgpd_requests'),
        ]);
    }

    public function store(StoreLgpdRequestRequest $request): RedirectResponse
    {
        $this->authorizeClinic();

        $data = $request->validated();

        $created = $this->lgpd->openRequest(
            entityId: (string) session('selected_entity_id'),
            type: LgpdRequestType::from($data['request_type']),
            requesterName: $data['requester_name'],
            requesterEmail: $data['requester_email'],
            description: $data['description'],
            patient: filled($data['patient_id'] ?? null) ? Patient::query()->findOrFail($data['patient_id']) : null,
            requesterDocument: $data['requester_document'] ?? null,
            requestedAt: Carbon::parse($data['requested_at'])->startOfDay(),
        );

        return back()->with('success', __('lgpd_requests.flash.created', [
            'date' => $created->deadline_at->locale(app()->getLocale())->isoFormat('L'),
        ]));
    }

    public function update(UpdateLgpdRequestRequest $request, LgpdRequest $lgpdRequest): RedirectResponse
    {
        $this->authorizeClinic();
        $this->ensureSameClinic($lgpdRequest);

        $action = (string) $request->validated('action');
        $actor  = EntityUser::query()->findOrFail(session('selected_entity_user_id'));
        $text   = trim((string) $request->validated('text'));

        // Trava a linha: concluir e recusar ao mesmo tempo (duas abas) não pode gravar os dois textos.
        DB::transaction(function () use ($lgpdRequest, $action, $actor, $text): void {
            $locked = LgpdRequest::query()->whereKey($lgpdRequest->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages(['action' => __('lgpd_requests.errors.not_open')]);
            }

            if ($action === 'start' && $locked->status !== LgpdRequestStatus::Pending) {
                throw ValidationException::withMessages(['action' => __('lgpd_requests.errors.not_pending')]);
            }

            match ($action) {
                'start'    => $this->lgpd->startProcessing($locked),
                'complete' => $this->lgpd->complete($locked, $actor, $text),
                'reject'   => $this->lgpd->reject($locked, $actor, $text),
            };
        });

        return back()->with('success', __("lgpd_requests.flash.{$action}"));
    }

    /**
     * Dados do paciente vinculado ao pedido, no mesmo formato da autoexportação
     * do portal (PatientDataExporter). Leitura em massa de dado de saúde:
     * registrada como acesso com finalidade "Solicitação LGPD".
     */
    public function export(LgpdRequest $lgpdRequest): Response
    {
        $this->authorizeClinic();
        $this->ensureSameClinic($lgpdRequest);

        $patient = $lgpdRequest->patient;

        abort_if($patient === null, 404);

        $data = $this->lgpd->exportPatientData($patient);

        $this->logAccess($patient, DataAccessPurpose::LgpdRequest, patientId: $patient->id);

        $filename = 'dados-paciente-' . ($patient->code ?? $patient->id) . '-' . now()->format('Y-m-d') . '.json';

        return response(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9\-_.]/', '_', $filename) . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    private function authorizeClinic(): void
    {
        Gate::authorize(EntityGate::ManageSettings->value, Entity::findOrFail(session('selected_entity_id')));
    }

    /** Defesa em profundidade além do escopo de clínica: pedido de outra clínica = 404. */
    private function ensureSameClinic(LgpdRequest $lgpdRequest): void
    {
        abort_unless((string) $lgpdRequest->entity_id === (string) session('selected_entity_id'), 404);
    }
}
