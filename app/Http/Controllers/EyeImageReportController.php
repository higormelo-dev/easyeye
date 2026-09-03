<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, DocumentationType, EntityGate};
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, ReportSettingContent};
use App\Services\{ConsultationRecordResolver, MedicalRecordDocumentationService};
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;
use Mews\Purifier\Facades\Purifier;

/**
 * "Laudo manual" do Gerenciador de Imagens Oftálmicas (/panel/eye-images):
 * médico escreve/edita achados a partir de um modelo pronto (Modelos —
 * reaproveita o MESMO catálogo ReportSetting/ReportSettingContent já usado
 * pelas Documentações do prontuário, filtrado às categorias de exame) e
 * salva como MedicalRecordDocumentation — a mesma tabela do laudo de IA, com
 * o mesmo PDF/histórico/auditoria já existentes.
 *
 * Ancoragem no prontuário: idêntica ao laudo de IA (mesmo agendamento >
 * mesmo dia > pergunta antes de abrir um novo) — ver ConsultationRecordResolver.
 * Diferença: esse fluxo é síncrono (sem AiRun/job), então "abrir prontuário"
 * acontece na MESMA request quando o médico confirma (confirm_open_record).
 */
class EyeImageReportController extends Controller
{
    use LogsDataAccess;

    /**
     * Categorias do catálogo de templates relevantes pra exame de imagem —
     * não mostra receituários/atestados/procedimentos aqui (ver ReportCategorySeeder).
     *
     * @var list<string>
     */
    private const CATEGORY_SLUGS = ['laudos', 'exames-especializados'];

    public function __construct(
        private readonly MedicalRecordDocumentationService $documentationService,
        private readonly ConsultationRecordResolver $recordResolver,
    ) {
    }

    /**
     * Catálogo de modelos ativos (globais + da clínica) restrito a
     * laudos/exames especializados — mesmo shape que MedicalRecordForm já
     * consome (report_setting_id/title/contents[]).
     */
    public function templates(): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');

        return response()->json([
            'data' => $this->documentationService->getActiveTemplates($entityId, self::CATEGORY_SLUGS),
        ]);
    }

    /**
     * Resolve um modelo (variáveis {{...}}) para o paciente informado, pronto
     * pra edição livre no editor. Tolera prontuário inexistente (variáveis de
     * medical_record caem no default do template) — a ancoragem definitiva só
     * acontece no store().
     */
    public function previewTemplate(Request $request): JsonResponse
    {
        $entityId  = (string) session('selected_entity_id');
        $validated = $request->validate([
            'report_setting_content_id' => ['required', 'uuid', 'exists:report_setting_contents,id'],
            'patient_id'                => ['required', 'uuid', 'exists:patients,id'],
            'exam_ids'                  => ['nullable', 'array'],
            'exam_ids.*'                => ['uuid'],
        ]);

        $patient = Patient::query()->where('entity_id', $entityId)->findOrFail($validated['patient_id']);
        $content = ReportSettingContent::findOrFail($validated['report_setting_content_id']);
        $this->documentationService->assertTemplateBelongsToEntity($content, $entityId);

        $examIds  = $this->ownedExamIds($validated['exam_ids'] ?? [], $entityId);
        $doctorId = $this->resolveDoctorId($entityId, $examIds);
        abort_if(! $doctorId, 422, __('eye_images.report_doctor_required'));

        [$scheduleId, $consultationDate] = $this->recordResolver->anchorForExamIds($examIds);
        $record                          = $this->recordResolver->findRecord($entityId, (string) $patient->id, $scheduleId, $consultationDate);

        $resolved = $this->documentationService->loadTemplate(
            $content,
            $patient,
            Doctor::findOrFail($doctorId),
            Entity::findOrFail($entityId),
            $record,
        );

        $this->logAccess($patient, DataAccessPurpose::PatientCare);

        return response()->json([
            'content' => $this->documentationService->stripResolvedPlaceholders($resolved['html']),
        ]);
    }

    /**
     * Salva o laudo manual. Sem prontuário do dia da consulta, devolve 422 +
     * `requires_record_confirmation` (mesmo contrato do laudo de IA) — o
     * front pergunta e reenvia com `confirm_open_record=true` pra abrir e
     * salvar na mesma tacada.
     */
    public function store(Request $request): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        $this->authorizeIssueReport($entityId);

        $validated = $request->validate([
            'patient_id'                => ['required', 'uuid', 'exists:patients,id'],
            'exam_ids'                  => ['nullable', 'array'],
            'exam_ids.*'                => ['uuid'],
            'report_setting_content_id' => ['nullable', 'uuid', 'exists:report_setting_contents,id'],
            'title'                     => ['nullable', 'string', 'max:255'],
            'content'                   => ['required', 'string'],
            'confirm_open_record'       => ['nullable', 'boolean'],
        ]);

        $patient = Patient::query()->where('entity_id', $entityId)->findOrFail($validated['patient_id']);
        $examIds = $this->ownedExamIds($validated['exam_ids'] ?? [], $entityId);

        [$scheduleId, $consultationDate] = $this->recordResolver->anchorForExamIds($examIds);
        $record                          = $this->recordResolver->findRecord($entityId, (string) $patient->id, $scheduleId, $consultationDate);

        if (! $record) {
            if (! (bool) ($validated['confirm_open_record'] ?? false)) {
                return response()->json([
                    'requires_record_confirmation' => true,
                    'consultation_date'            => $consultationDate,
                ], 422);
            }

            $doctorId = $this->resolveDoctorId($entityId, $examIds);
            abort_if(! $doctorId, 422, __('eye_images.report_doctor_required'));

            // Abrir prontuário é ato médico (mesma regra de AiRunsController::openRecordForRun).
            $record = MedicalRecord::query()->create(array_filter([
                'entity_id'   => $entityId,
                'patient_id'  => $patient->id,
                'doctor_id'   => (string) $doctorId,
                'schedule_id' => $scheduleId,
            ]));
        }

        $sanitized     = Purifier::clean($validated['content'], 'medical');
        $reportContent = isset($validated['report_setting_content_id'])
            ? ReportSettingContent::findOrFail($validated['report_setting_content_id'])
            : null;

        if ($reportContent) {
            $this->documentationService->assertTemplateBelongsToEntity($reportContent, $entityId);

            $documentation = $this->documentationService->store(
                $record,
                $reportContent,
                $sanitized,
                $validated['title'] ?? null,
            );
        } else {
            // Laudo em branco (sem modelo) — mesmo default de título do laudo de IA.
            $documentation = MedicalRecordDocumentation::create([
                'medical_record_id' => $record->id,
                'patient_id'        => $record->patient_id,
                'doctor_id'         => $record->doctor_id,
                'type'              => DocumentationType::Report->value,
                'title'             => $validated['title'] ?? __('eye_images.report_default_title'),
                'content'           => $sanitized,
            ]);
        }

        return response()->json([
            'id'                => $documentation->id,
            'medical_record_id' => $record->id,
            'title'             => $documentation->title,
            'created_at'        => $documentation->created_at?->format('d/m/Y H:i'),
            'pdf_url'           => route('panel.patients.medicalrecords.documentations.pdf', [$patient, $record, $documentation]),
        ], 201);
    }

    /**
     * @param array<int, mixed> $examIds
     *
     * @return list<string>
     */
    private function ownedExamIds(array $examIds, string $entityId): array
    {
        $examIds = array_values(array_unique(array_filter(array_map('strval', $examIds))));

        if ($examIds === []) {
            return [];
        }

        $owned = PatientExam::query()
            ->whereIn('patient_exams.id', $examIds)
            ->whereHas('patient', fn ($q) => $q->where('entity_id', $entityId))
            ->pluck('patient_exams.id')
            ->map(fn ($id) => (string) $id)
            ->all();

        // Nunca falha silenciosamente com um exam_id de outra clínica: 403
        // explícito (mesmo padrão de AiPayloadEnricher::authorizeExamIds).
        abort_if(count($owned) !== count($examIds), 403);

        return $owned;
    }

    /**
     * @param list<string> $examIds
     */
    private function resolveDoctorId(string $entityId, array $examIds): ?string
    {
        return $this->recordResolver->resolveDoctorIdForCurrentUser($entityId)
            ?? ($examIds !== [] ? PatientExam::query()->whereIn('id', $examIds)->value('doctor_id') : null);
    }

    private function authorizeIssueReport(string $entityId): void
    {
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));
    }
}
