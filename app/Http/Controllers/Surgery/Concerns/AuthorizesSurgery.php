<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery\Concerns;

use App\Enums\{DataAccessPurpose, EntityGate};
use App\Models\Entity;
use App\Models\Surgery\{SurgicalCase, SurgicalTeamMember};
use App\Services\DataAccessLogService;
use App\Support\CurrentDoctor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Autorização comum do módulo cirúrgico. O recorte por papel do médico
 * segue a regra única da Agenda (App\Support\CurrentDoctor): médico vê e
 * mexe só nos casos em que é cirurgião ou membro da equipe; perfil médico sem
 * cadastro não vê nada (a tela mostra o aviso).
 */
trait AuthorizesSurgery
{
    protected function surgeryEntity(): Entity
    {
        return Entity::query()->findOrFail((string) session('selected_entity_id'));
    }

    /** @return array{manage: bool, perform: bool, indicators: bool} */
    protected function surgeryAbilities(): array
    {
        $entity = $this->surgeryEntity();

        return [
            'manage'     => Gate::allows(EntityGate::ManageSurgery->value, $entity),
            'perform'    => Gate::allows(EntityGate::PerformSurgery->value, $entity),
            'indicators' => Gate::allows(EntityGate::ViewSurgeryIndicators->value, $entity),
        ];
    }

    protected function authorizeManage(): void
    {
        Gate::authorize(EntityGate::ManageSurgery->value, $this->surgeryEntity());
    }

    protected function authorizePerform(): void
    {
        Gate::authorize(EntityGate::PerformSurgery->value, $this->surgeryEntity());
    }

    /**
     * CFM 2.227/2018 + LGPD art. 37: leitura de dado sensível do paciente (PDF, cópia assinada)
     * entra em data_access_logs com a finalidade do perfil (médico = atendimento; demais = administrativo).
     */
    protected function logSurgeryRead(Model $resource, string $patientId): void
    {
        app(DataAccessLogService::class)->log(
            $resource,
            CurrentDoctor::isDoctorProfile() ? DataAccessPurpose::PatientCare : DataAccessPurpose::Administrative,
            patientId: $patientId,
            entityId: (string) session('selected_entity_id'),
        );
    }

    /** O médico logado participa deste caso (cirurgião ou equipe)? Outros perfis: sim. */
    protected function canAccessCase(SurgicalCase $case): bool
    {
        if (! CurrentDoctor::isDoctorProfile()) {
            return true;
        }

        $own = CurrentDoctor::doctor();

        if ($own === null) {
            return false;
        }

        if ((string) $case->surgeon_doctor_id === (string) $own->id) {
            return true;
        }

        return SurgicalTeamMember::query()
            ->where('surgical_case_id', $case->getKey())
            ->where('doctor_id', $own->id)
            ->exists();
    }

    /**
     * Mexer no caso (trocar cirurgião/equipe, cancelar, suspender, excluir) é do cirurgião do caso
     * ou da gestão (admin/secretária) — médico só da equipe (ex.: anestesista) não tira o caso do cirurgião.
     */
    protected function authorizeCaseOwner(SurgicalCase $case): void
    {
        $this->authorizeCaseAccess($case);

        if (CurrentDoctor::isDoctorProfile()) {
            abort_unless((string) CurrentDoctor::doctor()?->id === (string) $case->surgeon_doctor_id, 403);
        }
    }

    protected function authorizeCaseAccess(SurgicalCase $case): void
    {
        abort_unless((string) $case->entity_id === (string) session('selected_entity_id') && $this->canAccessCase($case), 403);
    }
}
