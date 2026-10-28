<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Enums\Surgery\ChecklistAnswer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Respostas de uma fase do checklist de cirurgia segura. */
class SurgeryChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'answers'          => ['present' => $this->routeIs('*.checklist.close') ? 'nullable' : 'required', 'array', 'max:40'],
            'answers.*.key'    => ['required', 'string', 'max:60'],
            'answers.*.answer' => ['required', Rule::enum(ChecklistAnswer::class)],
            'answers.*.note'   => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return list<array{key: string, answer: string, note?: string|null}> */
    public function answers(): array
    {
        return array_values((array) $this->validated('answers', []));
    }
}
