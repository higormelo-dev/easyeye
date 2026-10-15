<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Surgical;

use App\Domains\Tiss\Enums\{TissGuideStatus, TissGuideType};
use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\Support\IbgeUf;
use App\Enums\Surgery\{AuthorizationEventType, AuthorizationStatus, SurgeryRegime};
use App\Models\Entity;
use App\Models\Surgery\{SurgicalAuthorizationEvent, SurgicalCase, SurgicalCaseMaterial, SurgicalCaseProcedure};
use App\Support\Surgery\CanonicalHash;
use Illuminate\Support\Str;

/**
 * Monta, a partir do caso cirúrgico, o que vira o retrato da guia TISS (camada
 * `data` de `payload.surgical`) e os itens. Função pura sobre o caso carregado:
 * não grava nada, não trava nada.
 *
 * `source_hash` cobre o retrato + itens derivados do caso (nunca o que o usuário
 * completou à mão): é com ele que a tela sabe se o caso mudou depois de a guia
 * ser gerada.
 */
final class SurgicalGuideSource
{
    /** Dígitos máximos de telefone aceitos pelo XSD (st_texto11). */
    private const PHONE_DIGITS = 11;

    /**
     * @return array{data: array<string, mixed>, items: list<array<string, mixed>>, source_hash: string}
     */
    public function build(SurgicalCase $case, TissGuideType $type, ?TissGuide $inpatientGuide = null): array
    {
        $case->loadMissing(['patient.person', 'surgeon.person', 'covenant', 'facility', 'procedures', 'materials']);

        $entity  = Entity::query()->find($case->entity_id);
        $surgeon = $case->surgeon;
        $person  = $surgeon?->person;
        $items   = $type === TissGuideType::InpatientRequest ? $this->procedureItems($case) : $this->materialItems($case);

        $data = [
            'version'             => SurgicalGuideSnapshot::VERSION,
            'case'                => ['id' => (string) $case->getKey(), 'code' => $case->code],
            'urgency_code'        => $case->urgency->tissCode(),
            'inpatient_type_code' => '2', // dm_tipoInternacao: 2 = cirúrgica
            'regime_code'         => $case->regime === SurgeryRegime::Inpatient ? '1' : '2', // dm_regimeInternacao: 1 hospitalar, 2 hospital-dia
            'days_requested'      => (int) $case->days_requested,
            'opme'                => (bool) $case->needs_opme,
            'clinical_indication' => $this->clean($case->clinical_indication),
            'diagnoses'           => $this->diagnoses((array) $case->cid_codes),
            'suggested_date'      => $case->scheduled_start?->toDateString(),
            'hospital'            => [
                'name'          => $case->facility?->name,
                'operator_code' => $case->facility?->operator_code,
                'cnes'          => $case->facility?->cnes,
            ],
            'professional' => [
                'name'           => $person?->full_name,
                'council_code'   => '06', // dm_conselhoProfissional: 06 = CRM
                'council_number' => $surgeon?->record,
                'uf'             => IbgeUf::code($entity?->state),
                'cbos'           => filled($surgeon?->cbo_code) ? (string) $surgeon->cbo_code : (string) config('tiss.defaults.cbo_oftalmologista', '225265'),
                'phone'          => $this->phone($person?->cellphone ?: $person?->telephone),
            ],
            'beneficiary' => [
                'name' => $case->patient?->person?->full_name,
                'card' => $this->cardNumber($case),
            ],
            'justification'           => $type === TissGuideType::OpmeAttachment ? $this->justification($case) : null,
            'specification'           => $type === TissGuideType::OpmeAttachment ? $this->specification($case) : null,
            'referenced_guide_number' => $type === TissGuideType::OpmeAttachment ? $inpatientGuide?->guide_number_provider : null,
            'authorization'           => $this->authorization($case),
        ];

        return [
            'data'        => $data,
            'items'       => $items,
            'source_hash' => CanonicalHash::of(['data' => $data, 'items' => $items]),
        ];
    }

    public function cardNumber(SurgicalCase $case): ?string
    {
        $card = trim((string) ($case->covenant_card_number ?: $case->patient?->card_number));

        return $card === '' ? null : $card;
    }

    /** @return list<array<string, mixed>> */
    private function procedureItems(SurgicalCase $case): array
    {
        return $case->procedures->map(fn (SurgicalCaseProcedure $p) => [
            'tuss_code'   => trim((string) $p->tuss_code),
            'table_code'  => '22',
            'description' => Str::limit($this->clean($p->description) ?? '', 150, ''),
            'quantity'    => max(1, (int) $p->quantity),
            'unit_amount' => 0,
            'metadata'    => ['source_kind' => 'procedure', 'source_id' => (string) $p->getKey()],
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function materialItems(SurgicalCase $case): array
    {
        return $case->materials->map(fn (SurgicalCaseMaterial $m) => [
            'tuss_code'   => '',
            'table_code'  => '19', // dm_tabela: 19 = TUSS materiais
            'description' => Str::limit($this->clean($m->description) ?? '', 150, ''),
            'quantity'    => max(1, (int) $m->quantity),
            'unit_amount' => (float) ($m->unit_value ?? 0),
            'metadata'    => [
                'source_kind'             => 'material',
                'source_id'               => (string) $m->getKey(),
                'manufacturer'            => $m->manufacturer,
                'manufacturer_option'     => in_array((string) $m->manufacturer_option, ['1', '2', '3'], true) ? (string) $m->manufacturer_option : '1',
                'anvisa_registration'     => $m->anvisa_registration,
                'manufacturer_ref'        => $m->manufacturer_ref,
                'operating_authorization' => $m->operating_authorization,
            ],
        ])->values()->all();
    }

    /**
     * CID-10 como o TISS pede (st_texto4): sem ponto, maiúsculo, até 4 posições;
     * no máximo 4 hipóteses, sem repetição.
     *
     * @param array<int, mixed> $cids
     *
     * @return list<string>
     */
    private function diagnoses(array $cids): array
    {
        $codes = [];

        foreach ($cids as $cid) {
            $code = strtoupper(str_replace('.', '', trim((string) (is_array($cid) ? ($cid['code'] ?? '') : $cid))));

            if ($code !== '' && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return array_slice($codes, 0, 4);
    }

    /** Uma justificativa técnica por anexo (o XSD tem um campo só): as distintas dos materiais, em ordem. */
    private function justification(SurgicalCase $case): ?string
    {
        $texts = $case->materials
            ->pluck('technical_justification')
            ->map(fn ($t) => $this->clean($t))
            ->filter()
            ->unique()
            ->values();

        return $texts->isEmpty() ? null : $texts->implode(' ');
    }

    private function specification(SurgicalCase $case): ?string
    {
        $lines = $case->materials->map(function (SurgicalCaseMaterial $m): string {
            $maker = filled($m->manufacturer) ? " ({$m->manufacturer})" : '';

            return ($this->clean($m->description) ?? '') . $maker;
        })->filter()->values();

        return $lines->isEmpty() ? null : $lines->implode('; ');
    }

    /**
     * Dados da autorização já conhecidos (quando o anexo é gerado depois da
     * resposta da operadora): vão no cabeçalho do anexo.
     *
     * O anexo de OPME referencia a autorização da INTERNAÇÃO. Com o pedido em duas guias o caso
     * segue "aguardando" enquanto o OPME não é respondido, então o número e a senha vêm da guia de
     * internação já autorizada (e do evento dela), não do estado do caso. Autorização registrada à
     * mão (sem guia) vale pelo que o caso guarda.
     *
     * @return array{operator_guide_number: ?string, password: ?string, date: ?string}
     */
    private function authorization(SurgicalCase $case): array
    {
        // Autorização vencida (a renovar) não vai no anexo: o número velho não vale mais.
        $inpatient = in_array($case->authorization_status, [AuthorizationStatus::Pending, AuthorizationStatus::Authorized, AuthorizationStatus::PartiallyAuthorized], true)
            ? TissGuide::query()
                ->forEntity((string) $case->entity_id)
                ->where('surgical_case_id', $case->getKey())
                ->where('guide_type', TissGuideType::InpatientRequest->value)
                ->where('status', TissGuideStatus::Authorized->value)
                ->orderByDesc('updated_at')
                ->first()
            : null;

        if ($inpatient !== null) {
            $event = SurgicalAuthorizationEvent::query()
                ->where('entity_id', $case->entity_id)
                ->where('surgical_case_id', $case->getKey())
                ->where('tiss_guide_id', $inpatient->getKey())
                ->whereIn('type', [AuthorizationEventType::Authorized->value, AuthorizationEventType::PartiallyAuthorized->value, AuthorizationEventType::Note->value])
                ->whereNotNull('authorization_number')
                ->orderByDesc('occurred_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($event !== null) {
                return [
                    'operator_guide_number' => filled($inpatient->guide_number_operator) ? (string) $inpatient->guide_number_operator : $event->authorization_number,
                    'password'              => $event->authorization_password,
                    'date'                  => $event->occurred_at?->toDateString(),
                ];
            }
        }

        $known = in_array($case->authorization_status, [AuthorizationStatus::Authorized, AuthorizationStatus::PartiallyAuthorized], true);

        return [
            'operator_guide_number' => $known ? $case->authorization_number : null,
            'password'              => $known ? $case->authorization_password : null,
            'date'                  => $known ? $case->authorized_at?->toDateString() : null,
        ];
    }

    private function phone(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?: '';

        if (strlen($digits) > self::PHONE_DIGITS && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        $digits = substr($digits, -self::PHONE_DIGITS);

        return $digits === '' ? null : $digits;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }
}
