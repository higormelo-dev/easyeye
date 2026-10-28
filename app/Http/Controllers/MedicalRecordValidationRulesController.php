<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\{StoreMedicalRecordRequest, UpdateMedicalRecordRequest};
use App\Services\FormRequestRulesExporter;
use Illuminate\Http\{JsonResponse, Request};

/**
 * F9 — expõe as regras client-safe de `Store/UpdateMedicalRecordRequest`
 * para o validator Alpine. Mantém uma única fonte de verdade (FormRequest).
 */
class MedicalRecordValidationRulesController extends Controller
{
    public function __construct(
        private readonly FormRequestRulesExporter $exporter,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $mode = $request->string('mode')->lower()->value();

        $formRequest = $mode === 'update'
            ? new UpdateMedicalRecordRequest()
            : new StoreMedicalRecordRequest();

        // FormRequest precisa de container Laravel para resolver dependências
        // de `rules()`. Aqui só lemos rules() puro (não dispara prepareForValidation).
        return response()->json([
            'mode'  => $mode === 'update' ? 'update' : 'store',
            'rules' => $this->exporter->export($formRequest),
        ]);
    }
}
