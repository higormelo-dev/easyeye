<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\{Doctor, DoctorDocumentTemplate};
use App\Services\Documents\DoctorDocumentTemplateService;
use App\Support\CurrentDoctor;
use App\Support\Documents\DocumentDate;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

/**
 * Modelos PESSOAIS do médico (Fase 2 / B3 — "Salvar como modelo" no
 * atendimento): receita, pedido de exames e relatório/documento.
 *
 * Só o médico com cadastro ativo nesta clínica (CurrentDoctor::issuer) e só
 * os PRÓPRIOS modelos — de outro médico ou de outra clínica → 404.
 */
class DoctorDocumentTemplatesController extends Controller
{
    public function __construct(private readonly DoctorDocumentTemplateService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'string', Rule::in(DoctorDocumentTemplate::KINDS)],
            // Cada tela lista só o formato que entende: os modais de receita e
            // pedido pedem `text`; o editor de Documentações lista todos.
            'format' => ['nullable', 'string', Rule::in(['text', 'html'])],
        ]);

        $templates = $this->service->list($this->entityId(), $this->doctor(), $data['kind'] ?? null, $data['format'] ?? null);

        return response()->json(['data' => $templates->map(fn (DoctorDocumentTemplate $t) => $this->service->serialize($t))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind'                      => ['required', 'string', Rule::in(DoctorDocumentTemplate::KINDS)],
            'name'                      => ['required', 'string', 'min:2', 'max:120'],
            'body'                      => ['required', 'string', 'max:20000'],
            'payload'                   => ['nullable', 'array'],
            'report_setting_content_id' => ['nullable', 'uuid'],
            // Prontuário de onde o texto veio (editor): o servidor devolve os
            // dados do paciente às variáveis antes de gravar (LGPD).
            'medical_record_id' => ['nullable', 'uuid'],
            'document_date'     => DocumentDate::rules(),
        ], DocumentDate::messages());

        $template = $this->service->create($this->entityId(), $this->doctor(), $data);

        return response()->json([
            'message' => __('clinical_documents.templates.saved'),
            'data'    => $this->service->serialize($template),
        ], 201);
    }

    public function update(Request $request, string $template): JsonResponse
    {
        $model = $this->service->ownedOrFail($this->entityId(), $this->doctor(), $template);

        $data = $request->validate([
            'name'              => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'body'              => ['sometimes', 'required', 'string', 'max:20000'],
            'payload'           => ['sometimes', 'nullable', 'array'],
            'medical_record_id' => ['nullable', 'uuid'],
            'document_date'     => DocumentDate::rules(),
        ], DocumentDate::messages());

        $model = $this->service->update($model, $data);

        return response()->json([
            'message' => __('clinical_documents.templates.saved'),
            'data'    => $this->service->serialize($model),
        ]);
    }

    public function destroy(string $template): JsonResponse
    {
        $this->service->ownedOrFail($this->entityId(), $this->doctor(), $template)->delete();

        return response()->json(['message' => __('clinical_documents.templates.deleted')]);
    }

    /** Médico que emite (cadastro ativo nesta clínica) — 403 traduzido sem ele. */
    private function doctor(): Doctor
    {
        $doctor = CurrentDoctor::issuer();
        abort_if($doctor === null, 403, CurrentDoctor::missingMessage());

        return $doctor;
    }

    private function entityId(): string
    {
        return (string) session('selected_entity_id');
    }
}
