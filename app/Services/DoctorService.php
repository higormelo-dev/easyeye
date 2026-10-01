<?php

namespace App\Services;

use App\Http\Requests\DoctorRequest;
use App\Models\{Doctor, EntityUser, People, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DoctorService
{
    public function __construct(
        private readonly PatientService $patientService,
        private readonly EntityUserService $entityUserService,
    ) {
    }

    /**
     * Create a new doctor with all related entities.
     */
    public function create(DoctorRequest $request): EntityUser
    {
        return DB::transaction(function () use ($request) {
            $user       = $this->findOrCreateUser($request);
            $entityUser = $this->findOrCreateEntityUser($user, $request);
            $person     = $this->findOrCreatePerson($request);
            $this->findOrCreate($person, $entityUser, $request);

            return $entityUser;
        });
    }

    /**
     * Update existing doctor and related entities.
     */
    public function update(Doctor $doctor, DoctorRequest $request): Doctor
    {
        return DB::transaction(function () use ($doctor, $request) {
            $data = [];

            if ($request->has('record')) {
                $data['record'] = $request->record;
            }

            if ($request->has('record_specialty')) {
                $data['record_specialty'] = $request->record_specialty;
            }

            if ($request->has('cbo_code')) {
                $data['cbo_code'] = $request->cbo_code;
            }

            if ($request->has('color')) {
                $data['color'] = $request->color;
            }

            if ($request->has('partner')) {
                $data['partner'] = $request->boolean('partner');
            }

            if ($request->has('active')) {
                $data['active'] = $request->boolean('active');
            }

            if ($request->has('observation')) {
                $data['observation'] = $request->observation;
            }

            $doctor->update($data);
            $this->updateEntityUser($doctor->entityUser, $request);

            if (! $request->has('type_method')) {
                $entityId = (string) $doctor->entityUser->entity_id;

                // People e User são GLOBAIS: barrados (422) quando a edição
                // alteraria o cadastro/login usado por outra clínica.
                // withTrashed: People apagado indevidamente (exclusão em outra
                // clínica, antes do deletePersonIfUnused) não vira TypeError/500.
                $this->updatePerson($doctor->person()->withTrashed()->firstOrFail(), $request, $entityId);
                $this->entityUserService->updateLoginGuarded(
                    $doctor->entityUser->user,
                    (string) $request->nickname,
                    (string) $request->email,
                    $entityId,
                );
            }

            return $doctor;
        });
    }

    /**
     * Aceite de convite (DoctorInvitationService::accept): coloca o login
     * EXISTENTE do médico nesta clínica com os dados que ELA digitou — cadastro
     * (People) próprio da clínica; o login (nome/e-mail/senha) e os cadastros
     * de outras clínicas não são tocados. Chamar dentro de transação.
     *
     * @param array<string, mixed> $data payload do convite (formulário de médico)
     */
    public function attachExistingUser(User $user, string $entityId, array $data, ?string $invitedBy = null): Doctor
    {
        $entityUser = EntityUser::query()->withTrashed()
            ->where('user_id', $user->id)
            ->where('entity_id', $entityId)
            ->first();

        if ($entityUser?->trashed()) {
            $entityUser->restore();
        }

        $entityUser ??= new EntityUser(['entity_id' => $entityId, 'user_id' => $user->id, 'invited_by' => $invitedBy]);
        $entityUser->fill(['rule' => 'doctor', 'active' => true, 'joined_at' => now()])->save();

        $person = $this->findOrCreateClinicPerson($this->personData($data), $entityId);

        $doctorData = [
            'entity_user_id'   => $entityUser->id,
            'person_id'        => $person->id,
            'record'           => $data['record'] ?? null,
            'record_specialty' => $data['record_specialty'] ?? null,
            'cbo_code'         => $data['cbo_code'] ?? null,
            'color'            => $data['color'] ?? null,
            'partner'          => (bool) ($data['partner'] ?? false),
            'observation'      => $data['observation'] ?? null,
        ];

        $doctor = Doctor::query()->withTrashed()->where('entity_user_id', $entityUser->id)->first();

        if ($doctor?->trashed()) {
            $doctor->restore();
        }

        if ($doctor) {
            $doctor->update($doctorData);

            return $doctor;
        }

        return Doctor::create($doctorData);
    }

    /**
     * Find by ID or Code including soft-deleted records.
     */
    public function findByIdOrCode(string $idOrCode): ?Doctor
    {
        /** @var Doctor $record */
        $record = Doctor::query()
            ->withTrashed()
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->where('entity_users.entity_id', session()->get('selected_entity_id'))
            ->select('doctors.*')
            ->when(
                Str::isUuid($idOrCode),
                static fn ($q) => $q->where('doctors.id', $idOrCode),
                static fn ($q) => $q->where('doctors.code', $idOrCode),
            )
            ->firstOrFail();

        return $record;
    }

    /**
     * Cria o login do médico. Login EXISTENTE (mesmo e-mail, inclusive
     * excluído) nunca é reaproveitado aqui: vincular o login de alguém a esta
     * clínica só pelo convite que o próprio médico aceita
     * (DoctorInvitationService). O DoctorRequest já desvia esse caso para o
     * convite; isto é defesa em profundidade (takeover cross-tenant).
     *
     * @throws ValidationException
     */
    private function findOrCreateUser(DoctorRequest $request): User
    {
        if (User::query()->withTrashed()->whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $request->email))])->exists()) {
            throw ValidationException::withMessages([
                'email' => __('validation.unique', ['attribute' => __('validation.attributes.email')]),
            ]);
        }

        $user = User::create([
            'name'     => $request->nickname,
            'email'    => $request->email,
            'password' => $request->password,
        ]);
        $user->markEmailAsVerified();

        return $user;
    }

    /**
     * Find or create entity user.
     */
    private function findOrCreateEntityUser(User $user, DoctorRequest $request): EntityUser
    {
        $existingRecord = EntityUser::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->where('entity_id', session()->get('selected_entity_id'))
            ->first();

        if ($existingRecord) {
            if ($existingRecord->trashed()) {
                $existingRecord->restore();
            }

            $existingRecord->update([
                'rule'   => 'doctor',
                'active' => true,
            ]);

            return $existingRecord;
        }

        return EntityUser::create([
            'entity_id' => session()->get('selected_entity_id'),
            'user_id'   => $user->id,
            'rule'      => 'doctor',
            'active'    => true,
        ]);
    }

    /**
     * Find or create person — só entre os cadastros de MÉDICO desta clínica.
     *
     * O mesmo CPF pode ter um cadastro por clínica (médico em várias, ou
     * paciente em outra): o de outra clínica, ativo ou excluído, nunca é lido,
     * restaurado nem sobrescrito aqui; a ficha de paciente da própria clínica
     * também não. Cadastro compartilhado antigo: fillPersonGuarded barra (422).
     *
     * @throws ValidationException
     */
    private function findOrCreatePerson(DoctorRequest $request): People
    {
        return $this->findOrCreateClinicPerson($this->getPersonFromRequest($request), (string) session()->get('selected_entity_id'));
    }

    /** @param array<string, mixed> $personData */
    private function findOrCreateClinicPerson(array $personData, string $entityId): People
    {
        $existing = $this->patientService
            ->whereDoctorOfEntity(People::query()->withTrashed(), $entityId)
            ->where('national_registry', $personData['national_registry'] ?? null)
            ->orderBy('created_at')
            ->first();

        if (! $existing) {
            return People::create($personData);
        }

        if ($existing->trashed()) {
            $existing->restore();
        }

        $this->patientService->fillPersonGuarded($existing, $personData, $entityId, 'national_registry')->save();

        return $existing;
    }

    /**
     * Find or create doctor.
     */
    private function findOrCreate(People $person, EntityUser $entityUser, DoctorRequest $request): void
    {
        $existingRecord = Doctor::query()
            ->withTrashed()
            ->where('person_id', $person->id)
            ->where('record', $request->record)
            ->where('entity_user_id', $entityUser->id)
            ->first();

        $recordData = [
            'entity_user_id'   => $entityUser->id,
            'person_id'        => $person->id,
            'record'           => $request->record,
            'record_specialty' => $request->record_specialty,
            'cbo_code'         => $request->cbo_code,
            'color'            => $request->color,
            'partner'          => $request->partner,
            'observation'      => $request->observation,
        ];

        if ($existingRecord) {
            if ($existingRecord->trashed()) {
                $existingRecord->restore();
            }
            $existingRecord->update($recordData);

            return;
        }

        Doctor::create($recordData);
    }

    /**
     * Extract person data from request.
     */
    private function getPersonFromRequest(DoctorRequest $request): array
    {
        return $this->personData($request->all());
    }

    /**
     * Dados pessoais (People) a partir do formulário de médico — também usado
     * no aceite do convite (payload que a clínica digitou).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function personData(array $data): array
    {
        $fields = [
            'full_name'             => 'name', 'nickname' => 'nickname', 'birth_date' => 'birth_date', 'gender' => 'gender',
            'marital_status'        => 'marital_status', 'email' => 'email', 'mother_name' => 'mother_name',
            'father_name'           => 'father_name', 'national_registry' => 'national_registry', 'state_registry' => 'state_registry',
            'state_registry_agency' => 'state_registry_agency', 'state_registry_initial' => 'state_registry_initial',
            'state_registry_date'   => 'state_registry_date', 'telephone' => 'telephone', 'cellphone' => 'cellphone',
            'whatsapp'              => 'whatsapp', 'zipcode' => 'zipcode', 'address' => 'address', 'number' => 'number',
            'complement'            => 'complement', 'district' => 'district', 'city' => 'city', 'state' => 'state', 'country' => 'country',
        ];

        return array_map(fn (string $input) => $data[$input] ?? null, $fields);
    }

    /**
     * Update person data — barrado (422) quando o People é usado por cadastro
     * ativo de outra clínica e o formulário altera algum dado pessoal.
     *
     * @throws ValidationException
     */
    private function updatePerson(People $person, Request $request, string $entityId): void
    {
        $this->patientService->fillPersonGuarded($person, [
            'full_name'              => $request->name,
            'nickname'               => $request->nickname,
            'birth_date'             => $request->birth_date,
            'gender'                 => $request->gender,
            'marital_status'         => $request->marital_status,
            'email'                  => $request->email,
            'mother_name'            => $request->mother_name,
            'father_name'            => $request->father_name,
            'national_registry'      => $request->national_registry,
            'state_registry'         => $request->state_registry,
            'state_registry_agency'  => $request->state_registry_agency,
            'state_registry_initial' => $request->state_registry_initial,
            'state_registry_date'    => $request->state_registry_date,
            'telephone'              => $request->telephone,
            'cellphone'              => $request->cellphone,
            'whatsapp'               => $request->whatsapp,
            'zipcode'                => $request->zipcode,
            'address'                => $request->address,
            'number'                 => $request->number,
            'complement'             => $request->complement,
            'district'               => $request->district,
            'city'                   => $request->city,
            'state'                  => $request->state,
            'country'                => $request->country,
        ], $entityId, 'name')->save();
    }

    /**
     * Update entity user data.
     */
    private function updateEntityUser(EntityUser $entityUser, Request $request): void
    {
        $entityUser->update([
            'active' => $request->active,
        ]);
    }
}
