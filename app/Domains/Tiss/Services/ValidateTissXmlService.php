<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use DOMDocument;

class ValidateTissXmlService
{
    /**
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, schema: string|null}
     */
    public function validate(string $xml, ?string $versionCode = null): array
    {
        libxml_use_internal_errors(true);
        $dom         = new DOMDocument();
        $wellFormed  = $dom->loadXML($xml);
        $parseErrors = array_values(array_map(
            static fn ($error) => trim($error->message),
            libxml_get_errors(),
        ));
        libxml_clear_errors();

        // XML malformado nunca é válido, independente de achar schema ou de
        // require_schema_validation (isso decide só o RIGOR da conformidade,
        // nunca se o documento é XML de verdade) — achado via auditoria de
        // encerramento, EE-07: antes, sem schema resolvido, a validação
        // retornava "válido" sem nem tentar carregar o XML.
        if (! $wellFormed) {
            return [
                'valid'    => false,
                'errors'   => $parseErrors !== [] ? $parseErrors : ['XML malformado.'],
                'warnings' => [],
                'schema'   => null,
            ];
        }

        $schemaPath    = $this->resolveSchemaPath($versionCode);
        $requireSchema = (bool) config('tiss.require_schema_validation', false);

        if ($schemaPath === null) {
            return [
                'valid'    => ! $requireSchema,
                'errors'   => $requireSchema ? ['Schema XSD não encontrado para validação.'] : [],
                'warnings' => $requireSchema ? [] : ['Validação XSD ignorada por ausência de schema.'],
                'schema'   => null,
            ];
        }

        $ok     = $dom->schemaValidate($schemaPath);
        $errors = [];

        foreach (libxml_get_errors() as $error) {
            $errors[] = trim($error->message);
        }

        libxml_clear_errors();

        return [
            'valid'    => $ok,
            'errors'   => $errors,
            'warnings' => [],
            'schema'   => $schemaPath,
        ];
    }

    /**
     * Nome real do XSD de entrada por versão vem de config('tiss.schema_files')
     * — não é derivável do código da versão (achado via auditoria de
     * encerramento, EE-07: o caminho antigo montava um "ansTISS.xsd" que
     * nunca existiu em storage/app/tiss/schemas/{versão}/).
     */
    private function resolveSchemaPath(?string $versionCode): ?string
    {
        if (! $versionCode) {
            return null;
        }

        $filename = config("tiss.schema_files.{$versionCode}");

        if (! is_string($filename) || $filename === '') {
            return null;
        }

        $base      = (string) config('tiss.schema_base_path', storage_path('app/tiss/schemas'));
        $candidate = $base . DIRECTORY_SEPARATOR . $versionCode . DIRECTORY_SEPARATOR . $filename;

        return is_file($candidate) ? $candidate : null;
    }
}
