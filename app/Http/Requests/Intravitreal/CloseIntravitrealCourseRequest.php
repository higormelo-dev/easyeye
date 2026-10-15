<?php

declare(strict_types=1);

namespace App\Http\Requests\Intravitreal;

use Illuminate\Validation\Rule;

class CloseIntravitrealCourseRequest extends IntravitrealWriteRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['completed', 'discontinued'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
