<?php

declare(strict_types=1);

namespace App\Http\Requests\Recall;

class RecallDoneElsewhereRequest extends RecallActionRequest
{
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
