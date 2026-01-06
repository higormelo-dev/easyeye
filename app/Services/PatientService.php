<?php

namespace App\Services;

use App\Http\Requests\PatientRequest;
use App\Models\{Covenant, Patient, People};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\RandomException;

class PatientService
{
    /**
     * Create a new patient with all related entities
     */
    public function createPatient(PatientRequest $request): Patient
    {
        return DB::transaction(function () use ($request) {
            $person = $this->findOrCreatePerson($request);

            return $this->findOrCreatePatient($person->id, $request);
        });
    }

    /**
     * Update existing patient and related entities
     */
    public function updatePatient(Patient $patient, PatientRequest $request): Patient
    {
        return DB::transaction(function () use ($patient, $request) {
            $data = [];

            if ($request->has('covenant_id')) {
                $data['covenant_id'] = $request->covenant_id;
            }

            if ($request->has('skin_id')) {
                $data['skin_id'] = $request->skin_id;
            }

            if ($request->has('iris_id')) {
                $data['iris_id'] = $request->iris_id;
            }

            if ($request->has('active')) {
                $data['active'] = $request->boolean('active');
            }

            if ($request->has('covenant_id') && $request->has('card_number')) {
                $covenant            = Covenant::query()->find($request->covenant_id);
                $data['card_number'] = $covenant !== null && $covenant->name !== 'Particular' ?
                    $request->card_number : null;
            }

            $patient->update($data);

            if (! $request->has('type_method')) {
                $this->updatePersonData($patient->person, $request);
            }

            return $patient;
        });
    }

    /**
     * Find or create patient
     */
    private function findOrCreatePatient(string $personId, PatientRequest $request): Patient
    {
        $entityId              = session()->get('selected_entity_id');
        $covenant              = Covenant::query()->find($request->covenant_id);
        $existingPatientEntity = Patient::query()->withTrashed()
            ->where('entity_id', $entityId)
            ->where('person_id', $personId)
            ->first();

        $patientData = [
            'covenant_id' => $request->covenant_id,
            'skin_id'     => $request->skin_id,
            'iris_id'     => $request->iris_id,
            'card_number' => ($covenant !== null && $covenant->name !== 'Particular') ? $request->card_number : null,
        ];

        if ($existingPatientEntity) {
            if ($existingPatientEntity->trashed()) {
                $existingPatientEntity->restore();
            }

            $existingPatientEntity->update($patientData);

            return $existingPatientEntity;
        }

        return Patient::create(
            array_merge(
                $patientData,
                [
                    'entity_id' => $entityId,
                    'person_id' => $personId,
                    'code'      => $this->generateUniqueCode(),
                    'active'    => true,
                ]
            )
        );
    }

    /**
     * Find or create person
     */
    private function findOrCreatePerson(PatientRequest $request): People
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
     * Extract person data from request
     */
    private function getPersonDataFromRequest(PatientRequest $request): array
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
    private function updatePersonData(People $person, PatientRequest $request): void
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
     * Generate unique code for patient
     */
    private function generateUniqueCode(): string
    {
        $maxAttempts = 10;
        $attempt     = 0;

        do {
            try {
                $code = 'PAC' . Str::upper(Str::random(6));
            } catch (RandomException $e) {
                $code = 'PAC' . Str::upper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6));
            }

            $attempt++;

            $exists = Patient::query()->withTrashed()
                ->where('code', $code)
                ->where('entity_id', session()->get('selected_entity_id'))
                ->exists();

        } while ($exists && $attempt < $maxAttempts);

        if ($exists) {
            $code = 'PAC' . strtoupper(substr(md5(time() . rand()), 0, 6));
        }

        return $code;
    }
}
