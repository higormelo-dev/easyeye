<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\{DataAccessPurpose, DraftStatus, EntityGate};
use App\Http\Controllers\Concerns\AuthorizesEyeImageExams;
use App\Models\{Entity, EyeImageReportDraft, Patient, ReportSettingContent, User};
use App\Notifications\ImageReportDraftNotification;
use App\Services\MedicalRecordDocumentationService;
use App\Support\CurrentDoctor;
use App\Support\Documents\DocumentDate;
use App\Traits\LogsDataAccess;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\Rule;

/**
 * Rascunho de laudo de imagem (Fase 5 — secretária/admin preparam, médico
 * revisa e emite). Nunca emite documento assinado por si só — a emissão de
 * fato continua 100% em EyeImageReportController::store() (campo draft_id
 * opcional, doctor-only via Gate::IssueReport).
 *
 * Sem granularidade de "dono" de propósito (camada leve pedida pelo dono):
 * qualquer admin/doctor/secretary da clínica edita/descarta o rascunho de
 * um colega — isolamento por clínica é checado explicitamente abaixo
 * (assertDraftOwnership), não só pelo EntityScope global: o binding
 * implícito de {draft} resolve ANTES do middleware tenant.bind vincular o
 * TenantContext nesta pilha de middleware, então o scope fica inerte no
 * momento da resolução da rota (confirmado em teste — sem o check manual,
 * um rascunho de outra clínica era lido/editado normalmente).
 */
class EyeImageReportDraftController extends Controller
{
    use AuthorizesEyeImageExams;
    use LogsDataAccess;

    public function __construct(
        private readonly MedicalRecordDocumentationService $documentationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $entityId = $this->authorizeDraft();

        // Status desconhecido era repassado cru ao where (lista vazia sem
        // explicação): fila da tela só aceita os status do enum.
        $request->validate([
            'status' => ['nullable', Rule::in(array_column(DraftStatus::cases(), 'value'))],
            'mine'   => ['nullable', 'boolean'],
        ]);

        $status = $request->query('status');
        $query  = EyeImageReportDraft::query()
            ->where('entity_id', $entityId)
            ->with(['patient.person', 'resolvedDoctor.person'])
            ->latest('updated_at');

        if ($status !== null) {
            $query->where('status', $status);
        }

        // "Só os meus": para o médico, o que está endereçado a ele (o médico da
        // pré-visualização — resolved_doctor_id — ou sem destinatário); para
        // quem prepara, o que ele mesmo preparou. Não esconde nada por padrão:
        // a contagem do selo continua sendo a da clínica inteira.
        if ($request->boolean('mine')) {
            $doctor = CurrentDoctor::activeFor($request->user(), $entityId);

            if ($doctor !== null) {
                $query->where(fn ($q) => $q->whereNull('resolved_doctor_id')->orWhere('resolved_doctor_id', $doctor->id));
            } else {
                $query->where('created_by', $request->user()->getKey());
            }
        }

        // paginate() (não um limit() fixo): numa clínica com mais de 100
        // rascunhos pendentes, os mais antigos ficavam permanentemente
        // invisíveis na lista — mesmo o badge de pendentes (contagem
        // separada, sem teto) continuando a contá-los — sem nenhuma forma de
        // alcançá-los além de descartar/processar os 100 mais recentes
        // primeiro (achado via revisão Codex). Mesmo formato de meta já usado
        // em EyeImagesController::paginatorMeta().
        $paginator = $query->paginate(perPage: 25, page: max(1, (int) $request->query('page', 1)));

        return response()->json([
            'data' => $paginator->map(fn (EyeImageReportDraft $draft) => $this->serialize($draft))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'has_more'     => $paginator->hasMorePages(),
            ],
        ]);
    }

    public function show(EyeImageReportDraft $draft): JsonResponse
    {
        $entityId = $this->authorizeDraft();
        $this->assertDraftOwnership($draft, $entityId);

        // Leitura do conteúdo clínico do rascunho (LGPD art. 37) — mesmo
        // padrão de MedicalRecordDocumentationsController::show(); achado
        // via revisão Codex (show() devolvia content/clinical_indication/
        // observations sem nenhum registro de acesso).
        $this->logAccess($draft, DataAccessPurpose::PatientCare, patientId: (string) $draft->patient_id);

        return response()->json($this->serialize($draft, withContent: true));
    }

    public function store(Request $request): JsonResponse
    {
        $entityId = $this->authorizeDraft();

        $validated = $this->validatePayload($request);

        $patient = Patient::query()->where('entity_id', $entityId)->findOrFail($validated['patient_id']);
        $examIds = $this->ownedExamIds($validated['exam_ids'] ?? [], $entityId, (string) $patient->id);
        $this->assertTemplateOwnership($validated['report_setting_content_id'] ?? null, $entityId);

        $draft = EyeImageReportDraft::create([
            'entity_id'                 => $entityId,
            'patient_id'                => $patient->id,
            'exam_ids'                  => $examIds,
            'report_setting_content_id' => $validated['report_setting_content_id'] ?? null,
            'resolved_doctor_id'        => $validated['resolved_doctor_id'] ?? null,
            'title'                     => $validated['title'] ?? null,
            'content'                   => isset($validated['content']) ? $this->sanitizeReportContent($validated['content'], $examIds) : null,
            'clinical_indication'       => $validated['clinical_indication'] ?? null,
            'observations'              => $validated['observations'] ?? null,
            'document_date'             => DocumentDate::alternative($validated['document_date'] ?? null),
            'requesting_doctor_name'    => $validated['requesting_doctor_name'] ?? null,
            'requesting_doctor_email'   => $validated['requesting_doctor_email'] ?? null,
            'image_report_payload'      => $this->resolveImageReportBlock($validated['image_report'] ?? null, $examIds),
            'status'                    => DraftStatus::Draft,
        ]);

        return response()->json($this->serialize($draft, withContent: true), 201);
    }

    public function update(Request $request, EyeImageReportDraft $draft): JsonResponse
    {
        $entityId = $this->authorizeDraft();
        $this->assertDraftOwnership($draft, $entityId);
        abort_if(! $draft->isEditable(), 422, __('eye_images.draft_not_editable'));

        $validated = $this->validatePayload($request, forUpdate: true);
        $examIds   = $this->ownedExamIds($validated['exam_ids'] ?? [], $entityId, (string) $draft->patient_id);
        $this->assertTemplateOwnership($validated['report_setting_content_id'] ?? null, $entityId);

        // Tranca e relê o rascunho AQUI DENTRO (mesmo padrão de
        // EyeImageReportController::store()): a checagem isEditable() de
        // fora (antes da transação) só evita o caso comum. Entre aquela
        // checagem e este ponto, outra requisição (ex.: o médico emitindo o
        // MESMO rascunho via store()) pode ter comitado — sem o lock, esta
        // atualização seguiria sobrescrevendo um rascunho já emitido.
        $draft = DB::transaction(function () use ($draft, $examIds, $validated) {
            $locked = EyeImageReportDraft::query()->whereKey($draft->id)->lockForUpdate()->first();
            abort_if(! $locked || ! $locked->isEditable(), 422, __('eye_images.draft_not_editable'));

            // Revisão obsoleta: outra aba/pessoa salvou uma versão mais nova
            // deste MESMO rascunho entre a tela carregar e este clique
            // (achado via revisão adversarial Codex — ver mesma checagem em
            // EyeImageReportController::store()). Sem isto, esta chamada
            // sobrescreveria silenciosamente a correção alheia. Contador
            // dedicado (revision), NUNCA updated_at: esta tabela usa
            // timestamps() com precision 0 (padrão do Schema Builder, sem
            // fração de segundo) — 2 saves no mesmo segundo gravariam o
            // MESMO updated_at e uma correção real passaria sem ser detectada
            // (2ª rodada de revisão adversarial Codex). draft_revision é
            // `required` na validação (ver validatePayload()) — nunca mais
            // opcional: um valor ausente desligava esta checagem por
            // completo, bastando omitir o campo pra sobrescrever a correção
            // de qualquer um (achado na 3ª rodada de revisão adversarial
            // Codex).
            abort_if(
                $locked->revision !== (int) $validated['draft_revision'],
                409,
                __('eye_images.draft_revision_conflict'),
            );

            $locked->update([
                'exam_ids'                  => $examIds,
                'report_setting_content_id' => $validated['report_setting_content_id'] ?? null,
                'resolved_doctor_id'        => $validated['resolved_doctor_id'] ?? null,
                'title'                     => $validated['title'] ?? null,
                'content'                   => isset($validated['content']) ? $this->sanitizeReportContent($validated['content'], $examIds) : null,
                'clinical_indication'       => $validated['clinical_indication'] ?? null,
                'observations'              => $validated['observations'] ?? null,
                'document_date'             => DocumentDate::alternative($validated['document_date'] ?? null),
                'requesting_doctor_name'    => $validated['requesting_doctor_name'] ?? null,
                'requesting_doctor_email'   => $validated['requesting_doctor_email'] ?? null,
                'image_report_payload'      => $this->resolveImageReportBlock($validated['image_report'] ?? null, $examIds),
            ]);
            // Sob o mesmo lock, depois do conteúdo salvo: o PRÓXIMO leitor
            // (outra aba já aberta, ou esta mesma tela salvando de novo) só
            // pode passar a checagem de revisão acima com o valor NOVO.
            $locked->incrementRevision();

            return $locked;
        });

        return response()->json($this->serialize($draft, withContent: true));
    }

    public function submit(Request $request, EyeImageReportDraft $draft): JsonResponse
    {
        $entityId = $this->authorizeDraft();
        $this->assertDraftOwnership($draft, $entityId);
        abort_if(! $draft->canBeSubmitted(), 422, __('eye_images.draft_not_editable'));
        abort_if(trim(strip_tags((string) $draft->content)) === '', 422, __('eye_images.report_content_required'));

        // required, nunca nullable (mesmo motivo de update()/
        // EyeImageReportController::store() — achado na 3ª rodada de
        // revisão adversarial Codex): omitir o campo desligava a checagem
        // de obsolescência por completo.
        $validated = $request->validate(['draft_revision' => ['required', 'integer', 'min:1']]);

        // Tranca e relê o status AQUI DENTRO (mesmo padrão de update() acima
        // e de EyeImageReportController::store()): sem isso, um médico
        // emitindo o MESMO rascunho em paralelo pode comitar Issued entre a
        // checagem de fora e este ponto — markSubmitted() sobrescreveria
        // Issued de volta para Submitted, deixando issued_documentation_id
        // apontar para um documento já emitido num rascunho "editável" de
        // novo (achado via revisão Codex).
        $draft = DB::transaction(function () use ($draft, $validated) {
            $locked = EyeImageReportDraft::query()->whereKey($draft->id)->lockForUpdate()->first();
            abort_if(! $locked || ! $locked->canBeSubmitted(), 422, __('eye_images.draft_not_editable'));
            // Mesma checagem da linha 148, agora contra $locked (relido sob
            // lock), não contra o $draft capturado antes dele: uma edição
            // concorrente (update(), que permite conteúdo vazio) podia
            // esvaziar o rascunho ENTRE a checagem de fora e este ponto — a
            // checagem de fora só vê o $draft antigo, ainda com texto, e
            // nunca era refeita aqui dentro (achado via revisão Codex).
            abort_if(trim(strip_tags((string) $locked->content)) === '', 422, __('eye_images.report_content_required'));

            // Revisão obsoleta: outra pessoa salvou uma correção ENTRE a
            // tela carregar e este clique — submeter aqui marcaria "pronto
            // pra revisão" um conteúdo que ninguém com a tela aberta nunca
            // viu, sem avisar ninguém (achado na 5ª rodada de revisão
            // adversarial Codex).
            abort_if(
                $locked->revision !== (int) $validated['draft_revision'],
                409,
                __('eye_images.draft_revision_conflict'),
            );

            $locked->markSubmitted();

            return $locked;
        });

        $this->notifyDoctorAboutSubmission($draft, $entityId);

        return response()->json($this->serialize($draft));
    }

    /**
     * O médico devolve o rascunho enviado ao técnico, com o motivo. Só quem
     * emite (Gate IssueReport — médico com cadastro ativo) decide devolver: é
     * a mesma pessoa que responderia pelo laudo. O técnico corrige e reenvia
     * (submit()); enquanto isso o rascunho segue editável e FORA da fila de
     * "aguardando liberação".
     */
    public function returnToTechnician(Request $request, EyeImageReportDraft $draft): JsonResponse
    {
        $entityId = $this->authorizeDraft();
        $this->assertDraftOwnership($draft, $entityId);
        Gate::authorize(EntityGate::IssueReport->value, Entity::findOrFail($entityId));
        abort_if($draft->status !== DraftStatus::Submitted, 422, __('eye_images.draft_return_not_submitted'));

        $validated = $request->validate([
            'draft_revision' => ['required', 'integer', 'min:1'],
            'reason'         => ['required', 'string', 'max:1000'],
        ]);

        // Texto puro: a tela mostra com interpolação, mas o que é gravado
        // também não carrega marcação (vai a e-mail/relatórios no futuro).
        $reason = trim(strip_tags($validated['reason']));
        abort_if($reason === '', 422, __('eye_images.draft_return_reason_required'));

        $draft = DB::transaction(function () use ($draft, $validated, $reason, $request) {
            $locked = EyeImageReportDraft::query()->whereKey($draft->id)->lockForUpdate()->first();
            // Relê o status sob a trava: o técnico pode ter reenviado, o outro
            // médico já ter emitido ou devolvido entre a checagem de fora e aqui.
            abort_if(! $locked || $locked->status !== DraftStatus::Submitted, 422, __('eye_images.draft_return_not_submitted'));
            abort_if(
                $locked->revision !== (int) $validated['draft_revision'],
                409,
                __('eye_images.draft_revision_conflict'),
            );

            $locked->markReturned($reason, (string) $request->user()->getKey());

            return $locked;
        });

        $this->notifyPreparerAboutReturn($draft, $request->user());

        return response()->json($this->serialize($draft, withContent: true));
    }

    public function destroy(Request $request, EyeImageReportDraft $draft): JsonResponse
    {
        $entityId = $this->authorizeDraft();
        $this->assertDraftOwnership($draft, $entityId);
        abort_if(! $draft->isEditable(), 422, __('eye_images.draft_not_editable'));

        // required, nunca nullable (mesmo motivo de update()/submit() acima
        // — achado na 3ª rodada de revisão adversarial Codex): omitir o
        // campo desligava a checagem de obsolescência por completo.
        $validated = $request->validate(['draft_revision' => ['required', 'integer', 'min:1']]);

        // Mesmo padrão de update()/submit() acima: tranca e relê antes de
        // descartar — nunca sobrescreve um rascunho que outra requisição já
        // emitiu entre a checagem de fora e este ponto.
        DB::transaction(function () use ($draft, $validated) {
            $locked = EyeImageReportDraft::query()->whereKey($draft->id)->lockForUpdate()->first();
            abort_if(! $locked || ! $locked->isEditable(), 422, __('eye_images.draft_not_editable'));

            // Revisão obsoleta: outra pessoa salvou uma correção ENTRE a
            // tela carregar e este clique — descartar aqui jogaria fora uma
            // correção que ninguém com a tela aberta nunca viu, sem nenhuma
            // forma de restaurar depois (achado via revisão adversarial
            // Codex: destroy() só checava isEditable(), nunca comparava
            // revision como update()/submit() já faziam).
            abort_if(
                $locked->revision !== (int) $validated['draft_revision'],
                409,
                __('eye_images.draft_revision_conflict'),
            );

            $locked->update(['status' => DraftStatus::Discarded]);
        });

        return response()->json(['discarded' => true]);
    }

    /**
     * Médico endereçado (o da pré-visualização), se houver — sem destinatário, só o
     * selo da tela avisa. Só recebe quem tem vínculo ATIVO nesta clínica agora
     * (ImageReportDraftNotification::recipientIsActive); o job enfileirado revalida na saída.
     */
    private function notifyDoctorAboutSubmission(EyeImageReportDraft $draft, string $entityId): void
    {
        $doctor     = $draft->resolvedDoctor;
        $entityUser = $doctor && $doctor->active ? $doctor->entityUser : null;
        $user       = $entityUser && (string) $entityUser->entity_id === $entityId ? $entityUser->user : null;

        if ($user === null || blank($user->email) || ! ImageReportDraftNotification::recipientIsActive($user, $entityId)) {
            return;
        }

        $user->notify(new ImageReportDraftNotification(
            ImageReportDraftNotification::SUBMITTED,
            (string) (Entity::find($entityId)?->name ?? config('app.name')),
            route('panel.eye-images.index'),
            $entityId,
        ));
    }

    /** Avisa quem preparou o rascunho (created_by) — nunca a quem devolveu. */
    private function notifyPreparerAboutReturn(EyeImageReportDraft $draft, User $returner): void
    {
        if (blank($draft->created_by) || (string) $draft->created_by === (string) $returner->getKey()) {
            return;
        }

        $preparer = User::query()->find($draft->created_by);

        if ($preparer === null || blank($preparer->email) || ! ImageReportDraftNotification::recipientIsActive($preparer, (string) $draft->entity_id)) {
            return;
        }

        $preparer->notify(new ImageReportDraftNotification(
            ImageReportDraftNotification::RETURNED,
            (string) (Entity::find($draft->entity_id)?->name ?? config('app.name')),
            route('panel.eye-images.index'),
            (string) $draft->entity_id,
        ));
    }

    private function validatePayload(Request $request, bool $forUpdate = false): array
    {
        return $request->validate([
            'patient_id'                => $forUpdate ? ['prohibited'] : ['required', 'uuid', 'exists:patients,id'],
            'exam_ids'                  => ['nullable', 'array', 'max:50'],
            'exam_ids.*'                => ['uuid'],
            'report_setting_content_id' => ['nullable', 'uuid', 'exists:report_setting_contents,id'],
            // Médico usado na ÚLTIMA pré-visualização (Fase 5 / revisão Codex)
            // — só informativo (comparação pra aviso em store()), nunca usado
            // pra autorizar nem atribuir o documento; escopado por entidade
            // mesmo assim, mesmo padrão de doctor_id em ImportExternalExamRequest.
            'resolved_doctor_id' => [
                'nullable', 'uuid',
                Rule::exists('doctors', 'id')->where(
                    fn ($query) => $query->whereNull('deleted_at')
                        ->whereIn('entity_user_id', function ($sub) {
                            $sub->select('id')->from('entity_users')->where('entity_id', (string) session('selected_entity_id'));
                        }),
                ),
            ],
            'title'                         => ['nullable', 'string', 'max:255'],
            'content'                       => ['nullable', 'string'],
            'clinical_indication'           => ['nullable', 'string', 'max:2000'],
            'observations'                  => ['nullable', 'string', 'max:4000'],
            'document_date'                 => DocumentDate::rules(),
            'requesting_doctor_name'        => ['nullable', 'string', 'max:255'],
            'requesting_doctor_email'       => ['nullable', 'email', 'max:255'],
            'image_report'                  => ['nullable', 'array'],
            'image_report.enabled'          => ['nullable', 'boolean'],
            'image_report.columns'          => ['nullable', 'integer', Rule::in(config('eye_image_capture.image_report.allowed_columns'))],
            'image_report.pair_od_oe'       => ['nullable', 'boolean'],
            'image_report.images'           => ['nullable', 'array', 'max:50'],
            'image_report.images.*.exam_id' => ['required', 'uuid'],
            'image_report.images.*.caption' => ['nullable', 'string', 'max:255'],
            'image_report.images.*.order'   => ['nullable', 'integer'],
            // Token de revisão (serialize(), coluna revision). Nunca
            // `nullable` em update(): um valor ausente desligava a checagem
            // de obsolescência por completo (achado na 3ª rodada de revisão
            // adversarial Codex) — toda chamada de update() já tem um
            // draftId (e portanto uma revisão carregada) antes de chegar
            // aqui, então exigir o campo nunca quebra um fluxo legítimo.
            // Em store() (criação) o campo não existe — nunca enviado.
            'draft_revision' => $forUpdate ? ['required', 'integer', 'min:1'] : ['prohibited'],
        ], DocumentDate::messages());
    }

    private function authorizeDraft(): string
    {
        $entityId = (string) session('selected_entity_id');
        Gate::authorize(EntityGate::DraftImageReport->value, Entity::findOrFail($entityId));

        return $entityId;
    }

    // 404 (nunca 403): rascunho de outra clínica deve parecer "não existe",
    // nunca "existe mas não é seu" — mesmo raciocínio de IDOR de qualquer
    // outro recurso tenant-isolado no Gerenciador de Imagens.
    private function assertDraftOwnership(EyeImageReportDraft $draft, string $entityId): void
    {
        abort_if((string) $draft->entity_id !== $entityId, 404);
    }

    /** Mesma checagem de EyeImageReportController::store() — nunca um template de outra clínica. */
    private function assertTemplateOwnership(?string $reportContentId, string $entityId): void
    {
        if ($reportContentId === null) {
            return;
        }

        $this->documentationService->assertTemplateBelongsToEntity(
            ReportSettingContent::findOrFail($reportContentId),
            $entityId,
        );
    }

    private function serialize(EyeImageReportDraft $draft, bool $withContent = false): array
    {
        return [
            'id'      => (string) $draft->id,
            'patient' => [
                'id'   => (string) $draft->patient_id,
                'name' => $draft->patient?->person?->full_name,
            ],
            'exam_ids'                  => $draft->exam_ids ?? [],
            'report_setting_content_id' => $draft->report_setting_content_id,
            'title'                     => $draft->title,
            'status'                    => $draft->status->value,
            'status_label'              => $draft->status->label(),
            // Token de revisão — contador dedicado (coluna revision, 2ª
            // rodada de revisão adversarial Codex; NUNCA updated_at: esta
            // tabela usa timestamps() com precision 0, e 2 saves no mesmo
            // segundo colidiriam no mesmo valor). O front devolve isto em
            // update()/EyeImageReportController::store() (draft_revision);
            // sem bater com o valor relido SOB O LOCK, a requisição é
            // rejeitada como obsoleta (409) — sem isto, uma correção salva
            // por outra pessoa ENQUANTO o médico ainda tinha a revisão
            // antiga aberta era silenciosamente substituída pelo texto
            // antigo ao emitir, e o rascunho virava Issued (travado),
            // perdendo a correção pra sempre.
            'revision'     => $draft->revision,
            'updated_at'   => $draft->updated_at?->format('d/m/Y H:i'),
            'submitted_at' => $draft->submitted_at?->format('d/m/Y H:i'),
            // Devolução ao técnico: motivo e quando; só existem com status
            // `returned` (reenviar limpa). Quem devolveu fica na auditoria.
            'return_reason'        => $draft->return_reason,
            'returned_at'          => $draft->returned_at?->format('d/m/Y H:i'),
            'resolved_doctor_name' => $draft->resolvedDoctor?->person?->full_name,
            ...($withContent ? [
                'content'                 => $draft->content,
                'clinical_indication'     => $draft->clinical_indication,
                'observations'            => $draft->observations,
                'document_date'           => $draft->document_date?->toDateString(),
                'requesting_doctor_name'  => $draft->requesting_doctor_name,
                'requesting_doctor_email' => $draft->requesting_doctor_email,
                'image_report_payload'    => $draft->image_report_payload,
                'resolved_doctor_id'      => $draft->resolved_doctor_id,
            ] : []),
        ];
    }
}
