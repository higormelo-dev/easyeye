<?php

declare(strict_types=1);

use App\Domains\ClinicalReview\Support\ReviewerIdentity;
use Tests\TestCase;

uses(TestCase::class);

it('CRM e RQE: só dígitos, sem zeros à esquerda', function (string $typed, string $expected) {
    expect(ReviewerIdentity::number($typed))->toBe($expected);
})->with([
    ['12345', '12345'], ['12.345', '12345'], ['012345', '12345'], ['CRM 12.345-6', '123456'],
    ['  0000 ', ''], ['abc', ''], ['', ''],
]);

it('"12.345", "012345" e "12345" são o mesmo CRM; "Fulano@X.com " é o mesmo e-mail', function () {
    expect(ReviewerIdentity::number('12.345'))->toBe(ReviewerIdentity::number('012345'))
        ->and(ReviewerIdentity::email(' Fulano@EXEMPLO.com '))->toBe('fulano@exemplo.com');
});

it('UF em maiúsculas e sem espaços; telefone só com dígitos', function () {
    expect(ReviewerIdentity::uf(' sp '))->toBe('SP')
        ->and(ReviewerIdentity::phone('(11) 98765-4321'))->toBe('11987654321')
        ->and(ReviewerIdentity::phone(null))->toBe('');
});

it('texto livre: NFC, sem espaço nas pontas, e UTF-8 inválido vira vazio', function () {
    expect(ReviewerIdentity::text("  Jose\u{0301}  "))->toBe("Jos\u{00e9}")
        ->and(ReviewerIdentity::text("abc\xff"))->toBe('')
        ->and(ReviewerIdentity::text(null))->toBe('');
});

it('normalize devolve o cadastro completo, com especialidade padrão e opcionais nulos', function () {
    $data = ReviewerIdentity::normalize([
        'name'       => '  Ana Paula  ',
        'email'      => 'ANA@Exemplo.test',
        'crm_number' => '012.345',
        'crm_uf'     => 'sp',
        'rqe'        => '2.001',
    ]);

    expect($data)->toBe([
        'name'       => 'Ana Paula',
        'email'      => 'ana@exemplo.test',
        'crm_number' => '12345',
        'crm_uf'     => 'SP',
        'rqe'        => '2001',
        'specialty'  => 'Oftalmologia',
        'phone'      => null,
        'notes'      => null,
    ]);

    $withOptional = ReviewerIdentity::normalize(['name' => 'X', 'email' => 'x@y.z', 'crm_number' => '1', 'crm_uf' => 'RJ', 'rqe' => '1', 'specialty' => 'Retina', 'phone' => '(21) 9999-0000', 'notes' => ' contrato assinado ']);

    expect($withOptional['specialty'])->toBe('Retina')
        ->and($withOptional['phone'])->toBe('2199990000')
        ->and($withOptional['notes'])->toBe('contrato assinado');
});

it('a lista de UFs tem as 27 unidades da federação', function () {
    expect(ReviewerIdentity::UFS)->toHaveCount(27)->and(ReviewerIdentity::UFS)->toContain('SP', 'DF', 'AM', 'TO');
});
