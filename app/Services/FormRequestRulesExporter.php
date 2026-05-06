<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Foundation\Http\FormRequest;

/**
 * F9 — exporta as regras de um FormRequest em formato consumível pelo
 * validator client-side, mantendo paridade com o servidor sem reimplementar
 * lógica em duas camadas.
 *
 * Estratégia: introspecção sobre `rules()`, filtrando regras que dependem
 * de DB (`exists`, `unique`) ou de contexto Eloquent — essas só rodam no
 * servidor. Regras puramente sintáticas (`required`, `string`, `numeric`,
 * `integer`, `boolean`, `uuid`, `min`, `max`, `array`) são preservadas.
 */
class FormRequestRulesExporter
{
    /**
     * Tipos de regra serializáveis para o cliente.
     *
     * @var array<int, string>
     */
    private const CLIENT_RULES = [
        'required', 'nullable', 'sometimes', 'string', 'numeric',
        'integer', 'boolean', 'uuid', 'array', 'min', 'max',
        'date_format', 'in',
    ];

    /**
     * Regras descartadas (server-only). Listadas explicitamente para
     * documentar a decisão e evitar drift em futuras adições.
     *
     * @var array<int, string>
     */
    private const SERVER_ONLY_RULES = [
        'exists', 'unique',
    ];

    /**
     * @return array<string, array{rules: array<int, string>, params: array<string, mixed>}>
     */
    public function export(FormRequest $request): array
    {
        $output = [];

        foreach ($request->rules() as $field => $rules) {
            $normalized = $this->normalizeRules($rules);
            $exported   = $this->extractClientRules($normalized);

            if ($exported['rules'] !== []) {
                $output[$field] = $exported;
            }
        }

        return $output;
    }

    /**
     * Normaliza valor da rule (string ou array) em array<string>.
     *
     * @return array<int, string>
     */
    private function normalizeRules(mixed $rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }

        if (is_array($rules)) {
            return array_values(array_filter(
                array_map(fn ($r) => is_string($r) ? $r : null, $rules),
            ));
        }

        return [];
    }

    /**
     * Extrai regras client-safe + parâmetros (min, max, etc.).
     *
     * @param array<int, string> $rules
     *
     * @return array{rules: array<int, string>, params: array<string, mixed>}
     */
    private function extractClientRules(array $rules): array
    {
        $output = ['rules' => [], 'params' => []];

        foreach ($rules as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            $name         = strtolower($name);

            if (in_array($name, self::SERVER_ONLY_RULES, true)) {
                continue;
            }

            if (! in_array($name, self::CLIENT_RULES, true)) {
                continue;
            }

            $output['rules'][] = $name;

            if ($arg !== null && in_array($name, ['min', 'max', 'date_format', 'in'], true)) {
                $output['params'][$name] = $name === 'in' ? explode(',', $arg) : $arg;
            }
        }

        return $output;
    }
}
