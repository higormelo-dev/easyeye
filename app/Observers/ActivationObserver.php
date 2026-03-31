<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ActivationStep;
use App\Models\{Doctor, Entity, EntityIntegrator, EntityUser, MedicalRecord, Patient, Schedule};
use App\Services\ActivationService;
use Illuminate\Support\Facades\Log;

/**
 * Rastreia automaticamente os marcos de ativação de cada clínica.
 * Registrado em AppServiceProvider para: Doctor, Patient, Schedule,
 * MedicalRecord, EntityUser, EntityIntegrator, Entity.
 *
 * Cada observer method é silencioso: erros não propagam para a operação principal.
 */
class ActivationObserver
{
    public function __construct(
        private readonly ActivationService $activationService,
    ) {}

    // -------------------------------------------------------------------------
    // Doctor → FirstDoctorAdded
    // -------------------------------------------------------------------------

    public function created(Doctor $doctor): void
    {
        $this->fire(fn () => $doctor->entityUser?->entity_id, ActivationStep::FirstDoctorAdded);
    }

    // -------------------------------------------------------------------------
    // Patient → FirstPatientAdded
    // -------------------------------------------------------------------------

    public function patientCreated(Patient $patient): void
    {
        $this->fire(fn () => $patient->entity_id, ActivationStep::FirstPatientAdded);
    }

    // -------------------------------------------------------------------------
    // Schedule → FirstScheduleCreated
    // -------------------------------------------------------------------------

    public function scheduleCreated(Schedule $schedule): void
    {
        $this->fire(fn () => $schedule->entity_id, ActivationStep::FirstScheduleCreated);
    }

    // -------------------------------------------------------------------------
    // MedicalRecord → FirstMedicalRecord
    // -------------------------------------------------------------------------

    public function medicalRecordCreated(MedicalRecord $record): void
    {
        $this->fire(
            fn () => $record->schedule?->entity_id ?? session('selected_entity_id'),
            ActivationStep::FirstMedicalRecord,
        );
    }

    // -------------------------------------------------------------------------
    // EntityUser → TeamMemberInvited (somente não-owners)
    // -------------------------------------------------------------------------

    public function entityUserCreated(EntityUser $entityUser): void
    {
        if ($entityUser->is_owner) {
            return;
        }

        $this->fire(fn () => $entityUser->entity_id, ActivationStep::TeamMemberInvited);
    }

    // -------------------------------------------------------------------------
    // EntityIntegrator → IntegratorConnected
    // -------------------------------------------------------------------------

    public function entityIntegratorCreated(EntityIntegrator $integrator): void
    {
        $this->fire(fn () => $integrator->entity_id, ActivationStep::IntegratorConnected);
    }

    // -------------------------------------------------------------------------
    // Entity (updated) → EntityProfileCompleted
    // -------------------------------------------------------------------------

    public function entityUpdated(Entity $entity): void
    {
        if (! $entity->is_client) {
            return;
        }

        // Considera o perfil completo quando endereço e contato estão preenchidos
        if ($entity->zipcode && $entity->address && ($entity->telephone || $entity->cellphone)) {
            $this->fire(fn () => $entity->id, ActivationStep::EntityProfileCompleted);
        }
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Executa o registro de ativação capturando qualquer exceção.
     *
     * @param  callable(): ?string  $entityIdResolver  Retorna o entity_id ou null
     */
    private function fire(callable $entityIdResolver, ActivationStep $step): void
    {
        try {
            $entityId = $entityIdResolver();

            if (blank($entityId)) {
                return;
            }

            $this->activationService->complete($entityId, $step);
        } catch (\Throwable $e) {
            Log::warning('ActivationObserver: falha ao registrar etapa.', [
                'step'  => $step->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
