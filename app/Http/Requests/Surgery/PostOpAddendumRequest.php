<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use Illuminate\Foundation\Http\FormRequest;

/** Adendo de uma avaliação finalizada (texto + refração corrigida opcional). */
class PostOpAddendumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'text'               => ['required', 'string', 'min:3', 'max:3000'],
            'corrected_sphere'   => ['nullable', 'numeric', 'between:-30,30'],
            'corrected_cylinder' => ['nullable', 'numeric', 'between:-15,15'],
            'corrected_axis'     => ['nullable', 'integer', 'between:0,180'],
        ];
    }
}
