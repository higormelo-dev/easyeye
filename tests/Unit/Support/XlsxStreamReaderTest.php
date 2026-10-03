<?php

use App\Support\Spreadsheet\XlsxStreamReader;

/**
 * Leitor de XLSX em streaming (usado na lista CMED, ~68 MB de XML na aba).
 * Planilha montada à mão para cobrir variações que o writer do PhpSpreadsheet
 * não gera: texto inline, rich text com leitura fonética, número em notação
 * científica, linha/célula sem referência, aba sem <dimension>.
 */
function handmadeXlsx(): string
{
    $ns  = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $rel = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

    $parts = [
        'xl/workbook.xml' => "<workbook {$ns} {$rel}><sheets>"
            . '<sheet name="Capa" sheetId="1" r:id="rId1"/><sheet name="Lista" sheetId="2" r:id="rId2"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Target="/xl/worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Target="sharedStrings.xml"/></Relationships>',
        'xl/sharedStrings.xml' => "<sst {$ns}>"
            . '<si><t>SUBSTÂNCIA</t></si>'
            . '<si><r><t>COLÍRIO </t></r><r><t>5 ML</t></r><rPh><t>fonético</t></rPh></si>'
            . '<si/>'
            . '</sst>',
        'xl/worksheets/sheet1.xml' => "<worksheet {$ns}><dimension ref=\"A1:C3\"/><sheetData>"
            . '<row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>',
        'xl/worksheets/sheet2.xml' => "<worksheet {$ns}><sheetData>"
            . '<row r="1">'
            . '<c r="A1" t="s"><v>0</v></c>'
            . '<c r="B1" t="inlineStr"><is><t>texto inline</t></is></c>'
            . '<c r="C1"><v>5.38912020009717E+14</v></c>'
            . '<c r="D1" t="s"><v>1</v></c>'
            . '<c r="E1"><v>12345678901234567</v></c>'
            . '<c r="F1" t="str"><f>A1&amp;"x"</f><v>calculado</v></c>'
            . '<c r="G1" s="3"/>'
            . '<c r="H1"><v>1.5</v></c>'
            . '<c r="AA1" t="s"><v>2</v></c>'
            . '</row>'
            . '<row><c t="s"><v>0</v></c><c><v>7</v></c></row>'
            . '<row r="5"/>'
            . '</sheetData></worksheet>',
    ];

    $path = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
    $zip  = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($parts as $name => $xml) {
        $zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . $xml);
    }

    $zip->close();

    return $path;
}

beforeEach(function () {
    $this->path   = handmadeXlsx();
    $this->reader = new XlsxStreamReader($this->path);
});

afterEach(function () {
    @unlink($this->path);
});

it('lista as abas na ordem do arquivo, com caminho relativo ou absoluto no rels', function () {
    expect($this->reader->sheets())->toBe([
        ['name' => 'Capa', 'path' => 'xl/worksheets/sheet1.xml'],
        ['name' => 'Lista', 'path' => 'xl/worksheets/sheet2.xml'],
    ]);
});

it('lê os valores: compartilhado, inline, rich text sem fonética, fórmula e números', function () {
    $rows = iterator_to_array($this->reader->rows('xl/worksheets/sheet2.xml'));

    expect($rows[1])->toBe([
        'A'  => 'SUBSTÂNCIA',
        'B'  => 'texto inline',
        'C'  => '538912020009717',   // código gravado como número em notação científica
        'D'  => 'COLÍRIO 5 ML',
        'E'  => '12345678901234567', // inteiro longo fica exato, sem passar por float
        'F'  => 'calculado',
        'H'  => '1.5',
        'AA' => '',
    ]);
});

it('linha e célula sem referência seguem a sequência; linha vazia vem sem células', function () {
    $rows = iterator_to_array($this->reader->rows('xl/worksheets/sheet2.xml'));

    expect(array_keys($rows))->toBe([1, 2, 5])
        ->and($rows[2])->toBe(['A' => 'SUBSTÂNCIA', 'B' => '7'])
        ->and($rows[5])->toBe([]);
});

it('lê só as colunas pedidas', function () {
    $rows = iterator_to_array($this->reader->rows('xl/worksheets/sheet2.xml', ['B', 'D']));

    expect($rows[1])->toBe(['B' => 'texto inline', 'D' => 'COLÍRIO 5 ML'])
        ->and($rows[2])->toBe(['B' => '7']);
});

it('última linha vem da <dimension>; sem ela, da maior linha da aba', function () {
    expect($this->reader->lastRow('xl/worksheets/sheet1.xml'))->toBe(3)
        ->and($this->reader->lastRow('xl/worksheets/sheet2.xml'))->toBe(5);
});

it('arquivo que não é XLSX é recusado', function () {
    $path = tempnam(sys_get_temp_dir(), 'bad');
    file_put_contents($path, "SUBSTÂNCIA;PRODUTO\nx;y\n");

    expect(fn () => new XlsxStreamReader($path))->toThrow(RuntimeException::class);

    @unlink($path);
});
