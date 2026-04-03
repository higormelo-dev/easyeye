<?php

namespace App\Http\Requests;

use App\Enums\EntityGate;
use App\Models\{Entity, EntityUser};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class EntityUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $entity = Entity::find(session('selected_entity_id'));

        if (!$entity) {
            return false;
        }

        return Gate::allows(EntityGate::ManageUsers->value, $entity);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules          = [];
        $rules['name']  = ['required_without:type_method', 'string', 'min:2', 'max:255'];
        $rules['email'] = [
            'required_without:type_method',
            'string',
            'email',
            'max:255',
            Rule::unique('users', 'email')
                ->ignore($this->getIgnoredUserId(), 'id')
                ->where(function ($query) {
                    $query->where('entity_id', session('selected_entity_id'))
                        ->whereNull('deleted_at');
                }),
        ];
        $rules['rule'] = [
            'required_without:type_method',
            'string',
            'in:admin,financial,doctor,secretary,support,user',
        ];

        if ($this->isMethod('POST')) {
            $rules['password'] = [
                'required_without:type_method',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ];
            $rules['password_confirmation'] = [
                'required_without:type_method',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ];
        } elseif ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required_without'                  => trans('validation.custom.generic.required'),
            'email.required_without'                 => trans('validation.custom.generic.required'),
            'password.required_without'              => trans('validation.custom.generic.required'),
            'password_confirmation.required_without' => trans('validation.custom.generic.required'),
            'rule.required_without'                  => trans('validation.custom.generic.required'),
        ];
    }

    private function getIgnoredUserId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $entityUserId = $this->route('user');

            if ($entityUserId) {
                $entityUser = EntityUser::query()
                    ->with('user')
                    ->where('entity_users.entity_id', session('selected_entity_id'))
                    ->where('entity_users.id', $entityUserId)
                    ->first();

                return $entityUser && $entityUser->user ? $entityUser->user->id : null;
            }
        }

        return null;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => mb_strtoupper($this->input('name')),
            ]);
        }
    }
}
