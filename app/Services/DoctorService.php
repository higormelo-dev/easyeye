<?php

namespace App\Services;

use App\Http\Requests\DoctorRequest;
use App\Models\{Doctor, EntityUser, People, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DoctorService
{
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
                $this->updatePerson($doctor->person, $request);
                $this->updateUser($doctor->entityUser->user, $request);
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
            if ($existingUser->trashed()) {
                $existingUser->restore();
            }

            $existingUser->update([
                'name'     => $request->nickname,
                'password' => $request->password,
            ]);
            $existingUser->markEmailAsVerified();

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
     */
    private function findOrCreatePerson(DoctorRequest $request): People
    {
        $existingRecord = People::query()
            ->withTrashed()
            ->where('national_registry', $request->national_registry)
            ->first();

        $recordData = $this->getPersonFromRequest($request);

        if ($existingRecord) {
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
     * Update person data.
     */
    private function updatePerson(People $person, Request $request): void
    {
        $person->update([
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
        ]);
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

    /**
     * Update user data.
     */
    private function updateUser(User $user, Request $request): void
    {
        $user->update([
            'name'  => $request->nickname,
            'email' => $request->email,
        ]);
    }
}
