<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Exceptions\AllergyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Http\Requests\Surgery\PostOpGuidanceRequest;
use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\PostOp\PostOpGuidanceService;
use Illuminate\Http\JsonResponse;

/** Emite colírios e orientações pós-operatórias (documento congelado). Ato do médico. */
class PostOpGuidanceController extends Controller
{
    use AuthorizesSurgery;

    public function __construct(private readonly PostOpGuidanceService $service)
    {
    }

    public function store(PostOpGuidanceRequest $request, SurgicalCase $surgicalCase): JsonResponse
    {
        $this->authorizePerform();
        $this->authorizeCaseAccess($surgicalCase);

        try {
            $issue = $this->service->issue($surgicalCase, $request->guidanceData());
        } catch (AllergyConflictException $exception) {
            // Mesmo contrato da receita do prontuário: 422 com a alergia e a reação.
            return response()->json($exception->toResponsePayload(), 422);
        }

        return response()->json([
            'id'      => (string) $issue->getKey(),
            'pdf_url' => route('panel.surgery.postop.documents.pdf', ['surgicalCase' => $surgicalCase, 'document' => $issue]),
            'message' => __('surgery_postop.messages.guidance_issued'),
        ], 201);
    }
}
