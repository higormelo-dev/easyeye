<?php

declare(strict_types=1);

namespace App\Http\Controllers\ClinicalReview;

use App\Domains\ClinicalReview\Models\ClinicalReviewInvitation;
use App\Domains\ClinicalReview\Support\ReviewToken;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureClinicalReviewSession;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\DB;

/**
 * 1º salto do link do Revisor Clínico (ADR 0002): o ÚNICO ponto em que o token aparece na
 * URL. Troca o token por uma sessão e manda o navegador para uma URL limpa — o token nunca
 * fica no histórico, no Referer nem em tela.
 *
 * GET NUNCA consome o convite: abrir (um verificador de e-mail, um antivírus, o próprio
 * Revisor duas vezes) só conta a abertura. O que fecha o convite é o POST único do parecer.
 *
 * Qualquer problema — id desconhecido, token errado, convite expirado ou revogado — leva à
 * MESMA página genérica e faz o mesmo trabalho (a comparação do token roda mesmo sem
 * convite): a resposta não diz se o id existe.
 */
class ClinicalReviewOpenController extends Controller
{
    private const FILLER_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __invoke(Request $request, string $invitation, string $token): RedirectResponse
    {
        $record  = ClinicalReviewInvitation::query()->find($invitation);
        $matches = ReviewToken::matches($token, (string) ($record?->token_hash ?? self::FILLER_HASH));

        if ($record === null || ! $matches) {
            return redirect()->route('clinical-review.unavailable');
        }

        $answered = $record->isAnswered();

        if (! $record->isOpenForReviewer() && ! $answered) {
            return redirect()->route('clinical-review.unavailable');
        }

        if ($record->isOpenForReviewer()) {
            // Contador de aberturas (informativo para o Manager); sem tocar em updated_at/updated_by.
            DB::table('clinical_review_invitations')->where('id', $record->id)->update([
                'last_opened_at' => now(),
                'open_count'     => DB::raw('open_count + 1'),
            ]);
        }

        // ID de sessão NOVO (fixação de sessão: um cookie plantado antes do link não vale depois dele) e o
        // estado do convite sobrescreve qualquer outro convite que este navegador tivesse aberto. Não se
        // usa invalidate(): o mesmo navegador pode ter um usuário do painel logado, e abrir o link de
        // revisão não pode desconectá-lo.
        $request->session()->regenerate();
        $request->session()->put(EnsureClinicalReviewSession::SESSION_KEY, [
            'invitation_id' => $record->id,
            'token_hash'    => $record->token_hash,
        ]);

        if ($record->requiresAccessCode()) {
            return redirect()->route('clinical-review.code');
        }

        return redirect()->route($answered ? 'clinical-review.done' : 'clinical-review.show');
    }
}
