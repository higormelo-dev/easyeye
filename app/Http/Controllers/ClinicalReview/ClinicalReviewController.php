<?php

declare(strict_types=1);

namespace App\Http\Controllers\ClinicalReview;

use App\Domains\ClinicalReview\Enums\ReviewDecision;
use App\Domains\ClinicalReview\Exceptions\ReviewSubmissionException;
use App\Domains\ClinicalReview\Models\{ClinicalReviewAttestation, ClinicalReviewInvitation, ClinicalReviewItem};
use App\Domains\ClinicalReview\Services\{ReviewDraftService, ReviewPageBuilder, ReviewSubmissionService};
use App\Domains\ClinicalReview\Support\ReviewerIdentity;
use App\Domains\Recall\Models\RecallProtocol;
use App\Domains\Recall\Support\RecallRuleLabels;
use App\Http\Controllers\Controller;
use App\Http\Middleware\{EnsureClinicalReviewSession, SetLocale};
use App\Http\Requests\ClinicalReview\SubmitClinicalReviewRequest;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response};

/**
 * Páginas PÚBLICAS do Revisor Clínico (ADR 0002): ler as regras, escrever o rascunho e enviar
 * o parecer UMA vez. Todas atrás do middleware `review.session` (sessão do 1º salto) e dos
 * cabeçalhos `clinical-review.headers`. O que a página recebe é só o conteúdo das versões do
 * convite (ReviewPageBuilder) — nenhum dado de paciente, clínica ou usuário.
 */
class ClinicalReviewController extends Controller
{
    public function __construct(
        private readonly ReviewPageBuilder $pages,
        private readonly ReviewDraftService $drafts,
        private readonly ReviewSubmissionService $submissions,
    ) {
    }

    public function show(Request $request): Response
    {
        $invitation = $this->invitation($request);

        return Inertia::render('ClinicalReview/Show', $this->pages->build($invitation, app()->getLocale()) + [
            'options' => [
                'ufs'       => ReviewerIdentity::UFS,
                'decisions' => array_map(static fn (ReviewDecision $decision): array => ['value' => $decision->value, 'label' => $decision->label(), 'requires_comment' => $decision->requiresComment()], ReviewDecision::cases()),
            ],
            't'     => trans('clinical_review.public'),
            'ruleT' => RecallRuleLabels::texts(),
            'urls'  => [
                'draft'     => route('clinical-review.draft'),
                'keepalive' => route('clinical-review.keepalive'),
                'submit'    => route('clinical-review.submit'),
                'locale'    => route('clinical-review.locale'),
            ],
        ]);
    }

    /**
     * Sinal de vida: a página aberta o chama de tempos em tempos. Só passar pelo middleware já renova
     * a sessão; se ela acabou (ou o convite saiu do ar), o middleware responde 409 e a página avisa o
     * Revisor ENQUANTO o texto dele ainda está na tela — e não só quando ele aperta "enviar".
     */
    public function keepAlive(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /** Salva o rascunho (cifrado, no servidor). JSON — a página não recarrega a cada tecla. */
    public function saveDraft(Request $request): JsonResponse
    {
        $request->validate(['decisions' => ['nullable', 'array'], 'attestation' => ['nullable', 'array']]);

        try {
            $this->drafts->save($this->invitation($request), $request->only(['decisions', 'attestation']));
        } catch (ReviewSubmissionException $e) {
            return response()->json(['message' => __('clinical_review.public.errors.' . $e->reason())], $e->status());
        }

        return response()->json(['saved_at' => now()->toIso8601String()]);
    }

    public function submit(SubmitClinicalReviewRequest $request): RedirectResponse
    {
        $invitation = $this->invitation($request);

        try {
            $this->submissions->submit(
                $invitation,
                (array) $request->validated('decisions'),
                (array) $request->validated('attestation'),
                (string) $request->validated('statement_term_version_id'),
                (string) $request->validated('statement_sha256'),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (ReviewSubmissionException $e) {
            // Convite que já não está aberto: respondido (duplo clique, 2ª aba) vai ao recibo; revogado
            // ou vencido, à página genérica. Os demais conflitos voltam à própria página com o aviso
            // (ela se recarrega com o conjunto/declaração atuais).
            if ($e->reason() === ReviewSubmissionException::NOT_OPEN) {
                return redirect()->route($invitation->fresh()?->isAnswered() ? 'clinical-review.done' : 'clinical-review.unavailable');
            }

            return back()->with('error', __('clinical_review.public.errors.' . $e->reason()));
        }

        return redirect()->route('clinical-review.done');
    }

    public function done(Request $request): Response
    {
        $invitation  = $this->invitation($request);
        $attestation = ClinicalReviewAttestation::query()->where('invitation_id', $invitation->id)->first();
        $items       = $invitation->items()->get();
        $titles      = RecallProtocol::query()->whereIn('key', $items->pluck('subject_key'))->get()->keyBy('key');

        return Inertia::render('ClinicalReview/Done', [
            'reviewerName' => $invitation->reviewer_name,
            'submission'   => $attestation === null ? null : [
                'submitted_at'    => $attestation->signed_at->toIso8601String(),
                'submission_hash' => $attestation->submission_hash,
                'receipt_sent'    => $attestation->receipt_sent_at !== null,
                'statement'       => $attestation->statement_sha256,
            ],
            'decisions' => $items->map(static fn (ClinicalReviewItem $item): array => [
                'id'      => (string) $item->id,
                'key'     => $item->subject_key,
                'title'   => $titles->get($item->subject_key)?->title(app()->getLocale()) ?? $item->subject_key,
                'version' => $item->subject_version,
                'label'   => $item->decision?->label(),
            ])->values(),
            't'    => $this->texts('layout', 'done'),
            'urls' => ['locale' => route('clinical-review.locale')],
        ]);
    }

    /**
     * Página genérica: nunca diz por quê (id, token, prazo, revogação). Só recebe os textos dela e da
     * moldura — nada do formulário, que nem existe para quem chegou aqui.
     */
    public function unavailable(): Response
    {
        return Inertia::render('ClinicalReview/Unavailable', [
            't'    => $this->texts('layout', 'unavailable'),
            'urls' => ['locale' => route('clinical-review.locale')],
        ]);
    }

    /** Troca o idioma da página (o `/locale/{código}` do sistema volta por Referer, que esta página não envia). */
    public function locale(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(array_keys(SetLocale::SUPPORTED_LOCALES))],
            'page'   => ['nullable', Rule::in(['show', 'code', 'done', 'unavailable'])],
        ]);

        $request->session()->put('locale', $data['locale']);

        return redirect()->route('clinical-review.' . ($data['page'] ?? 'unavailable'));
    }

    /**
     * Textos da página no idioma atual, só dos grupos pedidos (`layout`, `done`...).
     *
     * @return array<string, mixed>
     */
    private function texts(string ...$groups): array
    {
        $texts = [];

        foreach ($groups as $group) {
            $texts[$group] = trans('clinical_review.public.' . $group);
        }

        return $texts;
    }

    private function invitation(Request $request): ClinicalReviewInvitation
    {
        $invitation = $request->attributes->get(EnsureClinicalReviewSession::ATTRIBUTE);

        abort_unless($invitation instanceof ClinicalReviewInvitation, 404);

        return $invitation;
    }
}
