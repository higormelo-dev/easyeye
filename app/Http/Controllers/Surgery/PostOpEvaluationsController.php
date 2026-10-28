<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Http\Requests\Surgery\{PostOpAddendumRequest, PostOpEvaluationRequest};
use App\Models\Surgery\{SurgicalCase, SurgicalPostopEvaluation};
use App\Services\Surgery\PostOp\PostOpEvaluationService;
use Illuminate\Http\JsonResponse;

/**
 * Avaliação pós-operatória estruturada: rascunho, finalizar e adendo. Ato do
 * médico (Gate PerformSurgery) que participa do caso; a regra clínica e as
 * travas ficam em PostOpEvaluationService. Respostas em JSON (a aba recarrega
 * as props depois).
 */
class PostOpEvaluationsController extends Controller
{
    use AuthorizesSurgery;

    public function __construct(private readonly PostOpEvaluationService $service)
    {
    }

    public function store(PostOpEvaluationRequest $request, SurgicalCase $surgicalCase): JsonResponse
    {
        $this->authorizeActor($surgicalCase);

        $evaluation = $this->service->saveDraft($surgicalCase, $request->evaluationData());

        return response()->json(['id' => (string) $evaluation->getKey(), 'revision' => $evaluation->revision, 'message' => __('surgery_postop.messages.draft_saved')], 201);
    }

    public function update(PostOpEvaluationRequest $request, SurgicalCase $surgicalCase, SurgicalPostopEvaluation $evaluation): JsonResponse
    {
        $this->authorizeActor($surgicalCase);
        $this->assertBelongs($surgicalCase, $evaluation);

        $evaluation = $this->service->saveDraft($surgicalCase, $request->evaluationData(), $evaluation, $request->expectedRevision());

        return response()->json(['id' => (string) $evaluation->getKey(), 'revision' => $evaluation->revision, 'message' => __('surgery_postop.messages.draft_saved')]);
    }

    public function destroy(SurgicalCase $surgicalCase, SurgicalPostopEvaluation $evaluation): JsonResponse
    {
        $this->authorizeActor($surgicalCase);
        $this->assertBelongs($surgicalCase, $evaluation);

        $this->service->deleteDraft($surgicalCase, $evaluation);

        return response()->json(['message' => __('surgery_postop.messages.draft_deleted')]);
    }

    public function finalize(PostOpEvaluationRequest $request, SurgicalCase $surgicalCase, SurgicalPostopEvaluation $evaluation): JsonResponse
    {
        $this->authorizeActor($surgicalCase);
        $this->assertBelongs($surgicalCase, $evaluation);

        // "Salvar e finalizar": grava o que a tela mandou e fecha, na ordem.
        $saved     = $this->service->saveDraft($surgicalCase, $request->evaluationData(), $evaluation, $request->expectedRevision());
        $finalized = $this->service->finalize($surgicalCase, $saved);

        return response()->json([
            'id'      => (string) $finalized->getKey(),
            'severe'  => (bool) $finalized->severe,
            'message' => __('surgery_postop.messages.finalized'),
        ]);
    }

    public function addendum(PostOpAddendumRequest $request, SurgicalCase $surgicalCase, SurgicalPostopEvaluation $evaluation): JsonResponse
    {
        $this->authorizeActor($surgicalCase);
        $this->assertBelongs($surgicalCase, $evaluation);

        $addendum = $this->service->addAddendum($surgicalCase, $evaluation, $request->validated());

        return response()->json(['id' => (string) $addendum->getKey(), 'message' => __('surgery_postop.messages.addendum_saved')], 201);
    }

    private function authorizeActor(SurgicalCase $case): void
    {
        $this->authorizePerform();
        $this->authorizeCaseAccess($case);
    }

    private function assertBelongs(SurgicalCase $case, SurgicalPostopEvaluation $evaluation): void
    {
        abort_unless((string) $evaluation->surgical_case_id === (string) $case->getKey(), 404);
    }
}
