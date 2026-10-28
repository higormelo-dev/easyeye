<?php

declare(strict_types=1);

/*
 * PDF do relatório de fluxo de caixa (resources/views/pdf/financial_cashflow.blade.php).
 *
 * Antes: textos fixos em português, datas d/m/Y e "R$ 1.234,56" montado à mão —
 * quem usa o sistema em inglês recebia o PDF inteiro em português. Agora segue o
 * idioma do usuário (lang financial_reports), como a tela e o CSV/XLSX.
 *
 * Os testes do export mocam o SnappyPdf (a view nunca é renderizada lá); aqui a
 * view é renderizada de verdade, com modelos em memória.
 */

use App\Models\{Entity, FinancialCashEntry, FinancialCategory};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;

function reportsCfPdfEntry(array $attributes, ?string $categoryName = null): FinancialCashEntry
{
    $entry = FinancialCashEntry::make([
        'entry_date'  => '2026-09-10',
        'description' => 'Consulta particular',
        'type'        => 'income',
        'status'      => 'paid',
        'amount'      => 1234.56,
        ...$attributes,
    ]);
    $entry->code = $attributes['code'] ?? 'FLC-1';

    $entry->setRelation('category', $categoryName ? FinancialCategory::make(['name' => $categoryName]) : null);
    $entry->setRelation('covenant', null);

    return $entry;
}

function reportsCfPdfRender(string $locale, array $entries): string
{
    App::setLocale($locale);

    return view('pdf.financial_cashflow', [
        'entity'      => Entity::make(['name' => 'Clínica Teste']), // uppercaseFields: vira CLÍNICA TESTE
        'entries'     => collect($entries),
        'summary'     => ['income' => 1234.56, 'expense' => 200.0, 'balance' => 1034.56],
        'from'        => '2026-09-01',
        'to'          => '2026-09-27',
        'generatedAt' => CarbonImmutable::parse('2026-09-27 15:10:00'),
    ])->render();
}

afterEach(fn () => App::setLocale('pt_BR'));

it('em pt_BR mantém os textos de sempre, com data e moeda no formato brasileiro', function (): void {
    $html = reportsCfPdfRender('pt_BR', [
        reportsCfPdfEntry([], 'Consultas'),
        reportsCfPdfEntry(['type' => 'expense', 'status' => 'pending', 'amount' => 200, 'code' => 'FLC-2', 'description' => 'Aluguel']),
    ]);

    expect($html)->toContain('<html lang="pt-BR">')
        ->and($html)->toContain('Relatório de fluxo de caixa')
        ->and($html)->toContain('Clínica: CLÍNICA TESTE')
        ->and($html)->toContain('Período: 01/09/2026 até 27/09/2026')
        ->and($html)->toContain('Gerado em: 27/09/2026 15:10')
        ->and($html)->toContain('Total de receitas')
        ->and($html)->toContain('Saldo do período')
        ->and($html)->toContain("R$\u{00A0}1.234,56")
        ->and($html)->toContain("R$\u{00A0}1.034,56")
        ->and($html)->toContain('10/09/2026')
        ->and($html)->toContain('CONSULTAS') // HasUppercaseName
        ->and($html)->toContain('Sem categoria')
        ->and($html)->toContain('Sem convênio')
        ->and($html)->toContain('Receita')
        ->and($html)->toContain('Despesa')
        ->and($html)->toContain('Pendente');
});

it('em inglês sai inteiro em inglês: textos, datas e moeda (antes: tudo em português)', function (): void {
    $html = reportsCfPdfRender('en', [reportsCfPdfEntry([])]);

    expect($html)->toContain('<html lang="en">')
        ->and($html)->toContain('Cash flow report')
        ->and($html)->toContain('Clinic: CLÍNICA TESTE')
        ->and($html)->toContain('Period: 09/01/2026 to 09/27/2026')
        ->and($html)->toContain('Generated on: 09/27/2026 3:10 PM')
        ->and($html)->toContain('Total income')
        ->and($html)->toContain('R$1,234.56')
        ->and($html)->toContain('09/10/2026')
        ->and($html)->toContain('No category')
        ->and($html)->toContain('No insurer');

    foreach (['Relatório', 'Clínica:', 'Período', 'Gerado em', 'Total de receitas', 'Sem categoria', 'Sem convênio', 'R$ 1.234,56', 'Descrição'] as $portuguese) {
        expect($html)->not->toContain($portuguese);
    }
});

it('sem lançamentos mostra o aviso no idioma do usuário', function (): void {
    expect(reportsCfPdfRender('en', []))->toContain('No entries in the period.')
        ->and(reportsCfPdfRender('pt_BR', []))->toContain('Nenhum lançamento no período.');
});
