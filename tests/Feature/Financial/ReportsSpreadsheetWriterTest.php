<?php

declare(strict_types=1);

use App\Models\{Entity, FinancialCashEntry};
use App\Support\Export\SpreadsheetWriter;
use Illuminate\Support\Carbon;

/*
 * SpreadsheetWriter (geração de CSV/XLS/XLSX extraída do
 * FinancialReportsController): os arquivos são IDÊNTICOS aos de antes da
 * extração. Os SHA-256 abaixo foram tirados da implementação anterior (os
 * métodos privados do controller) com estas mesmas linhas e o relógio
 * congelado — qualquer byte diferente no CSV, no XLS ou em uma parte do XLSX
 * quebra aqui. O docProps/core.xml tem a data de geração: comparado com o
 * horário normalizado.
 */

const REPORTS_WRITER_GOLDEN_TIME = '2026-09-27T10:11:12-03:00';

const REPORTS_WRITER_SHEETS = [
    'Fluxo de caixa',
    'Faturamento por convênio',
    'A[b]:c*d?e/f\\g & <h> nome muito longo para uma aba do Excel',
];

const REPORTS_WRITER_XLS_SHA256 = [
    'Fluxo de caixa'                                               => '98d1dd1624a3054d5b219a2f3b6fdb9dc49f333392a115822a8f7f1da311f671',
    'Faturamento por convênio'                                     => '4fa1c8a3f783f0a3f1bb9deafb09b5dc2bf7e315af812a5b1500bddbdbf96ba2',
    'A[b]:c*d?e/f\\g & <h> nome muito longo para uma aba do Excel' => '50107a7f1c8ad191577c1b5727637f86a72517a32bb619fbba834028b8aac782',
];

const REPORTS_WRITER_XLSX_WORKBOOK_SHA256 = [
    'Fluxo de caixa'                                               => '2c2c9b73bc4105c3c033227e13ebdcb7a4fd0a08ef02e99d765d89758edd90ce',
    'Faturamento por convênio'                                     => '4cb572af7da18c73f4a9d93b8b489cbd8926b2292b0caa1d933fd85e30522161',
    'A[b]:c*d?e/f\\g & <h> nome muito longo para uma aba do Excel' => '21404681a2793160744e1205b1ea8844b46d59b16d104b051177d6c488447359',
];

/** Partes do XLSX iguais para qualquer aba (na ordem em que entram no ZIP). */
const REPORTS_WRITER_XLSX_PARTS_SHA256 = [
    '[Content_Types].xml'        => '70e86bd436cba3f73f5bd41ae6ea76ad43ccc1ff84a086f5220a38323de7a147',
    '_rels/.rels'                => '817b36e9264c42465521970ff77c597d871afe88223865048632101a37bdb9ab',
    'docProps/app.xml'           => '9b9d8348cf7ee134a71e1f55f9f9ae5967b64ac92d076b7d7813cb54c1fd35a2',
    'docProps/core.xml'          => '239e20ba8879d1d3815898dada86d173f6146f3a5075558d42691e759fa063c5',
    'xl/workbook.xml'            => null, // depende da aba (REPORTS_WRITER_XLSX_WORKBOOK_SHA256)
    'xl/_rels/workbook.xml.rels' => '2cc71db7dd87e6e83c47b55febb9df59413ea399d88c252b783026fcc890dd38',
    'xl/styles.xml'              => 'ef23b07cb68b51fadca04955dc1f9f6c1d7dc86fb9255a7a8bb71a716bedba6a',
    'xl/worksheets/sheet1.xml'   => 'c1fef969176b156aefa8d48762c5fe5fde9f9f1dab1c68698a3f228121d7eeaa',
];

const REPORTS_WRITER_CSV_SHA256 = [
    ',' => '19f8c93e698cf663f67b34e08693eb8bdb45304fcbce98fb59944a14635fb39b',
    '.' => '0388d91796f05826d2f6f9c314b5c633c35c16e4f89bc7b66428d0586039a5c6',
];

/** As mesmas linhas usadas para tirar os hashes da implementação anterior. */
function reportsWriterRows(): array
{
    return [
        ['Data', 'Código', 'Descrição', 'Tipo', 'Valor'],
        ['27/09/2026', 'FLC0000000001', '=HYPERLINK("x")', 'Receita', 150.5],
        [null, 'FLC0000000002', '+CMD & <tag> "aspas" \'apos\'', 'Despesa', 10],
        ['01/01/2026', '', '-CMD', "@SUM\tx", 0.1 + 0.2],
        ['02/01/2026', 'X', "\rCR", 'Açaí · ção', 1234567.891],
        ['03/01/2026', 'Y', '', 'Z', -42.0],
    ];
}

/** Partes do .xlsx (nome => conteúdo), na ordem do ZIP. */
function reportsWriterXlsxParts(string $binary): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'writer_golden_');
    file_put_contents($tmp, $binary);

    $zip = new ZipArchive();
    expect($zip->open($tmp))->toBeTrue();

    $parts = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $parts[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
    }

    $zip->close();
    @unlink($tmp);

    return $parts;
}

it('XLS sai byte a byte igual ao da implementação anterior (nome de aba saneado e texto neutralizado)', function (string $sheet) {
    $xls = app(SpreadsheetWriter::class)->xls(reportsWriterRows(), $sheet);

    expect(hash('sha256', $xls))->toBe(REPORTS_WRITER_XLS_SHA256[$sheet]);
})->with(REPORTS_WRITER_SHEETS);

it('XLSX tem as mesmas partes, na mesma ordem e com o mesmo conteúdo da implementação anterior', function (string $sheet) {
    $this->travelTo(Carbon::parse('2026-09-27 13:11:12', 'UTC'));

    $parts = reportsWriterXlsxParts(app(SpreadsheetWriter::class)->xlsx(reportsWriterRows(), $sheet));

    expect(array_keys($parts))->toBe(array_keys(REPORTS_WRITER_XLSX_PARTS_SHA256));

    // Data de geração no fuso da aplicação: normaliza para o horário do golden.
    $core = str_replace(now()->toAtomString(), REPORTS_WRITER_GOLDEN_TIME, $parts['docProps/core.xml']);

    expect($parts['docProps/core.xml'])->toContain('<dcterms:created xsi:type="dcterms:W3CDTF">' . now()->toAtomString() . '</dcterms:created>')
        ->and(hash('sha256', $core))->toBe(REPORTS_WRITER_XLSX_PARTS_SHA256['docProps/core.xml'])
        ->and(hash('sha256', $parts['xl/workbook.xml']))->toBe(REPORTS_WRITER_XLSX_WORKBOOK_SHA256[$sheet]);

    foreach (REPORTS_WRITER_XLSX_PARTS_SHA256 as $name => $sha256) {
        if ($sha256 !== null && $name !== 'docProps/core.xml') {
            expect(hash('sha256', $parts[$name]))->toBe($sha256, "Parte {$name} mudou");
        }
    }
})->with(REPORTS_WRITER_SHEETS);

it('CSV sai igual (BOM, ";", aspas RFC 4180, fórmula neutralizada e separador decimal do idioma)', function (string $separator) {
    $csv = app(SpreadsheetWriter::class)->csv(reportsWriterRows(), $separator);

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and(hash('sha256', $csv))->toBe(REPORTS_WRITER_CSV_SHA256[$separator]);
})->with([',', '.']);

it('neutraliza só texto que começa com = + - @ tab ou CR', function () {
    $writer = app(SpreadsheetWriter::class);

    expect($writer->sanitizeCell('=1+1'))->toBe("'=1+1")
        ->and($writer->sanitizeCell("\tx"))->toBe("'\tx")
        ->and($writer->sanitizeCell('a=1'))->toBe('a=1')
        ->and($writer->sanitizeCell(-5.5))->toBe(-5.5)
        ->and($writer->sanitizeCell(''))->toBe('')
        ->and($writer->sanitizeCell(null))->toBeNull();
});

it('as exportações HTTP entregam exatamente o que o SpreadsheetWriter gera', function () {
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    actingAsFinancialEntityUser($entity);

    $entry = FinancialCashEntry::query()->create([
        'entity_id'   => $entity->id,
        'entry_date'  => '2026-08-10',
        'description' => '=Consulta',
        'type'        => 'income',
        'status'      => 'paid',
        'amount'      => 150.5,
        'active'      => true,
    ]);

    $rows = [
        ['Data', 'Código', 'Descrição', 'Tipo', 'Status', 'Categoria', 'Convênio', 'Valor'],
        ['10/08/2026', $entry->code, '=Consulta', 'Receita', 'Pago', 'Sem categoria', 'Sem convênio', 150.5],
    ];

    $writer = app(SpreadsheetWriter::class);
    $query  = ['from' => '2026-08-01', 'to' => '2026-08-31'];

    $this->get(route('panel.financial.reports.cash-flow.export', [...$query, 'format' => 'xls']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="fluxo_caixa_2026-08-01_2026-08-31.xls"')
        ->assertContent($writer->xls($rows, 'Fluxo de caixa'));

    $this->get(route('panel.financial.reports.cash-flow.export', $query))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertContent($writer->csv($rows, ','));

    $xlsx = $this->get(route('panel.financial.reports.cash-flow.export', [...$query, 'format' => 'xlsx']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->getContent();

    expect(reportsWriterXlsxParts($xlsx)['xl/worksheets/sheet1.xml'])
        ->toBe(reportsWriterXlsxParts($writer->xlsx($rows, 'Fluxo de caixa'))['xl/worksheets/sheet1.xml']);
});
