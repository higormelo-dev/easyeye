<?php

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, EntityGate, ExamReportRegistry};
use App\Exceptions\AllergyConflictException;
use App\Models\{Doctor, Entity, MedicalRecord, Patient};
use App\Services\{DayExtensionService, MedicalRecordDocumentationService, MedicalRecordQuickActionService, ProcedureSolicitationService, SurgerySchedulingDocService};
use App\Services\Documents\CertificateBuilder;
use App\Support\CurrentDoctor;
use App\Support\Documents\DocumentDate;
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
        // Documento sai no nome de QUEM EMITE (médico logado, cadastro ativo —
        // o Gate IssueReport acima já exige), nunca no do prontuário/exame.
        $doctor = $this->issuer();

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
        } catch (AllergyConflictException $exception) {
            // Receita bate com alergia ativa (Fase 2 / A4): a tela mostra a
            // alergia/reação e pede o motivo para seguir.
            return response()->json($exception->toResponsePayload(), 422);
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
            // Data alternativa impressa (Fase 2 / B3) — a lista mostra as duas.
            'document_date' => $documentation->document_date?->format('d/m/Y'),
            'pdf_url'       => route('panel.patients.medicalrecords.documentations.pdf', [$patient, $medicalrecord, $documentation]),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, string $action): array
    {
        // Toda receita (medicamentos, catarata, pterígio, lentes) passa pela
        // checagem de alergia no service; o motivo para seguir vem aqui.
        $override = in_array($action, self::PRESCRIPTION_ACTIONS, true)
            ? $request->validate(['allergy_override_reason' => ['nullable', 'string', 'max:1000']])
            : [];

        // Data alternativa (Fase 2 / B3): receita, relatório, pedido de
        // exames e atestados — faixa de config/clinical_documents.php.
        $dated = in_array($action, self::DATED_ACTIONS, true)
            ? $request->validate(['document_date' => DocumentDate::rules()], DocumentDate::messages())
            : [];

        // Modelo pessoal usado (Fase 2 / B3) — receita e pedido; o service
        // confere que é do médico que emite.
        $template = in_array($action, ['medication-prescription', 'procedure-request'], true)
            ? $request->validate(['template_id' => ['nullable', 'uuid']])
            : [];

        return [...$override, ...$dated, ...$template, ...$this->actionPayload($request, $action)];
    }

    /** Ações que emitem documento do tipo receita (checagem de alergia — Fase 2 / A4). */
    private const PRESCRIPTION_ACTIONS = ['medication-prescription', 'cataract-prescription', 'pterygium-prescription', 'lens-prescription'];

    /** Documentos com data alternativa (Fase 2 / B3): receitas, relatório, pedido e atestados. */
    private const DATED_ACTIONS = [
        'medication-prescription', 'cataract-prescription', 'pterygium-prescription', 'lens-prescription',
        'medical-declaration', 'procedure-request',
        'medical-certificate', 'attendance-certificate', 'companion-certificate', 'visual-fitness-certificate',
    ];

    /**
     * @return array<string, mixed>
     */
    private function actionPayload(Request $request, string $action): array
    {
        $time = ['nullable', 'date_format:H:i'];

        return match ($action) {
            // Atestado médico (afastamento): CID OPCIONAL só com a autorização
            // do paciente (Res. CFM 1.658/2002, art. 5º) — sem ela, 422.
            'medical-certificate' => $request->validate([
                'days'           => ['required', 'integer', 'min:1', 'max:365'],
                'date'           => ['nullable', 'date_format:d/m/Y'],
                'content'        => ['nullable', 'string', 'max:5000'],
                'cid'            => ['nullable', 'string', 'max:10'],
                'cid_authorized' => ['exclude_without:cid', 'accepted'],
            ], [
                'cid_authorized.accepted' => __('clinical_documents.certificates.cid_authorization_required'),
            ]),
            // Comparecimento: horário de/até ou período, e dilatação pupilar
            // (não dirigir/operar máquinas por N horas).
            'attendance-certificate' => $request->validate([
                ...$this->visitRules($time),
                'content'        => ['nullable', 'string', 'max:5000'],
                'dilation'       => ['nullable', 'boolean'],
                'dilation_hours' => [
                    'required_if_accepted:dilation', 'nullable', 'integer',
                    'min:' . (int) config('clinical_documents.dilation_hours.min', 1),
                    'max:' . (int) config('clinical_documents.dilation_hours.max', 24),
                ],
            ], $this->visitMessages()),
            'companion-certificate' => $request->validate([
                ...$this->visitRules($time),
                'companion_name'     => ['required', 'string', 'min:3', 'max:160'],
                'companion_document' => ['nullable', 'string', 'max:40'],
                'content'            => ['nullable', 'string', 'max:5000'],
            ], $this->visitMessages()),
            // Aptidão visual: a AV vem do PRONTUÁRIO (servidor), não da tela.
            'visual-fitness-certificate' => $request->validate([
                'conclusion'    => ['required', 'string', Rule::in(array_keys(CertificateBuilder::CONCLUSIONS))],
                'purpose'       => ['required', 'string', Rule::in(array_keys(CertificateBuilder::PURPOSES))],
                'purpose_other' => ['required_if:purpose,other', 'nullable', 'string', 'max:120'],
                'content'       => ['nullable', 'string', 'max:5000'],
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
            // Receituário: além do texto, os itens ESTRUTURADOS (catálogo ou
            // nome livre) — a checagem de alergia usa o princípio ativo deles.
            'medication-prescription' => $request->validate([
                'content'                   => ['required', 'string'],
                'items'                     => ['nullable', 'array', 'max:30'],
                'items.*.medicine_id'       => ['nullable', 'uuid'],
                'items.*.name'              => ['nullable', 'string', 'max:255'],
                'items.*.active_ingredient' => ['nullable', 'string', 'max:1000'],
            ]),
            // Pedido de exames (Fase 2 / B2): itens estruturados (kit,
            // catálogo da clínica ou avulso com código TUSS), caráter,
            // indicações, CIDs e observações — o corpo é montado no servidor.
            // Sem itens, vale o texto livre antigo (`content`).
            'procedure-request' => $request->validate([
                'content'              => ['required_without:items', 'nullable', 'string', 'max:10000'],
                'items'                => ['nullable', 'array', 'max:' . ProcedureSolicitationService::MAX_PROCEDURES],
                'items.*'              => ['array'],
                'items.*.procedure_id' => ['nullable', 'uuid'],
                'items.*.code'         => ['nullable', 'string', 'max:10', 'regex:/^[0-9A-Za-z.\-]+$/'],
                'items.*.name'         => ['required_without:items.*.procedure_id', 'nullable', 'string', 'max:160'],
                'items.*.kit_id'       => ['nullable', 'uuid'],
                'kit_ids'              => ['nullable', 'array', 'max:' . ProcedureSolicitationService::MAX_KITS],
                'kit_ids.*'            => ['uuid'],
                'type'                 => ['nullable', 'string', Rule::in(ProcedureSolicitationService::typeKeys())],
                'indications'          => ['nullable', 'array', 'max:' . ProcedureSolicitationService::MAX_INDICATIONS],
                'indications.*'        => ['uuid'],
                'indication_text'      => ['nullable', 'string', 'max:500'],
                'cids'                 => ['nullable', 'array', 'max:' . ProcedureSolicitationService::MAX_CIDS],
                'cids.*'               => ['string', 'max:10'],
                'notes'                => ['nullable', 'string', 'max:2000'],
            ], [
                'items.max' => __('clinical_documents.request.items_max', ['max' => ProcedureSolicitationService::MAX_PROCEDURES]),
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
     * Dia e horário/período de comparecimento (comparecimento e acompanhante).
     *
     * @param list<string> $time
     *
     * @return array<string, list<mixed>>
     */
    private function visitRules(array $time): array
    {
        return [
            'date'      => DocumentDate::rules(),
            'time_from' => [...$time, 'required_with:time_to'],
            'time_to'   => [...$time, 'required_with:time_from', 'after:time_from'],
            'period'    => ['nullable', 'string', Rule::in(array_keys(CertificateBuilder::PERIODS))],
        ];
    }

    /** @return array<string, string> */
    private function visitMessages(): array
    {
        return [
            ...DocumentDate::messages('date'),
            'time_to.after' => __('clinical_documents.certificates.time_order'),
        ];
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
        // Prévia com o médico que vai emitir (o mesmo de issue()).
        $doctor = $this->issuer();

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

    /** Médico emissor (logado, cadastro ativo) — 403 traduzido se não houver. */
    private function issuer(): Doctor
    {
        $doctor = CurrentDoctor::issuer();
        abort_if($doctor === null, 403, CurrentDoctor::missingMessage());

        return $doctor->loadMissing('person');
    }

    private function authorizeIssueReport(): void
    {
        $entityId = session('selected_entity_id');
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));
    }

    private function assertMedicalRecordBelongsToPatient(Patient $patient, MedicalRecord $medicalrecord): void
    {
        // Achado de segurança (auditoria panel.* IDOR) — ver mesmo comentário em
        // MedicalRecordsController::assertMedicalRecordBelongsToPatient().
        abort_unless((string) $patient->entity_id === (string) session('selected_entity_id'), 404);
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
