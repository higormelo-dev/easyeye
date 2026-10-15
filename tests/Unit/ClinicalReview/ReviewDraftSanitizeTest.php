<?php

declare(strict_types=1);

use App\Domains\ClinicalReview\Services\ReviewDraftService;

it('mantém só os itens do convite e só os campos conhecidos', function () {
    $clean = ReviewDraftService::sanitize([
        'decisions' => [
            'item-1' => ['decision' => 'approved', 'comment' => 'ok', 'proposed_changes' => '', 'extra' => 'x'],
            'item-2' => ['decision' => 'rejected', 'comment' => 'por quê'],
            'alheio' => ['decision' => 'approved', 'comment' => 'não é deste convite'],
            'item-3' => 'não é um array',
        ],
        'attestation' => ['name' => 'Ana', 'campo_inventado' => 'x', 'crm_uf' => 'sp'],
        'outra_coisa' => ['x' => 1],
    ], ['item-1', 'item-2', 'item-3']);

    expect(array_keys($clean))->toBe(['decisions', 'attestation'])
        ->and(array_keys($clean['decisions']))->toBe(['item-1', 'item-2'])
        ->and(array_keys($clean['decisions']['item-1']))->toBe(['decision', 'comment', 'proposed_changes'])
        ->and($clean['attestation']['name'])->toBe('Ana')
        ->and($clean['attestation']['crm_uf'])->toBe('SP')
        ->and($clean['attestation'])->not->toHaveKey('campo_inventado');
});

it('decisão inválida vira nula; as válidas passam', function () {
    $clean = ReviewDraftService::sanitize(['decisions' => [
        'a' => ['decision' => 'approved'], 'b' => ['decision' => 'changes_requested'], 'c' => ['decision' => 'rejected'],
        'd' => ['decision' => 'banana'], 'e' => ['decision' => null], 'f' => [],
    ]], ['a', 'b', 'c', 'd', 'e', 'f']);

    expect(array_map(fn ($row) => $row['decision'], $clean['decisions']))
        ->toBe(['a' => 'approved', 'b' => 'changes_requested', 'c' => 'rejected', 'd' => null, 'e' => null, 'f' => null]);
});

it('limita o tamanho dos textos', function () {
    $clean = ReviewDraftService::sanitize([
        'decisions'   => ['a' => ['decision' => 'approved', 'comment' => str_repeat('x', 9000), 'proposed_changes' => str_repeat('y', 9000)]],
        'attestation' => ['name' => str_repeat('n', 500), 'email' => str_repeat('e', 500), 'crm_number' => str_repeat('1', 40), 'crm_uf' => 'SPX', 'rqe' => str_repeat('2', 40), 'specialty' => str_repeat('s', 200), 'conflict_description' => str_repeat('c', 5000)],
    ], ['a']);

    expect(mb_strlen($clean['decisions']['a']['comment']))->toBe(3000)
        ->and(mb_strlen($clean['decisions']['a']['proposed_changes']))->toBe(6000)
        ->and(mb_strlen($clean['attestation']['name']))->toBe(160)
        ->and(mb_strlen($clean['attestation']['email']))->toBe(190)
        ->and(mb_strlen($clean['attestation']['crm_number']))->toBe(15)
        ->and($clean['attestation']['crm_uf'])->toBe('SP')
        ->and(mb_strlen($clean['attestation']['rqe']))->toBe(15)
        ->and(mb_strlen($clean['attestation']['specialty']))->toBe(80)
        ->and(mb_strlen($clean['attestation']['conflict_description']))->toBe(2000);
});

it('"sim / não / ainda não respondeu" (tristate) e concordância', function () {
    $clean = ReviewDraftService::sanitize(['attestation' => ['has_conflict' => false, 'allow_identity_display' => true, 'agreed' => 'sim']], []);

    expect($clean['attestation']['has_conflict'])->toBeFalse()
        ->and($clean['attestation']['allow_identity_display'])->toBeTrue()
        ->and($clean['attestation']['agreed'])->toBeTrue();

    $blank = ReviewDraftService::sanitize(['attestation' => ['has_conflict' => 'talvez', 'allow_identity_display' => 1]], []);

    expect($blank['attestation']['has_conflict'])->toBeNull()
        ->and($blank['attestation']['allow_identity_display'])->toBeNull()
        ->and($blank['attestation']['agreed'])->toBeFalse();
});

it('UTF-8 inválido some em vez de gravar lixo; NFC e espaços do rascunho são preservados', function () {
    $clean = ReviewDraftService::sanitize(['decisions' => ['a' => ['decision' => 'approved', 'comment' => "abc\xff"]], 'attestation' => ['name' => "Jose\u{0301} "]], ['a']);

    expect($clean['decisions']['a']['comment'])->toBe('')
        // O espaço do fim fica: o rascunho é salvo a cada pausa na digitação e tirá-lo "comeria" a tecla do Revisor.
        ->and($clean['attestation']['name'])->toBe("Jos\u{00e9} ");
});

it('entrada vazia ou fora do formato devolve um rascunho vazio e válido', function () {
    foreach ([[], ['decisions' => 'x', 'attestation' => 'y'], ['decisions' => null]] as $input) {
        $clean = ReviewDraftService::sanitize($input, ['a']);

        expect($clean['decisions'])->toBe([])
            ->and($clean['attestation']['name'])->toBe('')
            ->and($clean['attestation']['agreed'])->toBeFalse();
    }
});
