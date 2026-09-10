<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EntityGate;
use App\Models\{Entity, EntityUser, PatientDocumentShare, PatientExam};
use App\Services\PatientDocumentShareService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\Gate;

/**
 * Ações rápidas por imagem no Gerenciador de Imagens (menu de contexto e
 * barra de seleção): trocar lateralidade, avaliar qualidade da captura,
 * habilitar/desabilitar imagem — um PatientExam por vez, mesmo padrão de
 * ExamDiagnosisController. Mesclar/Dividir/Desagrupar são em lote (N
 * exam_ids). Gate::IssueReport (escrita clínica, só médico) + checagem de
 * tenant via patient->entity_id (PatientExam não tem entity_id próprio) em
 * toda ação.
 */
class EyeImageExamActionsController extends Controller
{
    public function __construct(
        private readonly PatientDocumentShareService $shareService,
    ) {
    }

    /**
     * 1 = OD, 2 = OE, null = Binocular/AO (mesma convenção já usada em
     * queryPatients()/examFilterClosure() do EyeImagesController).
     */
    public function updateLaterality(Request $request, PatientExam $exam): JsonResponse
    {
        $this->authorizeExam($exam);

        $validated = $request->validate([
            'laterality' => ['nullable', 'integer', 'in:1,2'],
        ]);

        $exam->update(['laterality' => $validated['laterality'] ?? null]);

        return response()->json(['id' => (string) $exam->id, 'laterality' => (int) ($exam->laterality ?? 0)]);
    }

    /**
     * 0-5 estrelas; 0 (ou null) = "cancelar avaliação" — mesma semântica do
     * ícone "Cancel this rating!" do concorrente (Ger Exames), mas sem
     * duplicar o vocabulário deles: aqui é só uma nota numérica.
     */
    public function updateQualityRating(Request $request, PatientExam $exam): JsonResponse
    {
        $this->authorizeExam($exam);

        $validated = $request->validate([
            'quality_rating' => ['nullable', 'integer', 'min:0', 'max:5'],
        ]);

        $rating = $validated['quality_rating'] ?? null;
        $exam->update(['quality_rating' => $rating === 0 ? null : $rating]);

        return response()->json(['id' => (string) $exam->id, 'quality_rating' => $exam->quality_rating]);
    }

    /**
     * Habilitar/desabilitar imagem. `active` já existia na coluna (seeded
     * como true em ExternalExamImportService) mas não tinha endpoint nem
     * nenhum consumidor de leitura até 09/09/2026 — hoje esmaece a miniatura
     * E bloqueia a imagem de entrar em laudo/PDF novo (EyeImageReportController
     * ::assertExamsActive()) e em análise de IA nova (AiPayloadEnricher
     * ::authorizeExamIds()), sempre com 422 de regra de negócio (nunca 403 —
     * isso é posse de tenant, coisa diferente). Desabilitar revoga
     * automaticamente qualquer compartilhamento ativo com o Portal do
     * Paciente — uma imagem escondida do staff nunca deveria continuar
     * visível pro titular.
     */
    public function toggleActive(Request $request, PatientExam $exam): JsonResponse
    {
        $this->authorizeExam($exam);

        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $exam->update(['active' => $validated['active']]);

        if (! $validated['active']) {
            $share = PatientDocumentShare::query()
                ->where('shareable_type', PatientExam::class)
                ->where('shareable_id', $exam->id)
                ->whereNull('revoked_at')
                ->first();

            if ($share) {
                $this->shareService->revoke($this->currentEntityUser(), $share, 'Imagem desabilitada pelo médico.');
            }
        }

        return response()->json(['id' => (string) $exam->id, 'active' => (bool) $exam->active]);
    }

    /**
     * Mesclar exames — força 2+ imagens (mesmo paciente) a caírem no MESMO
     * grupo visual do Gerenciador de Imagens, mesmo que data/equipamento/
     * tipo reais sejam diferentes. Nunca mexe em exam_performed_at/
     * entity_integrator_equipment_id/exam_id (dado factual de captura,
     * sensível a auditoria) — grava só `exam_session_id`, que Index.vue usa
     * como override da chave de agrupamento derivada (ver migration
     * 2026_09_09_110000). Reaproveita um session_id já existente entre as
     * imagens selecionadas (merge incremental em cima de um merge anterior)
     * em vez de sempre gerar um novo — evita "esquecer" imagens de merges
     * passados fora do grupo resultante.
     */
    public function mergeExams(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exam_ids'   => ['required', 'array', 'min:2', 'max:50'],
            'exam_ids.*' => ['uuid'],
        ]);

        $exams = $this->ownedSamePatientExams($validated['exam_ids']);

        $sessionId = $exams->pluck('exam_session_id')->filter()->first() ?? (string) Str::uuid();

        PatientExam::query()->whereIn('id', $exams->pluck('id'))->update(['exam_session_id' => $sessionId]);

        return response()->json(['exam_session_id' => $sessionId, 'exam_ids' => $exams->pluck('id')->map(fn ($id) => (string) $id)]);
    }

    /**
     * Dividir exame — separa as imagens selecionadas do grupo em que estão
     * hoje, dando a elas um `exam_session_id` novo e distinto (só entre si).
     * Não afeta as imagens que ficaram de fora da seleção — se elas não
     * tinham session_id, continuam se agrupando pela chave derivada de
     * sempre; se tinham, mantêm o session_id antigo.
     */
    public function splitExams(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exam_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'exam_ids.*' => ['uuid'],
        ]);

        $exams = $this->ownedSamePatientExams($validated['exam_ids']);

        $sessionId = (string) Str::uuid();

        PatientExam::query()->whereIn('id', $exams->pluck('id'))->update(['exam_session_id' => $sessionId]);

        return response()->json(['exam_session_id' => $sessionId, 'exam_ids' => $exams->pluck('id')->map(fn ($id) => (string) $id)]);
    }

    /**
     * Desfaz um merge/split — limpa `exam_session_id` das imagens
     * informadas, voltando ao agrupamento 100% derivado (data|equipamento|
     * tipo). Ação do badge "Mesclado ×" no Gerenciador de Imagens.
     */
    public function ungroupExams(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exam_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'exam_ids.*' => ['uuid'],
        ]);

        $exams = $this->ownedSamePatientExams($validated['exam_ids']);

        PatientExam::query()->whereIn('id', $exams->pluck('id'))->update(['exam_session_id' => null]);

        return response()->json(['exam_ids' => $exams->pluck('id')->map(fn ($id) => (string) $id)]);
    }

    /**
     * Igual a authorizeExam(), mas em lote — pra merge/split, ALÉM do tenant
     * (patient->entity_id), exige que TODAS as imagens sejam do MESMO
     * paciente. Sem isso, mesclar/dividir cross-patient seria um bug de
     * integridade clínica bem pior que um IDOR comum (dado de UM paciente
     * passaria a exibir junto do de OUTRO).
     *
     * @param array<int, mixed> $examIds
     *
     * @return Collection<int, PatientExam>
     */
    private function ownedSamePatientExams(array $examIds): Collection
    {
        $entityId = (string) session('selected_entity_id');

        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));

        $examIds = array_values(array_unique(array_map('strval', $examIds)));

        $exams = PatientExam::query()
            ->whereIn('id', $examIds)
            ->whereHas('patient', fn ($q) => $q->where('entity_id', $entityId))
            ->get();

        abort_if($exams->count() !== count($examIds), 403);
        abort_if($exams->pluck('patient_id')->unique()->count() > 1, 422, __('eye_images.merge_split_same_patient'));

        return $exams;
    }

    private function authorizeExam(PatientExam $exam): void
    {
        $entityId = (string) session('selected_entity_id');

        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));

        abort_unless((string) optional($exam->patient)->entity_id === $entityId, 403);
    }

    private function currentEntityUser(): EntityUser
    {
        return EntityUser::query()->findOrFail(session('selected_entity_user_id'));
    }
}
