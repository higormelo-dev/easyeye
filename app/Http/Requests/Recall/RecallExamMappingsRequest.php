<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

use App\Domains\Recall\Enums\RecallExamKey;
use Closure;

class RecallExamMappingsRequest extends RecallSettingsRequest
{
    public function rules(): array
    {
        $rules = ['mappings' => ['required', 'array']];

        foreach (RecallExamKey::values() as $key) {
            $rules["mappings.{$key}"]   = ['nullable', 'array', 'max:40'];
            $rules["mappings.{$key}.*"] = ['uuid', 'distinct'];
        }

        // Chave fora da lista fechada é recusada (o serviço também recusa tipo de exame de outra clínica).
        $rules['mappings'][] = static function (string $attribute, mixed $value, Closure $fail): void {
            foreach (array_keys((array) $value) as $key) {
                if (! in_array((string) $key, RecallExamKey::values(), true)) {
                    $fail(__('recall_settings.errors.unknown_exam_key', ['key' => (string) $key]));
                }
            }
        };

        return $rules;
    }
}
