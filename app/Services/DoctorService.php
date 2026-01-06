<?php

namespace App\Services;

use App\Http\Requests\DoctorRequest;
use App\Models\{Doctor, EntityUser, People, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\RandomException;

class DoctorService
{
    /**
     * Create a new doctor with all related entities
     */
    public function createDoctor(DoctorRequest $request): EntityUser
    {
        return DB::transaction(function () use ($request) {
            $user       = $this->findOrCreateUser($request);
            $entityUser = $this->findOrCreateEntityUser($user, $request);
            $person     = $this->findOrCreatePerson($request);
            $this->findOrCreateDoctor($person, $entityUser, $request);

            return $entityUser;
        });
    }

    /**
     * Update existing doctor and related entities
     */
    public function updateDoctor(Doctor $doctor, DoctorRequest $request): Doctor
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
            $this->updateEntityUserData($doctor->entityUser, $request);

            if (! $request->has('type_method')) {
                $this->updatePersonData($doctor->person, $request);
                $this->updateUserData($doctor->entityUser->user, $request);
            }

            return $doctor;
        });
    }

    /**
     * Find or create user
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
     * Find or create entity user
     */
    private function findOrCreateEntityUser(User $user, DoctorRequest $request): EntityUser
    {
        $existingEntityUser = EntityUser::query()->withTrashed()
            ->where('user_id', $user->id)
            ->where('entity_id', session()->get('selected_entity_id'))
            ->first();

        if ($existingEntityUser) {
            if ($existingEntityUser->trashed()) {
                $existingEntityUser->restore();
            }

            $existingEntityUser->update([
                'rule'   => 'doctor',
                'active' => true,
            ]);

            return $existingEntityUser;
        }

        return EntityUser::create([
            'entity_id' => session()->get('selected_entity_id'),
            'user_id'   => $user->id,
            'rule'      => 'doctor',
            'active'    => true,
        ]);
    }

    /**
     * Find or create person
     */
    private function findOrCreatePerson(DoctorRequest $request): People
    {
        $existingPerson = People::query()->withTrashed()
            ->where('national_registry', $request->national_registry)
            ->first();

        $personData = $this->getPersonDataFromRequest($request);

        if ($existingPerson) {
            if ($existingPerson->trashed()) {
                $existingPerson->restore();
            }
            $existingPerson->update($personData);

            return $existingPerson;
        }

        return People::create($personData);
    }

    /**
     * Find or create doctor
     */
    private function findOrCreateDoctor(People $person, EntityUser $entityUser, DoctorRequest $request): void
    {
        $existingDoctor = Doctor::query()->withTrashed()
            ->where('person_id', $person->id)
            ->where('record', $request->record)
            ->where('entity_user_id', $entityUser->id)
            ->first();

        $doctorData = [
            'entity_user_id'   => $entityUser->id,
            'person_id'        => $person->id,
            'record'           => $request->record,
            'record_specialty' => $request->record_specialty,
            'color'            => $request->color,
            'partner'          => $request->partner,
            'observation'      => $request->observation,
        ];

        if ($existingDoctor) {
            if ($existingDoctor->trashed()) {
                $existingDoctor->restore();
            }
            $existingDoctor->update($doctorData);

            return;
        }

        Doctor::create(array_merge($doctorData, [
            'code' => $this->generateUniqueCode(),
        ]));
    }

    /**
     * Extract person data from request
     */
    private function getPersonDataFromRequest(DoctorRequest $request): array
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
     * Update person data
     */
    private function updatePersonData(People $person, Request $request): void
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
     * Update entity user data
     */
    private function updateEntityUserData(EntityUser $entityUser, Request $request): void
    {
        $entityUser->update([
            'active' => $request->active,
        ]);
    }

    /**
     * Update user data
     */
    private function updateUserData(User $user, Request $request): void
    {
        $user->update([
            'name'  => $request->nickname,
            'email' => $request->email,
        ]);
    }

    /**
     * Generate unique code for doctor
     */
    private function generateUniqueCode(): string
    {
        $maxAttempts = 10;
        $attempt     = 0;

        do {
            try {
                $code = 'DOC' . Str::upper(Str::random(6));
            } catch (RandomException $e) {
                $code = 'DOC' . Str::upper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
            }

            $attempt++;

            $exists = Doctor::query()->withTrashed()
                ->where('code', $code)
                ->whereHas('entityUser', function ($query) {
                    $query->where('entity_id', session()->get('selected_entity_id'));
                })
                ->exists();

        } while ($exists && $attempt < $maxAttempts);

        if ($exists) {
            $code = 'PAC' . strtoupper(substr(md5(time() . rand()), 0, 6));
        }

        return $code;
    }
}
