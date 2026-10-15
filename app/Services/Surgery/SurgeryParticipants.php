<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\Surgery\SurgeryTeamRole;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Doctor;
use App\Models\Surgery\{SurgicalCase, SurgicalTeamMember};

/**
 * Quem participa do caso. Ato médico da cirurgia (registrar como realizada,
 * descrever, finalizar, adendar) é do CIRURGIÃO do caso ou de um cirurgião da
 * equipe; registrar/anular implante cabe a qualquer médico da equipe. Outro
 * médico da clínica não age em caso alheio (403).
 */
final class SurgeryParticipants
{
    public function isSurgeon(SurgicalCase $case, Doctor $doctor): bool
    {
        if ((string) $case->surgeon_doctor_id === (string) $doctor->getKey()) {
            return true;
        }

        return SurgicalTeamMember::query()
            ->where('surgical_case_id', $case->getKey())
            ->where('doctor_id', $doctor->getKey())
            ->where('role', SurgeryTeamRole::Surgeon->value)
            ->exists();
    }

    public function isMember(SurgicalCase $case, Doctor $doctor): bool
    {
        if ($this->isSurgeon($case, $doctor)) {
            return true;
        }

        return SurgicalTeamMember::query()
            ->where('surgical_case_id', $case->getKey())
            ->where('doctor_id', $doctor->getKey())
            ->exists();
    }

    public function assertSurgeon(SurgicalCase $case, Doctor $doctor): void
    {
        if (! $this->isSurgeon($case, $doctor)) {
            throw new SurgeryRuleException((string) __('surgery_operation.errors.not_the_surgeon'), 403);
        }
    }

    public function assertMember(SurgicalCase $case, Doctor $doctor): void
    {
        if (! $this->isMember($case, $doctor)) {
            throw new SurgeryRuleException((string) __('surgery_operation.errors.not_in_the_team'), 403);
        }
    }
}
