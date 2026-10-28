<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Support;

use App\Support\CanonicalJson;
use Carbon\CarbonInterface;

/**
 * Hashes da prova de validação (CanonicalJson, domínios próprios):
 *
 *  - `set_hash`: o CONJUNTO de versões de um convite (tipo:chave:vN:hash, ordenado). Se uma
 *    versão muda ou sai do convite, o hash muda — o parecer só vale para este conjunto.
 *  - `submission_hash`: tudo que o Revisor enviou — conjunto, decisões, atestado e a hora
 *    (UTC). É o código de verificação que vai no recibo por e-mail.
 */
final class ReviewHashes
{
    public const SET_DOMAIN = 'easyeye.clinical_review.set.v1';

    public const SUBMISSION_DOMAIN = 'easyeye.clinical_review.submission.v1';

    /**
     * @param array<int, array{subject_type: string, subject_key: string, subject_version: int, content_hash: string}> $items
     */
    public static function setHash(array $items): string
    {
        $lines = array_map(
            static fn (array $item): string => sprintf('%s:%s:v%d:%s', $item['subject_type'], $item['subject_key'], $item['subject_version'], $item['content_hash']),
            $items,
        );

        sort($lines, SORT_STRING);

        return CanonicalJson::hashOf(self::SET_DOMAIN, array_values($lines));
    }

    /**
     * @param array<int, array{subject_id: string, decision: string, comment: ?string, proposed_changes: ?string}> $decisions
     * @param array<string, mixed>                                                                                 $attestation campos declarados + statement_sha256
     */
    public static function submissionHash(string $setHash, array $decisions, array $attestation, CarbonInterface $signedAt): string
    {
        usort($decisions, static fn (array $a, array $b): int => strcmp($a['subject_id'], $b['subject_id']));

        return CanonicalJson::hashOf(self::SUBMISSION_DOMAIN, [
            'set_hash'    => $setHash,
            'decisions'   => $decisions,
            'attestation' => $attestation,
            'signed_at'   => $signedAt->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
