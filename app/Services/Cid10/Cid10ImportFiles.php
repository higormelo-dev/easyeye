<?php

declare(strict_types=1);

namespace App\Services\Cid10;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Valida e normaliza o envio da importação da CID-10 (Manager → CID-10):
 * o CID10CSV.zip do DATASUS ou os CSVs soltos.
 *
 * - Reconhece cada arquivo pelo CABEÇALHO (o nome pode ter mudado):
 *   subcategorias (SUBCAT…; obrigatório), grupos (CATINIC/CATFIM), capítulos
 *   (NUMCAP) e categorias (CAT/CLASSIF). No zip, CID-O-* (outra
 *   classificação, de neoplasias) é ignorado.
 * - Codificação: o DATASUS publica em ISO-8859-1; converte para UTF-8 (UTF-8
 *   já válido fica como está, sem BOM). Quebra de linha CRLF → LF.
 * - Limites: tamanho de cada CSV (também dentro do zip — protege contra zip
 *   "bomba") e quantidade de entradas do zip.
 *
 * Grava os CSVs normalizados (cid-10-<tipo>.csv) na pasta da importação, no
 * disco padrão — o job lê dali (Cid10ImportService). Categorias: validado e
 * registrado, mas a carga não usa (as categorias sem subdivisão já vêm no
 * arquivo de subcategorias; as com subdivisão não são código de diagnóstico).
 */
class Cid10ImportFiles
{
    public const KIND_SUBCATEGORIES = 'subcategorias';

    public const KIND_GROUPS = 'grupos';

    public const KIND_CHAPTERS = 'capitulos';

    public const KIND_CATEGORIES = 'categorias';

    /** Tipos que a carga lê (os demais só são validados). */
    private const STORED = [self::KIND_SUBCATEGORIES, self::KIND_GROUPS, self::KIND_CHAPTERS];

    /** O maior arquivo oficial (subcategorias) tem ~1,3 MB. */
    public const MAX_CSV_BYTES = 10 * 1024 * 1024;

    private const MAX_ZIP_ENTRIES = 50;

    /**
     * @param list<UploadedFile> $uploads
     *
     * @return array{folder: string, original_name: string, files: list<array{name: string, kind: string, converted: bool}>}
     *
     * @throws ValidationException (campo "files", mensagem traduzida)
     */
    public function store(array $uploads, string $folder): array
    {
        /** @var array<string, array{name: string, content: string, converted: bool}> $found */
        $found = [];

        foreach ($uploads as $upload) {
            $name = mb_substr($upload->getClientOriginalName(), 0, 255);

            if ($this->isZip($upload)) {
                foreach ($this->zipEntries($upload, $name) as $entryName => $content) {
                    $this->accept($found, $entryName, $content);
                }

                continue;
            }

            if ($upload->getSize() > self::MAX_CSV_BYTES) {
                $this->fail('import_file_too_large', ['name' => $name]);
            }

            $this->accept($found, $name, (string) file_get_contents($upload->getRealPath()));
        }

        if (! isset($found[self::KIND_SUBCATEGORIES])) {
            $this->fail('import_missing_subcategories');
        }

        $files = [];

        foreach ([self::KIND_SUBCATEGORIES, self::KIND_GROUPS, self::KIND_CHAPTERS, self::KIND_CATEGORIES] as $kind) {
            if (! isset($found[$kind])) {
                continue;
            }

            if (in_array($kind, self::STORED, true)) {
                Storage::disk()->put("{$folder}/cid-10-{$kind}.csv", $found[$kind]['content']);
            }

            $files[] = ['name' => $found[$kind]['name'], 'kind' => $kind, 'converted' => $found[$kind]['converted']];
        }

        return [
            'folder'        => $folder,
            'original_name' => mb_substr(implode(', ', array_unique(array_map(fn (UploadedFile $u) => $u->getClientOriginalName(), $uploads))), 0, 1000),
            'files'         => $files,
        ];
    }

    private function isZip(UploadedFile $upload): bool
    {
        return mb_strtolower($upload->getClientOriginalExtension()) === 'zip'
            || in_array($upload->getMimeType(), ['application/zip', 'application/x-zip-compressed'], true);
    }

    /** @return array<string, string> nome da entrada => conteúdo (só os .CSV da CID-10) */
    private function zipEntries(UploadedFile $upload, string $name): array
    {
        $zip = new ZipArchive();

        if ($zip->open((string) $upload->getRealPath(), ZipArchive::RDONLY) !== true) {
            $this->fail('import_invalid_zip', ['name' => $name]);
        }

        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                $this->fail('import_invalid_zip', ['name' => $name]);
            }

            $entries = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat  = $zip->statIndex($i);
                $entry = (string) ($stat['name'] ?? '');
                $base  = basename(str_replace('\\', '/', $entry));

                // Pastas, outros tipos e a CID-O (classificação de neoplasias) ficam de fora.
                if ($base === '' || str_ends_with($entry, '/') || ! str_ends_with(mb_strtolower($base), '.csv') || str_starts_with(mb_strtoupper($base), 'CID-O')) {
                    continue;
                }

                // Tamanho declarado E lido: entrada que descompacta além do limite é recusada.
                if (($stat['size'] ?? 0) > self::MAX_CSV_BYTES) {
                    $this->fail('import_file_too_large', ['name' => $base]);
                }

                $content = $zip->getFromIndex($i, self::MAX_CSV_BYTES + 1);

                if ($content === false || strlen($content) > self::MAX_CSV_BYTES) {
                    $this->fail('import_file_too_large', ['name' => $base]);
                }

                $entries[$base] = $content;
            }

            if ($entries === []) {
                $this->fail('import_zip_without_csv', ['name' => $name]);
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, array{name: string, content: string, converted: bool}> $found */
    private function accept(array &$found, string $name, string $raw): void
    {
        [$content, $converted] = $this->normalize($raw);
        $kind                  = $this->detect($content);

        if ($kind === null) {
            $this->fail('import_unrecognized_file', ['name' => $name]);
        }

        if (isset($found[$kind])) {
            $this->fail('import_duplicate_file', ['name' => $name]);
        }

        $found[$kind] = ['name' => $name, 'content' => $content, 'converted' => $converted];
    }

    /** @return array{0: string, 1: bool} conteúdo em UTF-8 com LF, se houve conversão */
    private function normalize(string $raw): array
    {
        $converted = false;

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw       = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            $converted = true;
        }

        return [str_replace(["\r\n", "\r"], "\n", $raw), $converted];
    }

    /**
     * Tipo pelo cabeçalho + a primeira linha de dados no formato da CID-10
     * (evita confundir com a CID-O, que tem cabeçalhos parecidos).
     */
    private function detect(string $content): ?string
    {
        $lines  = explode("\n", $content, 3);
        $header = array_map(fn ($c) => strtoupper(trim($c)), explode(';', $lines[0] ?? ''));
        $first  = array_map('trim', explode(';', $lines[1] ?? ''));
        $has    = fn (string ...$columns) => array_diff($columns, $header) === [];
        $value  = fn (string $column) => strtoupper($first[array_search($column, $header, true)] ?? '');

        return match (true) {
            $has('SUBCAT', 'DESCRICAO')                      => preg_match('/^[A-Z]\d{2}\d?$/', $value('SUBCAT')) ? self::KIND_SUBCATEGORIES : null,
            $has('NUMCAP', 'CATINIC', 'CATFIM', 'DESCRICAO') => preg_match('/^[A-Z]\d{2}$/', $value('CATINIC')) ? self::KIND_CHAPTERS : null,
            $has('CATINIC', 'CATFIM', 'DESCRICAO')           => preg_match('/^[A-Z]\d{2}$/', $value('CATINIC')) ? self::KIND_GROUPS : null,
            $has('CAT', 'CLASSIF', 'DESCRICAO')              => preg_match('/^[A-Z]\d{2}$/', $value('CAT')) ? self::KIND_CATEGORIES : null,
            default                                          => null,
        };
    }

    /** @param array<string, string> $replace */
    private function fail(string $key, array $replace = []): never
    {
        throw ValidationException::withMessages(['files' => __('manager_cid10.' . $key, $replace)]);
    }
}
