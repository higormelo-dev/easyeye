<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\PostOp\PostOpReturnService;
use Illuminate\Http\JsonResponse;

/** "Tentar de novo" os retornos D1/D7/D30 que ficaram sem horário ou falharam (recepção). */
class PostOpReturnsController extends Controller
{
    use AuthorizesSurgery;

    public function __construct(private readonly PostOpReturnService $service)
    {
    }

    public function retry(SurgicalCase $surgicalCase): JsonResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);

        $returns = $this->service->retry($surgicalCase);

        return response()->json([
            'message' => __('surgery_postop.messages.returns_retried'),
            'count'   => count($returns),
        ]);
    }
}
