<?php

namespace App\Enums;

/**
 * Marcos de ativação de uma clínica durante o trial.
 *
 * Quanto mais etapas concluídas → maior "sticky" no produto → maior conversão → menor CAC efetivo.
 * Cada etapa é marcada automaticamente por observers de model.
 */
enum ActivationStep: string
{
    case EntityProfileCompleted = 'entity_profile_completed'; // Dados da clínica preenchidos
    case FirstDoctorAdded       = 'first_doctor_added';       // Primeiro médico cadastrado
    case FirstPatientAdded      = 'first_patient_added';      // Primeiro paciente cadastrado
    case FirstScheduleCreated   = 'first_schedule_created';   // Primeira consulta agendada
    case FirstMedicalRecord     = 'first_medical_record';     // Primeiro prontuário criado
    case TeamMemberInvited      = 'team_member_invited';      // Membro da equipe convidado
    case IntegratorConnected    = 'integrator_connected';     // Equipamento conectado via API

    public function label(): string
    {
        return match ($this) {
            self::EntityProfileCompleted => 'Perfil da clínica preenchido',
            self::FirstDoctorAdded       => 'Primeiro médico cadastrado',
            self::FirstPatientAdded      => 'Primeiro paciente cadastrado',
            self::FirstScheduleCreated   => 'Primeira consulta agendada',
            self::FirstMedicalRecord     => 'Primeiro prontuário criado',
            self::TeamMemberInvited      => 'Membro da equipe convidado',
            self::IntegratorConnected    => 'Equipamento conectado',
        };
    }

    /** Peso da etapa no score total (soma = 100) */
    public function weight(): int
    {
        return match ($this) {
            self::EntityProfileCompleted => 10,
            self::FirstDoctorAdded       => 15,
            self::FirstPatientAdded      => 15,
            self::FirstScheduleCreated   => 20,
            self::FirstMedicalRecord     => 20,
            self::TeamMemberInvited      => 10,
            self::IntegratorConnected    => 10,
        };
    }

    /**
     * Retorna todas as etapas em ordem de importância para o onboarding.
     *
     * @return self[]
     */
    public static function ordered(): array
    {
        return [
            self::EntityProfileCompleted,
            self::FirstDoctorAdded,
            self::FirstPatientAdded,
            self::FirstScheduleCreated,
            self::FirstMedicalRecord,
            self::TeamMemberInvited,
            self::IntegratorConnected,
        ];
    }
}
