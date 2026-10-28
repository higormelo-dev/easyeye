<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\{ConsentType, LegalBasis};
use App\Models\{EntityUser, Patient, PatientConsent};
use App\Models\WhatsApp\WhatsAppSetting;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ConsentService
{
    /**
     * Registra o consentimento de um paciente.
     * LGPD Art. 8 — o consentimento deve ser livre, informado e inequívoco.
     *
     * @param Patient     $patient         Paciente titular
     * @param ConsentType $type            Tipo de consentimento
     * @param EntityUser  $collectedBy     Profissional que coletou o consentimento
     * @param string      $channel         Canal: in_person | digital | phone
     * @param string|null $documentVersion Versão da política de privacidade vigente
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
     * SAIR no WhatsApp tira a autorização de lembretes de retorno de quem tem aquele celular (ADR 0002, item 9).
     * O descadastro do número (`whatsapp_opt_outs`) já barra qualquer envio; aqui o consentimento acompanha, para
     * que VOLTAR — que só reativa o número — não ressuscite uma autorização que o titular retirou. Quem envia
     * pelo app PRÓPRIO da clínica afeta só os pacientes dela; o número global do EasyEye, só as clínicas que enviam
     * por ele (as sem app próprio — `WhatsAppSetting::sendingSetting()`): quem manda pelo próprio app não recebeu o
     * SAIR e o paciente não retirou a autorização dele. Sem usuário (`revoked_by` nulo): foi o próprio titular,
     * pelo canal.
     *
     * @param string $reasonKey chave de `consent.revoked.*`
     *
     * @return int quantos consentimentos foram revogados
     */
    public function revokeCareRemindersForPhone(WhatsAppSetting $sender, string $phone, string $reasonKey = 'whatsapp_opt_out'): int
    {
        $revoked = 0;
        $reason  = (string) __('consent.revoked.' . $reasonKey, [], 'pt_BR');

        DB::table('patient_consents as pc')
            ->join('patients as p', 'p.id', '=', 'pc.patient_id')
            ->join('people as pe', 'pe.id', '=', 'p.person_id')
            ->where('pc.consent_type', ConsentType::CareReminders->value)
            ->where('pc.status', 'granted')
            ->whereNull('pc.revoked_at')
            ->when(
                $sender->entity_id !== null,
                fn ($query) => $query->where('pc.entity_id', (string) $sender->entity_id),
                fn ($query) => $query->whereIn('pc.entity_id', static function ($clinics): void {
                    $clinics->select('ws.entity_id')->from('whatsapp_settings as ws')
                        ->whereNotNull('ws.entity_id')
                        ->whereRaw("COALESCE(TRIM(ws.app_id), '') = ''");
                }),
            )
            ->select('pc.id', 'pe.cellphone')
            ->chunkById(1000, function ($rows) use ($phone, $reason, &$revoked): void {
                $ids = $rows
                    ->filter(fn (object $row): bool => WhatsAppService::normalizePhone($row->cellphone) === $phone)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    return;
                }

                $revoked += DB::table('patient_consents')->whereIn('id', $ids)->update([
                    'status'            => 'revoked',
                    'revoked_at'        => now(),
                    'revocation_reason' => $reason,
                    'updated_at'        => now(),
                ]);
            }, 'pc.id', 'id');

        return $revoked;
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
            fn (ConsentType $type) => $type->isRequired(),
        );

        return array_values(array_filter(
            $required,
            fn (ConsentType $type) => ! $this->hasActiveConsent($patient, $type),
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
            array_filter(ConsentType::cases(), fn ($t) => $t->isRequired()),
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
            ConsentType::DataCollection, ConsentType::ImageAndVoice, ConsentType::ResearchParticipation, ConsentType::CareReminders => LegalBasis::Consent,
            ConsentType::SensitiveData, ConsentType::MinorGuardian => LegalBasis::HealthProtection,
            ConsentType::DataSharing => LegalBasis::ContractExecution,
        };
    }
}
