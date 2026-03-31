<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\{ConsentType, LegalBasis};
use App\Models\{EntityUser, Patient, PatientConsent};
use Illuminate\Database\Eloquent\Collection;

class ConsentService
{
    /**
     * Registra o consentimento de um paciente.
     * LGPD Art. 8 — o consentimento deve ser livre, informado e inequívoco.
     *
     * @param  Patient     $patient        Paciente titular
     * @param  ConsentType $type           Tipo de consentimento
     * @param  EntityUser  $collectedBy    Profissional que coletou o consentimento
     * @param  string      $channel        Canal: in_person | digital | phone
     * @param  string|null $documentVersion Versão da política de privacidade vigente
     */
    public function grant(
        Patient $patient,
        ConsentType $type,
        EntityUser $collectedBy,
        string $channel = 'in_person',
        ?string $documentVersion = null,
    ): PatientConsent {
        // Revoga consentimentos anteriores do mesmo tipo antes de criar um novo
        $this->revokeExisting($patient, $type, $collectedBy, 'Substituído por novo consentimento');

        return PatientConsent::create([
            'entity_id'        => $patient->entity_id,
            'patient_id'       => $patient->id,
            'consent_type'     => $type,
            'legal_basis'      => $this->resolveLegalBasis($type),
            'status'           => 'granted',
            'document_version' => $documentVersion,
            'channel'          => $channel,
            'granted_by'       => $collectedBy->id,
            'granted_at'       => now(),
            'ip_address'       => request()->ip(),
            'user_agent'       => request()->userAgent(),
        ]);
    }

    /**
     * Revoga um consentimento específico de um paciente.
     * LGPD Art. 18, IX — o titular pode revogar consentimento a qualquer momento.
     */
    public function revoke(
        Patient $patient,
        ConsentType $type,
        EntityUser $revokedBy,
        string $reason,
    ): void {
        PatientConsent::query()
            ->where('patient_id', $patient->id)
            ->where('consent_type', $type->value)
            ->where('status', 'granted')
            ->get()
            ->each(function (PatientConsent $consent) use ($revokedBy, $reason): void {
                $consent->update([
                    'status'            => 'revoked',
                    'revoked_by'        => $revokedBy->id,
                    'revoked_at'        => now(),
                    'revocation_reason' => $reason,
                ]);
            });
    }

    /**
     * Verifica se um paciente tem consentimento ativo para um tipo específico.
     */
    public function hasActiveConsent(Patient $patient, ConsentType $type): bool
    {
        return PatientConsent::query()
            ->where('patient_id', $patient->id)
            ->where('consent_type', $type->value)
            ->where('status', 'granted')
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * Retorna todos os consentimentos ativos de um paciente.
     *
     * @return Collection<PatientConsent>
     */
    public function activeConsents(Patient $patient): Collection
    {
        return PatientConsent::query()
            ->where('patient_id', $patient->id)
            ->where('status', 'granted')
            ->whereNull('revoked_at')
            ->get();
    }

    /**
     * Retorna os tipos de consentimento obrigatórios ainda não coletados.
     *
     * @return ConsentType[]
     */
    public function missingRequiredConsents(Patient $patient): array
    {
        $required = array_filter(
            ConsentType::cases(),
            fn (ConsentType $type) => $type->isRequired()
        );

        return array_values(array_filter(
            $required,
            fn (ConsentType $type) => ! $this->hasActiveConsent($patient, $type)
        ));
    }

    /**
     * Registra todos os consentimentos obrigatórios de uma vez.
     * Usado no cadastro inicial do paciente.
     *
     * @return PatientConsent[]
     */
    public function grantRequired(
        Patient $patient,
        EntityUser $collectedBy,
        string $channel = 'in_person',
        ?string $documentVersion = null,
    ): array {
        return array_map(
            fn (ConsentType $type) => $this->grant($patient, $type, $collectedBy, $channel, $documentVersion),
            array_filter(ConsentType::cases(), fn ($t) => $t->isRequired())
        );
    }

    private function revokeExisting(Patient $patient, ConsentType $type, EntityUser $revokedBy, string $reason): void
    {
        PatientConsent::query()
            ->where('patient_id', $patient->id)
            ->where('consent_type', $type->value)
            ->where('status', 'granted')
            ->get()
            ->each(fn (PatientConsent $c) => $c->update([
                'status'            => 'revoked',
                'revoked_by'        => $revokedBy->id,
                'revoked_at'        => now(),
                'revocation_reason' => $reason,
            ]));
    }

    private function resolveLegalBasis(ConsentType $type): LegalBasis
    {
        return match ($type) {
            ConsentType::DataCollection, ConsentType::ImageAndVoice, ConsentType::ResearchParticipation
                => LegalBasis::Consent,
            ConsentType::SensitiveData, ConsentType::MinorGuardian
                => LegalBasis::HealthProtection,
            ConsentType::DataSharing
                => LegalBasis::ContractExecution,
        };
    }
}
