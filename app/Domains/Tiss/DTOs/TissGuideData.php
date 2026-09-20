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
        // Campos obrigatórios TISS 4.03 sem fonte de dado no projeto hoje —
        // vêm de config/tiss.php (defaults), não de dado real por guia. Ver
        // Fase B do plano de conformidade: exigiria captura por guia
        // (regime, tipo de consulta, caráter do atendimento) que não existe.
        public string $regimeAtendimento = '01',       // dm_regimeAtendimento: 01=Ambulatorial (correto pra esta clínica)
        public string $tipoConsulta = '1',             // dm_tipoConsulta: 1=Primeira — sem distinção 1ª/retorno hoje
        public string $tipoAtendimentoSadt = '04',      // dm_tipoAtendimento (só SP-SADT): "05-Exames" foi inativado sem substituto claro no schema
        public string $caraterAtendimentoSadt = '1',    // dm_caraterAtendimento (só SP-SADT): 1=Eletiva
        public string $atendimentoRN = 'N',             // dm_simNao: N=não é atendimento de recém-nascido
        public string $accidentIndicator = '9',   // indicacaoAcidente: 9=Não acidente
        public ?string $clinicalIndication = null, // indicacaoClinica
        public ?string $doctorName = null,          // profissionalExecutante/Solicitante: nomeProfissional
        public ?string $doctorCbo = null,           // CBOS — Fase B (doctors.cbo_code)
        public ?string $doctorCouncilNumber = null, // numeroConselhoProfissional — Fase B (doctors.record, já existia como "CRM")
        public ?string $doctorCpf = null,           // profissionalExecutante/Solicitante não pede CPF do médico contratado
        // (esse CPF é de ct_contratadoDados, papel de PESSOA JURÍDICA/física
        // contratada — não do profissional; mantido pra uso futuro, não
        // emitido no builder hoje).
    ) {
    }

    public static function fromModel(TissGuide $guide): self
    {
        $guide->loadMissing(['items', 'doctor.person']);

        $doctorName          = null;
        $doctorCbo           = null;
        $doctorCouncilNumber = null;
        $doctorCpf           = null;

        if ($guide->doctor) {
            // Doctor não tem coluna própria "name" — o nome mora em
            // Person (person_id). Ler $guide->doctor->name direto sempre
            // voltava null em produção (só "funcionava" em teste unitário
            // com new Doctor(['name' => ...]), que aceita atributo solto
            // sem refletir o schema real).
            $doctorName          = (string) ($guide->doctor->person?->full_name ?? '');
            $doctorCbo           = (string) ($guide->doctor->cbo_code ?? '');
            $doctorCouncilNumber = (string) ($guide->doctor->record ?? '');
            $doctorCpf           = (string) ($guide->doctor->person?->national_registry ?? '');
        }

        return new self(
            guideNumber: (string) $guide->guide_number_provider,
            guideType: $guide->guide_type,
            attendanceDate: $guide->attendance_date?->format('Y-m-d') ?? now()->toDateString(),
            beneficiaryName: $guide->beneficiary_name,
            beneficiaryCard: $guide->beneficiary_card_number,
            authorizationNumber: $guide->authorization_number,
            totalAmount: (float) $guide->total_amount,
            items: $guide->items->map(static fn (TissGuideItem $item) => TissGuideItemData::fromModel($item))->all(),
            regimeAtendimento: (string) config('tiss.defaults.regime_atendimento', '01'),
            tipoConsulta: (string) config('tiss.defaults.tipo_consulta', '1'),
            tipoAtendimentoSadt: (string) config('tiss.defaults.tipo_atendimento_sadt', '04'),
            caraterAtendimentoSadt: (string) config('tiss.defaults.carater_atendimento_sadt', '1'),
            accidentIndicator: '9',
            clinicalIndication: $guide->clinical_indication,
            doctorName: $doctorName ?: null,
            doctorCbo: $doctorCbo ?: null,
            doctorCouncilNumber: $doctorCouncilNumber ?: null,
            doctorCpf: $doctorCpf ?: null,
        );
    }
}
