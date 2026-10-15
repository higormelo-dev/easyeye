<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Domains\ClinicalReview\Models\{ClinicalGovernanceEvent, ClinicalReviewAcceptance, ClinicalReviewAttestation, ClinicalReviewInvitation, ClinicalReviewItem};
use App\Domains\ClinicalReview\Services\GovernanceLog;
use App\Domains\Recall\Enums\{IntervalUnit, ProtocolVersionState, RecallExamKey, RecallTaskKind};
use App\Domains\Recall\Exceptions\{InvalidProtocolContentException, ProtocolGovernanceException, ProtocolVersionException};
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Domains\Recall\Services\{ProtocolCatalogService, ProtocolGovernanceService};
use App\Domains\Recall\Support\{ProtocolContentDiff, ProtocolContentSchema, RecallPreview, RecallRuleLabels};
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\{ProtocolGovernanceReasonRequest, SaveRecallProtocolDraftRequest};
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Collection;
use Inertia\{Inertia, Response};
use Throwable;

/**
 * Manager → Protocolos de retorno (recall): o catálogo global das regras de
 * acompanhamento por condição e suas VERSÕES (ADR 0002).
 *
 * O Manager edita o RASCUNHO, congela para revisão e abre a versão seguinte; a
 * passagem pelo Revisor Clínico, o aceite e a liberação têm telas próprias. Tudo
 * que muda uma versão passa por ProtocolCatalogService (transação + trava + evento
 * de governança). Somente admin SaaS (saas.role:admin na rota); os modelos são
 * globais, então o binding por id é seguro — a versão é sempre buscada DENTRO do
 * protocolo da URL (nunca confiar só no id).
 */
class RecallProtocolsController extends Controller
{
    private const EVENTS_PER_PAGE = 40;

    public function __construct(
        private readonly ProtocolCatalogService $catalog,
        private readonly ProtocolGovernanceService $governance,
    ) {
    }

    public function index(): Response
    {
        $protocols = RecallProtocol::query()->orderBy('sort_order')->orderBy('key')->get();
        $versions  = RecallProtocolVersion::query()
            ->whereIn('protocol_id', $protocols->pluck('id'))
            ->orderBy('version')
            ->get()
            ->groupBy('protocol_id');

        return Inertia::render('Panel/Manager/RecallProtocols/Index', [
            'protocols' => $protocols->map(function (RecallProtocol $protocol) use ($versions): array {
                $all = $versions->get($protocol->id, collect());

                return [
                    'id'             => $protocol->id,
                    'key'            => $protocol->key,
                    'title'          => $protocol->title(),
                    'summary'        => $protocol->text('summary'),
                    'status'         => $protocol->status,
                    'versions_count' => $all->count(),
                    'released'       => $this->versionSummary($all->first(fn (RecallProtocolVersion $v) => $v->state === ProtocolVersionState::Released)),
                    'open'           => $this->versionSummary($all->first(fn (RecallProtocolVersion $v) => $v->state->isOpen())),
                    'latest'         => $this->versionSummary($all->last()),
                ];
            })->values(),
            't' => trans('manager_recall'),
        ]);
    }

    public function show(string $protocol): Response
    {
        $model    = RecallProtocol::query()->findOrFail($protocol);
        $versions = $model->versions()->get();

        return Inertia::render('Panel/Manager/RecallProtocols/Show', [
            'protocol' => [
                'id'      => $model->id,
                'key'     => $model->key,
                'title'   => $model->title(),
                'summary' => $model->text('summary'),
            ],
            'versions' => $versions->map(fn (RecallProtocolVersion $v): array => $this->versionSummary($v) + [
                'parent_version' => $v->parent_version_id ? $versions->firstWhere('id', $v->parent_version_id)?->version : null,
            ])->reverse()->values(),
            'canStartRevision' => $versions->isNotEmpty() && ! $versions->contains(fn (RecallProtocolVersion $v) => $v->state->isOpen()),
            'events'           => $this->events($versions->pluck('id')->all(), $versions),
            't'                => trans('manager_recall'),
        ]);
    }

    public function version(Request $request, string $protocol, string $version): Response
    {
        $model   = RecallProtocol::query()->findOrFail($protocol);
        $current = $this->versionOf($model, $version);
        $parent  = $current->parent_version_id ? RecallProtocolVersion::query()->find($current->parent_version_id) : null;

        [$content, $contentError] = $this->displayContent($current);
        $parentContent            = $parent !== null ? $this->displayContent($parent)[0] : null;

        $exampleDate = $this->exampleDate($request->query('example_date'));

        return Inertia::render('Panel/Manager/RecallProtocols/Version', [
            'protocol' => ['id' => $model->id, 'key' => $model->key, 'title' => $model->title()],
            'version'  => $this->versionSummary($current) + [
                'editable'      => $current->state === ProtocolVersionState::Draft,
                'lock_version'  => $current->lock_version,
                'integrity_ok'  => $current->integrityOk(),
                'actions'       => $this->actions($current),
                'marks'         => $this->marks($current),
                'content_error' => $contentError,
                'parent'        => $parent !== null ? ['id' => $parent->id, 'version' => $parent->version, 'state' => $parent->state->value, 'state_label' => $parent->state->label()] : null,
            ],
            'validation' => $this->validation($current),
            // A versão hoje liberada (se não for esta): liberar esta a passa a "Substituída" — o modal avisa.
            'currentReleased' => $this->currentReleased($model, $current),
            'content'         => $content,
            'canonical'       => $current->content,
            'diff'            => $content !== null && $parentContent !== null ? ProtocolContentDiff::between($parentContent, $content) : [],
            'examples'        => $content !== null ? RecallPreview::examples($content, $exampleDate) : [],
            'exampleDate'     => $exampleDate->toDateString(),
            'events'          => $this->events([$current->id], collect([$current])),
            'options'         => $this->editorOptions(),
            't'               => trans('manager_recall'),
            'ruleT'           => RecallRuleLabels::texts(),
        ]);
    }

    /** Abre a próxima versão (rascunho) a partir da última. */
    public function startRevision(Request $request, string $protocol): RedirectResponse
    {
        $model = RecallProtocol::query()->findOrFail($protocol);

        try {
            $version = $this->catalog->startRevision($model, $request->user());
        } catch (ProtocolVersionException $e) {
            return back()->with('error', $this->versionError($e));
        }

        return redirect()
            ->route('manager.recall-protocols.versions.show', [$model->id, $version->id])
            ->with('success', __('manager_recall.flash.revision_started', ['version' => $version->version]));
    }

    public function saveDraft(SaveRecallProtocolDraftRequest $request, string $protocol, string $version): RedirectResponse
    {
        $model   = RecallProtocol::query()->findOrFail($protocol);
        $current = $this->versionOf($model, $version);

        try {
            $this->catalog->saveDraft($current, (array) $request->validated('content'), (int) $request->validated('lock_version'), $request->user());
        } catch (InvalidProtocolContentException $e) {
            return back()->withErrors($this->contentErrors($e))->withInput();
        } catch (ProtocolVersionException $e) {
            return $e->reason() === ProtocolVersionException::STALE
                ? back()->withErrors(['lock_version' => $this->versionError($e)])
                : back()->with('error', $this->versionError($e));
        }

        return back()->with('success', __('manager_recall.flash.draft_saved'));
    }

    public function freeze(Request $request, string $protocol, string $version): RedirectResponse
    {
        $model   = RecallProtocol::query()->findOrFail($protocol);
        $current = $this->versionOf($model, $version);

        try {
            $this->catalog->freeze($current, $request->user());
        } catch (InvalidProtocolContentException $e) {
            return back()->withErrors($this->contentErrors($e));
        } catch (ProtocolVersionException $e) {
            return back()->with('error', $this->versionError($e));
        }

        return back()->with('success', __('manager_recall.flash.frozen'));
    }

    // ── governança depois da revisão ────────────────────────────────────────

    /** `validated → released`: a regra passa a ser oferecida às clínicas. */
    public function release(Request $request, string $protocol, string $version): RedirectResponse
    {
        return $this->govern($protocol, $version, fn (RecallProtocolVersion $current) => $this->governance->release($current, $request->user()), 'released');
    }

    /** `released | superseded → suspended`: sai de uso na hora, para todas as clínicas. */
    public function suspend(ProtocolGovernanceReasonRequest $request, string $protocol, string $version): RedirectResponse
    {
        return $this->govern($protocol, $version, fn (RecallProtocolVersion $current) => $this->governance->suspend($current, (string) $request->validated('reason'), $request->user()), 'suspended');
    }

    /** `suspended → released | superseded`. */
    public function resume(Request $request, string $protocol, string $version): RedirectResponse
    {
        return $this->govern($protocol, $version, fn (RecallProtocolVersion $current) => $this->governance->resume($current, $request->user()), 'resumed');
    }

    /** Qualquer estado (menos rascunho) `→ retired`. */
    public function retire(ProtocolGovernanceReasonRequest $request, string $protocol, string $version): RedirectResponse
    {
        return $this->govern($protocol, $version, fn (RecallProtocolVersion $current) => $this->governance->retire($current, (string) $request->validated('reason'), $request->user()), 'retired');
    }

    /** `in_review | validated → withdrawn`: a EasyEye desiste desta versão antes de liberá-la. */
    public function withdraw(ProtocolGovernanceReasonRequest $request, string $protocol, string $version): RedirectResponse
    {
        return $this->govern($protocol, $version, fn (RecallProtocolVersion $current) => $this->governance->withdraw($current, (string) $request->validated('reason'), $request->user()), 'withdrawn');
    }

    /**
     * Prévia do rascunho em edição, SEM salvar: normaliza o que a tela tem, devolve o
     * hash que resultaria e os exemplos de datas calculados pelo servidor.
     */
    public function preview(Request $request, string $protocol): JsonResponse
    {
        RecallProtocol::query()->findOrFail($protocol);

        $request->validate(['content' => ['required', 'array'], 'example_date' => ['nullable', 'date_format:Y-m-d']]);

        try {
            $prepared = ProtocolContentSchema::prepare($request->input('content'));
        } catch (InvalidProtocolContentException $e) {
            return response()->json(['valid' => false, 'errors' => $this->contentErrors($e)], 422);
        }

        return response()->json([
            'valid'    => true,
            'hash'     => $prepared['hash'],
            'bytes'    => strlen($prepared['canonical']),
            'content'  => $prepared['content'],
            'examples' => RecallPreview::examples($prepared['content'], $this->exampleDate($request->input('example_date'))),
        ]);
    }

    // ── apoio ───────────────────────────────────────────────────────────────

    /** @param Closure(RecallProtocolVersion): mixed $action */
    private function govern(string $protocol, string $version, Closure $action, string $flash): RedirectResponse
    {
        $current = $this->versionOf(RecallProtocol::query()->findOrFail($protocol), $version);

        try {
            $action($current);
        } catch (ProtocolVersionException $e) {
            return back()->with('error', $this->versionError($e));
        } catch (ProtocolGovernanceException $e) {
            return back()->with('error', __('manager_recall.errors.' . $e->reason(), ['min' => ProtocolGovernanceService::MIN_REASON_LENGTH]));
        }

        return back()->with('success', __('manager_recall.flash.' . $flash));
    }

    /** @return array{version: int}|null */
    private function currentReleased(RecallProtocol $protocol, RecallProtocolVersion $version): ?array
    {
        $released = $protocol->releasedVersion();

        return $released !== null && $released->id !== $version->id ? ['version' => $released->version] : null;
    }

    /**
     * O que o Manager pode fazer com a versão AGORA (o servidor é quem decide; a tela só mostra).
     *
     * @return array<string, bool>
     */
    private function actions(RecallProtocolVersion $version): array
    {
        $state = $version->state;

        return [
            'release'  => $state === ProtocolVersionState::Validated,
            'suspend'  => in_array($state, [ProtocolVersionState::Released, ProtocolVersionState::Superseded], true),
            'resume'   => $state === ProtocolVersionState::Suspended,
            'retire'   => $state->canTransitionTo(ProtocolVersionState::Retired),
            'withdraw' => in_array($state, [ProtocolVersionState::InReview, ProtocolVersionState::Validated], true),
        ];
    }

    /**
     * Quem fez o quê e quando (marcos da vida da versão), com o nome de quem agiu e a situação do prazo de
     * revalidação.
     *
     * @return array<string, mixed>
     */
    private function marks(RecallProtocolVersion $version): array
    {
        $names = User::query()
            ->whereIn('id', array_filter([$version->frozen_by, $version->released_by, $version->suspended_by, $version->retired_by]))
            ->pluck('name', 'id');

        $dueAt  = $version->review_due_at;
        $status = $dueAt === null ? null : ($dueAt->isPast() ? 'overdue' : ($dueAt->lte(now()->addDays(60)) ? 'soon' : 'ok'));

        return [
            'frozen_by'            => $names[$version->frozen_by] ?? null,
            'released_by'          => $names[$version->released_by] ?? null,
            'suspended_by'         => $names[$version->suspended_by] ?? null,
            'suspended_reason'     => $version->suspended_reason,
            'suspended_from_label' => ProtocolVersionState::tryFrom((string) $version->suspended_from_state)?->label(),
            'retired_by'           => $names[$version->retired_by] ?? null,
            'retired_reason'       => $version->retired_reason,
            'review_due_at'        => $dueAt?->toDateString(),
            'review_due_status'    => $status,
        ];
    }

    /**
     * A prova da validação (invariante I7): QUEM validou, QUANDO, qual versão e qual hash — os convites que
     * citam esta versão com o que o Revisor decidiu e declarou, e o aceite da EasyEye que a validou.
     *
     * @return array{invitations: list<array<string, mixed>>}
     */
    private function validation(RecallProtocolVersion $version): array
    {
        $items = ClinicalReviewItem::query()
            ->where('subject_type', ClinicalReviewItem::SUBJECT_PROTOCOL_VERSION)
            ->where('subject_id', $version->id)
            ->get()
            ->keyBy('invitation_id');

        $invitations = ClinicalReviewInvitation::query()
            ->whereIn('id', $items->keys())
            ->with(['attestation', 'acceptance'])
            ->latest('created_at')
            ->get();

        return [
            'invitations' => $invitations->map(function (ClinicalReviewInvitation $invitation) use ($items, $version): array {
                /** @var ClinicalReviewItem $item */
                $item = $items[$invitation->id];

                return [
                    'id'               => $invitation->id,
                    'reviewer'         => $invitation->reviewer_name,
                    'status'           => $invitation->status->value,
                    'status_label'     => $invitation->status->label(),
                    'sent_at'          => $invitation->sent_at?->toIso8601String(),
                    'submitted_at'     => $invitation->submitted_at?->toIso8601String(),
                    'decision'         => $item->decision?->value,
                    'decision_label'   => $item->decision?->label(),
                    'comment'          => $item->comment,
                    'proposed_changes' => $item->proposed_changes,
                    'hash_matches'     => $item->content_hash === $version->content_hash,
                    'is_validating'    => $invitation->acceptance !== null && $invitation->acceptance->id === $version->validated_acceptance_id,
                    'attestation'      => $invitation->attestation instanceof ClinicalReviewAttestation ? [
                        'name'                   => $invitation->attestation->declared_name,
                        'crm_number'             => $invitation->attestation->declared_crm_number,
                        'crm_uf'                 => $invitation->attestation->declared_crm_uf,
                        'rqe'                    => $invitation->attestation->declared_rqe,
                        'conflict_of_interest'   => $invitation->attestation->conflict_of_interest,
                        'allow_identity_display' => $invitation->attestation->allow_identity_display,
                        'signed_at'              => $invitation->attestation->signed_at?->toIso8601String(),
                        'submission_hash'        => $invitation->attestation->submission_hash,
                    ] : null,
                    'acceptance' => $invitation->acceptance instanceof ClinicalReviewAcceptance ? [
                        'decision'       => $invitation->acceptance->decision->value,
                        'decision_label' => $invitation->acceptance->decision->label(),
                        'accepted_by'    => $invitation->acceptance->accepted_by_label,
                        'checked_at'     => $invitation->acceptance->checked_at?->toIso8601String(),
                        'crm_checked'    => $invitation->acceptance->crm_checked,
                        'rqe_checked'    => $invitation->acceptance->rqe_checked,
                        'note'           => $invitation->acceptance->note,
                    ] : null,
                ];
            })->values()->all(),
        ];
    }

    private function versionOf(RecallProtocol $protocol, string $version): RecallProtocolVersion
    {
        return RecallProtocolVersion::query()
            ->where('protocol_id', $protocol->id)
            ->findOrFail($version);
    }

    /** @return array<string, mixed>|null */
    private function versionSummary(?RecallProtocolVersion $version): ?array
    {
        if ($version === null) {
            return null;
        }

        return [
            'id'             => $version->id,
            'version'        => $version->version,
            'state'          => $version->state->value,
            'state_label'    => $version->state->label(),
            'short_hash'     => $version->shortHash(),
            'content_hash'   => $version->content_hash,
            'frozen_at'      => $version->frozen_at?->toIso8601String(),
            'validated_at'   => $version->validated_at?->toIso8601String(),
            'released_at'    => $version->released_at?->toIso8601String(),
            'suspended_at'   => $version->suspended_at?->toIso8601String(),
            'retired_at'     => $version->retired_at?->toIso8601String(),
            'review_due_at'  => $version->review_due_at?->toDateString(),
            'review_overdue' => $version->review_due_at !== null && $version->review_due_at->isPast(),
            'created_at'     => $version->created_at?->toIso8601String(),
        ];
    }

    /**
     * Conteúdo para exibir/editar: o gravado, normalizado (ordem e padrões do esquema).
     * Texto gravado que já não passa no esquema (adulteração, esquema antigo) vira o
     * JSON cru + o aviso, em vez de derrubar a tela que serve para investigar.
     *
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    private function displayContent(RecallProtocolVersion $version): array
    {
        try {
            return [ProtocolContentSchema::normalize($version->contentArray()), false];
        } catch (Throwable) {
            return [null, true];
        }
    }

    /**
     * @param list<string>                           $versionIds
     * @param Collection<int, RecallProtocolVersion> $versions
     *
     * @return list<array<string, mixed>>
     */
    private function events(array $versionIds, $versions): array
    {
        $numbers = $versions->pluck('version', 'id');

        return ClinicalGovernanceEvent::query()
            ->where('subject_type', GovernanceLog::SUBJECT_PROTOCOL_VERSION)
            ->whereIn('subject_id', $versionIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::EVENTS_PER_PAGE)
            ->get()
            ->map(fn (ClinicalGovernanceEvent $event): array => [
                'id'         => $event->id,
                'event'      => $event->event,
                'label'      => $this->eventLabel($event->event),
                'version'    => $numbers[$event->subject_id] ?? null,
                'from_state' => $event->from_state,
                'to_state'   => $event->to_state,
                'from_label' => $event->from_state ? ProtocolVersionState::tryFrom($event->from_state)?->label() : null,
                'to_label'   => $event->to_state ? ProtocolVersionState::tryFrom($event->to_state)?->label() : null,
                'actor'      => $event->actor_label,
                'reason'     => $event->reason,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function eventLabel(string $event): string
    {
        $key  = 'manager_recall.events.' . $event;
        $text = __($key);

        return $text === $key ? $event : $text;
    }

    /** @return array<string, mixed> */
    private function editorOptions(): array
    {
        $medications = array_map(
            static fn (string $key): array => ['value' => $key, 'label' => __('recall_rule.medication_names.' . $key)],
            array_keys((array) config('recall.medications', [])),
        );

        return [
            'task_kinds'  => array_map(static fn (RecallTaskKind $k): array => ['value' => $k->value, 'label' => $k->label()], RecallTaskKind::cases()),
            'exam_keys'   => array_map(static fn (RecallExamKey $k): array => ['value' => $k->value, 'label' => $k->label()], RecallExamKey::cases()),
            'units'       => array_map(static fn (IntervalUnit $u): array => ['value' => $u->value, 'label' => $u->label(), 'max' => $u->maxValue()], IntervalUnit::cases()),
            'evidence'    => array_map(static fn (string $level): array => ['value' => $level, 'label' => __('recall_protocol.evidence.' . $level)], ProtocolContentSchema::EVIDENCE_LEVELS),
            'medications' => $medications,
            'limits'      => [
                'tasks'       => ProtocolContentSchema::MAX_TASKS,
                'references'  => ProtocolContentSchema::MAX_REFERENCES,
                'cid'         => ProtocolContentSchema::MAX_CID_PER_LIST,
                'rationale'   => 2000,
                'change_note' => 1000,
            ],
        ];
    }

    private function exampleDate(mixed $value): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

                // O PHP "rola" datas impossíveis (2026-13-45 vira 2027-02-14): só vale a que volta igual.
                if ($date !== false && $date->format('Y-m-d') === $value) {
                    return $date;
                }
            } catch (Throwable) {
                // cai no padrão
            }
        }

        return CarbonImmutable::today();
    }

    /** @return array<string, string> erros por caminho do formulário (`content.tasks.0.…`) */
    private function contentErrors(InvalidProtocolContentException $e): array
    {
        $errors = [];

        foreach ($e->messages() as $path => $message) {
            $errors[$path === InvalidProtocolContentException::ROOT ? 'content' : 'content.' . $path] = $message;
        }

        return $errors;
    }

    private function versionError(ProtocolVersionException $e): string
    {
        return __('manager_recall.errors.' . $e->reason());
    }
}
