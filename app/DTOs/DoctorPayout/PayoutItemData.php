<?php

declare(strict_types=1);

namespace App\DTOs\DoctorPayout;

use App\Enums\DoctorPayout\{DoctorPayoutBaseSource, DoctorPayoutBeneficiaryRole, DoctorPayoutCalculation, DoctorPayoutServiceType, DoctorPayoutSourceType, DoctorPayoutWarning};
use Carbon\CarbonImmutable;

/**
 * Um ato de produção de um médico (consulta, exame ou procedimento), com o
 * valor base em centavos e — depois do DoctorPayoutRuleResolver — a regra
 * aplicada e o repasse. Imutável: cada etapa devolve uma cópia.
 *
 * No regime por recebimento (DoctorPayoutReleaseService) o mesmo DTO é a
 * PARCELA a liberar (tranche ≥ 1): baseCents/payoutCents são o que esta
 * parcela libera (recebido acumulado − já liberado); receivedCents é o
 * recebido acumulado considerado até receiptsUntil. Ato ainda sem
 * recebimento (tranche 0) traz a previsão em forecastCents.
 */
final readonly class PayoutItemData
{
    /**
     * @param list<DoctorPayoutWarning>                                              $warnings
     * @param list<array{id: string, date: string, amount_cents: int, kind: string}> $receipts
     */
    public function __construct(
        public DoctorPayoutSourceType $sourceType,
        public string $sourceId,
        public DoctorPayoutServiceType $serviceType,
        public CarbonImmutable $performedAt,
        public string $doctorId,
        public ?string $patientId,
        public ?string $covenantId,
        public ?string $covenantName,
        public bool $isParticular,
        public string $description,
        public ?string $visitTypeId,
        public ?string $procedureId,
        public ?string $examTypeId,
        public int $baseCents,
        public DoctorPayoutBaseSource $baseSource,
        public array $warnings = [],
        public ?string $ruleId = null,
        public ?DoctorPayoutCalculation $ruleCalculation = null,
        public ?string $rulePercentage = null,
        public ?int $ruleFixedCents = null,
        public int $payoutCents = 0,
        public int $tranche = 0,
        public ?int $receivedCents = null,
        public ?int $expectedCents = null,
        public int $releasedBeforeCents = 0,
        public ?string $receiptsUntil = null,
        public array $receipts = [],
        public ?int $forecastCents = null,
        public ?DoctorPayoutBeneficiaryRole $beneficiaryRole = null,
        public ?string $sharePercentage = null,
        public ?int $netCents = null,
        public int $deductionsCents = 0,
        public array $split = [],
    ) {
    }

    /**
     * Divisão da parcela (E4): papel do beneficiário, % do grupo que cabe a
     * ele, recebido líquido (recebido − deduções) e o retrato da divisão.
     *
     * @param array<string, mixed> $split
     */
    public function withSplit(DoctorPayoutBeneficiaryRole $role, ?string $sharePercentage, ?int $netCents, int $deductionsCents, array $split): self
    {
        return $this->with([
            'beneficiaryRole' => $role,
            'sharePercentage' => $sharePercentage,
            'netCents'        => $netCents,
            'deductionsCents' => $deductionsCents,
            'split'           => $split,
        ]);
    }

    /**
     * Parcela do regime por recebimento: base = recebido desta parcela, com a
     * regra (congelada no 1º fechamento do ato ou a resolvida) e o retrato.
     *
     * @param array{id: ?string, calculation: ?DoctorPayoutCalculation, percentage: ?string, fixed_cents: ?int} $rule
     * @param list<array{id: string, date: string, amount_cents: int, kind: string}>                            $receipts
     * @param list<DoctorPayoutWarning>                                                                         $extraWarnings
     */
    public function withRelease(
        int $tranche,
        int $baseCents,
        int $payoutCents,
        int $receivedCents,
        int $expectedCents,
        int $releasedBeforeCents,
        string $receiptsUntil,
        array $receipts,
        array $rule,
        array $extraWarnings = [],
    ): self {
        $warnings = $this->warnings;

        foreach ($extraWarnings as $warning) {
            if (! in_array($warning, $warnings, true)) {
                $warnings[] = $warning;
            }
        }

        return $this->with([
            'tranche'             => $tranche,
            'baseCents'           => $baseCents,
            'baseSource'          => DoctorPayoutBaseSource::Received,
            'payoutCents'         => $payoutCents,
            'receivedCents'       => $receivedCents,
            'expectedCents'       => $expectedCents,
            'releasedBeforeCents' => $releasedBeforeCents,
            'receiptsUntil'       => $receiptsUntil,
            'receipts'            => $receipts,
            'ruleId'              => $rule['id'],
            'ruleCalculation'     => $rule['calculation'],
            'rulePercentage'      => $rule['percentage'],
            'ruleFixedCents'      => $rule['fixed_cents'],
            'warnings'            => $warnings,
        ]);
    }

    /** Ato ainda sem recebimento: fica visível com a previsão (regra × valor cobrado). */
    public function asAwaiting(int $expectedCents): self
    {
        return $this->with([
            'tranche'       => 0,
            'receivedCents' => 0,
            'expectedCents' => $expectedCents,
            'forecastCents' => $this->payoutCents,
            'payoutCents'   => 0,
        ]);
    }

    public function isRelease(): bool
    {
        return $this->tranche > 0;
    }

    public function withoutWarnings(DoctorPayoutWarning ...$remove): self
    {
        return $this->with([
            'warnings' => array_values(array_filter($this->warnings, fn (DoctorPayoutWarning $warning) => ! in_array($warning, $remove, true))),
        ]);
    }

    /** Identidade do ato — a mesma usada no índice único dos itens de fechamento. */
    public function key(): string
    {
        return $this->sourceType->value . ':' . $this->sourceId;
    }

    public function withWarning(DoctorPayoutWarning $warning): self
    {
        if (in_array($warning, $this->warnings, true)) {
            return $this;
        }

        return $this->with(['warnings' => [...$this->warnings, $warning]]);
    }

    /**
     * @param list<DoctorPayoutWarning> $extraWarnings
     */
    public function withResolution(
        ?string $ruleId,
        ?DoctorPayoutCalculation $calculation,
        ?string $percentage,
        ?int $fixedCents,
        int $payoutCents,
        array $extraWarnings = [],
    ): self {
        $warnings = $this->warnings;

        foreach ($extraWarnings as $warning) {
            if (! in_array($warning, $warnings, true)) {
                $warnings[] = $warning;
            }
        }

        return $this->with([
            'ruleId'          => $ruleId,
            'ruleCalculation' => $calculation,
            'rulePercentage'  => $percentage,
            'ruleFixedCents'  => $fixedCents,
            'payoutCents'     => $payoutCents,
            'warnings'        => $warnings,
        ]);
    }

    public function hasWarning(DoctorPayoutWarning $warning): bool
    {
        return in_array($warning, $this->warnings, true);
    }

    public function blocksClosing(): bool
    {
        foreach ($this->warnings as $warning) {
            if ($warning->blocksClosing()) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $changes */
    private function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
