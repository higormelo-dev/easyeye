<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

use App\Domains\ClinicalReview\Enums\{AcceptanceDecision, ReviewDecision};
use App\Domains\ClinicalReview\Models\{ClinicalReviewAcceptance, ClinicalReviewAttestation, ClinicalReviewItem};
use App\Domains\Recall\Exceptions\ProtocolGovernanceException;
use App\Domains\Recall\Models\RecallProtocolVersion;
use Carbon\CarbonImmutable;

/**
 * A PROVA de que uma versão de protocolo foi validada (invariante I1 do ADR 0002): aceite `accepted` (alguém da
 * EasyEye conferiu CRM/UF e RQE do Revisor no CFM) + decisão `approved` do Revisor sobre ESTA versão + o MESMO
 * hash que ele viu + conteúdo íntegro (o hash gravado bate com o texto gravado).
 *
 * Fonte única: quem LIBERA a versão (governança) e quem a ADOTA numa clínica (ativação) reconferem a prova com
 * este código — a regra não pode divergir entre os dois. `assert()` recusa; `summary()` descreve a prova para a
 * tela da clínica (quem validou, quando, qual versão), sem nunca mostrar o que o Revisor não autorizou.
 */
final class ValidationEvidence
{
    /**
     * @throws ProtocolGovernanceException
     */
    public static function assert(RecallProtocolVersion $version): void
    {
        $acceptance = self::acceptance($version);

        if ($acceptance === null || $acceptance->decision !== AcceptanceDecision::Accepted) {
            throw ProtocolGovernanceException::noValidationEvidence('falta o aceite da conferência do CRM/RQE no CFM');
        }

        $item = self::item($acceptance, $version);

        if ($item === null || $item->decision !== ReviewDecision::Approved) {
            throw ProtocolGovernanceException::noValidationEvidence('o Revisor não aprovou esta versão');
        }

        if ($item->content_hash !== $version->content_hash) {
            throw ProtocolGovernanceException::noValidationEvidence('o conteúdo aprovado não é o desta versão');
        }

        if (! $version->integrityOk()) {
            throw ProtocolGovernanceException::integrityFailed((string) $version->id);
        }
    }

    /** A prova está completa? (a mesma conferência de assert(), sem exceção) */
    public static function holds(RecallProtocolVersion $version): bool
    {
        try {
            self::assert($version);
        } catch (ProtocolGovernanceException) {
            return false;
        }

        return true;
    }

    /**
     * O que a clínica vê da validação: versão, hash, datas e — só se o Revisor AUTORIZOU no atestado — nome e
     * registro. Sem autorização, `reviewer` é nulo e a tela diz "Revisor clínico externo".
     *
     * @return array{version: int, hash: string, hash_short: string, validated_at: ?string, accepted_at: ?string, released_at: ?string, review_due_at: ?string, review_overdue: bool, reviewer: ?array{name: string, crm: string, rqe: string, specialty: ?string}}|null
     */
    public static function summary(RecallProtocolVersion $version, ?CarbonImmutable $today = null): ?array
    {
        $acceptance = self::acceptance($version);

        if ($acceptance === null || $acceptance->decision !== AcceptanceDecision::Accepted) {
            return null;
        }

        $attestation = ClinicalReviewAttestation::query()->where('invitation_id', $acceptance->invitation_id)->first();
        $today       = ($today ?? CarbonImmutable::now())->startOfDay();
        $dueAt       = $version->review_due_at !== null ? CarbonImmutable::parse($version->review_due_at)->startOfDay() : null;

        return [
            'version'        => (int) $version->version,
            'hash'           => (string) $version->content_hash,
            'hash_short'     => $version->shortHash(),
            'validated_at'   => $version->validated_at !== null ? CarbonImmutable::parse($version->validated_at)->toDateString() : null,
            'accepted_at'    => $acceptance->checked_at?->toDateString(),
            'released_at'    => $version->released_at !== null ? CarbonImmutable::parse($version->released_at)->toDateString() : null,
            'review_due_at'  => $dueAt?->toDateString(),
            'review_overdue' => $dueAt !== null && $dueAt->lessThan($today),
            'reviewer'       => $attestation !== null && $attestation->allow_identity_display ? [
                'name'      => (string) $attestation->declared_name,
                'crm'       => 'CRM/' . $attestation->declared_crm_uf . ' ' . $attestation->declared_crm_number,
                'rqe'       => 'RQE ' . $attestation->declared_rqe,
                'specialty' => $attestation->declared_specialty,
            ] : null,
        ];
    }

    private static function acceptance(RecallProtocolVersion $version): ?ClinicalReviewAcceptance
    {
        return $version->validated_acceptance_id !== null ? ClinicalReviewAcceptance::query()->find($version->validated_acceptance_id) : null;
    }

    private static function item(ClinicalReviewAcceptance $acceptance, RecallProtocolVersion $version): ?ClinicalReviewItem
    {
        return ClinicalReviewItem::query()
            ->where('invitation_id', $acceptance->invitation_id)
            ->where('subject_id', $version->id)
            ->first();
    }
}
