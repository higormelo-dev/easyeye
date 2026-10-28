<?php

declare(strict_types=1);

namespace App\Http\Requests\Intravitreal;

class ScheduleNextIntravitrealRequest extends IntravitrealWriteRequest
{
    public function rules(): array
    {
        return [
            'date_time' => ['required', 'date', 'after:now'],
        ];
    }
}
