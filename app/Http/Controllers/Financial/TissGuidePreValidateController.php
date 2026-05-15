<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\Services\PreValidateTissGuideService;
use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TissGuidePreValidateController extends Controller
{
    public function __construct(
        private readonly PreValidateTissGuideService $preValidator,
    ) {
    }

    public function __invoke(TissGuide $guide): JsonResponse
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        if ((string) $guide->entity_id !== (string) $entity->id) {
            abort(403);
        }

        $result = $this->preValidator->validate($guide);

        return response()->json([
            'passes'   => $result->passes(),
            'errors'   => $result->errors()->map(fn ($i) => $i->toArray())->values(),
            'warnings' => $result->warnings()->map(fn ($i) => $i->toArray())->values(),
            'summary'  => $result->isEmpty()
                ? 'Guia validada com sucesso. Nenhuma pendência encontrada.'
                : ($result->hasErrors()
                    ? 'Guia possui pendências que causarão glosa.'
                    : 'Guia possui avisos. Verifique antes de enviar.'),
        ]);
    }
}
