<?php

declare(strict_types=1);

namespace App\Services\Surgery\Guards;

use App\Enums\Surgery\SurgeryDocumentType;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Doctor;
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalCase};
use App\Services\Surgery\Documents\ConsentBasis;

/**
 * Cirurgia só é registrada como REALIZADA com o TCLE emitido e a cópia assinada
 * pelo paciente anexada. Exceção do cirurgião (urgência, trauma, paciente sem
 * condição de assinar): `consent_override_reason` com justificativa (mínimo 10
 * caracteres), que a etapa de cirurgia grava junto do registro.
 *
 * Vale o TCLE VIGENTE: se foi reemitido, a cópia assinada precisa ser da
 * emissão atual (a anterior foi substituída). E ele precisa AINDA cobrir o pedido:
 * assinado para o olho direito e depois trocado para o esquerdo (ou outro procedimento,
 * cirurgião ou anestesia), o termo está "desatualizado" — reemitir e colher nova assinatura
 * (ConsentBasis); sem isso, só com a justificativa.
 *
 * Registro: config('surgery.perform_guards') — a decisão de ligar é do
 * produto (clínica sem o hábito de digitalizar o termo trava no primeiro dia).
 */
final class ConsentSignedGuard implements SurgeryPerformGuard
{
    public const MIN_OVERRIDE_LENGTH = 10;

    public function assert(SurgicalCase $locked, array $input, Doctor $performer): void
    {
        $consent = self::currentConsent($locked);
        $state   = self::stateOf($consent, $locked);

        if ($state === 'signed') {
            return;
        }

        if (mb_strlen(trim((string) ($input['consent_override_reason'] ?? ''))) >= self::MIN_OVERRIDE_LENGTH) {
            return;
        }

        if ($state === 'outdated' && $consent !== null) {
            throw SurgeryRuleException::state('surgery_documents.errors.consent_outdated', [
                'fields' => ConsentBasis::labels(ConsentBasis::divergence($consent, $locked)),
            ]);
        }

        throw SurgeryRuleException::state('surgery_documents.errors.consent_' . $state);
    }

    /** O TCLE vigente do caso (a emissão não substituída mais recente). */
    public static function currentConsent(SurgicalCase $case): ?SurgeryDocumentIssue
    {
        return SurgeryDocumentIssue::query()
            ->where('surgical_case_id', $case->getKey())
            ->where('type', SurgeryDocumentType::Consent->value)
            ->whereNull('superseded_by_id')
            ->orderByDesc('issued_at')
            ->first();
    }

    /** Estado do termo para a tela: signed | unsigned | outdated (não cobre mais o pedido) | missing. */
    public static function state(SurgicalCase $case): string
    {
        return self::stateOf(self::currentConsent($case), $case);
    }

    private static function stateOf(?SurgeryDocumentIssue $consent, SurgicalCase $case): string
    {
        if ($consent === null) {
            return 'missing';
        }

        if (ConsentBasis::divergence($consent, $case) !== []) {
            return 'outdated';
        }

        return $consent->hasSignedCopy() ? 'signed' : 'unsigned';
    }
}
