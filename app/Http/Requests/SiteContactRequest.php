<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SiteContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:120'],
            'email'     => ['required', 'string', 'email', 'max:191'],
            'phone'     => ['required', 'string', 'max:30'],
            'is_client' => ['nullable', 'string', 'max:60'],
            'role'      => ['nullable', 'string', 'max:80'],
            'segment'   => ['nullable', 'string', 'max:80'],
            'message'   => ['required', 'string', 'max:5000'],
            'terms'     => ['required', 'accepted'],
        ];
    }

    public function attributes(): array
    {
        return collect(array_keys($this->rules()))
            ->mapWithKeys(fn (string $field) => [$field => $field === 'terms' ? __('validation.attributes.terms') : __("site.contact.form.{$field}")])
            ->all();
    }
}
