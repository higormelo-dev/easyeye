<?php

declare(strict_types=1);

/**
 * Processo FILHO do ClinicalReviewConcurrencyTest: "outra requisição" do fluxo de revisão
 * clínica numa sessão PostgreSQL própria. Faz a operação DENTRO de uma transação, avisa o
 * principal pelo arquivo marcador e SEGURA a transação por alguns segundos antes de comitar
 * — a janela em que o principal tenta a operação concorrente.
 *
 * Uso: php clinical_review_child.php <modo> <id> <arquivo_marcador> <segundos_de_pausa>
 *   modos (id = convite): submit · revoke · resend · draft · accept
 *         (id = versão): release · withdraw · suspend
 *         (id = Revisor): deactivate
 *         (id = Revisor, versões em CRC_VERSION_IDS, separadas por vírgula): create
 * Em caso de sucesso imprime "OK" (e, em create/resend, o token novo: "OK <token>").
 */

use App\Domains\ClinicalReview\Enums\AcceptanceDecision;
use App\Domains\ClinicalReview\Models\{ClinicalReviewInvitation, ClinicalReviewer};
use App\Domains\ClinicalReview\Services\{ClinicalReviewerService, ReviewAcceptanceService, ReviewDraftService, ReviewInvitationService};
use App\Domains\Recall\Models\RecallProtocolVersion;
use App\Domains\Recall\Services\ProtocolGovernanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

require_once __DIR__ . '/../../ClinicalReviewFixtures.php';

[, $mode, $id, $marker, $holdSeconds] = $argv;

try {
    $output = DB::transaction(function () use ($mode, $id, $marker, $holdSeconds): string {
        $output = 'OK';

        switch ($mode) {
            case 'submit':
                $invitation = ClinicalReviewInvitation::query()->findOrFail($id);
                crfSubmit(['invitation' => $invitation, 'reviewer' => ClinicalReviewer::query()->findOrFail($invitation->reviewer_id)]);

                break;
            case 'revoke':
                app(ReviewInvitationService::class)->revoke(ClinicalReviewInvitation::query()->findOrFail($id), 'Revogado pelo processo filho do teste.');

                break;
            case 'resend':
                $output .= ' ' . app(ReviewInvitationService::class)->resend(ClinicalReviewInvitation::query()->findOrFail($id))['token'];

                break;
            case 'draft':
                $invitation = ClinicalReviewInvitation::query()->findOrFail($id);
                $itemId     = (string) $invitation->items()->firstOrFail()->id;
                app(ReviewDraftService::class)->save($invitation, ['decisions' => [$itemId => ['decision' => 'approved', 'comment' => 'rascunho do filho']]]);

                break;
            case 'accept':
                app(ReviewAcceptanceService::class)->record(ClinicalReviewInvitation::query()->findOrFail($id), AcceptanceDecision::Accepted, true, true, null);

                break;
            case 'release':
                app(ProtocolGovernanceService::class)->release(RecallProtocolVersion::query()->findOrFail($id));

                break;
            case 'withdraw':
                app(ProtocolGovernanceService::class)->withdraw(RecallProtocolVersion::query()->findOrFail($id), 'Retirada pelo processo filho do teste.');

                break;
            case 'suspend':
                app(ProtocolGovernanceService::class)->suspend(RecallProtocolVersion::query()->findOrFail($id), 'Suspensa pelo processo filho do teste, com motivo longo.');

                break;
            case 'deactivate':
                app(ClinicalReviewerService::class)->setActive(ClinicalReviewer::query()->findOrFail($id), false);

                break;
            case 'create':
                $created = app(ReviewInvitationService::class)->create(
                    ClinicalReviewer::query()->findOrFail($id),
                    explode(',', (string) getenv('CRC_VERSION_IDS')),
                    null,
                    14,
                );
                $output .= ' ' . $created['token'];

                break;
            default:
                throw new InvalidArgumentException("modo desconhecido: {$mode}");
        }

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));

        return $output;
    });

    fwrite(STDOUT, $output . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
