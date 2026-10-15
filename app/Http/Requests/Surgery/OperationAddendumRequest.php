<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use Illuminate\Foundation\Http\FormRequest;

class OperationAddendumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'text'   => ['required', 'string', 'min:10', 'max:20000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
