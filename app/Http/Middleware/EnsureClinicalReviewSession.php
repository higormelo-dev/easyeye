<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\ClinicalReview\Models\ClinicalReviewInvitation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sessão do Revisor Clínico (ADR 0002): depois do 1º salto (que traz o token na URL e a
 * troca por esta sessão), todas as páginas dele passam por aqui.
 *
 * A sessão guarda o id do convite e o hash do token com que ele entrou. A cada request o
 * convite é relido e o hash CONFERIDO: reenviar o convite (token novo), revogá-lo ou
 * deixá-lo expirar derruba a sessão na hora — não existe "sessão que sobreviveu".
 *
 * Modos (parâmetro da rota):
 *  - `open`  (padrão) — a revisão: convite pendente e dentro do prazo, com o PIN (se houver) já digitado;
 *  - `code`  — a tela do PIN (não exige o PIN, e vale também para o convite já respondido);
 *  - `done`  — a conclusão: convite já respondido, com o PIN (se houver) já digitado.
 *
 * Convite morto (revogado, vencido, token trocado, sem sessão) cai na página genérica e
 * derruba a sessão. Convite vivo na página errada NÃO derruba nada: respondido vai à
 * conclusão, pendente que pede a conclusão volta à revisão — o Revisor que aperta "voltar"
 * ou digita um endereço não perde o acesso.
 */
class EnsureClinicalReviewSession
{
    public const SESSION_KEY = 'clinical_review';

    public const ATTRIBUTE = 'clinical_review_invitation';

    public function handle(Request $request, Closure $next, string $mode = 'open'): Response
    {
        $invitation = $this->resolve($request);

        if ($invitation === null || (! $invitation->isOpenForReviewer() && ! $invitation->isAnswered())) {
            $request->session()->forget(self::SESSION_KEY);

            return $this->unavailable($request);
        }

        if ($mode !== 'code' && $invitation->requiresAccessCode() && ! $this->pinPassed($request, $invitation)) {
            return $this->moveTo($request, 'clinical-review.code', 'access_code_required');
        }

        if ($invitation->isAnswered() && $mode === 'open') {
            return $this->moveTo($request, 'clinical-review.done', 'answered');
        }

        if ($invitation->isOpenForReviewer() && $mode === 'done') {
            return $this->moveTo($request, 'clinical-review.show', 'not_answered');
        }

        $request->attributes->set(self::ATTRIBUTE, $invitation);

        return $next($request);
    }

    /**
     * Sem sessão válida: a página genérica. Chamada de fetch (o rascunho salvo em segundo
     * plano) recebe 409 + o destino, para a tela avisar o Revisor em vez de seguir um
     * redirecionamento que devolveria HTML.
     */
    private function unavailable(Request $request): Response
    {
        return $this->moveTo($request, 'clinical-review.unavailable', 'unavailable');
    }

    private function moveTo(Request $request, string $route, string $reason): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $reason, 'redirect' => route($route)], 409);
        }

        return redirect()->route($route);
    }

    private function resolve(Request $request): ?ClinicalReviewInvitation
    {
        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state) || ! is_string($state['invitation_id'] ?? null) || ! is_string($state['token_hash'] ?? null)) {
            return null;
        }

        $invitation = ClinicalReviewInvitation::query()->find($state['invitation_id']);

        if ($invitation === null || ! hash_equals((string) $invitation->token_hash, $state['token_hash'])) {
            return null;
        }

        return $invitation;
    }

    private function pinPassed(Request $request, ClinicalReviewInvitation $invitation): bool
    {
        return hash_equals((string) $invitation->id, (string) $request->session()->get(self::SESSION_KEY . '.pin_ok', ''));
    }
}
