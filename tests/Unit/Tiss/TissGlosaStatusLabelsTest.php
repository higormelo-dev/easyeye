<?php

declare(strict_types=1);

namespace Tests\Unit\Tiss;

use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use Tests\TestCase;

uses(TestCase::class);

/**
 * Rótulos dos status de glosa/recurso seguem o idioma do usuário
 * (lang/{locale}/financial_glosas.php); em pt_BR o texto é o de sempre.
 */
afterEach(function (): void {
    app()->setLocale('pt_BR');
});

it('mantém os rótulos em pt_BR iguais aos de antes', function (): void {
    app()->setLocale('pt_BR');

    expect(array_map(fn (TissGlosaStatus $s) => $s->label(), TissGlosaStatus::cases()))
        ->toBe(['Aberta', 'Recorrida', 'Revertida parcialmente', 'Revertida', 'Mantida', 'Cancelada'])
        ->and(array_map(fn (TissAppealStatus $s) => $s->label(), TissAppealStatus::cases()))
        ->toBe(['Aberto', 'Enviado', 'Em análise', 'Aceito', 'Rejeitado', 'Cancelado']);
});

it('traduz os rótulos quando o idioma é inglês', function (): void {
    app()->setLocale('en');

    expect(TissGlosaStatus::Open->label())->toBe('Open')
        ->and(TissGlosaStatus::PartialReversed->label())->toBe('Partially reversed')
        ->and(TissGlosaStatus::Maintained->label())->toBe('Upheld')
        ->and(TissAppealStatus::Submitted->label())->toBe('Submitted')
        ->and(TissAppealStatus::Accepted->label())->toBe('Accepted');
});

// Texto persistido (histórico TISS) usa idioma fixo, independente da interface.
it('aceita um idioma fixo para texto persistido, independente do idioma atual', function (): void {
    app()->setLocale('en');

    expect(TissAppealStatus::Accepted->label('pt_BR'))->toBe('Aceito')
        ->and(TissGlosaStatus::PartialReversed->label('pt_BR'))->toBe('Revertida parcialmente')
        ->and(TissAppealStatus::Accepted->label())->toBe('Accepted');
});

it('tem rótulo traduzido (não a chave crua) para todos os status nas duas línguas', function (string $locale): void {
    app()->setLocale($locale);

    foreach ([...TissGlosaStatus::cases(), ...TissAppealStatus::cases()] as $case) {
        expect($case->label())->not->toStartWith('financial_glosas.');
    }
})->with(['pt_BR', 'en']);
