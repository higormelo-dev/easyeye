<?php

declare(strict_types=1);

/**
 * Hashes da prova de validação. Os vetores dourados vêm de OUTRA implementação (Python: JSON
 * canônico + sha256 com o domínio) e são CONGELADOS: mudar o que entra no hash é uma versão
 * nova do algoritmo, nunca um ajuste de vetor.
 */

use App\Domains\ClinicalReview\Support\ReviewHashes;
use App\Domains\Recall\Support\ProtocolContentSchema;
use Carbon\CarbonImmutable;

function rhItem(string $key, int $version, string $hash): array
{
    return ['subject_type' => 'recall_protocol_version', 'subject_key' => $key, 'subject_version' => $version, 'content_hash' => $hash];
}

it('[DOURADO] set_hash do conjunto: linhas tipo:chave:vN:hash, ordenadas', function () {
    $items = [rhItem('glaucoma', 1, '1:' . str_repeat('a', 64)), rhItem('dmri', 2, '1:' . str_repeat('b', 64))];

    expect(ReviewHashes::setHash($items))->toBe('1:09e0f5ac4e23b48bb1a629b87a4828c78940a47af9f6f14487c5d50c07ed2c43');
});

it('o set_hash não depende da ordem dos itens', function () {
    $a = rhItem('glaucoma', 1, '1:' . str_repeat('a', 64));
    $b = rhItem('dmri', 2, '1:' . str_repeat('b', 64));

    expect(ReviewHashes::setHash([$a, $b]))->toBe(ReviewHashes::setHash([$b, $a]));
});

it('o set_hash muda se qualquer parte do conjunto muda', function () {
    $base = [rhItem('glaucoma', 1, '1:' . str_repeat('a', 64)), rhItem('dmri', 2, '1:' . str_repeat('b', 64))];
    $hash = ReviewHashes::setHash($base);

    $variants = [
        'hash do conteúdo' => [rhItem('glaucoma', 1, '1:' . str_repeat('c', 64)), $base[1]],
        'versão'           => [rhItem('glaucoma', 2, '1:' . str_repeat('a', 64)), $base[1]],
        'protocolo'        => [rhItem('ceratocone', 1, '1:' . str_repeat('a', 64)), $base[1]],
        'item a menos'     => [$base[0]],
        'item a mais'      => [...$base, rhItem('dmri', 3, '1:' . str_repeat('d', 64))],
        'tipo do assunto'  => [['subject_type' => 'outro'] + $base[0], $base[1]],
    ];

    foreach ($variants as $name => $items) {
        expect(ReviewHashes::setHash($items))->not->toBe($hash, $name);
    }
});

function rhSubmission(array $overrides = []): array
{
    return array_replace_recursive([
        'set'       => '1:' . str_repeat('c', 64),
        'decisions' => [
            ['subject_id' => '22222222-2222-4222-8222-222222222222', 'decision' => 'changes_requested', 'comment' => 'Reduzir a campimetria para 9 meses é razoável.', 'proposed_changes' => null],
            ['subject_id' => '11111111-1111-4111-8111-111111111111', 'decision' => 'approved', 'comment' => null, 'proposed_changes' => null],
        ],
        'attestation' => [
            'name'                 => 'DRA. ANA PAULA SOUZA', 'email' => 'ana@exemplo.test', 'crm_number' => '12345', 'crm_uf' => 'SP', 'rqe' => '2001',
            'specialty'            => 'Oftalmologia', 'statement_sha256' => str_repeat('d', 64), 'conflict_of_interest' => false,
            'conflict_description' => null, 'allow_identity_display' => true,
        ],
        'signed_at' => '2026-10-14 12:30:45',
    ], $overrides);
}

function rhSubmissionHash(array $parts): string
{
    return ReviewHashes::submissionHash($parts['set'], $parts['decisions'], $parts['attestation'], CarbonImmutable::parse($parts['signed_at'], 'UTC'));
}

it('[DOURADO] submission_hash: conjunto + decisões + atestado + hora UTC', function () {
    expect(rhSubmissionHash(rhSubmission()))->toBe('1:1c1acbbd5b90359a16ac1710854755b4ac615224ec10c729e636d4996db72c38');
});

it('o submission_hash não depende da ordem das decisões e usa a hora em UTC, em qualquer fuso', function () {
    $parts                 = rhSubmission();
    $reversed              = $parts;
    $reversed['decisions'] = array_reverse($parts['decisions']);

    expect(rhSubmissionHash($reversed))->toBe(rhSubmissionHash($parts));

    $inSaoPaulo = CarbonImmutable::parse('2026-10-14 09:30:45', 'America/Sao_Paulo');

    expect(ReviewHashes::submissionHash($parts['set'], $parts['decisions'], $parts['attestation'], $inSaoPaulo))->toBe(rhSubmissionHash($parts));
});

it('o submission_hash muda se qualquer coisa enviada muda', function () {
    $hash = rhSubmissionHash(rhSubmission());

    $variants = [
        'conjunto'          => rhSubmission(['set' => '1:' . str_repeat('e', 64)]),
        'decisão'           => rhSubmission(['decisions' => [['decision' => 'rejected']]]),
        'comentário'        => rhSubmission(['decisions' => [['comment' => 'Outro texto.']]]),
        'nome'              => rhSubmission(['attestation' => ['name' => 'OUTRO NOME']]),
        'CRM'               => rhSubmission(['attestation' => ['crm_number' => '12346']]),
        'RQE'               => rhSubmission(['attestation' => ['rqe' => '2002']]),
        'declaração'        => rhSubmission(['attestation' => ['statement_sha256' => str_repeat('f', 64)]]),
        'conflito'          => rhSubmission(['attestation' => ['conflict_of_interest' => true]]),
        'exibir identidade' => rhSubmission(['attestation' => ['allow_identity_display' => false]]),
        'hora'              => rhSubmission(['signed_at' => '2026-10-14 12:30:46']),
    ];

    foreach ($variants as $name => $parts) {
        expect(rhSubmissionHash($parts))->not->toBe($hash, $name);
    }
});

it('os dois hashes usam domínios próprios: nunca valem um pelo outro', function () {
    expect(ReviewHashes::SET_DOMAIN)->not->toBe(ReviewHashes::SUBMISSION_DOMAIN)
        ->and(ReviewHashes::SET_DOMAIN)->not->toBe(ProtocolContentSchema::HASH_DOMAIN);
});
