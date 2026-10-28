<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\DTOs\DoctorPayout\PayoutItemData;
use App\Enums\DoctorPayout\{DoctorPayoutBaseSource, DoctorPayoutCalculation, DoctorPayoutPayerScope, DoctorPayoutWarning};
use App\Models\DoctorPayoutRule;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Qual regra vale para cada item e quanto ela paga — PHP puro: as regras da
 * clínica já chegam carregadas, nenhuma consulta por item.
 *
 * Especificidade (a maior vence), comparada nesta ordem:
 *  1. médico específico  > todos os médicos
 *  2. tipo de atendimento > procedimento / tipo de exame > padrão do tipo
 *  3. convênio específico > particular ou convênio > qualquer pagador
 * Empate só acontece com vigências sobrepostas no mesmo escopo (o
 * DoctorPayoutRuleService recusa): vence o início de vigência mais recente e
 * depois a regra mais nova — sempre determinístico.
 *
 * Valores em centavos; percentual com meio centavo para cima (Money).
 */
final class DoctorPayoutRuleResolver
{
    /**
     * @param Collection<int, DoctorPayoutRule> $rules
     * @param Collection<int, PayoutItemData>   $items
     *
     * @return Collection<int, PayoutItemData>
     */
    public function resolve(Collection $rules, Collection $items): Collection
    {
        return $items->map(fn (PayoutItemData $item) => $this->resolveItem($rules, $item))->values();
    }

    /** @param Collection<int, DoctorPayoutRule> $rules */
    public function resolveItem(Collection $rules, PayoutItemData $item): PayoutItemData
    {
        $rule = $this->match($rules, $item);

        if ($rule === null) {
            return $item->withResolution(null, null, null, null, 0, [DoctorPayoutWarning::NoRule]);
        }

        if ($rule->calculation === DoctorPayoutCalculation::Fixed) {
            $fixed = Money::toCents($rule->fixed_amount);

            return $item->withResolution((string) $rule->id, $rule->calculation, null, $fixed, $fixed);
        }

        $percentage = (string) $rule->percentage;
        $warnings   = $item->baseSource === DoctorPayoutBaseSource::None ? [DoctorPayoutWarning::NoBaseValue] : [];

        return $item->withResolution(
            (string) $rule->id,
            $rule->calculation,
            $percentage,
            null,
            Money::percentageOf($item->baseCents, $percentage),
            $warnings,
        );
    }

    /** @param Collection<int, DoctorPayoutRule> $rules */
    public function match(Collection $rules, PayoutItemData $item): ?DoctorPayoutRule
    {
        $day = $item->performedAt->toDateString();

        return $rules
            ->filter(fn (DoctorPayoutRule $rule) => $this->matches($rule, $item, $day))
            ->sort(fn (DoctorPayoutRule $a, DoctorPayoutRule $b) => $this->rank($b) <=> $this->rank($a))
            ->first();
    }

    private function matches(DoctorPayoutRule $rule, PayoutItemData $item, string $day): bool
    {
        if (! $rule->active || $rule->service_type !== $item->serviceType) {
            return false;
        }

        if ($rule->doctor_id !== null && $rule->doctor_id !== $item->doctorId) {
            return false;
        }

        if ($rule->visit_type_id !== null && $rule->visit_type_id !== $item->visitTypeId) {
            return false;
        }

        if ($rule->procedure_id !== null && $rule->procedure_id !== $item->procedureId) {
            return false;
        }

        if ($rule->exam_type_id !== null && $rule->exam_type_id !== $item->examTypeId) {
            return false;
        }

        if (! $this->matchesPayer($rule, $item)) {
            return false;
        }

        if ($rule->valid_from !== null && $day < $rule->valid_from->toDateString()) {
            return false;
        }

        return $rule->valid_until === null || $day <= $rule->valid_until->toDateString();
    }

    private function matchesPayer(DoctorPayoutRule $rule, PayoutItemData $item): bool
    {
        return match ($rule->payer_scope) {
            DoctorPayoutPayerScope::Any        => true,
            DoctorPayoutPayerScope::Particular => $item->isParticular,
            DoctorPayoutPayerScope::Covenant   => $rule->covenant_id !== null
                ? $rule->covenant_id === $item->covenantId
                : ! $item->isParticular,
        };
    }

    /**
     * Chave de ordenação: especificidade e, no empate, vigência mais recente e
     * regra mais nova.
     *
     * @return array{0: array{0: int, 1: int, 2: int}, 1: string, 2: string, 3: string}
     */
    private function rank(DoctorPayoutRule $rule): array
    {
        $item = match (true) {
            $rule->visit_type_id !== null                                => 2,
            $rule->procedure_id !== null || $rule->exam_type_id !== null => 1,
            default                                                      => 0,
        };

        $payer = match (true) {
            $rule->covenant_id !== null                        => 2,
            $rule->payer_scope !== DoctorPayoutPayerScope::Any => 1,
            default                                            => 0,
        };

        return [
            [$rule->doctor_id !== null ? 1 : 0, $item, $payer],
            $rule->valid_from?->toDateString() ?? '',
            $rule->created_at?->format('Y-m-d H:i:s.u') ?? '',
            (string) $rule->id,
        ];
    }
}
