<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Http\Requests\Surgery\SurgeryDischargeRequest;
use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\PostOp\SurgeryDischargeService;
use Illuminate\Http\JsonResponse;

/** Alta cirúrgica: decisão do cirurgião (texto + plano de seguimento). */
class SurgeryDischargeController extends Controller
{
    use AuthorizesSurgery;

    public function __construct(private readonly SurgeryDischargeService $service)
    {
    }

    public function store(SurgeryDischargeRequest $request, SurgicalCase $surgicalCase): JsonResponse
    {
        $this->authorizePerform();
        $this->authorizeCaseAccess($surgicalCase);

        $case = $this->service->discharge($surgicalCase, $request->validated(), $request->expectedRevision());

        return response()->json([
            'status'  => $case->status->value,
            'message' => __('surgery_postop.messages.discharged'),
        ]);
    }
}
