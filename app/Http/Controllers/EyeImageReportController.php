<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, DocumentationType, EntityGate};
use App\Exceptions\AllergyConflictException;
use App\Http\Controllers\Concerns\AuthorizesEyeImageExams;
use App\Models\{Doctor, Entity, EyeImageReportDraft, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, ReportSettingContent, Schedule};
use App\Services\{ConsultationRecordResolver, MedicalRecordDocumentationService};
use App\Services\EyeImages\{PdfTextExtractionService, ReportExamSummary, ReportImageBlockBuilder};
use App\Support\ClinicalSafety\PrescriptionAllergyGuard;
use App\Support\CurrentDoctor;
use App\Support\Documents\DocumentDate;
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\Rule;

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
    use AuthorizesEyeImageExams;
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
        private readonly PdfTextExtractionService $pdfTextExtraction,
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
        $entityId = (string) session('selected_entity_id');
        Gate::authorize(EntityGate::DraftImageReport->value, Entity::findOrFail($entityId));

        $validated = $request->validate([
            'report_setting_content_id' => ['required', 'uuid', 'exists:report_setting_contents,id'],
            'patient_id'                => ['required', 'uuid', 'exists:patients,id'],
            // max:50 — nenhuma sessão de exame real chega perto disso; sem
            // limite, doctor autenticado podia mandar array gigante e forçar
            // um whereIn() caro (achado na revisão de segurança 09/09/2026).
            'exam_ids'   => ['nullable', 'array', 'max:50'],
            'exam_ids.*' => ['uuid'],
            // Data alternativa (mesma faixa/regra de store()) — sem isso o
            // preview sempre resolvia {{DATA_ATUAL}}/{{LOCAL_DATA}} com hoje,
            // divergindo do PDF final quando o médico escolhe outra data.
            'document_date' => DocumentDate::rules(),
        ], DocumentDate::messages());

        $patient = Patient::query()->where('entity_id', $entityId)->findOrFail($validated['patient_id']);
        $content = ReportSettingContent::findOrFail($validated['report_setting_content_id']);
        $this->documentationService->assertTemplateBelongsToEntity($content, $entityId);

        $examIds = $this->ownedExamIds($validated['exam_ids'] ?? [], $entityId, (string) $patient->id);
        $this->assertExamsActive($examIds);

        [$scheduleId, $consultationDate] = $this->recordResolver->anchorForExamIds($examIds);
        $doctorId                        = $this->resolveDoctorIdForPreview($entityId, $scheduleId, $examIds);
        abort_if(! $doctorId, 422, __('eye_images.report_doctor_required'));

        $record       = $this->recordResolver->findRecord($entityId, (string) $patient->id, $scheduleId, $consultationDate);
        $documentDate = DocumentDate::alternative($validated['document_date'] ?? null);

        $resolved = $this->documentationService->loadTemplate(
            $content,
            $patient,
            Doctor::findOrFail($doctorId),
            Entity::findOrFail($entityId),
            $record,
            $documentDate,
        );

        $this->logAccess($patient, DataAccessPurpose::PatientCare);

        return response()->json([
            'content' => $this->documentationService->stripResolvedPlaceholders($resolved['html']),
            // Médico usado AGORA pra resolver {{MEDICO_NOME}}/{{MEDICO_CRM}}
            // (se o template citar — os seedados não citam). O front carrega
            // isto pro rascunho (resolved_doctor_id); se quem EMITIR depois
            // for outro médico, store() avisa — nunca edita o texto sozinho
            // (achado via revisão Codex).
            'resolved_doctor_id' => $doctorId,
        ]);
    }

    /**
     * Extrai o texto puro do PDF nativo do equipamento (Pentacam etc.) pro
     * editor — benchmark 18/09/2026 ("Extraindo texto do PDF..." no vídeo
     * de referência do ticket). Mesmo Gate/tenant/active dos outros
     * endpoints de laudo: o texto extraído alimenta diretamente um
     * documento clínico, então é ato de laudo, não leitura genérica.
     */
    public function extractPdfText(Request $request): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        Gate::authorize(EntityGate::DraftImageReport->value, Entity::findOrFail($entityId));

        $validated = $request->validate([
            'exam_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'exam_ids.*' => ['uuid'],
        ]);

        $examIds = $this->ownedExamIds($validated['exam_ids'], $entityId);
        $this->assertExamsActive($examIds);

        $exams = PatientExam::query()->whereIn('id', $examIds)->get();
        $texts = $this->pdfTextExtraction->extractMany($exams->all());

        abort_if($texts === [], 422, __('eye_images.pdf_no_text_found'));

        return response()->json(['text' => implode("\n\n---\n\n", $texts)]);
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
            'patient_id' => ['required', 'uuid', 'exists:patients,id'],
            // max:50 — nenhuma sessão de exame real chega perto disso; sem
            // limite, doctor autenticado podia mandar array gigante e forçar
            // um whereIn() caro (achado na revisão de segurança 09/09/2026).
            'exam_ids'                  => ['nullable', 'array', 'max:50'],
            'exam_ids.*'                => ['uuid'],
            'report_setting_content_id' => ['nullable', 'uuid', 'exists:report_setting_contents,id'],
            'title'                     => ['nullable', 'string', 'max:255'],
            'content'                   => ['required', 'string'],
            'confirm_open_record'       => ['nullable', 'boolean'],
            // Modelo do tipo receita (raro aqui): mesma checagem de alergia
            // do receituário (Fase 2 / A4).
            'allergy_override_reason' => ['nullable', 'string', 'max:1000'],
            // Indicação clínica, observações e data alternativa (Fase 5) —
            // mesma faixa/regra que receita/atestado/pedido já usam.
            'clinical_indication' => ['nullable', 'string', 'max:2000'],
            'observations'        => ['nullable', 'string', 'max:4000'],
            'document_date'       => DocumentDate::rules(),
            // Rascunho de secretária/admin que o médico está revisando e
            // emitindo agora (Fase 5) — opcional, fluxo direto continua igual.
            'draft_id' => ['nullable', 'uuid', 'exists:eye_image_report_drafts,id'],
            // Token de revisão do rascunho (EyeImageReportDraftController::
            // serialize(), coluna revision — contador dedicado, nunca
            // updated_at: timestamps() aqui usa precision 0, então 2 saves
            // no mesmo segundo colidiriam no mesmo valor e uma correção real
            // passaria sem ser detectada — 2ª rodada de revisão adversarial
            // Codex). Comparado sob o lock dentro da transação abaixo,
            // nunca aqui fora dela. required_with:draft_id, nunca nullable
            // sozinho: um draft_id SEM draft_revision desligava a checagem
            // de obsolescência por completo — bastava omitir o campo pra
            // emitir por cima da correção de qualquer um (achado na 3ª
            // rodada de revisão adversarial Codex).
            'draft_revision' => ['required_with:draft_id', 'integer', 'min:1'],
            // Médico que previu ≠ quem está emitindo (ver physician_mismatch_warning
            // abaixo) — true só depois do médico CONFIRMAR que revisou o texto.
            'confirm_physician_mismatch' => ['nullable', 'boolean'],
            // Imagem selecionada que não pôde ser congelada agora (ver
            // image_report_incomplete_warning abaixo) — true só depois do
            // médico CONFIRMAR que quer emitir mesmo assim.
            'confirm_image_report_incomplete' => ['nullable', 'boolean'],
            // Mesma ideia, pras referências inline <img data-exam-image-id>
            // deixadas em content pelo botão "Inserir imagem" (ver
            // inline_images_incomplete_warning abaixo) — achado via revisão
            // adversarial Codex.
            'confirm_inline_images_incomplete' => ['nullable', 'boolean'],
            // Médico solicitante (Fase 5) — texto livre, pode não ter cadastro
            // no EasyEye; com e-mail presente, dispara o envio por e-mail.
            'requesting_doctor_name'  => ['nullable', 'string', 'max:255'],
            'requesting_doctor_email' => ['nullable', 'email', 'max:255'],
            // Imagens embutidas no laudo assinado (Fase 5 / 5b) — resolveImageReportBlock()
            // revalida exam_id contra $examIds; nunca confia cru no payload.
            'image_report'                  => ['nullable', 'array'],
            'image_report.enabled'          => ['nullable', 'boolean'],
            'image_report.columns'          => ['nullable', 'integer', Rule::in(config('eye_image_capture.image_report.allowed_columns'))],
            'image_report.pair_od_oe'       => ['nullable', 'boolean'],
            'image_report.images'           => ['nullable', 'array', 'max:50'],
            'image_report.images.*.exam_id' => ['required', 'uuid'],
            'image_report.images.*.caption' => ['nullable', 'string', 'max:255'],
            'image_report.images.*.order'   => ['nullable', 'integer'],
        ], DocumentDate::messages());

        $patient = Patient::query()->where('entity_id', $entityId)->findOrFail($validated['patient_id']);
        $examIds = $this->ownedExamIds($validated['exam_ids'] ?? [], $entityId, (string) $patient->id);
        $this->assertExamsActive($examIds);

        $draft = isset($validated['draft_id']) ? EyeImageReportDraft::findOrFail($validated['draft_id']) : null;

        if ($draft) {
            // 404, não 422: rascunho de outra clínica deve parecer "não
            // existe" (mesmo raciocínio de EyeImageReportDraftController)
            // — nunca confiar só no EntityScope global aqui.
            abort_if((string) $draft->entity_id !== $entityId, 404);
            abort_if(! $draft->isEditable(), 422, __('eye_images.draft_not_editable'));
            abort_if((string) $draft->patient_id !== (string) $patient->id, 422, __('eye_images.report_same_patient'));
        }

        // Resolvido uma vez só e reaproveitado tanto pra abrir o prontuário
        // (quando faltar) quanto pra autoria da documentação abaixo — nunca
        // $record->doctor_id: o prontuário pode já existir sob OUTRO médico
        // da mesma clínica (cobertura/plantão), e Gate::IssueReport autoriza
        // por role, não por posse do prontuário.
        $doctorId = $this->resolveDoctorId($entityId, $examIds);
        abort_if(! $doctorId, 422, __('eye_images.report_doctor_required'));

        // resolved_doctor_id = médico usado na ÚLTIMA pré-visualização deste
        // rascunho (Fase 5 / revisão Codex). Se for outro médico emitindo
        // agora, o texto já resolvido pode citar {{MEDICO_NOME}}/{{MEDICO_CRM}}
        // de QUEM PREVIU, não de quem está assinando — nunca editamos esse
        // texto automaticamente (risco de trocar o nome errado num parágrafo
        // clínico qualquer), só avisamos pra revisão manual antes de assinar.
        $physicianMismatchWarning = null;

        if ($draft && $draft->resolved_doctor_id && (string) $draft->resolved_doctor_id !== (string) $doctorId) {
            $physicianMismatchWarning = __('eye_images.report_physician_mismatch_warning', [
                'doctor' => $draft->resolvedDoctor?->person?->full_name ?? __('eye_images.report_physician_mismatch_unknown_doctor'),
            ]);
        }

        // Bloqueia ANTES de criar qualquer coisa — nunca depois (achado via
        // revisão Codex): o aviso só aparecia na resposta de SUCESSO, quando
        // o documento já estava criado, o rascunho já marcado Issued e o
        // e-mail do médico solicitante (se houver) já enviado de verdade
        // (afterCommit, dentro desta mesma request) — o médico via o aviso
        // só DEPOIS que um laudo com o nome errado já tinha saído pra fora
        // da clínica. 422 aqui, mesmo padrão de requires_record_confirmation
        // abaixo: o front mostra o aviso e só reenvia com
        // confirm_physician_mismatch=true depois do médico revisar.
        if ($physicianMismatchWarning !== null && ! (bool) ($validated['confirm_physician_mismatch'] ?? false)) {
            return response()->json([
                'physician_mismatch_warning' => $physicianMismatchWarning,
            ], 422);
        }

        $sanitized     = $this->sanitizeReportContent($validated['content'], $examIds);
        $reportContent = isset($validated['report_setting_content_id'])
            ? ReportSettingContent::findOrFail($validated['report_setting_content_id'])
            : null;

        if ($reportContent) {
            $this->documentationService->assertTemplateBelongsToEntity($reportContent, $entityId);
        }

        $documentDate = DocumentDate::alternative($validated['document_date'] ?? null);
        $metadata     = array_filter([
            'clinical_indication' => $validated['clinical_indication'] ?? null,
            'observations'        => $validated['observations'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        // Resolve e CONGELA agora (mesmo motivo/ordem do bloco de imagens
        // abaixo: 422 ANTES de criar qualquer coisa) as referências inline
        // <img data-exam-image-id> deixadas em content pelo botão "Inserir
        // imagem" — achado via revisão adversarial Codex (o profile
        // 'medical' excluía <img>, a imagem inserida desaparecia em
        // silêncio ao salvar, inclusive no laudo final).
        ['html' => $sanitized, 'images' => $inlineImages, 'missing' => $missingInlineCount, 'expanded_bytes' => $inlineExpandedBytes]
            = app(ReportImageBlockBuilder::class)->freezeInlineImages($sanitized);

        // A MESMA imagem referenciada várias vezes no texto multiplica o
        // peso esperado quando contentForRender() expande cada ocorrência
        // de volta pro data_uri inteiro — mesmo pagando o orçamento de
        // resolução só 1 vez por exame único (freezeInlineImages() acima).
        // Nunca um "confirma mesmo assim" aqui (diferente do aviso abaixo):
        // não existe emissão aceitável que depois vai falhar em TODA
        // tentativa futura de visualizar/imprimir (achado via revisão
        // adversarial Codex).
        abort_if(
            $inlineExpandedBytes > (int) config('eye_image_capture.image_report.max_embedded_bytes'),
            422,
            __('eye_images.report_inline_images_too_large'),
        );

        if ($missingInlineCount > 0 && ! (bool) ($validated['confirm_inline_images_incomplete'] ?? false)) {
            return response()->json([
                'inline_images_incomplete_warning' => trans_choice(
                    'eye_images.report_inline_image_incomplete_warning',
                    $missingInlineCount,
                    ['count' => $missingInlineCount],
                ),
            ], 422);
        }

        if ($inlineImages !== []) {
            $metadata['inline_images'] = $inlineImages;
        }

        $imageReportBlock = $this->resolveImageReportBlock($validated['image_report'] ?? null, $examIds);

        if ($imageReportBlock !== null) {
            // Resolve e CONGELA os bytes AGORA, antes de emitir — nunca deixa
            // o laudo assinado reabrir exam_id/Storage depois (achado via
            // revisão adversarial Codex, 2ª rodada: exame desabilitado/
            // excluído ou arquivo sumindo do S3 fazia a imagem desaparecer
            // numa reimpressão futura sem nenhum aviso, inclusive no link
            // público do médico solicitante). Mesmo motivo de
            // physician_mismatch_warning acima (e de requires_record_
            // confirmation mais abaixo ainda): 422 ANTES de criar qualquer
            // coisa — inclusive antes do PRÓPRIO prontuário, que
            // withRecordOpeningLock() abriria e JÁ COMITARIA mesmo que o
            // médico cancelasse este aviso depois (achado no /codex:review:
            // "an empty medical record remains despite the rejected report
            // operation" — esta checagem vinha DEPOIS da abertura do
            // prontuário).
            ['block' => $resolvedImageReport, 'missing' => $missingImageCount] = app(ReportImageBlockBuilder::class)->resolveForIssuance($imageReportBlock);

            if ($missingImageCount > 0 && ! (bool) ($validated['confirm_image_report_incomplete'] ?? false)) {
                return response()->json([
                    'image_report_incomplete_warning' => trans_choice(
                        'eye_images.report_image_incomplete_warning',
                        $missingImageCount,
                        ['count' => $missingImageCount],
                    ),
                ], 422);
            }

            if ($resolvedImageReport !== null) {
                // Mantém o MESMO shape que MedicalRecordDocumentation::
                // imageReportBlock() exige pra devolver o bloco de volta
                // (enabled true é o portão do accessor) — resolveForIssuance()
                // já devolve images no formato {data_uri, caption} (bytes
                // congelados); pair_od_oe fica sempre false aqui porque o
                // pareamento OD/OE já foi aplicado na ORDEM da lista, nunca
                // reaplicado numa leitura futura (que nem tem mais o objeto
                // PatientExam pra reagrupar).
                $metadata['image_report'] = [
                    'enabled'    => true,
                    'columns'    => $resolvedImageReport['columns'],
                    'pair_od_oe' => false,
                    'images'     => $resolvedImageReport['images'],
                ];
            }
        }

        // Retrato dos exames NA EMISSÃO (ReportExamSummary): o laudo assinado — e o
        // link público do médico solicitante — não pode mudar porque o exame de
        // origem foi editado depois (lateralidade, mesclar/dividir, renomear).
        // Valores brutos (data ISO, código do olho); o PDF os localiza ao imprimir.
        // Achado via revisão adversarial Codex (20ª rodada).
        if ($examIds !== []) {
            $metadata[ReportExamSummary::SNAPSHOT_KEY] = app(ReportExamSummary::class)->snapshotForExamIds($examIds);
        }

        try {
            $override = $reportContent?->type === DocumentationType::Prescription
                ? app(PrescriptionAllergyGuard::class)->guard($patient, $sanitized, [], $validated['allergy_override_reason'] ?? null)
                : null;
        } catch (AllergyConflictException $exception) {
            return response()->json($exception->toResponsePayload(), 422);
        }

        // Resolução do prontuário (leitura, sem escrita) só DEPOIS de TODAS
        // as checagens que podem rejeitar com 422 acima (divergência de
        // médico, imagem incompleta, conflito de alergia) — nunca antes
        // (achado no /codex:review). A ABERTURA de verdade (escreve e
        // comita) fica pra dentro da transação abaixo: ela sozinha, fora da
        // transação do documento, sobrevivia a um 409/422 que só se
        // descobre DEPOIS de abrir (rascunho obsoleto, não mais editável) —
        // o prontuário ficava vazio e órfão pra sempre mesmo com a emissão
        // rejeitada (achado na 9ª rodada de revisão adversarial Codex).
        [$scheduleId, $consultationDate] = $this->recordResolver->anchorForExamIds($examIds);
        $record                          = $this->recordResolver->findRecord($entityId, (string) $patient->id, $scheduleId, $consultationDate);

        if (! $record && ! (bool) ($validated['confirm_open_record'] ?? false)) {
            return response()->json([
                'requires_record_confirmation' => true,
                'consultation_date'            => $consultationDate,
            ], 422);
        }

        // Laudo + vínculo com os exames na mesma transação: nunca um laudo
        // salvo sem registro de quais exames ele cobre (o PDF lista esses
        // exames — ReportExamSummary; pode ser mais de um, laudo conjunto).
        $requestingDoctorName  = $validated['requesting_doctor_name'] ?? null;
        $requestingDoctorEmail = $validated['requesting_doctor_email'] ?? null;

        // $record (e não só $documentation) volta da transação porque pode
        // ter sido aberto AQUI DENTRO agora (ver comentário acima) — a
        // variável de fora, capturada por valor na closure, nunca vê essa
        // atribuição; a resposta abaixo (medical_record_id, rota do PDF)
        // precisa do prontuário de verdade, nunca ficar com o $record nulo
        // de antes de abrir.
        [$record, $documentation] = DB::transaction(function () use ($reportContent, $record, $scheduleId, $consultationDate, $sanitized, $validated, $doctorId, $examIds, $entityId, $override, $documentDate, $metadata, $draft, $requestingDoctorName, $requestingDoctorEmail, $patient) {
            // Abrir prontuário é ato médico (mesma regra de AiRunsController::openRecordForRun).
            // Relê sob o lock de abertura do paciente: outra requisição (outro
            // laudo, prontuário do laudo de IA) pode ter aberto o prontuário da
            // consulta depois da busca de fora — reaproveita em vez de abrir um
            // segundo. Dentro da MESMA transação do documento (ver comentário
            // acima): pg_advisory_xact_lock de withRecordOpeningLock() usa
            // savepoint da transação já aberta, então o lock e o commit real
            // só valem pro commit EXTERNO — um abort_if mais abaixo desfaz os
            // dois juntos.
            if (! $record) {
                $record = MedicalRecord::withRecordOpeningLock(
                    $entityId,
                    (string) $patient->id,
                    // Abre já com o retrato de alergias/medicações (Fase 2 / G3).
                    fn (): MedicalRecord => $this->recordResolver->findRecord($entityId, (string) $patient->id, $scheduleId, $consultationDate)
                        ?? $this->recordResolver->openRecord($entityId, (string) $patient->id, (string) $doctorId, $scheduleId),
                );
            }

            // Tranca e relê o rascunho AQUI DENTRO: a checagem isEditable() de
            // fora (antes da transação) só evita o caso comum. Entre aquela
            // checagem e este ponto, OUTRA requisição (2 médicos emitindo o
            // MESMO rascunho quase ao mesmo tempo) pode ter passado pela
            // mesma checagem e já estar comitando — sem o lock, as duas
            // criam documento e chamam markIssued(), e issued_documentation_id
            // fica só com a última a comitar, orfanando o documento da
            // primeira (achado via revisão Codex).
            $draftId = $draft?->id;
            $draft   = $draftId ? EyeImageReportDraft::query()->whereKey($draftId)->lockForUpdate()->first() : null;
            // $draftId presente exige um rascunho editável relido AQUI — nunca
            // segue em silêncio se ele desapareceu ou mudou de status entre a
            // checagem de fora e este ponto.
            abort_if($draftId && (! $draft || ! $draft->isEditable()), 422, __('eye_images.draft_not_editable'));

            // Revisão obsoleta: o médico abriu este rascunho, outra pessoa
            // salvou uma correção (novo revision) e o médico emite em
            // seguida sem nunca ter visto o texto novo — $sanitized aqui
            // vem do que o NAVEGADOR dele carregou antes da correção, não
            // do que o rascunho tem agora sob o lock (achado via revisão
            // adversarial Codex: a correção sai do laudo emitido, some pra
            // sempre, e o rascunho trava em Issued). Comparação sob o MESMO
            // lock acima, não antes dele. Contador dedicado (revision),
            // nunca updated_at: esta tabela usa timestamps() com precision 0
            // (sem fração de segundo) — 2 saves no mesmo segundo colidiriam
            // no mesmo valor e uma correção real passaria sem ser detectada
            // (2ª rodada de revisão adversarial Codex). draft_revision é
            // required_with:draft_id na validação — nunca comparado só
            // "quando presente": omitir o campo desligava a checagem por
            // completo (achado na 3ª rodada de revisão adversarial Codex).
            abort_if(
                $draftId && $draft->revision !== (int) $validated['draft_revision'],
                409,
                __('eye_images.draft_revision_conflict'),
            );

            $documentation = $reportContent
                ? $this->documentationService->store(
                    $record,
                    $reportContent,
                    $sanitized,
                    $validated['title'] ?? null,
                    (string) $doctorId,
                    $override,
                    documentDate: $documentDate,
                    metadata: $metadata,
                )
                // Laudo em branco (sem modelo) — mesmo default de título do laudo de IA.
                : MedicalRecordDocumentation::create([
                    'medical_record_id' => $record->id,
                    'patient_id'        => $record->patient_id,
                    'doctor_id'         => $doctorId,
                    'type'              => DocumentationType::Report->value,
                    'title'             => $validated['title'] ?? __('eye_images.report_default_title'),
                    'content'           => $sanitized,
                    'document_date'     => $documentDate?->toDateString(),
                    'metadata'          => $metadata !== [] ? $metadata : null,
                ]);

            $documentation->syncExams($examIds, $entityId);

            // Rascunho revisado e emitido agora (Fase 5) — nunca emite por si
            // só (ver EyeImageReportDraftController), só fecha o ciclo aqui,
            // na mesma transação do documento que ele originou.
            $draft?->markIssued((string) $documentation->id);

            // Nome e e-mail do médico solicitante são campos INDEPENDENTES —
            // o usuário pode informar só o nome (sem e-mail pra notificar) ou
            // só o e-mail. Antes, os dois só eram gravados dentro do if de
            // e-mail: informar nome sem e-mail era aceito sem erro mas o
            // nome nunca chegava a persistir, e emitir um rascunho que já
            // tinha nome salvo (sem e-mail) apagava esse nome silenciosamente
            // (achado via revisão Codex).
            if ($requestingDoctorName !== null || $requestingDoctorEmail !== null) {
                $documentation->update([
                    'requesting_doctor_name'  => $requestingDoctorName,
                    'requesting_doctor_email' => $requestingDoctorEmail,
                ]);
            }

            // O ENVIO ao médico solicitante NÃO acontece aqui. O laudo nasce
            // dentro de um prontuário que ainda não pode estar assinado
            // (GuardedBySignedRecord recusa documento novo em prontuário
            // assinado) — então o documento ainda é editável e excluível pela
            // clínica. Mandar o link público agora entregava a um terceiro um
            // laudo que podia mudar ou sumir depois (achado via revisão
            // adversarial Codex). Só o destinatário fica gravado; o envio é
            // liberado quando o prontuário é assinado (imutável), pelo
            // EyeImageReportResendController — o mesmo requisito do
            // compartilhamento com o paciente e da leitura pelo link público
            // (ExternalMedicalReportController).

            if ($override !== null) {
                app(PrescriptionAllergyGuard::class)->recordOverride($documentation, $override);
            }

            return [$record, $documentation];
        });

        return response()->json([
            'id'                      => $documentation->id,
            'medical_record_id'       => $record->id,
            'title'                   => $documentation->title,
            'created_at'              => $documentation->created_at?->format('d/m/Y H:i'),
            'pdf_url'                 => route('panel.patients.medicalrecords.documentations.pdf', [$patient, $record, $documentation]),
            'requesting_doctor_email' => $documentation->requesting_doctor_email,
            // Sempre null na emissão: o envio só é liberado com o prontuário
            // assinado (ver o comentário em store(), dentro da transação).
            'requesting_doctor_sent_at'            => null,
            'requesting_doctor_awaiting_signature' => filled($documentation->requesting_doctor_email),
            'physician_mismatch_warning'           => $physicianMismatchWarning,
        ], 201);
    }

    /**
     * Imagem desabilitada (`active=false`, menu de contexto do Gerenciador
     * de Imagens) nunca entra num laudo/PDF novo — 422 de regra de negócio,
     * DE PROPÓSITO separado do 403 de posse de ownedExamIds() acima (posse
     * de outra clínica é tentativa de acesso; imagem desabilitada é uma
     * request válida que só viola uma regra do domínio — HTTP status
     * diferente, semântica diferente, nunca misturar os dois).
     *
     * @param list<string> $examIds já validados como pertencentes ao tenant
     */
    private function assertExamsActive(array $examIds): void
    {
        if ($examIds === []) {
            return;
        }

        $hasInactive = PatientExam::query()
            ->whereIn('id', $examIds)
            ->where('active', false)
            ->exists();

        abort_if($hasInactive, 422, __('eye_images.report_inactive_exam'));
    }

    /**
     * @param list<string> $examIds
     */
    private function resolveDoctorId(string $entityId, array $examIds): ?string
    {
        // Laudo no nome de quem emite (cadastro de médico ativo — o Gate
        // IssueReport exige); o médico do exame não assina mais por ele.
        $doctorId = $this->recordResolver->resolveDoctorIdForCurrentUser($entityId);
        abort_if($doctorId === null, 403, CurrentDoctor::missingMessage());

        return $doctorId;
    }

    /**
     * Médico só pra resolver variável no PREVIEW (rascunho) — NUNCA usado
     * por store(). Quem está logado já sendo médico cadastrado usa a MESMA
     * regra de resolveDoctorId() acima; secretária/admin rascunhando (sem
     * cadastro de médico) vê o médico do agendamento ligado ao exame, só
     * pra mostrar como o modelo fica — não credita ninguém. Quem de fato
     * assina resolve de novo, do zero, em store() (Fase 5).
     *
     * Sem $scheduleId (exame importado/capturado sem agendamento — import
     * manual ou câmera, ver ExternalExamImportService), cai pro doctor_id
     * gravado no(s) PatientExam selecionado(s): é o médico escolhido no
     * campo "Médico" do ImportExternalExamModal, só pra mostrar como o
     * modelo fica — a autoria de fato continua resolvida do zero em store().
     *
     * @param list<string> $examIds
     */
    private function resolveDoctorIdForPreview(string $entityId, ?string $scheduleId, array $examIds = []): ?string
    {
        $doctorId = $this->recordResolver->resolveDoctorIdForCurrentUser($entityId);

        if ($doctorId !== null) {
            return $doctorId;
        }

        if ($scheduleId) {
            $doctorId = Schedule::query()->whereKey($scheduleId)->value('doctor_id');

            if ($doctorId !== null) {
                return $doctorId;
            }
        }

        return $examIds !== []
            ? PatientExam::query()->whereIn('id', $examIds)->whereNotNull('doctor_id')->value('doctor_id')
            : null;
    }

    private function authorizeIssueReport(string $entityId): void
    {
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));
    }
}
