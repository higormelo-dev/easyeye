<?php

declare(strict_types=1);

namespace App\Services\Cid10;

use Generator;
use RuntimeException;

/**
 * Classificação oficial da CID-10 (DATASUS) por faixa de categorias:
 * capítulo (I a XXII) e grupo ("Doenças infecciosas intestinais"), lidos de
 * cid-10-capitulos.csv e cid-10-grupos.csv (UTF-8, ';').
 *
 * Usado pelo importador (com os arquivos enviados) e para código criado pelo
 * manager (com os arquivos do repositório, database/data/cid10).
 */
final class Cid10Classifier
{
    /** O DATASUS usa aspas LITERAIS e nunca campo entre aspas. */
    public const NO_ENCLOSURE = "\x01";

    private static ?self $bundled = null;

    /**
     * @param list<array{chapter: string, name: string, start: string, end: string}> $chapters
     * @param list<array{name: string, start: string, end: string}>                  $groups
     */
    public function __construct(
        private readonly array $chapters,
        private readonly array $groups,
    ) {
    }

    /**
     * Arquivo ausente (capítulos e grupos são opcionais na importação) = usa
     * a classificação de $fallback, se houver.
     */
    public static function fromDirectory(string $directory, ?self $fallback = null): self
    {
        $chapters = [];
        $groups   = [];

        if (is_file($directory . '/cid-10-capitulos.csv')) {
            foreach (self::csv($directory . '/cid-10-capitulos.csv') as $line) {
                $number = (int) trim($line['NUMCAP'] ?? '');
                $start  = strtoupper(trim($line['CATINIC'] ?? ''));
                $end    = strtoupper(trim($line['CATFIM'] ?? ''));

                if ($number < 1 || $start === '' || $end === '') {
                    continue;
                }

                $chapters[] = [
                    'chapter' => self::roman($number),
                    // "Capítulo VII - Doenças do olho e anexos" → "Doenças do olho e anexos"
                    'name'  => trim((string) preg_replace('/^Cap[ií]tulo\s+[IVXL]+\s*-\s*/u', '', trim($line['DESCRICAO'] ?? ''))),
                    'start' => $start,
                    'end'   => $end,
                ];
            }
        }

        if (is_file($directory . '/cid-10-grupos.csv')) {
            foreach (self::csv($directory . '/cid-10-grupos.csv') as $line) {
                $start = strtoupper(trim($line['CATINIC'] ?? ''));
                $end   = strtoupper(trim($line['CATFIM'] ?? ''));

                if ($start !== '' && $end !== '') {
                    $groups[] = ['name' => trim($line['DESCRICAO'] ?? ''), 'start' => $start, 'end' => $end];
                }
            }
        }

        return new self(
            $chapters !== [] ? $chapters : ($fallback->chapters ?? []),
            $groups !== [] ? $groups : ($fallback->groups ?? []),
        );
    }

    /** Classificação dos arquivos oficiais do repositório (database/data/cid10). */
    public static function bundled(): self
    {
        return self::$bundled ??= self::fromDirectory(database_path('data/cid10'));
    }

    /** Capítulo em algarismos romanos ("VII") do código ("H25.1", "H251" ou "H25"). */
    public function chapterOf(string $code): ?string
    {
        $category = self::category($code);

        foreach ($this->chapters as $chapter) {
            if (strcmp($category, $chapter['start']) >= 0 && strcmp($category, $chapter['end']) <= 0) {
                return $chapter['chapter'];
            }
        }

        return null;
    }

    /** Grupo oficial do código ("Doenças infecciosas intestinais"). */
    public function groupOf(string $code): ?string
    {
        $category = self::category($code);

        foreach ($this->groups as $group) {
            if (strcmp($category, $group['start']) >= 0 && strcmp($category, $group['end']) <= 0) {
                return $group['name'];
            }
        }

        return null;
    }

    /** @return list<array{chapter: string, name: string, start: string, end: string}> */
    public function chapters(): array
    {
        return $this->chapters;
    }

    /** Ordem numérica do capítulo romano (I=1 … XXII=22); desconhecido = null. */
    public static function chapterNumber(string $roman): ?int
    {
        for ($n = 1; $n <= 22; $n++) {
            if (self::roman($n) === $roman) {
                return $n;
            }
        }

        return null;
    }

    public static function roman(int $number): string
    {
        $map    = ['XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1];
        $result = '';

        foreach ($map as $roman => $value) {
            while ($number >= $value) {
                $result .= $roman;
                $number -= $value;
            }
        }

        return $result;
    }

    private static function category(string $code): string
    {
        return substr(strtoupper(str_replace('.', '', trim($code))), 0, 3);
    }

    /**
     * Linhas do CSV oficial (cabeçalho → chave). Também usado pelo importador.
     *
     * @return Generator<int, array<string, string>>
     */
    public static function csv(string $path): Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Arquivo da CID-10 não encontrado: {$path}");
        }

        try {
            // O DATASUS usa aspas LITERAIS na descrição ('"Flutter" e
            // fibrilação atrial') e nunca campo entre aspas: o "enclosure" é
            // um caractere que não aparece no arquivo, para as aspas ficarem.
            $header = fgetcsv($handle, 0, ';', self::NO_ENCLOSURE, '');

            if (! is_array($header)) {
                return;
            }

            $header = array_map(fn ($column) => strtoupper(trim((string) $column)), $header);

            while (($values = fgetcsv($handle, 0, ';', self::NO_ENCLOSURE, '')) !== false) {
                if ($values === [null]) {
                    continue;
                }

                $values = array_slice(array_pad($values, count($header), ''), 0, count($header));

                // Defesa: arquivo que chegou sem passar pela normalização (ISO-8859-1 do DATASUS).
                yield array_combine($header, array_map(
                    fn ($value) => mb_check_encoding((string) $value, 'UTF-8') ? (string) $value : mb_convert_encoding((string) $value, 'UTF-8', 'ISO-8859-1'),
                    $values,
                ));
            }
        } finally {
            fclose($handle);
        }
    }
}
