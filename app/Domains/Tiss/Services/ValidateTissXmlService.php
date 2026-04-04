<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Services;

use DOMDocument;

class ValidateTissXmlService
{
    public function validate(string $xml, ?string $versionCode = null): array
    {
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

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($xml);

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

    private function resolveSchemaPath(?string $versionCode): ?string
    {
        $base = (string) config('tiss.schema_base_path', storage_path('app/tiss/schemas'));

        if ($versionCode) {
            $candidate = $base . DIRECTORY_SEPARATOR . $versionCode . DIRECTORY_SEPARATOR . 'ansTISS.xsd';

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $default = $base . DIRECTORY_SEPARATOR . 'ansTISS.xsd';

        return is_file($default) ? $default : null;
    }
}
