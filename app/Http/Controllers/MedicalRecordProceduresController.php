<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Recall\Intravitreal\Exceptions\IntravitrealException;
use App\Domains\Recall\Intravitreal\Models\{IntravitrealApplication, IntravitrealCourse};
use App\Domains\Recall\Intravitreal\Services\{IntravitrealApplicationService, IntravitrealCourseService};
use App\Enums\{DataAccessPurpose, EntityGate, FeatureKey};
use App\Http\Requests\{MarkMedicalRecordProcedureDoneRequest, MedicalRecordProcedureRequest};
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordProcedure, Patient, Procedure, StockLot};
use App\Services\{FeatureGateService, MedicalRecordProcedureExecutionService};
use App\Support\CurrentDoctor;
use App\Traits\LogsDataAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Gate};
use InvalidArgumentException;

/**
 * Solicitação/execução ESTRUTURADA de procedimento (Fase 3 — estoque ↔
 * prontuário). Mesmo desenho de MedicalRecordEvolutionsController: leitura
 * segue o grupo de rotas do prontuário (admin/doctor/secretary), escrita
 * (`store`/`markDone`/`cancel`) restrita a médico via Gate IssueReport —
 * são atos clínicos (solicitar/confirmar execução de procedimento).
 *
 * `bom()` fica FORA do grupo `permission:stock.manage` de propósito: é uma
 * SUGESTÃO de leitura consumida durante o atendimento (qualquer papel que
 * acessa o prontuário precisa ver, não só quem administra o catálogo de
 * estoque) — a escrita de fato (consumo) continua condicionada à feature
 * `has_inventory_module` estar habilitada no plano, checada abaixo.
 *
 * Injeção intravítrea (anti-VEGF — Fase 6): a solicitação abre (ou continua) o curso do olho na MESMA transação do
 * pedido e a execução registra a aplicação (lote, autorização, intervalo decidido) na transação do `markDone`. Recusa
 * de negócio do curso (`IntravitrealException`) vira 422 com a mensagem traduzida e o campo, e nada é gravado.
 */
class MedicalRecordProceduresController extends Controller
{
    use LogsDataAccess;

    public function __construct(
        private readonly MedicalRecordProcedureExecutionService $executionService,
        private readonly FeatureGateService $featureGate,
        private readonly IntravitrealCourseService $courses,
        private readonly IntravitrealApplicationService $applications,
    ) {
    }

    /**
     * Listagem cronológica (mais recente primeiro) de TODOS os procedimentos
     * do paciente — atravessa prontuários, mesmo desenho de
     * MedicalRecordEvolutionsController::index().
     */
    public function index(Patient $patient): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        abort_unless((string) $patient->entity_id === $entityId, 404);

        $procedures = MedicalRecordProcedure::query()
            ->with(['procedure', 'doctor.person:id,full_name', 'executedBy.user:id,name'])
            ->where('entity_id', $entityId)
            ->where('patient_id', $patient->id)
            ->orderByDesc('created_at')
            ->get();

        $this->logAccess($patient, DataAccessPurpose::PatientCare, patientId: $patient->id);

        return response()->json([
            'data' => $procedures->map(fn (MedicalRecordProcedure $p) => $this->serialize($p))->values(),
        ]);
    }

    public function store(MedicalRecordProcedureRequest $request, Patient $patient, MedicalRecord $medicalrecord): JsonResponse
    {
        abort_if($medicalrecord->patient_id !== $patient->id, 404);

        $entityId = (string) session('selected_entity_id');
        $entity   = Entity::findOrFail($entityId);

        abort_unless((string) $patient->entity_id === $entityId, 404);

        // Solicitar procedimento é ato médico — mesmo gate doctor-only das
        // evoluções/quick actions de documento.
        Gate::authorize(EntityGate::IssueReport->value, $entity);

        // Autoria = médico que registra (logado, cadastro ativo — o Gate acima
        // exige), nunca o médico do prontuário (seria no nome de um colega).
        $doctor = CurrentDoctor::issuer()?->loadMissing('person');
        abort_if($doctor === null, 403, CurrentDoctor::missingMessage());

        try {
            $procedure = DB::transaction(function () use ($request, $entity, $patient, $medicalrecord, $doctor): MedicalRecordProcedure {
                $procedure = MedicalRecordProcedure::create([
                    'entity_id'         => $entity->id,
                    'patient_id'        => $patient->id,
                    'medical_record_id' => $medicalrecord->id,
                    'procedure_id'      => $request->validated('procedure_id'),
                    'doctor_id'         => $doctor->id,
                    'eye'               => $request->validated('eye'),
                    'solicitation_type' => $request->validated('solicitation_type'),
                    'notes'             => $request->validated('notes'),
                ]);

                $procedure->load(['procedure', 'doctor.person:id,full_name']);

                // Injeção intravítrea: o curso do olho nasce (ou continua) junto com o pedido.
                if ($procedure->procedure?->isIntravitreal()) {
                    $this->courses->ensureForProcedure($procedure, (string) $procedure->eye, $request->intravitreal());
                }

                return $procedure;
            });
        } catch (IntravitrealException $e) {
            return $this->refused($e);
        }

        $this->logAccess($procedure, DataAccessPurpose::PatientCare, patientId: $patient->id);

        return response()->json(['data' => $this->serialize($procedure)], 201);
    }

    /**
     * BOM sugerida (App\Models\ProcedureProduct) do procedimento — pré-
     * preenchimento do form de confirmação de consumo. Vazio (não erro)
     * quando não há BOM cadastrada ou o módulo de estoque está desabilitado
     * no plano — a tela trata os dois casos como "sem sugestão".
     */
    public function bom(Procedure $procedure): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        // Procedure pode ser GLOBAL (entity_id null, catálogo compartilhado)
        // — só bloqueia procedimento de OUTRA clínica, mesma regra de
        // ProcedureProductsController::assertOwnsProcedure().
        abort_unless($procedure->entity_id === null || (string) $procedure->entity_id === $entityId, 404);

        if (! $this->featureGate->status($entityId, FeatureKey::HasInventoryModule)->allowed) {
            return response()->json(['data' => []]);
        }

        // `where('entity_id', ...)` é o que isola de verdade quando o
        // procedimento é global — ver mesmo comentário em
        // ProcedureProductsController::index().
        $items = $procedure->productBom()
            ->where('entity_id', $entityId)
            ->with('product:id,name,code,unit,requires_lot,qty_on_hand')
            ->get()
            ->map(function ($bom) {
                $product = $bom->product;

                return [
                    'entity_product_id' => $bom->entity_product_id,
                    'product_name'      => $product?->name,
                    'product_code'      => $product?->code,
                    'unit_label'        => $product?->unit?->label(),
                    'requires_lot'      => (bool) $product?->requires_lot,
                    'qty_on_hand'       => (float) ($product?->qty_on_hand ?? 0),
                    'quantity'          => (float) $bom->quantity,
                    // `bom()` fica fora de permission:stock.manage justamente
                    // pra ser lido por quem não administra estoque (médico) —
                    // por isso os LOTES disponíveis (mesma trava de negócio de
                    // StockService::consumptionOut(): item requires_lot exige
                    // stock_lot_id explícito) vêm aqui, e não via
                    // ProductLotsController (esse SIM atrás de stock.manage).
                    'lots' => $product?->requires_lot
                        ? $product->lots()
                            ->active()
                            ->withBalance()
                            ->orderBy('expiry_date')
                            ->orderBy('lot_number')
                            ->get()
                            ->map(fn (StockLot $lot) => [
                                'id'          => $lot->id,
                                'lot_number'  => $lot->lot_number,
                                'expiry_date' => $lot->expiry_date?->format('Y-m-d'),
                                'qty_on_hand' => (float) $lot->qty_on_hand,
                            ])
                            ->values()
                        : [],
                ];
            });

        return response()->json(['data' => $items->values()]);
    }

    public function markDone(MarkMedicalRecordProcedureDoneRequest $request, Patient $patient, MedicalRecordProcedure $medicalRecordProcedure): JsonResponse
    {
        $entity = $this->authorizeProcedureWrite($patient, $medicalRecordProcedure);

        $items = $request->items();

        if ($items !== [] && ! $this->featureGate->status((string) $entity->id, FeatureKey::HasInventoryModule)->allowed) {
            return response()->json([
                'message' => __('subscriptions.feature_not_included', ['feature' => FeatureKey::HasInventoryModule->label()]),
            ], 403);
        }

        $entityUser = auth()->user()?->entityUserFor($entity);

        if (! $entityUser) {
            return response()->json(['message' => __('http-statuses.403')], 403);
        }

        $intravitreal = $request->intravitreal();

        try {
            $updated = $this->executionService->markDone(
                procedure: $medicalRecordProcedure,
                executedBy: $entityUser,
                items: $items,
                notes: $request->validated('notes'),
                intravitreal: $intravitreal === [] ? null : $intravitreal,
                performedBy: CurrentDoctor::issuer(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (IntravitrealException $e) {
            return $this->refused($e);
        }

        $updated->load(['procedure', 'doctor.person:id,full_name', 'executedBy.user:id,name']);

        $payload = ['data' => $this->serialize($updated)];

        if ($updated->procedure?->isIntravitreal()) {
            $payload['intravitreal'] = $this->afterExecution($updated, $intravitreal);
        }

        return response()->json($payload);
    }

    public function cancel(Request $request, Patient $patient, MedicalRecordProcedure $medicalRecordProcedure): JsonResponse
    {
        $this->authorizeProcedureWrite($patient, $medicalRecordProcedure);

        try {
            $updated = $this->executionService->cancel($medicalRecordProcedure, $request->input('notes'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->serialize($updated->load(['procedure', 'doctor.person:id,full_name']))]);
    }

    /**
     * O que a tela precisa saber depois da aplicação: a data da próxima, se o curso foi encerrado e o resultado do
     * agendamento pedido (a injeção já está registrada; horário que não coube não desfaz nada).
     *
     * @param array<string, mixed> $data
     *
     * @return array{message: string, next_due_on: ?string, closed: bool, schedule: ?array{ok: bool, message: ?string, schedule_id: ?string}}
     */
    private function afterExecution(MedicalRecordProcedure $procedure, array $data): array
    {
        $application = IntravitrealApplication::query()->withoutGlobalScopes()->where('medical_record_procedure_id', $procedure->id)->first();
        $course      = $application !== null ? IntravitrealCourse::query()->withoutGlobalScopes()->find($application->course_id) : null;
        $closed      = $course === null || ! $course->status->isOpen();
        $schedule    = null;

        if (! $closed && filled($data['schedule_at'] ?? null)) {
            $schedule = $this->applications->scheduleNext((string) $procedure->entity_id, (string) $course->id, CarbonImmutable::parse((string) $data['schedule_at']));

            // A reserva que acabou de sair É a data da próxima aplicação (scheduleNext a gravou no curso): o aviso e o
            // `next_due_on` mostram a data marcada, não a do intervalo que o curso tinha antes.
            if ($schedule['ok']) {
                $course->refresh();
            }
        }

        $next = $course?->next_due_on?->toDateString();

        return [
            'message' => $closed
                ? __('recall_course.done.executed_closed')
                : __('recall_course.done.executed', ['date' => $course->next_due_on?->format('d/m/Y')]),
            'next_due_on' => $next,
            'closed'      => $closed,
            'schedule'    => $schedule !== null && ! $schedule['ok']
                ? $schedule + ['message' => __('recall_course.done.schedule_failed', ['reason' => (string) $schedule['message']])]
                : $schedule,
        ];
    }

    private function refused(IntravitrealException $e): JsonResponse
    {
        return response()->json([
            'message' => __('recall_course.errors.' . $e->reason()),
            'errors'  => $e->field() !== null ? [$e->field() => [__('recall_course.errors.' . $e->reason())]] : null,
        ], 422);
    }

    private function authorizeProcedureWrite(Patient $patient, MedicalRecordProcedure $procedure): Entity
    {
        $entityId = (string) session('selected_entity_id');
        $entity   = Entity::findOrFail($entityId);

        abort_unless((string) $patient->entity_id === $entityId, 404);
        abort_unless((string) $procedure->entity_id === $entityId, 404);
        abort_if($procedure->patient_id !== $patient->id, 404);

        Gate::authorize(EntityGate::IssueReport->value, $entity);

        return $entity;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(MedicalRecordProcedure $procedure): array
    {
        return [
            'id'                => $procedure->id,
            'medical_record_id' => $procedure->medical_record_id,
            'procedure_id'      => $procedure->procedure_id,
            'procedure_name'    => $procedure->procedure?->name,
            'doctor_name'       => $procedure->doctor?->person?->full_name,
            'eye'               => $procedure->eye,
            'solicitation_type' => $procedure->solicitation_type,
            // intravitreal_injection pede os dados do curso na solicitação e da aplicação na execução.
            'clinical_kind'    => $procedure->procedure?->clinical_kind,
            'status'           => $procedure->status->value,
            'status_label'     => $procedure->status->label(),
            'notes'            => $procedure->notes,
            'executed_at'      => $procedure->executed_at?->format('d/m/Y H:i'),
            'executed_by_name' => $procedure->executedBy?->user?->name,
            'created_at'       => $procedure->created_at?->format('d/m/Y H:i'),
        ];
    }
}
