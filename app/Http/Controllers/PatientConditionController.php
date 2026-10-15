<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Recall\Exceptions\PatientConditionException;
use App\Domains\Recall\Models\PatientCondition;
use App\Domains\Recall\Services\{PatientConditionService, PatientConditionsPresenter};
use App\Enums\{DataAccessPurpose, Eye};
use App\Http\Requests\Recall\{DischargePatientConditionRequest, PatientConditionActionRequest, StorePatientConditionRequest, UpdatePatientConditionRequest};
use App\Models\Patient;
use App\Traits\LogsDataAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Condições crônicas do paciente na gaveta do cadastro (Fase 6): o médico vê o que o sistema detectou, confirma,
 * descarta, dá alta, registra à mão e corrige olho/início. JSON para a gaveta; cada resposta devolve a situação
 * inteira atualizada (a tela troca o bloco de uma vez).
 *
 * Ato clínico: só o médico com cadastro ativo (Gate DecideRecall, conferido no FormRequest — que vale também para
 * ler: a condição é dado clínico derivado do diagnóstico). Rotas atrás da feature `has_patient_recall`. Tenant e
 * posse conferidos aqui: o binding de {patient}/{condition} roda antes do escopo de clínica.
 */
class PatientConditionController extends Controller
{
    use LogsDataAccess;

    public function __construct(
        private readonly PatientConditionService $service,
        private readonly PatientConditionsPresenter $presenter,
    ) {
    }

    public function index(PatientConditionActionRequest $request, Patient $patient): JsonResponse
    {
        $this->assertPatient($patient);

        // Leitura de dado de saúde sensível (LGPD art. 11/37).
        $this->logAccess($patient, DataAccessPurpose::PatientCare, patientId: (string) $patient->getKey());

        return $this->summary($patient);
    }

    public function store(StorePatientConditionRequest $request, Patient $patient): JsonResponse
    {
        $this->assertPatient($patient);

        return $this->act($patient, 'added', 201, fn () => $this->service->add(
            $this->entityId(),
            (string) $patient->getKey(),
            (string) $request->validated('protocol_key'),
            Eye::from((string) $request->validated('eye')),
            $this->date($request->validated('started_on')),
            $request->user(),
        ));
    }

    public function update(UpdatePatientConditionRequest $request, Patient $patient, PatientCondition $condition): JsonResponse
    {
        $this->assertCondition($patient, $condition);

        return $this->act($patient, 'saved', 200, fn () => $this->service->update(
            $this->entityId(),
            (string) $condition->getKey(),
            Eye::from((string) $request->validated('eye')),
            $this->date($request->validated('started_on')),
        ));
    }

    public function confirm(PatientConditionActionRequest $request, Patient $patient, PatientCondition $condition): JsonResponse
    {
        $this->assertCondition($patient, $condition);

        return $this->act($patient, 'confirmed', 200, fn () => $this->service->confirm($this->entityId(), (string) $condition->getKey(), $request->user()));
    }

    public function reject(PatientConditionActionRequest $request, Patient $patient, PatientCondition $condition): JsonResponse
    {
        $this->assertCondition($patient, $condition);

        return $this->act($patient, 'rejected', 200, fn () => $this->service->reject($this->entityId(), (string) $condition->getKey(), $request->user()));
    }

    public function discharge(DischargePatientConditionRequest $request, Patient $patient, PatientCondition $condition): JsonResponse
    {
        $this->assertCondition($patient, $condition);

        return $this->act($patient, 'discharged', 200, fn () => $this->service->discharge($this->entityId(), (string) $condition->getKey(), (string) $request->validated('reason'), $request->user()));
    }

    // ── infraestrutura ──────────────────────────────────────────────────────

    /** Roda o ato; recusa de negócio vira mensagem traduzida (422, ou 404 se sumiu), nunca erro 500. */
    private function act(Patient $patient, string $done, int $status, callable $act): JsonResponse
    {
        try {
            $act();
        } catch (PatientConditionException $e) {
            return response()->json([
                'message' => __('recall_conditions.errors.' . $e->reason()),
                'reason'  => $e->reason(),
            ], $e->reason() === PatientConditionException::NOT_FOUND ? 404 : 422);
        }

        return $this->summary($patient, __('recall_conditions.done.' . $done), $status);
    }

    private function summary(Patient $patient, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'data'    => $this->presenter->present($this->entityId(), (string) $patient->getKey()),
            'message' => $message,
        ], $status);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay() : null;
    }

    private function entityId(): string
    {
        return (string) session('selected_entity_id');
    }

    private function assertPatient(Patient $patient): void
    {
        abort_unless((string) $patient->entity_id === $this->entityId(), 404);
    }

    private function assertCondition(Patient $patient, PatientCondition $condition): void
    {
        $this->assertPatient($patient);
        abort_unless((string) $condition->patient_id === (string) $patient->getKey() && (string) $condition->entity_id === $this->entityId(), 404);
    }
}
