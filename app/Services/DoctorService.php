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
     * Find or create user.
     */
    private function findOrCreateUser(DoctorRequest $request): User
    {
        $existingUser = User::query()->withTrashed()
            ->where('email', $request->email)->first();

        if ($existingUser) {
            // BUGFIX (revisao de seguranca): nunca sobrescrever nome/senha/verificacao de um User ja
            // existente aqui -- isso permitia takeover de conta cross-tenant reaproveitando o email de
            // login de outro usuario (o "people.email" validado pelo DoctorRequest nao cobre staff sem
            // registro em "people"). Um User existente e apenas reaproveitado (e restaurado se estava
            // soft-deleted), nunca mutado.
            if ($existingUser->trashed()) {
                $existingUser->restore();
            }

            return $existingUser;
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
     * Find or create person.
     *
     * O DoctorRequest já barra CPF de People ativo; aqui só chega People
     * EXCLUÍDO. Se ele ainda for usado por cadastro ativo de outra clínica,
     * vale a regra do unique (CPF em uso) — nunca reescreve o cadastro dela.
     *
     * @throws ValidationException
     */
    private function findOrCreatePerson(DoctorRequest $request): People
    {
        $existingRecord = People::query()
            ->withTrashed()
            ->where('national_registry', $request->national_registry)
            ->first();

        $recordData = $this->getPersonFromRequest($request);

        if ($existingRecord) {
            if ($this->patientService->personSharedWithOtherEntities($existingRecord->id, (string) session()->get('selected_entity_id'))) {
                throw ValidationException::withMessages([
                    'national_registry' => __('validation.unique', ['attribute' => __('validation.attributes.national_registry')]),
                ]);
            }

            if ($existingRecord->trashed()) {
                $existingRecord->restore();
            }
            $existingRecord->update($recordData);

            return $existingRecord;
        }

        return People::create($recordData);
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
        return [
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
        ];
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
