<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Services;

use App\Domains\ClinicalReview\Enums\ReviewDecision;
use App\Domains\ClinicalReview\Exceptions\ReviewSubmissionException;
use App\Domains\ClinicalReview\Models\ClinicalReviewInvitation;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rascunho do parecer, guardado NO SERVIDOR (cifrado) e nunca no navegador: o Revisor pode
 * parar e voltar do mesmo link sem que o texto fique em localStorage de um computador que
 * pode ser de outra pessoa. É só conveniência — não é a decisão (que é gravada uma vez, no
 * envio) — e some ao enviar, expirar ou revogar.
 *
 * O que chega é PENEIRADO: só os campos conhecidos, só os itens do convite, textos limitados.
 */
class ReviewDraftService
{
    private const LIMITS = [
        'comment'          => 3000,
        'proposed_changes' => 6000,
        'name'             => 160,
        'email'            => 190,
        'crm_number'       => 15,
        'crm_uf'           => 2,
        'rqe'              => 15,
        'specialty'        => 80,
        'conflict'         => 2000,
    ];

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $itemIds ids dos itens do convite
     *
     * @return array{decisions: array<string, array{decision: ?string, comment: string, proposed_changes: string}>, attestation: array<string, mixed>}
     */
    public static function sanitize(array $input, array $itemIds): array
    {
        $decisions = [];

        foreach ((array) ($input['decisions'] ?? []) as $id => $row) {
            if (! in_array((string) $id, $itemIds, true) || ! is_array($row)) {
                continue;
            }

            $decision = ReviewDecision::tryFrom((string) ($row['decision'] ?? ''));

            $decisions[(string) $id] = [
                'decision'         => $decision?->value,
                'comment'          => self::text($row['comment'] ?? null, self::LIMITS['comment']),
                'proposed_changes' => self::text($row['proposed_changes'] ?? null, self::LIMITS['proposed_changes']),
            ];
        }

        $attestation = (array) ($input['attestation'] ?? []);

        return [
            'decisions'   => $decisions,
            'attestation' => [
                'name'                   => self::text($attestation['name'] ?? null, self::LIMITS['name']),
                'email'                  => self::text($attestation['email'] ?? null, self::LIMITS['email']),
                'crm_number'             => self::text($attestation['crm_number'] ?? null, self::LIMITS['crm_number']),
                'crm_uf'                 => strtoupper(self::text($attestation['crm_uf'] ?? null, self::LIMITS['crm_uf'])),
                'rqe'                    => self::text($attestation['rqe'] ?? null, self::LIMITS['rqe']),
                'specialty'              => self::text($attestation['specialty'] ?? null, self::LIMITS['specialty']),
                'has_conflict'           => self::tristate($attestation['has_conflict'] ?? null),
                'conflict_description'   => self::text($attestation['conflict_description'] ?? null, self::LIMITS['conflict']),
                'allow_identity_display' => self::tristate($attestation['allow_identity_display'] ?? null),
                'agreed'                 => (bool) ($attestation['agreed'] ?? false),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ReviewSubmissionException o convite deixou de estar aberto
     */
    public function save(ClinicalReviewInvitation $invitation, array $input): void
    {
        $itemIds = $invitation->items()->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $payload = self::sanitize($input, $itemIds);

        DB::transaction(function () use ($invitation, $payload): void {
            // A mesma trava do envio: um rascunho nunca se grava por cima de um convite já respondido.
            $locked = ClinicalReviewInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpenForReviewer()) {
                throw ReviewSubmissionException::notOpen();
            }

            $locked->forceFill(['draft_payload' => $payload, 'draft_saved_at' => now()])->save();
        });
    }

    private static function text(mixed $value, int $limit): string
    {
        if (! is_string($value)) {
            return '';
        }

        try {
            $text = CanonicalJson::normalizeText($value);
        } catch (InvalidArgumentException) {
            return '';
        }

        return mb_substr($text, 0, $limit);
    }

    /** true / false / null (ainda não respondeu). */
    private static function tristate(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
