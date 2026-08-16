<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{ClientRule, EntityGate};
use App\Http\Requests\UpdateExamDiagnosisRequest;
use App\Models\{Entity, PatientExam};
use App\Services\DiagnosisCatalogService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Gate};

/**
 * "Diagnóstico do exame" (Gerenciador de Imagens): busca combinada
 * CID-10 + customizados da clínica, criação de diagnóstico customizado e
 * persistência do diagnóstico do exame (patient_exams.diagnosis_cids).
 *
 * Endpoints auxiliares em JSON puro (sem Inertia::render) — mesmo padrão de
 * Cid10SearchController e EyeImagesController::search().
 */
class ExamDiagnosisController extends Controller
{
    public function __construct(
        private readonly DiagnosisCatalogService $diagnosisCatalog,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $q        = $request->string('q')->trim()->value();
        $entityId = (string) session('selected_entity_id');

        // Defesa em profundidade, por simetria com store()/updateDiagnosis()
        // (que já não confiam só na rota): replica aqui o mesmo allowlist do
        // middleware de rota (`entity.role:admin,doctor`). Diferente de
        // store()/updateDiagnosis(), search() é só leitura (autocomplete),
        // então usa o allowlist admin+doctor da rota — não o Gate IssueReport
        // (exclusivo de doctor, para ATOS DE ESCRITA clínica), que bloquearia
        // indevidamente o admin aqui.
        abort_unless(
            (bool) auth()->user()?->hasAnyRoleInEntity(Entity::findOrFail($entityId), [ClientRule::Admin, ClientRule::Doctor]),
            403,
        );

        // Sem termo (q vazio): mesmo endpoint atende o `mostUsedUrl` do
        // Cid10Picker, chamado ao focar o input ainda vazio — devolve os
        // diagnósticos mais usados da clínica como sugestão inicial, antes de
        // o usuário digitar qualquer coisa.
        if ($q === '') {
            return response()->json(['data' => $this->diagnosisCatalog->mostUsed($entityId)]);
        }

        // Mesmo comportamento de Cid10SearchController/MedicineSearchController:
        // termo curto retorna lista vazia (sem erro 422) — evita "flash" de
        // validação no autocomplete enquanto o usuário ainda está digitando.
        if (mb_strlen($q, 'UTF-8') < 2) {
            return response()->json(['data' => []]);
        }

        $results = $this->diagnosisCatalog->search($entityId, $q);

        return response()->json(['data' => $results]);
    }

    public function store(Request $request): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        $this->authorizeIssueReport($entityId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $diagnosis = $this->diagnosisCatalog->findOrCreateCustom(
            $entityId,
            $validated['name'],
            (string) auth()->id(),
        );

        return response()->json([
            'data' => [
                'type'                => 'custom',
                'code'                => null,
                'custom_diagnosis_id' => (string) $diagnosis->id,
                'description'         => $diagnosis->name,
                'use_count'           => 0,
            ],
        ], 201);
    }

    public function updateDiagnosis(UpdateExamDiagnosisRequest $request, PatientExam $exam): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        $this->authorizeIssueReport($entityId);

        abort_unless((string) optional($exam->patient)->entity_id === $entityId, 403);

        $validated = $request->validated();

        $exam = DB::transaction(function () use ($exam, $validated, $entityId): PatientExam {
            $diagnoses = $this->diagnosisCatalog->prepareDiagnoses($entityId, $validated['diagnoses']);

            $exam->update(['diagnosis_cids' => $diagnoses]);

            $this->diagnosisCatalog->recordUsage($entityId, $diagnoses);

            return $exam;
        });

        return response()->json([
            'data' => [
                'id'             => (string) $exam->id,
                'diagnosis_cids' => $exam->diagnosis_cids ?? [],
            ],
        ]);
    }

    private function authorizeIssueReport(string $entityId): void
    {
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));
    }
}
