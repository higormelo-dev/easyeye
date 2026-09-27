<?php

declare(strict_types=1);

namespace App\Support\Export;

use RuntimeException;
use ZipArchive;

/**
 * Planilhas das exportações dos relatórios financeiros: CSV (BOM UTF-8, ';'),
 * XLS (SpreadsheetML 2003) e XLSX (OOXML mínimo, uma aba) — sem dependência
 * externa. Extraído do FinancialReportsController SEM mudar os arquivos
 * gerados (ReportsSpreadsheetWriterTest compara com a saída anterior).
 *
 * Linhas: arrays de células; int/float viram número, o resto vira texto
 * (sempre passando por sanitizeCell()). Cabeçalhos e rótulos chegam já
 * traduzidos — a classe não conhece idioma, só o separador decimal do CSV.
 */
final class SpreadsheetWriter
{
    /** O .xlsx precisa de ZipArchive; sem a extensão o chamador cai no .xls. */
    public static function supportsXlsx(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * OWASP CSV/Formula Injection: célula de texto começando com =, +, -, @,
     * tab ou CR vira fórmula executável ao abrir no Excel — nome de paciente,
     * observação e nome de convênio/categoria são texto livre, superfície de
     * ataque real aqui. Prefixa com aspas simples pra neutralizar sem alterar
     * o valor visível na planilha.
     */
    public function sanitizeCell(mixed $cell): mixed
    {
        if (! is_string($cell) || $cell === '') {
            return $cell;
        }

        return preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'" . $cell : $cell;
    }

    /**
     * CSV com BOM UTF-8 (sem ele o Excel BR mostra "DescriÃ§Ã£o" e nomes
     * acentuados quebrados) e números com o separador decimal do idioma
     * (vírgula em pt_BR, coerente com o delimitador ';'). Texto continua
     * passando por sanitizeCell(); números são gerados pelo sistema, não vêm
     * do usuário, então não entram na neutralização de fórmula.
     *
     * @param list<array<int, mixed>> $rows
     */
    public function csv(array $rows, string $decimalSeparator): string
    {
        $stream = fopen('php://temp', 'r+');

        fwrite($stream, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            // escape '' = RFC 4180 (aspas dobradas), o que o Excel entende; sem
            // passar explícito o PHP 8.4 emite deprecation.
            fputcsv($stream, array_map(fn (mixed $cell): mixed => $this->csvCell($cell, $decimalSeparator), $row), ';', '"', '');
        }

        rewind($stream);
        $content = stream_get_contents($stream) ?: '';
        fclose($stream);

        return $content;
    }

    /** @param list<array<int, mixed>> $rows */
    public function xls(array $rows, string $sheetName): string
    {
        $xml   = [];
        $xml[] = '<?xml version="1.0"?>';
        $xml[] = '<?mso-application progid="Excel.Sheet"?>';
        $xml[] = '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
        $xml[] = ' xmlns:o="urn:schemas-microsoft-com:office:office"';
        $xml[] = ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
        $xml[] = ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $xml[] = '<Worksheet ss:Name="' . $this->sheetName($sheetName) . '">';
        $xml[] = '<Table>';

        foreach ($rows as $row) {
            $xml[] = '<Row>';

            foreach ($row as $cell) {
                $isNumeric = is_int($cell) || is_float($cell);
                $type      = $isNumeric ? 'Number' : 'String';
                $value     = $isNumeric
                    ? (string) $cell
                    : htmlspecialchars((string) $this->sanitizeCell($cell), ENT_QUOTES | ENT_XML1, 'UTF-8');

                $xml[] = sprintf(
                    '<Cell><Data ss:Type="%s">%s</Data></Cell>',
                    $type,
                    $value,
                );
            }

            $xml[] = '</Row>';
        }

        $xml[] = '</Table>';
        $xml[] = '</Worksheet>';
        $xml[] = '</Workbook>';

        return implode('', $xml);
    }

    /**
     * @param list<array<int, mixed>> $rows
     *
     * @throws RuntimeException sem arquivo temporário/ZIP (o chamador decide o fallback)
     */
    public function xlsx(array $rows, string $sheetName): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cashflow_xlsx_');

        if ($tmpFile === false) {
            throw new RuntimeException('XLSX: temporary file unavailable.');
        }

        $zip = new ZipArchive();

        if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);

            throw new RuntimeException('XLSX: zip archive could not be opened.');
        }

        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypesXml());
        $zip->addFromString('_rels/.rels', $this->xlsxRootRelsXml());
        $zip->addFromString('docProps/app.xml', $this->xlsxAppXml());
        $zip->addFromString('docProps/core.xml', $this->xlsxCoreXml());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbookXml($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->xlsxStylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlsxSheetXml($rows));
        $zip->close();

        $binary = file_get_contents($tmpFile) ?: '';
        @unlink($tmpFile);

        return $binary;
    }

    private function csvCell(mixed $cell, string $decimalSeparator): mixed
    {
        if (is_int($cell) || is_float($cell)) {
            return number_format((float) $cell, 2, $decimalSeparator, '');
        }

        return $this->sanitizeCell($cell);
    }

    /**
     * Nome de aba válido no Excel: até 31 caracteres, sem []:*?/\ e escapado
     * para XML (o nome vem do arquivo de tradução).
     */
    private function sheetName(string $name): string
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name));
        $name = mb_substr($name !== '' ? $name : 'Sheet1', 0, 31);

        return htmlspecialchars($name, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** @param list<array<int, mixed>> $rows */
    private function xlsxSheetXml(array $rows): string
    {
        $lines   = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $lines[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $lines[] = '<sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $lines[]  = sprintf('<row r="%d">', $excelRow);

            foreach (array_values($row) as $columnIndex => $cell) {
                $column  = $this->columnLetter($columnIndex + 1);
                $cellRef = $column . $excelRow;

                if (is_int($cell) || is_float($cell)) {
                    $lines[] = sprintf('<c r="%s"><v>%s</v></c>', $cellRef, $cell);

                    continue;
                }

                $value   = htmlspecialchars((string) $this->sanitizeCell($cell), ENT_QUOTES | ENT_XML1, 'UTF-8');
                $lines[] = sprintf('<c r="%s" t="inlineStr"><is><t>%s</t></is></c>', $cellRef, $value);
            }

            $lines[] = '</row>';
        }

        $lines[] = '</sheetData>';
        $lines[] = '</worksheet>';

        return implode('', $lines);
    }

    private function columnLetter(int $number): string
    {
        $letter = '';

        while ($number > 0) {
            $mod    = ($number - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $number = intdiv($number - 1, 26);
        }

        return $letter;
    }

    private function xlsxContentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function xlsxRootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function xlsxAppXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>EasyEye</Application>'
            . '</Properties>';
    }

    private function xlsxCoreXml(): string
    {
        $generatedAt = now()->toAtomString();

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>EasyEye</dc:creator>'
            . '<cp:lastModifiedBy>EasyEye</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $generatedAt . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $generatedAt . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function xlsxWorkbookXml(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $this->sheetName($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function xlsxWorkbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function xlsxStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="1"><xf xfId="0"/></cellXfs>'
            . '</styleSheet>';
    }
}
