<?php

namespace App\Http\Requests;

use App\Models\EntityUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntityUserRequest extends FormRequest
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
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($this->getIgnoredUserId(), 'id')
                    ->where(function ($query) {
                        return $query->whereNull('deleted_at');
                    }),
            ],
        ];

        if ($this->isMethod('POST')) {
            $rules['password']              = ['required', 'string', 'min:8', 'confirmed'];
            $rules['password_confirmation'] = ['required', 'string', 'min:8'];
            $rules['rule']                  = [
                'required',
                'string',
                'in:admin,financial,doctor,secretary,support,user',
            ];
        } elseif ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
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
}
