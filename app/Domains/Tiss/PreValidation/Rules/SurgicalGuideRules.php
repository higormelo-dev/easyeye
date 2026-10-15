<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation\Rules;

use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\PreValidation\Contracts\TissGuideValidationRule;
use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use App\Domains\Tiss\PreValidation\TissGuideValidationIssue;
use App\Domains\Tiss\Surgical\SurgicalGuideSnapshot;
use DateTimeImmutable;

/**
 * Pré-validação das mensagens avulsas do módulo cirúrgico (solicitação de internação e
 * anexo de OPME): o que a operadora vai recusar ou glosar se faltar, e o que o XSD
 * 4.03.00 torna obrigatório. Lê o RETRATO da guia (payload.surgical), nunca o caso vivo.
 *
 * Códigos e textos em lang/*\/tiss_prevalidation.php (mesma convenção das demais regras).
 */
final class SurgicalGuideRules implements TissGuideValidationRule
{
    private const CID_PATTERN = '/^[A-Z]\d{2,3}$/';

    public function validate(TissGuide $guide): array
    {
        $snapshot = SurgicalGuideSnapshot::of($guide);
        $issues   = [];

        $this->common($guide, $snapshot, $issues);

        match ($guide->guide_type) {
            TissGuideType::InpatientRequest => $this->inpatient($guide, $snapshot, $issues),
            TissGuideType::OpmeAttachment   => $this->opme($guide, $snapshot, $issues),
            default                         => null,
        };

        return $issues;
    }

    /** @param list<TissGuideValidationIssue> $issues */
    private function common(TissGuide $guide, SurgicalGuideSnapshot $snapshot, array &$issues): void
    {
        $guide->loadMissing(['operator', 'entity', 'items']);

        if (blank($guide->beneficiary_card_number)) {
            $issues[] = $this->issue('BENEFICIARY_CARD_REQUIRED', 'beneficiary_card_number', $guide);
        }

        if (blank($guide->doctor_id)) {
            $issues[] = $this->issue('DOCTOR_REQUIRED', 'doctor_id', $guide);
        }

        if (preg_match('/^\d{6}$/', preg_replace('/\D+/', '', (string) $guide->operator?->ans_code) ?? '') !== 1) {
            $issues[] = $this->issue('OPERATOR_ANS_INVALID', 'operator', $guide);
        }

        $providerCode = (string) $guide->entity?->code;

        if ($providerCode === '' || mb_strlen($providerCode) > 14) {
            $issues[] = $this->issue('PROVIDER_CODE_INVALID', 'provider_code', $guide);
        }

        // Conselho, número, UF e CBOS do profissional são obrigatórios JUNTOS no XSD.
        if (blank($snapshot->nullableString('professional.council_number'))) {
            $issues[] = $this->issue('PROFESSIONAL_COUNCIL_REQUIRED', 'professional.council_number', $guide);
        }

        if (blank($snapshot->nullableString('professional.uf'))) {
            $issues[] = $this->issue('PROFESSIONAL_UF_REQUIRED', 'professional.uf', $guide);
        }

        if ($guide->items->isEmpty()) {
            $issues[] = $this->issue(
                'ITEMS_REQUIRED',
                'items',
                $guide,
                ['type' => __('tiss_prevalidation.guide_type.' . $guide->guide_type->value)],
            );
        }
    }

    /** @param list<TissGuideValidationIssue> $issues */
    private function inpatient(TissGuide $guide, SurgicalGuideSnapshot $snapshot, array &$issues): void
    {
        if (blank($snapshot->nullableString('hospital.operator_code')) || mb_strlen((string) $snapshot->get('hospital.operator_code')) > 14) {
            $issues[] = $this->issue('HOSPITAL_CODE_REQUIRED', 'hospital.operator_code', $guide);
        }

        if (blank($snapshot->nullableString('hospital.name'))) {
            $issues[] = $this->issue('HOSPITAL_NAME_REQUIRED', 'hospital.name', $guide);
        }

        $cnes = $snapshot->nullableString('hospital.cnes');

        if ($cnes === null || preg_match('/^\d{7}$/', $cnes) !== 1) {
            $issues[] = $this->issue('HOSPITAL_CNES_RECOMMENDED', 'hospital.cnes', $guide, severity: TissValidationSeverity::Warning);
        }

        $date = $snapshot->nullableString('suggested_date');

        if ($date === null || ! $this->isDate($date)) {
            $issues[] = $this->issue('SUGGESTED_DATE_REQUIRED', 'suggested_date', $guide);
        }

        $diagnoses = array_values(array_filter((array) $snapshot->get('diagnoses', []), fn ($d) => filled($d)));

        if ($diagnoses === []) {
            $issues[] = $this->issue('CID_REQUIRED', 'diagnoses', $guide);
        } elseif (count($diagnoses) > 4) {
            $issues[] = $this->issue('CID_TOO_MANY', 'diagnoses', $guide);
        }

        foreach ($diagnoses as $cid) {
            if (preg_match(self::CID_PATTERN, strtoupper((string) $cid)) !== 1) {
                $issues[] = $this->issue('CID_FORMAT_INVALID', 'diagnoses', $guide, ['cid' => (string) $cid]);
            }
        }

        $indication = (string) $snapshot->nullableString('clinical_indication');

        if ($indication === '') {
            $issues[] = $this->issue('CLINICAL_INDICATION_REQUIRED', 'clinical_indication', $guide);
        } elseif (mb_strlen($indication) > 500) {
            $issues[] = $this->issue('CLINICAL_INDICATION_TRUNCATED', 'clinical_indication', $guide, severity: TissValidationSeverity::Warning);
        }

        $days = (int) $snapshot->get('days_requested', 0);

        if ($days < 1 || $days > 99) {
            $issues[] = $this->issue('DAYS_REQUESTED_INVALID', 'days_requested', $guide);
        }
    }

    /** @param list<TissGuideValidationIssue> $issues */
    private function opme(TissGuide $guide, SurgicalGuideSnapshot $snapshot, array &$issues): void
    {
        if (blank($snapshot->nullableString('referenced_guide_number'))) {
            $issues[] = $this->issue('OPME_REFERENCED_GUIDE_REQUIRED', 'referenced_guide_number', $guide);
        }

        if (blank($snapshot->nullableString('professional.phone'))) {
            $issues[] = $this->issue('OPME_PROFESSIONAL_PHONE_REQUIRED', 'professional.phone', $guide);
        }

        $justification = (string) $snapshot->nullableString('justification');

        if ($justification === '') {
            $issues[] = $this->issue('OPME_JUSTIFICATION_REQUIRED', 'justification', $guide);
        } elseif (mb_strlen($justification) > 1000) {
            $issues[] = $this->issue('OPME_JUSTIFICATION_TRUNCATED', 'justification', $guide, severity: TissValidationSeverity::Warning);
        }

        foreach ($guide->items as $item) {
            $meta  = (array) ($item->metadata ?? []);
            $field = "items.{$item->id}";

            if (! in_array((string) ($meta['manufacturer_option'] ?? ''), ['1', '2', '3'], true)) {
                $issues[] = $this->issue('OPME_MANUFACTURER_OPTION_INVALID', "{$field}.manufacturer_option", $guide, ['item' => $item->description]);
            }

            if (blank($meta['anvisa_registration'] ?? null)) {
                $issues[] = $this->issue('OPME_ANVISA_REQUIRED', "{$field}.anvisa_registration", $guide, ['item' => $item->description]);
            }

            if ((float) $item->quantity < 1 || (float) $item->quantity > 999) {
                $issues[] = $this->issue('OPME_QUANTITY_INVALID', "{$field}.quantity", $guide, ['item' => $item->description]);
            }
        }
    }

    /**
     * @param array<string, scalar|null> $replace
     */
    private function issue(
        string $code,
        string $field,
        TissGuide $guide,
        array $replace = [],
        TissValidationSeverity $severity = TissValidationSeverity::Error,
    ): TissGuideValidationIssue {
        $operator = $guide->relationLoaded('operator')
            ? ($guide->operator?->trade_name ?? $guide->operator?->name ?? __('tiss_prevalidation.operator_fallback'))
            : __('tiss_prevalidation.operator_fallback');

        $hintKey = "tiss_prevalidation.{$code}.operator_hint";

        return new TissGuideValidationIssue(
            severity: $severity,
            code: $code,
            field: $field,
            message: __("tiss_prevalidation.{$code}.message", $replace),
            suggestion: __("tiss_prevalidation.{$code}.suggestion", $replace),
            operatorHint: __($hintKey) === $hintKey ? null : __($hintKey, $replace + ['operator' => $operator]),
        );
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
