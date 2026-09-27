<?php

namespace App\Services;

use App\Http\Requests\PatientRequest;
use App\Models\{Covenant, Doctor, Patient, People};
use App\Models\Scopes\EntityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientService
{
    /**
     * Create a new patient with all related entities.
     */
    public function create(PatientRequest $request): Patient
    {
        return DB::transaction(function () use ($request) {
            $person = $this->findOrCreatePerson($request);

            return $this->findOrCreate($person->id, $request);
        });
    }

    /**
     * Update existing patient and related entities.
     */
    public function update(Patient $patient, PatientRequest $request): Patient
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
                $data['card_number'] = $covenant !== null && ! $this->isParticular($covenant) ?
                    $request->card_number : null;
            }

            $patient->update($data);

            if (! $request->has('type_method')) {
                $this->updatePersonData($patient, $request);
            }

            return $patient;
        });
    }

    /**
     * Find by ID or Code including soft-deleted records.
     */
    public function findByIdOrCode(string $idOrCode): ?Patient
    {
        /** @var Patient $record */
        $record = Patient::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->when(
                Str::isUuid($idOrCode),
                static fn ($q) => $q->where('id', $idOrCode),
                static fn ($q) => $q->where('code', $idOrCode),
            )
            ->firstOrFail();

        return $record;
    }

    // ── People: identidade GLOBAL compartilhada entre clínicas ───────────────
    //
    // People não tem entity_id: o mesmo registro pode ser o paciente/médico de
    // várias clínicas (e o titular do Portal do Paciente). Toda consulta de
    // vínculo abaixo ignora o EntityScope, que esconderia justamente os
    // vínculos das OUTRAS clínicas.

    /**
     * O People pode ser vinculado a um cadastro desta clínica a partir de dado
     * informado pelo cliente (CPF/nome+telefone de planilha)? Só se já for
     * desta clínica ou não pertencer a nenhuma — vincular o cadastro de outra
     * clínica expunha os dados dela (e o portal do titular) sem prova de posse.
     * Vínculos excluídos (soft delete) contam: os dados continuam sendo da
     * clínica que os coletou.
     */
    public function personLinkableToEntity(string $personId, string $entityId): bool
    {
        $entityIds = $this->personEntityIds($personId, withTrashed: true);

        return $entityIds->isEmpty() || $entityIds->contains($entityId);
    }

    /**
     * O People está em uso por paciente/médico ATIVO de outra clínica? Nesse
     * caso uma clínica não pode reescrevê-lo sozinha (alteraria o cadastro que
     * a outra vê — e o e-mail para onde vai o convite do portal).
     */
    public function personSharedWithOtherEntities(string $personId, string $entityId): bool
    {
        return $this->personEntityIds($personId, withTrashed: false)
            ->contains(fn (string $id): bool => $id !== $entityId);
    }

    /**
     * Restringe uma query de People aos cadastros com vínculo (paciente ou
     * médico, inclusive excluído) com a clínica.
     *
     * @param Builder<People> $people
     *
     * @return Builder<People>
     */
    public function whereLinkedToEntity(Builder $people, string $entityId): Builder
    {
        return $people->where(fn (Builder $q) => $q
            ->whereIn('people.id', $this->patientPersonIds($entityId, withTrashed: true))
            ->orWhereIn('people.id', $this->doctorPersonIds($entityId, withTrashed: true)));
    }

    /**
     * Exclusão de paciente/médico: o People só sai (soft delete) quando nenhum
     * paciente/médico ATIVO de QUALQUER clínica o usa. Antes a contagem passava
     * pelo EntityScope e só enxergava a clínica atual — excluir o paciente em A
     * apagava o People que a clínica B ainda usava ($patient->person = null).
     */
    public function deletePersonIfUnused(string $personId, ?string $exceptPatientId = null, ?string $exceptDoctorId = null): void
    {
        $patientInUse = Patient::query()
            ->withoutGlobalScope(EntityScope::class)
            ->where('person_id', $personId)
            ->when($exceptPatientId, fn (Builder $q, string $id) => $q->whereKeyNot($id))
            ->exists();

        $doctorInUse = Doctor::query()
            ->where('person_id', $personId)
            ->when($exceptDoctorId, fn (Builder $q, string $id) => $q->whereKeyNot($id))
            ->exists();

        if ($patientInUse || $doctorInUse) {
            return;
        }

        People::query()->find($personId)?->delete();
    }

    /**
     * Preenche o People e barra a gravação quando ela ALTERARIA um cadastro em
     * uso ativo por outra clínica (sem alteração real, segue normalmente — o
     * formulário reenvia todos os campos). Não salva: o chamador decide.
     *
     * @param array<string, mixed> $data
     *
     * @throws ValidationException
     */
    public function fillPersonGuarded(People $person, array $data, string $entityId, string $errorField): People
    {
        $person->fill($data);

        if ($person->exists && $this->hasRealChanges($person) && $this->personSharedWithOtherEntities($person->id, $entityId)) {
            throw ValidationException::withMessages([
                $errorField => __('shared_identity.person_shared_readonly'),
            ]);
        }

        return $person;
    }

    /**
     * Find or create patient.
     */
    private function findOrCreate(string $personId, PatientRequest $request): Patient
    {
        $entityId              = session()->get('selected_entity_id');
        $covenant              = Covenant::query()->find($request->covenant_id);
        $existingPatientEntity = Patient::query()
            ->withTrashed()
            ->where('entity_id', $entityId)
            ->where('person_id', $personId)
            ->first();

        $patientData = [
            'covenant_id' => $request->covenant_id,
            'skin_id'     => $request->skin_id,
            'iris_id'     => $request->iris_id,
            'card_number' => ($covenant !== null && ! $this->isParticular($covenant)) ? $request->card_number : null,
        ];

        if ($existingPatientEntity) {
            if ($existingPatientEntity->trashed()) {
                $existingPatientEntity->restore();
            }

            $existingPatientEntity->update($patientData);

            return $existingPatientEntity->fresh();
        }

        return Patient::create(
            array_merge(
                $patientData,
                [
                    'entity_id' => $entityId,
                    'person_id' => $personId,
                    'active'    => true,
                ],
            ),
        );
    }

    /**
     * Find or create person.
     *
     * O PatientRequest já barra CPF de People ativo; aqui só chega People
     * EXCLUÍDO (soft delete), que é restaurado e sobrescrito com o formulário.
     * Se ele ainda for usado por cadastro ativo de outra clínica (dados antigos
     * da exclusão que apagava People compartilhado), vale a mesma regra do
     * unique: CPF em uso — nunca reescreve o cadastro da outra clínica.
     *
     * @throws ValidationException
     */
    private function findOrCreatePerson(PatientRequest $request): People
    {
        $existingPerson = People::query()
            ->withTrashed()
            ->where('national_registry', $request->national_registry)
            ->first();

        $personData = $this->getPersonDataFromRequest($request);

        if ($existingPerson) {
            if ($this->personSharedWithOtherEntities($existingPerson->id, (string) session()->get('selected_entity_id'))) {
                throw ValidationException::withMessages([
                    'national_registry' => __('validation.unique', ['attribute' => __('validation.attributes.national_registry')]),
                ]);
            }

            if ($existingPerson->trashed()) {
                $existingPerson->restore();
            }
            $existingPerson->update($personData);

            return $existingPerson->fresh();
        }

        return People::create($personData);
    }

    /**
     * Extract person data from request.
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
     * Update person data — barrado (422) quando o People é usado por cadastro
     * ativo de outra clínica e o formulário altera algum dado pessoal.
     *
     * @throws ValidationException
     */
    private function updatePersonData(Patient $patient, PatientRequest $request): void
    {
        // withTrashed: People apagado indevidamente (exclusão em outra clínica,
        // antes do deletePersonIfUnused) não pode virar TypeError/500 na edição.
        $this->fillPersonGuarded(
            $patient->person()->withTrashed()->firstOrFail(),
            $this->getPersonDataFromRequest($request),
            (string) $patient->entity_id,
            'name',
        )->save();
    }

    /**
     * Houve mudança real? getDirty() já compara com os casts (datas, números);
     * aqui só se ignora whatsapp nulo (cadastro rápido/importação) reenviado
     * como false pelo formulário.
     */
    private function hasRealChanges(People $person): bool
    {
        foreach (array_keys($person->getDirty()) as $field) {
            if ($field === 'whatsapp' && (bool) $person->getOriginal($field) === (bool) $person->getAttribute($field)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Clínicas (entity_id) em que o People é paciente ou médico.
     *
     * @return Collection<int, string>
     */
    private function personEntityIds(string $personId, bool $withTrashed): Collection
    {
        $patientEntities = Patient::query()
            ->withoutGlobalScope(EntityScope::class)
            ->when($withTrashed, fn (Builder $q) => $q->withTrashed())
            ->where('person_id', $personId)
            ->distinct()
            ->pluck('entity_id');

        $doctorEntities = Doctor::query()
            ->when($withTrashed, fn (Builder $q) => $q->withTrashed())
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->where('doctors.person_id', $personId)
            ->distinct()
            ->pluck('entity_users.entity_id');

        return $patientEntities->merge($doctorEntities)
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();
    }

    /** Subquery: person_id dos pacientes da clínica. */
    private function patientPersonIds(string $entityId, bool $withTrashed): Builder
    {
        return Patient::query()
            ->withoutGlobalScope(EntityScope::class)
            ->when($withTrashed, fn (Builder $q) => $q->withTrashed())
            ->where('entity_id', $entityId)
            ->select('person_id');
    }

    /** Subquery: person_id dos médicos da clínica. */
    private function doctorPersonIds(string $entityId, bool $withTrashed): Builder
    {
        return Doctor::query()
            ->when($withTrashed, fn (Builder $q) => $q->withTrashed())
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->where('entity_users.entity_id', $entityId)
            ->select('doctors.person_id');
    }

    /** Covenant grava `name` em maiúsculas (HasUppercaseFields) — comparar sem caixa. */
    private function isParticular(Covenant $covenant): bool
    {
        return mb_strtoupper(trim((string) $covenant->name), 'UTF-8') === 'PARTICULAR';
    }
}
