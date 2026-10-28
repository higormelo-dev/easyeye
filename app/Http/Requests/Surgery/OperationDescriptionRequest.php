<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Enums\Surgery\AnesthesiaType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Rascunho da descrição cirúrgica. */
class OperationDescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'performed_start_at'  => ['nullable', 'date'],
            'performed_end_at'    => ['nullable', 'date'],
            'anesthesia_used'     => ['nullable', Rule::enum(AnesthesiaType::class)],
            'findings'            => ['nullable', 'string', 'max:20000'],
            'technique_performed' => ['nullable', 'string', 'max:20000'],
            'no_complications'    => ['nullable', 'boolean'],
            'complications'       => ['nullable', 'string', 'max:20000'],
            'expected_revision'   => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function expectedRevision(): ?int
    {
        return $this->filled('expected_revision') ? (int) $this->input('expected_revision') : null;
    }
}
