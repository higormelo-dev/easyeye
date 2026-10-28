<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Leitura de XLSX linha a linha (ZipArchive + XMLReader), com memória
 * constante em relação ao tamanho da aba.
 *
 * O PhpSpreadsheet carrega o XML inteiro da aba com SimpleXML, mesmo com
 * filtro de leitura — e essa memória é do libxml, fora do memory_limit do
 * PHP. A lista CMED (aba de ~68 MB de XML) passava de 1,4 GB de RSS e o
 * worker era morto pelo OOM killer do servidor.
 *
 * Só valores (sem estilos/fórmulas): texto compartilhado, texto inline,
 * resultado em cache de fórmula e números como estão gravados.
 */
final class XlsxStreamReader
{
    private const MAIN_DIR = 'xl/';

    /** @var list<string>|null */
    private ?array $sharedStrings = null;

    public function __construct(private readonly string $path)
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Arquivo XLSX inválido.');
        }

        $valid = $zip->locateName(self::MAIN_DIR . 'workbook.xml') !== false;
        $zip->close();

        if (! $valid) {
            throw new RuntimeException('Arquivo XLSX inválido.');
        }
    }

    /**
     * Abas na ordem do arquivo.
     *
     * @return list<array{name: string, path: string}>
     */
    public function sheets(): array
    {
        $targets = [];
        $rels    = $this->open(self::MAIN_DIR . '_rels/workbook.xml.rels');

        while ($rels->read()) {
            if ($rels->nodeType === XMLReader::ELEMENT && $rels->localName === 'Relationship') {
                $targets[(string) $rels->getAttribute('Id')] = $this->resolveTarget((string) $rels->getAttribute('Target'));
            }
        }

        $rels->close();

        $sheets   = [];
        $workbook = $this->open(self::MAIN_DIR . 'workbook.xml');

        while ($workbook->read()) {
            if ($workbook->nodeType === XMLReader::ELEMENT && $workbook->localName === 'sheet') {
                $id = $workbook->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

                if ($id !== null && isset($targets[$id])) {
                    $sheets[] = ['name' => (string) $workbook->getAttribute('name'), 'path' => $targets[$id]];
                }
            }
        }

        $workbook->close();

        return $sheets;
    }

    /**
     * Última linha da aba, pela tag <dimension> (sem ela, conta as linhas).
     */
    public function lastRow(string $sheetPath): int
    {
        $xml = $this->open($sheetPath);

        try {
            while ($xml->read()) {
                if ($xml->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($xml->localName === 'dimension') {
                    $ref = (string) $xml->getAttribute('ref');

                    // "A1:BU26409" → 26409; "A1" sozinho = gravado sem dimensão real.
                    if (str_contains($ref, ':') && preg_match('/(\d+)$/', $ref, $m)) {
                        return (int) $m[1];
                    }
                }

                if ($xml->localName === 'row') {
                    $last = 0;

                    do {
                        $last = max($last, (int) $xml->getAttribute('r'));
                    } while ($xml->next('row'));

                    return $last;
                }
            }

            return 0;
        } finally {
            $xml->close();
        }
    }

    /**
     * Linhas da aba: número da linha → [letra da coluna → valor]. Células
     * vazias não aparecem.
     *
     * @param list<string>|null $columns letras das colunas a ler (null = todas)
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(string $sheetPath, ?array $columns = null): Generator
    {
        $wanted  = $columns === null ? null : array_flip($columns);
        $strings = $this->sharedStrings();
        $xml     = $this->open($sheetPath);

        $rowNumber = 0;
        $cells     = [];
        $column    = null;
        $nextIndex = 1;
        $type      = 'n';
        $value     = null;

        try {
            while ($xml->read()) {
                if ($xml->nodeType === XMLReader::ELEMENT) {
                    switch ($xml->localName) {
                        case 'row':
                            $rowNumber = (int) $xml->getAttribute('r') ?: $rowNumber + 1;
                            $cells     = [];
                            $nextIndex = 1;

                            if ($xml->isEmptyElement) {
                                yield $rowNumber => [];
                            }

                            break;

                        case 'c':
                            $ref       = $xml->getAttribute('r');
                            $letters   = $ref !== null ? rtrim($ref, '0123456789') : self::letters($nextIndex);
                            $nextIndex = self::index($letters) + 1;
                            $type      = $xml->getAttribute('t') ?? 'n';
                            $value     = null;
                            $column    = ($xml->isEmptyElement || ($wanted !== null && ! isset($wanted[$letters]))) ? null : $letters;

                            break;

                        case 'v':
                            if ($column !== null && $type !== 'inlineStr') {
                                $value = $xml->readString();
                            }

                            break;

                        case 't':
                            if ($column !== null && $type === 'inlineStr') {
                                $value = ($value ?? '') . $xml->readString();
                            }

                            break;
                    }

                    continue;
                }

                if ($xml->nodeType !== XMLReader::END_ELEMENT) {
                    continue;
                }

                if ($xml->localName === 'c') {
                    if ($column !== null && $value !== null) {
                        $cells[$column] = self::cellValue($type, $value, $strings);
                    }

                    $column = null;
                } elseif ($xml->localName === 'row') {
                    yield $rowNumber => $cells;
                } elseif ($xml->localName === 'sheetData') {
                    break;
                }
            }
        } finally {
            $xml->close();
        }
    }

    /** @return list<string> */
    private function sharedStrings(): array
    {
        if ($this->sharedStrings !== null) {
            return $this->sharedStrings;
        }

        $this->sharedStrings = [];
        $zip                 = new ZipArchive();
        $zip->open($this->path, ZipArchive::RDONLY);
        $exists = $zip->locateName(self::MAIN_DIR . 'sharedStrings.xml') !== false;
        $zip->close();

        if (! $exists) {
            return $this->sharedStrings;
        }

        $xml      = $this->open(self::MAIN_DIR . 'sharedStrings.xml');
        $current  = null;
        $phonetic = 0;

        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT) {
                if ($xml->localName === 'si') {
                    $current = '';

                    if ($xml->isEmptyElement) {
                        $this->sharedStrings[] = '';
                        $current               = null;
                    }
                } elseif ($xml->localName === 'rPh' && ! $xml->isEmptyElement) {
                    $phonetic++; // leitura fonética (japonês) não faz parte do texto
                } elseif ($xml->localName === 't' && $current !== null && $phonetic === 0) {
                    $current .= $xml->readString();
                }
            } elseif ($xml->nodeType === XMLReader::END_ELEMENT) {
                if ($xml->localName === 'si') {
                    $this->sharedStrings[] = $current ?? '';
                    $current               = null;
                } elseif ($xml->localName === 'rPh') {
                    $phonetic--;
                }
            }
        }

        $xml->close();

        return $this->sharedStrings;
    }

    /** @param list<string> $strings */
    private static function cellValue(string $type, string $raw, array $strings): string
    {
        if ($type === 's') {
            return $strings[(int) $raw] ?? '';
        }

        // Número grande gravado em notação científica ("5.38912020009717E+14"):
        // códigos (GGREM, EAN, registro) voltam a ser só dígitos.
        if ($type === 'n' && ! preg_match('/^-?\d+$/', $raw) && is_numeric($raw)) {
            $number = (float) $raw;

            if (floor($number) === $number && abs($number) < 1e15) {
                return number_format($number, 0, '', '');
            }
        }

        return $raw;
    }

    private function open(string $entry): XMLReader
    {
        $xml = XMLReader::open('zip://' . $this->path . '#' . $entry, null, LIBXML_NONET | LIBXML_COMPACT);

        if (! $xml instanceof XMLReader) {
            throw new RuntimeException("Parte ausente no XLSX: {$entry}");
        }

        return $xml;
    }

    /** "worksheets/sheet2.xml" | "/xl/worksheets/sheet2.xml" → "xl/worksheets/sheet2.xml" */
    private function resolveTarget(string $target): string
    {
        return str_starts_with($target, '/') ? ltrim($target, '/') : self::MAIN_DIR . $target;
    }

    private static function index(string $letters): int
    {
        $index = 0;

        foreach (str_split(strtoupper($letters)) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index;
    }

    private static function letters(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $mod     = ($index - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $index   = intdiv($index - 1, 26);
        }

        return $letters;
    }
}
