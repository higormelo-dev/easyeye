<?php

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, EntityGate, ExamReportRegistry};
use App\Models\{Doctor, Entity, MedicalRecord, Patient};
use App\Services\{DayExtensionService, MedicalRecordDocumentationService, MedicalRecordQuickActionService, SurgerySchedulingDocService};
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class MedicalRecordQuickActionsController extends Controller
{
    use LogsDataAccess;

    public function __construct(
        private readonly MedicalRecordQuickActionService $service,
        private readonly MedicalRecordDocumentationService $documentationService,
        private readonly DayExtensionService $dayExtension,
    ) {
    }

    public function issue(Request $request, Patient $patient, MedicalRecord $medicalrecord, string $action): JsonResponse
    {
        $this->assertMedicalRecordBelongsToPatient($patient, $medicalrecord);
        $this->authorizeIssueReport();

        $payload = $this->validatePayload($request, $action);
        $entity  = Entity::findOrFail(session('selected_entity_id'));
        $doctor  = $medicalrecord->doctor;

        // Fallback: usuário logado é médico → resolve automaticamente.
        if (! $doctor) {
            $doctor = Doctor::with('person')
                ->whereHas('entityUser', fn ($q) => $q
                    ->where('entity_id', $entity->id)
                    ->where('user_id', auth()->id()))
                ->first();
        }

        if (! $doctor) {
            return response()->json([
                'message' => 'Selecione o médico responsável antes de emitir o documento.',
            ], 422);
        }

        try {
            $documentation = $this->service->issue(
                $action,
                $medicalrecord,
                $patient,
                $doctor,
                $entity,
                $payload,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        // F10 — CFM Res. 2.227/2018 + LGPD Art. 37: emissão de documento
        // (laudo, atestado, receituário) é tratada como "Report" para fins
        // de trilha. O recurso logado é a documentação criada (rastreio fino),
        // mas o vínculo com o paciente é preservado via patientId.
        $this->logAccess(
            $documentation,
            DataAccessPurpose::Report,
            patientId: $patient->id,
        );

        return response()->json([
            'id'         => $documentation->id,
            'type'       => $documentation->type,
            'type_label' => $documentation->getTypeLabel(),
            'title'      => $documentation->title,
            'created_at' => $documentation->created_at?->format('d/m/Y H:i'),
            'pdf_url'    => route('panel.patients.medicalrecords.documentations.pdf', [$patient, $medicalrecord, $documentation]),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, string $action): array
    {
        return match ($action) {
            'medical-certificate' => $request->validate([
                'days'    => ['required', 'integer', 'min:1', 'max:365'],
                'date'    => ['nullable', 'date_format:d/m/Y'],
                'content' => ['nullable', 'string', 'max:5000'],
            ]),
            'attendance-certificate' => $request->validate([
                'content' => ['nullable', 'string', 'max:5000'],
            ]),
            'cataract-prescription' => $request->validate([
                'eye'          => ['required', 'string', Rule::in(SurgerySchedulingDocService::eyeKeys())],
                'date_surgery' => ['nullable', 'date_format:d/m/Y'],
                'hour_surgery' => ['nullable', 'date_format:H:i'],
                'template'     => ['nullable', 'string', Rule::in(SurgerySchedulingDocService::templateKeys())],
            ]),
            // Declaração médica pode ser emitida em branco (médico preenche
            // manuscrito após impressão) — content opcional.
            'medical-declaration' => $request->validate([
                'content' => ['nullable', 'string'],
            ]),
            'medication-prescription', 'procedure-request' => $request->validate([
                'content' => ['required', 'string'],
            ]),
            'lens-prescription' => $request->validate([
                'mode' => ['required', 'in:dynamic,static,presbyopia_dynamic,presbyopia'],
            ]),
            'exam-report' => $request->validate([
                'exam_type' => ['required', 'string', Rule::in(array_map(fn (ExamReportRegistry $e) => $e->value, ExamReportRegistry::cases()))],
                'subtype'   => ['nullable', 'string', 'max:64'],
                'content'   => ['nullable', 'string'],
                'title'     => ['nullable', 'string', 'max:255'],
            ]),
            default => [],
        };
    }

    /**
     * Carrega template de laudo já com variáveis dinâmicas resolvidas e
     * placeholders clínicos do prontuário (auto-populate via ExamReportRegistry).
     *
     * Consumido pelo modal Alpine `openExam(examType)` antes de abrir o editor.
     * Retorna 422 se o template não existir na entidade — F4d cria seeds.
     */
    public function examTemplate(Request $request, Patient $patient, MedicalRecord $medicalrecord, string $exam): JsonResponse
    {
        $this->assertMedicalRecordBelongsToPatient($patient, $medicalrecord);
        $this->authorizeIssueReport();

        $registry = ExamReportRegistry::tryFrom($exam);

        if (! $registry) {
            return response()->json(['message' => "Tipo de exame inválido: {$exam}"], 422);
        }

        $entity = Entity::findOrFail(session('selected_entity_id'));
        $doctor = $medicalrecord->doctor ?? Doctor::with('person')
            ->whereHas('entityUser', fn ($q) => $q
                ->where('entity_id', $entity->id)
                ->where('user_id', auth()->id()))
            ->first();

        if (! $doctor) {
            return response()->json([
                'message' => 'Selecione o médico responsável antes de carregar o template.',
            ], 422);
        }

        // F4e — subtype opcional para exames multi-variante (Pentacam).
        $subtypeRaw = $request->query('subtype');
        $subtype    = is_string($subtypeRaw) && $subtypeRaw !== '' ? $subtypeRaw : null;

        try {
            $content = $this->service->findActiveTemplateForExamSubtype(
                (string) $entity->id,
                $registry,
                $subtype,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $resolved = $this->documentationService->loadTemplate(
            $content,
            $patient,
            $doctor,
            $entity,
            $medicalrecord,
        );

        // F10 — auto-populate de laudo lê dados clínicos do prontuário
        // (tonometria, refração, etc.) p/ preencher placeholders. Acesso
        // sensível, registra como PatientCare.
        $this->logAccess(
            $medicalrecord,
            DataAccessPurpose::PatientCare,
            patientId: $patient->id,
        );

        $title = $registry->label();

        if ($subtype !== null && ($subtypeLabel = $registry->subtypeLabel($subtype)) !== null) {
            $title = $registry->label() . ' — ' . $subtypeLabel;
        }

        // Strip placeholders restantes ({{CONTEUDO_LIVRE}}, etc.) — quick-action
        // resolve dinamicamente; em preview no docModal o médico edita manual,
        // placeholder literal só polui o textarea.
        $html = preg_replace('/\{\{[A-Z_][A-Z0-9_]*\}\}/u', '', $resolved['html']) ?? $resolved['html'];

        // Remove cabeçalho de data resolvido (`<p text-align:right>CIDADE, DATA</p>`
        // + `<p>&nbsp;</p>`) que vem do template — a data já é renderizada no
        // bloco de assinatura do PDF (`_signature.blade.php`); manter no textarea
        // duplica visualmente e polui o editor.
        $html = $this->stripResolvedDateHeader($html);

        return response()->json([
            'exam_type'    => $registry->value,
            'subtype'      => $subtype,
            'subtypes'     => $registry->subtypes(),
            'label'        => $registry->label(),
            'title'        => $title,
            'html'         => $html,
            'unresolved'   => $resolved['unresolved'],
            'autoPopulate' => $registry->autoPopulate(),
        ]);
    }

    /**
     * F7 — preview de "X (X por extenso) dias" usado em tempo real no atestado
     * médico. Mantém estado de soletração no servidor (NumberFormatter intl)
     * para evitar lib client-side e divergência com o PDF emitido.
     */
    public function dayExtensionPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        return response()->json([
            'days'    => $validated['days'],
            'spelt'   => $this->dayExtension->spell($validated['days']),
            'display' => $this->dayExtension->format($validated['days']),
        ]);
    }

    private function authorizeIssueReport(): void
    {
        $entityId = session('selected_entity_id');
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));
    }

    private function assertMedicalRecordBelongsToPatient(Patient $patient, MedicalRecord $medicalrecord): void
    {
        abort_if($medicalrecord->patient_id !== $patient->id, 404);
    }

    /**
     * Remove o `<p style="text-align:right">{LOCAL_DATA resolvido}</p>` + o
     * `<p>&nbsp;</p>` espaçador no INÍCIO do HTML — a data já será renderizada
     * no bloco de assinatura do PDF. Padrão pareia qualquer cidade/data, sem
     * hardcoded de mês/local, e só age no início do conteúdo.
     */
    private function stripResolvedDateHeader(string $html): string
    {
        $pattern = '/^\s*<p\s+[^>]*text-align\s*:\s*right[^>]*>[^<]*<\/p>\s*(?:<p[^>]*>&nbsp;<\/p>)?\s*/iu';

        return preg_replace($pattern, '', $html, 1) ?? $html;
    }
}
