<?php

declare(strict_types=1);

namespace App\Domains\Tiss\DTOs;

use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\{TissGuide, TissGuideItem};

final readonly class TissGuideData
{
    /**
     * @param TissGuideItemData[] $items
     */
    public function __construct(
        public string $guideNumber,
        public TissGuideType $guideType,
        public string $attendanceDate,
        public ?string $beneficiaryName,
        public ?string $beneficiaryCard,
        public ?string $authorizationNumber,
        public float $totalAmount,
        public array $items,
        // Campos obrigatórios TISS 4.x
        public string $attendanceType = '01',     // tipoAtendimento: 01=Consulta, 06=SP-SADT
        public string $accidentIndicator = '9',   // indicacaoAcidente: 9=Não acidente
        public ?string $clinicalIndication = null, // indicacaoClinica
        public ?string $doctorName = null,         // dadosSolicitante/nomeSolicitante
        public ?string $doctorCbo = null,          // dadosSolicitante/codigoCBO
    ) {
    }

    public static function fromModel(TissGuide $guide): self
    {
        $guide->loadMissing(['items', 'doctor']);

        $doctorName = null;
        $doctorCbo  = null;

        if ($guide->doctor) {
            $doctorName = (string) ($guide->doctor->name ?? '');
            $doctorCbo  = property_exists($guide->doctor, 'cbo_code')
                ? (string) ($guide->doctor->cbo_code ?? '')
                : null;
        }

        $attendanceType = match ($guide->guide_type) {
            TissGuideType::Consultation => '01',
            TissGuideType::Sadt         => '06',
            default                     => '01',
        };

        return new self(
            guideNumber: (string) $guide->guide_number_provider,
            guideType: $guide->guide_type,
            attendanceDate: $guide->attendance_date?->format('Y-m-d') ?? now()->toDateString(),
            beneficiaryName: $guide->beneficiary_name,
            beneficiaryCard: $guide->beneficiary_card_number,
            authorizationNumber: $guide->authorization_number,
            totalAmount: (float) $guide->total_amount,
            items: $guide->items->map(static fn (TissGuideItem $item) => TissGuideItemData::fromModel($item))->all(),
            attendanceType: $attendanceType,
            accidentIndicator: '9',
            clinicalIndication: $guide->clinical_indication,
            doctorName: $doctorName ?: null,
            doctorCbo: $doctorCbo ?: null,
        );
    }
}
