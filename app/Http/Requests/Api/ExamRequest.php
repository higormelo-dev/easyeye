<?php

namespace App\Http\Requests\Api;

use App\Models\{ExamType, PatientExam, Schedule};
use Illuminate\Foundation\Http\FormRequest;

class ExamRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $integrator = request()->attributes->get('integrator');
        $entityId   = $integrator->user->entity_id;

        return [
            'exam_identifier' => [
                'required',
                function ($attribute, $value, $fail) use ($entityId) {
                    $query = ExamType::query()
                        ->where(function ($query) use ($entityId) {
                            $query->where('entity_id', $entityId)
                                ->orWhereNull('entity_id');
                        })
                        ->whereNull('deleted_at');

                    $isUuid = preg_match(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                        $value
                    );

                    if ($isUuid) {
                        $query->where('id', $value);
                    } else {
                        $query->where('code', $value);
                    }

                    if (! $query->exists()) {
                        $fail(__('validation.custom.validation_invalid.exam_identifier'));
                    }
                },
            ],
            'schedule_identifier' => [
                'required',
                function ($attribute, $value, $fail) use ($entityId) {
                    $isUuid = preg_match(
                        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                        $value
                    );

                    $query = Schedule::query()
                        ->where('entity_id', $entityId)
                        ->whereNull('deleted_at');

                    if ($isUuid) {
                        $query->where('id', $value);
                    } else {
                        $query->whereRaw('LOWER(code) = LOWER(?)', [$value]);
                    }

                    if (! $query->exists()) {
                        $fail(__('validation.custom.validation_invalid.schedule_identifier'));
                    }
                },
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                'min:3',
                function ($attribute, $value, $fail) use ($entityId) {
                    if ($value === null || $value === '') {
                        return;
                    }

                    // name é único por entidade
                    $exists = PatientExam::query()
                        ->whereHas('patient', function ($query) use ($entityId) {
                            $query->where('entity_id', $entityId)->whereNull('deleted_at');
                        })
                        ->whereRaw('LOWER(name) = LOWER(?)', [$value])
                        ->exists();

                    if ($exists) {
                        $fail(__('validation.custom.validation_unique.name_combination'));
                    }
                },
            ],
            'archive' => 'required|file|mimes:jpg,jpeg,png|max:10240',
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        foreach ($this->all() as $key => $value) {
            if ($key === 'archive') {
                $data[$key] = $value;

                continue;
            }

            if (is_string($value)) {
                $cleanValue = trim($value);
                $cleanValue = trim($cleanValue, '-');
                $cleanValue = trim($cleanValue);

                $data[$key] = $cleanValue === '' ? null : $cleanValue;
            } elseif (is_null($value)) {
                $data[$key] = null;
            } else {
                $data[$key] = $value;
            }
        }

        $this->replace($data);
    }
}
