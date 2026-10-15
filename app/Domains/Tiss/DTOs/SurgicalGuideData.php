<?php

declare(strict_types=1);

namespace App\Domains\Tiss\DTOs;

use App\Domains\Tiss\Models\{TissGuide, TissGuideItem};
use App\Domains\Tiss\Surgical\SurgicalGuideSnapshot;

/**
 * Tudo que o builder das mensagens avulsas (solicitação de internação e anexo de
 * OPME) precisa, já resolvido: o retrato gravado na guia (`payload.surgical`),
 * os itens e os dados da clínica/operadora. O builder NUNCA relê o caso
 * cirúrgico — o que sai no XML é o que foi congelado na guia.
 */
final readonly class SurgicalGuideData
{
    /**
     * @param list<SurgicalGuideItemData>                                                                                     $items
     * @param list<string>                                                                                                    $diagnoses    CID sem ponto (st_texto4)
     * @param array{name: ?string, council_code: string, council_number: ?string, uf: ?string, cbos: ?string, phone: ?string} $professional
     * @param array{name: ?string, operator_code: ?string, cnes: ?string}                                                     $hospital
     */
    public function __construct(
        public string $guideNumber,
        public string $requestDate,
        public string $operatorAnsCode,
        public string $layoutVersion,
        public string $providerCode,
        public string $providerName,
        public ?string $providerCnes,
        public ?string $beneficiaryCard,
        public string $urgencyCode,
        public string $inpatientTypeCode,
        public string $regimeCode,
        public int $daysRequested,
        public bool $opme,
        public ?string $clinicalIndication,
        public array $diagnoses,
        public ?string $suggestedDate,
        public array $hospital,
        public array $professional,
        public array $items,
        public ?string $justification,
        public ?string $specification,
        public ?string $referencedGuideNumber,
        public ?string $observation,
        public ?string $operatorGuideNumber,
        public ?string $password,
        public ?string $authorizationDate,
    ) {
    }

    public static function fromGuide(TissGuide $guide): self
    {
        $guide->loadMissing(['items', 'operator', 'version', 'entity']);

        $snapshot = SurgicalGuideSnapshot::of($guide);

        return new self(
            guideNumber: (string) $guide->guide_number_provider,
            requestDate: $guide->attendance_date?->format('Y-m-d') ?? now()->toDateString(),
            operatorAnsCode: (string) ($guide->operator?->ans_code ?? ''),
            layoutVersion: (string) ($guide->version?->layout_version ?? ''),
            providerCode: (string) ($guide->entity?->code ?? ''),
            providerName: (string) ($guide->entity?->name ?? ''),
            providerCnes: $guide->entity?->cnes,
            beneficiaryCard: $guide->beneficiary_card_number,
            urgencyCode: $snapshot->string('urgency_code', '1'),
            inpatientTypeCode: $snapshot->string('inpatient_type_code', '2'),
            regimeCode: $snapshot->string('regime_code', '2'),
            daysRequested: (int) $snapshot->get('days_requested', 1),
            opme: (bool) $snapshot->get('opme', false),
            clinicalIndication: $snapshot->nullableString('clinical_indication'),
            diagnoses: array_values(array_filter(array_map(
                static fn ($cid) => filled($cid) ? (string) $cid : null,
                (array) $snapshot->get('diagnoses', []),
            ))),
            suggestedDate: $snapshot->nullableString('suggested_date'),
            hospital: [
                'name'          => $snapshot->nullableString('hospital.name'),
                'operator_code' => $snapshot->nullableString('hospital.operator_code'),
                'cnes'          => $snapshot->nullableString('hospital.cnes'),
            ],
            professional: [
                'name'           => $snapshot->nullableString('professional.name'),
                'council_code'   => $snapshot->string('professional.council_code', '06'),
                'council_number' => $snapshot->nullableString('professional.council_number'),
                'uf'             => $snapshot->nullableString('professional.uf'),
                'cbos'           => $snapshot->nullableString('professional.cbos'),
                'phone'          => $snapshot->nullableString('professional.phone'),
            ],
            items: $guide->items
                ->sortBy('id')
                ->values()
                ->map(static fn (TissGuideItem $item) => SurgicalGuideItemData::fromModel($item))
                ->all(),
            justification: $snapshot->nullableString('justification'),
            specification: $snapshot->nullableString('specification'),
            referencedGuideNumber: $snapshot->nullableString('referenced_guide_number'),
            observation: $snapshot->nullableString('observation'),
            operatorGuideNumber: $snapshot->nullableString('authorization.operator_guide_number'),
            password: $snapshot->nullableString('authorization.password'),
            authorizationDate: $snapshot->nullableString('authorization.date'),
        );
    }
}
