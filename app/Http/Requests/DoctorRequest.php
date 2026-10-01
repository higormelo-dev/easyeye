<?php

namespace App\Http\Requests;

use App\Enums\EntityGate;
use App\Models\{Doctor, Entity};
use App\Models\User;
use App\Services\DoctorInvitationService;
use App\Support\BrazilianFormat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\{Rule, Validator};
use Illuminate\Validation\Rules\Password;

class DoctorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Cadastro novo (e convite): mesma permissão do controller, checada
        // ANTES da validação — a validação agora diz se o e-mail/CPF já tem
        // login no EasyEye, e isso só pode chegar a quem gerencia médicos.
        if ($this->isMethod('POST')) {
            $entity = Entity::query()->find(session('selected_entity_id'));

            return $entity !== null && Gate::allows(EntityGate::ManageSettings->value, $entity);
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rules                      = [];
        $rules['name']              = ['required_without:type_method', 'string', 'min:2', 'max:255'];
        $rules['national_registry'] = [
            'required_without:type_method',
            'string',
            'max:11',
            // Único entre os MÉDICOS desta clínica. CPF de médico de outra
            // clínica cai em after() (convite); CPF de paciente (desta ou de
            // outra clínica) não é conflito — cada cadastro é próprio.
            Rule::unique('people', 'national_registry')
                ->ignore($this->getIgnoredPersonId(), 'id')
                ->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->whereIn('id', fn ($doctors) => $doctors
                        ->select('doctors.person_id')
                        ->from('doctors')
                        ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                        ->where('entity_users.entity_id', session('selected_entity_id'))
                        ->whereNull('doctors.deleted_at'))),
        ];
        $rules['nickname'] = ['required_without:type_method', 'string', 'min:2', 'max:255'];
        $rules['record']   = [
            'required_without:type_method',
            'string',
            'min:2',
            'max:255',
            Rule::unique('doctors', 'record')
                ->ignore($this->route('doctor'))
                ->where(function ($query) {
                    $query->whereIn('entity_user_id', function ($subquery) {
                        $subquery->select('id')
                            ->from('entity_users')
                            ->where('entity_id', session('selected_entity_id'));
                    })->whereNull('deleted_at');
                }),
        ];
        $rules['record_specialty'] = [
            'required_without:type_method',
            'string',
            'min:2',
            'max:255',
            Rule::unique('doctors', 'record_specialty')
                ->ignore($this->route('doctor'))
                ->where(function ($query) {
                    $query->whereIn('entity_user_id', function ($subquery) {
                        $subquery->select('id')
                            ->from('entity_users')
                            ->where('entity_id', session('selected_entity_id'));
                    })->whereNull('deleted_at');
                }),
        ];
        $rules['color'] = [
            'required_without:type_method',
            'string',
            'max:255',
            Rule::unique('doctors', 'color')
                ->ignore($this->route('doctor'))
                ->where(function ($query) {
                    $query->whereIn('entity_user_id', function ($subquery) {
                        $subquery->select('id')
                            ->from('entity_users')
                            ->where('entity_id', session('selected_entity_id'));
                    })->whereNull('deleted_at');
                }),
        ];
        $rules['cbo_code']       = ['nullable', 'string', 'max:10'];
        $rules['birth_date']     = ['nullable', 'date'];
        $rules['gender']         = ['nullable', 'integer'];
        $rules['marital_status'] = ['nullable', 'integer'];
        $rules['email']          = [
            'required_without:type_method',
            'string',
            'max:255',
            // BUGFIX (revisao de seguranca): unicidade de email deve ser validada contra "users" (tabela de
            // login usada por DoctorService::findOrCreateUser), nao "people" -- staff sem registro em
            // "people" (secretary/admin/financial) permitia colisao de email de login cross-tenant.
            // Cadastro NOVO: o e-mail de um login existente não vira erro seco
            // aqui — after() decide (convite / duplicidade / conflito) e o
            // DoctorService nunca reaproveita login existente.
            ...($this->isMethod('POST') ? [] : [
                Rule::unique('users', 'email')->ignore($this->getIgnoredUserId(), 'id'),
            ]),
        ];
        $rules['mother_name']            = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['father_name']            = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['state_registry']         = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['state_registry_agency']  = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['state_registry_initial'] = ['nullable', 'string'];
        $rules['state_registry_date']    = ['nullable', 'date'];
        $rules['telephone']              = ['nullable', 'string'];
        $rules['cellphone']              = ['nullable', 'string'];
        $rules['whatsapp']               = ['nullable', 'boolean'];
        $rules['zipcode']                = ['nullable', 'string', 'min:2', 'max:20'];
        $rules['address']                = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['number']                 = ['nullable', 'string', 'min:2', 'max:50'];
        $rules['complement']             = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['district']               = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['city']                   = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['state']                  = ['nullable', 'string', 'min:2', 'max:255'];
        $rules['observation']            = ['nullable', 'string'];
        $rules['partner']                = ['nullable', 'boolean'];

        if ($this->isMethod('POST')) {
            $rules['password'] = [
                'required_without:type_method',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ];
            $rules['password_confirmation'] = [
                'required_without:type_method',
            ];
        } elseif ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * Cadastro novo: o e-mail/CPF já pertencem a alguém com login no EasyEye?
     * Roda só se e-mail e CPF passaram nas regras (formato, duplicidade na
     * própria clínica).
     *
     * @return list<callable>
     */
    public function after(): array
    {
        if (! $this->isMethod('POST') || $this->has('type_method')) {
            return [];
        }

        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['email', 'national_registry'])) {
                return;
            }

            $this->handleExistingLogin($validator, app(DoctorInvitationService::class)->detect(
                (string) $this->input('email'),
                (string) $this->input('national_registry'),
                (string) session('selected_entity_id'),
            ));
        }];
    }

    /**
     * Cadastro normal: login de outra clínica → aviso "já possui cadastro no
     * EasyEye, mas não nesta clínica" (chave existing_doctor — a tela oferece
     * o convite). Nunca devolve nome, e-mail ou clínicas do médico.
     *
     * @param array{status: string, user: ?User, field: ?string} $detection
     */
    protected function handleExistingLogin(Validator $validator, array $detection): void
    {
        match ($detection['status']) {
            DoctorInvitationService::DETECT_INVITE   => $validator->errors()->add('existing_doctor', __('doctors.invitation.exists_elsewhere')),
            DoctorInvitationService::DETECT_MEMBER   => $this->addDuplicateError($validator, (string) $detection['field']),
            DoctorInvitationService::DETECT_TAKEN    => $this->addDuplicateError($validator, (string) $detection['field']),
            DoctorInvitationService::DETECT_CONFLICT => $this->addConflictError($validator),
            default                                  => null,
        };
    }

    protected function addDuplicateError(Validator $validator, string $field): void
    {
        $validator->errors()->add($field, __('validation.unique', ['attribute' => __('validation.attributes.' . $field)]));
    }

    protected function addConflictError(Validator $validator): void
    {
        $validator->errors()->add('national_registry', __('doctors.invitation.conflict'));
        $validator->errors()->add('email', __('doctors.invitation.conflict'));
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'record.required_without'                => trans('validation.custom.generic.required'),
            'record_specialty.required_without'      => trans('validation.custom.generic.required'),
            'color.required_without'                 => trans('validation.custom.generic.required'),
            'name.required_without'                  => trans('validation.custom.generic.required'),
            'nickname.required_without'              => trans('validation.custom.generic.required'),
            'birth_date.required_without'            => trans('validation.custom.generic.required'),
            'gender.required_without'                => trans('validation.custom.generic.required'),
            'marital_status.required_without'        => trans('validation.custom.generic.required'),
            'email.required_without'                 => trans('validation.custom.generic.required'),
            'national_registry.required_without'     => trans('validation.custom.generic.required'),
            'cellphone.required_without'             => trans('validation.custom.generic.required'),
            'whatsapp.required_without'              => trans('validation.custom.generic.required'),
            'password.required_without'              => trans('validation.custom.generic.required'),
            'password_confirmation.required_without' => trans('validation.custom.generic.required'),
        ];
    }

    private function getIgnoredPersonId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $doctorId = $this->route('doctor');

            $doctor = Doctor::query()
                ->with('person')
                ->where('doctors.id', $doctorId)
                ->first();

            return $doctor && $doctor->person ? $doctor->person->id : null;
        }

        return null;
    }

    // BUGFIX (revisao de seguranca): resolve o "users.id" do proprio medico em edicao, para o unique de
    // email (agora contra "users") nao rejeitar o email atual dele.
    private function getIgnoredUserId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $doctorId = $this->route('doctor');

            $doctor = Doctor::query()
                ->with('entityUser.user')
                ->where('doctors.id', $doctorId)
                ->first();

            return $doctor && $doctor->entityUser && $doctor->entityUser->user
                ? $doctor->entityUser->user->id
                : null;
        }

        return null;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('name')) {
            $merge['name'] = mb_strtoupper($this->input('name'));
        }

        if ($this->has('color')) {
            $merge['color'] = mb_strtoupper($this->input('color'));
        }

        // Front envia CPF/telefones/CEP mascarados (v-mask); banco guarda só dígitos,
        // igual PatientRequest e DoctorImportService — o max:11 e o unique de
        // people.national_registry passam a comparar o mesmo formato.
        foreach (['national_registry', 'zipcode'] as $digitsField) {
            if ($this->has($digitsField) && $this->input($digitsField) !== null) {
                $merge[$digitsField] = preg_replace('/\D/', '', (string) $this->input($digitsField)) ?: null;
            }
        }

        // Telefones: só dígitos e sem DDI 55 (legado/colado com +55 vira DDD + número,
        // formato que a máscara exibe e os gateways esperam — área = 2 primeiros dígitos).
        foreach (['telephone', 'cellphone'] as $phoneField) {
            if ($this->has($phoneField) && $this->input($phoneField) !== null) {
                $merge[$phoneField] = BrazilianFormat::canonicalPhone((string) $this->input($phoneField));
            }
        }

        foreach (['whatsapp', 'partner', 'active'] as $booleanField) {
            if ($this->has($booleanField)) {
                $merge[$booleanField] = $this->normalizeBoolean($this->input($booleanField));
            }
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    private function normalizeBoolean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return (bool) $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => $value,
        };
    }
}
