<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manager;

use App\Domains\ClinicalReview\Enums\{AcceptanceDecision, ReviewInvitationStatus};
use App\Domains\ClinicalReview\Exceptions\{ReviewAcceptanceException, ReviewInvitationException};
use App\Domains\ClinicalReview\Models\{ClinicalReviewAcceptance, ClinicalReviewAttestation, ClinicalReviewInvitation, ClinicalReviewItem, ClinicalReviewer};
use App\Domains\ClinicalReview\Services\{ClinicalReviewerService, ReviewAcceptanceService, ReviewInvitationService};
use App\Domains\ClinicalReview\Support\{ReviewToken, ReviewerIdentity};
use App\Domains\Recall\Enums\ProtocolVersionState;
use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\{ClinicalReviewerRequest, RecordAcceptanceRequest, RevokeClinicalReviewRequest, StoreClinicalReviewRequest};
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Collection;
use Inertia\{Inertia, Response};

/**
 * Manager → Revisões clínicas: os Revisores (cadastro) e os convites de revisão dos
 * protocolos de retorno (ADR 0002). Somente admin SaaS (saas.role:admin na rota).
 *
 * O link do convite e o PIN gerado só existem na resposta de quem os criou: o link vai por
 * e-mail (nunca é mostrado aqui) e o PIN gerado pelo sistema aparece UMA vez, em
 * `issuedAccessCode`, para o Manager passá-lo por outro canal. Nada disso é gravado em claro.
 */
class ClinicalReviewsController extends Controller
{
    private const INVITATIONS_LIMIT = 50;

    public function __construct(
        private readonly ClinicalReviewerService $reviewers,
        private readonly ReviewInvitationService $invitations,
        private readonly ReviewAcceptanceService $acceptances,
    ) {
    }

    public function index(): Response
    {
        $reviewers = ClinicalReviewer::query()
            ->withCount('invitations')
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        $invitations = ClinicalReviewInvitation::query()
            ->with(['items', 'reviewer', 'attestation', 'acceptance'])
            ->latest('created_at')
            ->limit(self::INVITATIONS_LIMIT)
            ->get();

        $titles    = RecallProtocol::query()->get()->keyBy('key');
        $protocols = RecallProtocolVersion::query()
            ->whereIn('id', $invitations->flatMap(fn (ClinicalReviewInvitation $invitation) => $invitation->items->pluck('subject_id'))->unique())
            ->pluck('protocol_id', 'id');

        return Inertia::render('Panel/Manager/ClinicalReviews/Index', [
            'reviewers'          => $reviewers->map(fn (ClinicalReviewer $reviewer): array => $this->reviewerRow($reviewer))->values(),
            'invitations'        => $invitations->map(fn (ClinicalReviewInvitation $invitation): array => $this->invitationRow($invitation, $titles, $protocols))->values(),
            'reviewableVersions' => $this->reviewableVersions(),
            'issuedAccessCode'   => session()->pull('review_access_code'),
            'options'            => [
                'ufs'            => ReviewerIdentity::UFS,
                'expiry_days'    => [7, 14, 21, ReviewInvitationService::MAX_EXPIRY_DAYS],
                'default_expiry' => ReviewInvitationService::DEFAULT_EXPIRY_DAYS,
            ],
            't' => trans('manager_reviews') + ['status' => trans('clinical_review.status'), 'decision' => trans('clinical_review.decision')],
        ]);
    }

    public function storeReviewer(ClinicalReviewerRequest $request): RedirectResponse
    {
        $reviewer = $this->reviewers->create($request->validated(), $request->user());

        return back()->with('success', __('manager_reviews.flash.reviewer_created', ['name' => $reviewer->name]));
    }

    public function updateReviewer(ClinicalReviewerRequest $request, string $reviewer): RedirectResponse
    {
        $model = ClinicalReviewer::query()->findOrFail($reviewer);

        $this->reviewers->update($model, $request->validated(), $request->user());

        return back()->with('success', __('manager_reviews.flash.reviewer_updated'));
    }

    public function setReviewerActive(Request $request, string $reviewer): RedirectResponse
    {
        $request->validate(['active' => ['required', 'boolean']]);

        $model = ClinicalReviewer::query()->findOrFail($reviewer);

        $this->reviewers->setActive($model, $request->boolean('active'), $request->user());

        return back()->with('success', __($request->boolean('active') ? 'manager_reviews.flash.reviewer_activated' : 'manager_reviews.flash.reviewer_deactivated'));
    }

    public function store(StoreClinicalReviewRequest $request): RedirectResponse
    {
        $reviewer = ClinicalReviewer::query()->findOrFail($request->validated('reviewer_id'));

        $generate = $request->boolean('generate_access_code');
        $code     = $generate ? ReviewToken::generateAccessCode() : $request->validated('access_code');

        try {
            $created = $this->invitations->create(
                $reviewer,
                $request->validated('version_ids'),
                $code,
                (int) ($request->validated('expires_in_days') ?: ReviewInvitationService::DEFAULT_EXPIRY_DAYS),
                $request->user(),
            );
        } catch (ReviewInvitationException $e) {
            return back()->with('error', $this->invitationError($e))->withInput();
        }

        $sent = $this->invitations->send($created['invitation'], $created['token'], $request->user());

        $response = $sent
            ? back()->with('success', __('manager_reviews.flash.invitation_sent', ['email' => $reviewer->email]))
            : back()->with('error', __('manager_reviews.flash.invitation_not_sent'));

        // O PIN gerado pelo sistema aparece uma vez; o digitado pelo Manager ele já conhece.
        return $generate ? $response->with('review_access_code', $created['accessCode']) : $response;
    }

    public function resend(Request $request, string $invitation): RedirectResponse
    {
        $model = ClinicalReviewInvitation::query()->findOrFail($invitation);

        try {
            $result = $this->invitations->resend($model, $request->user());
        } catch (ReviewInvitationException $e) {
            return back()->with('error', $this->invitationError($e));
        }

        return $this->invitations->send($result['invitation'], $result['token'], $request->user())
            ? back()->with('success', __('manager_reviews.flash.invitation_resent', ['email' => $model->reviewer_email]))
            : back()->with('error', __('manager_reviews.flash.invitation_not_sent'));
    }

    public function revoke(RevokeClinicalReviewRequest $request, string $invitation): RedirectResponse
    {
        $model = ClinicalReviewInvitation::query()->findOrFail($invitation);

        try {
            $this->invitations->revoke($model, $request->validated('reason'), $request->user());
        } catch (ReviewInvitationException $e) {
            return back()->with('error', $this->invitationError($e));
        }

        return back()->with('success', __('manager_reviews.flash.invitation_revoked'));
    }

    /**
     * O aceite da EasyEye: conferiu (ou não) o CRM/UF e o RQE que o Revisor declarou no portal do CFM.
     * Aceitar valida as versões que ele aprovou; recusar mantém tudo em revisão.
     */
    public function accept(RecordAcceptanceRequest $request, string $invitation): RedirectResponse
    {
        $model = ClinicalReviewInvitation::query()->findOrFail($invitation);

        try {
            $this->acceptances->record(
                $model,
                AcceptanceDecision::from((string) $request->validated('decision')),
                $request->boolean('crm_checked'),
                $request->boolean('rqe_checked'),
                $request->validated('note'),
                $request->user(),
            );
        } catch (ReviewAcceptanceException $e) {
            // Falta de conferência e de motivo são erro de CAMPO (o formulário aponta); o resto é aviso.
            return match ($e->reason()) {
                ReviewAcceptanceException::CHECKS_REQUIRED => back()->withErrors(['crm_checked' => __('manager_reviews.acceptance.errors.checks_required')]),
                ReviewAcceptanceException::NOTE_REQUIRED   => back()->withErrors(['note' => __('manager_reviews.acceptance.errors.note_required')]),
                default                                    => back()->with('error', __('manager_reviews.acceptance.errors.' . $e->reason())),
            };
        }

        return back()->with('success', __($request->validated('decision') === AcceptanceDecision::Accepted->value ? 'manager_reviews.flash.accepted' : 'manager_reviews.flash.refused'));
    }

    // ── apoio ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function reviewerRow(ClinicalReviewer $reviewer): array
    {
        return [
            'id'                => $reviewer->id,
            'name'              => $reviewer->name,
            'email'             => $reviewer->email,
            'crm_number'        => $reviewer->crm_number,
            'crm_uf'            => $reviewer->crm_uf,
            'crm_label'         => $reviewer->crmLabel(),
            'rqe'               => $reviewer->rqe,
            'specialty'         => $reviewer->specialty,
            'phone'             => $reviewer->phone,
            'notes'             => $reviewer->notes,
            'active'            => $reviewer->active,
            'invitations_count' => (int) $reviewer->invitations_count,
        ];
    }

    /**
     * @param Collection<string, RecallProtocol> $titles
     * @param Collection<string, string>         $protocols versão → protocolo (para o link da versão)
     *
     * @return array<string, mixed>
     */
    private function invitationRow(ClinicalReviewInvitation $invitation, $titles, $protocols): array
    {
        $open = $invitation->status->isOpenForReviewer();

        return [
            'id'              => $invitation->id,
            'reviewer'        => ['id' => $invitation->reviewer_id, 'name' => $invitation->reviewer_name, 'crm_label' => $invitation->reviewer?->crmLabel()],
            'status'          => $invitation->status->value,
            'status_label'    => $invitation->status->label(),
            'is_expired'      => $open && $invitation->isExpired(),
            'created_at'      => $invitation->created_at?->toIso8601String(),
            'sent_at'         => $invitation->sent_at?->toIso8601String(),
            'expires_at'      => $invitation->expires_at?->toIso8601String(),
            'last_opened_at'  => $invitation->last_opened_at?->toIso8601String(),
            'open_count'      => $invitation->open_count,
            'submitted_at'    => $invitation->submitted_at?->toIso8601String(),
            'pin_required'    => $invitation->requiresAccessCode(),
            'last_send_error' => $invitation->last_send_error,
            'revoked_reason'  => $invitation->revoked_reason,
            'can_resend'      => $open && ! $invitation->isExpired(),
            'can_revoke'      => $open,
            'can_accept'      => $invitation->status === ReviewInvitationStatus::Submitted,
            'attestation'     => $invitation->attestation !== null ? $this->attestationRow($invitation->attestation, $invitation->reviewer) : null,
            'acceptance'      => $invitation->acceptance !== null ? $this->acceptanceRow($invitation->acceptance) : null,
            'items'           => $invitation->items->map(fn (ClinicalReviewItem $item): array => [
                'id'             => $item->id,
                'version_id'     => $item->subject_id,
                'protocol_id'    => $protocols->get($item->subject_id),
                'key'            => $item->subject_key,
                'title'          => $titles->get($item->subject_key)?->title() ?? $item->subject_key,
                'version'        => $item->subject_version,
                'short_hash'     => substr($item->content_hash, 2, 12),
                'decision'       => $item->decision?->value,
                'decision_label' => $item->decision?->label(),
                // Texto livre digitado por uma pessoa de fora: a tela o mostra como TEXTO, nunca como HTML.
                'comment'          => $item->comment,
                'proposed_changes' => $item->proposed_changes,
            ])->values(),
        ];
    }

    /**
     * O que o Revisor DECLAROU (e a prova do envio), com os campos em que difere do cadastro: é o que a
     * pessoa da EasyEye confere no portal do CFM — o declarado, não o cadastro.
     *
     * @return array<string, mixed>
     */
    private function attestationRow(ClinicalReviewAttestation $attestation, ?ClinicalReviewer $registered): array
    {
        $mismatch = $this->acceptances->mismatchWith($registered, $attestation);

        return [
            'name'                   => $attestation->declared_name,
            'email'                  => $attestation->declared_email,
            'crm_number'             => $attestation->declared_crm_number,
            'crm_uf'                 => $attestation->declared_crm_uf,
            'rqe'                    => $attestation->declared_rqe,
            'specialty'              => $attestation->declared_specialty,
            'conflict_of_interest'   => $attestation->conflict_of_interest,
            'conflict_description'   => $attestation->conflict_description,
            'allow_identity_display' => $attestation->allow_identity_display,
            'signed_at'              => $attestation->signed_at?->toIso8601String(),
            'ip'                     => $attestation->ip,
            'user_agent'             => $attestation->user_agent,
            'submission_hash'        => $attestation->submission_hash,
            'statement_sha256'       => $attestation->statement_sha256,
            'receipt_sent'           => $attestation->receipt_sent_at !== null,
            'identity_matches'       => $mismatch === [],
            'identity_mismatch'      => $mismatch,
            'registered'             => $registered !== null ? [
                'name'       => $registered->name,
                'email'      => $registered->email,
                'crm_number' => $registered->crm_number,
                'crm_uf'     => $registered->crm_uf,
                'rqe'        => $registered->rqe,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function acceptanceRow(ClinicalReviewAcceptance $acceptance): array
    {
        return [
            'decision'       => $acceptance->decision->value,
            'decision_label' => $acceptance->decision->label(),
            'accepted_by'    => $acceptance->accepted_by_label,
            'method'         => $acceptance->verification_method,
            'crm_checked'    => $acceptance->crm_checked,
            'rqe_checked'    => $acceptance->rqe_checked,
            'checked_at'     => $acceptance->checked_at?->toIso8601String(),
            'note'           => $acceptance->note,
        ];
    }

    /** @return list<array<string, mixed>> versões que podem entrar num convite (`in_review`) */
    private function reviewableVersions(): array
    {
        return RecallProtocolVersion::query()
            ->with('protocol')
            ->where('state', ProtocolVersionState::InReview->value)
            ->orderBy('protocol_id')
            ->orderBy('version')
            ->get()
            ->map(fn (RecallProtocolVersion $version): array => [
                'id'             => $version->id,
                'protocol_key'   => $version->protocol->key,
                'protocol_title' => $version->protocol->title(),
                'version'        => $version->version,
                'short_hash'     => $version->shortHash(),
                'frozen_at'      => $version->frozen_at?->toIso8601String(),
            ])
            ->all();
    }

    private function invitationError(ReviewInvitationException $e): string
    {
        return __('manager_reviews.errors.' . $e->reason());
    }
}
