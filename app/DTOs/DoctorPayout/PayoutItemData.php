<?php

declare(strict_types=1);

namespace App\DTOs\DoctorPayout;

use App\Enums\DoctorPayout\{DoctorPayoutBaseSource, DoctorPayoutCalculation, DoctorPayoutServiceType, DoctorPayoutSourceType, DoctorPayoutWarning};
use Carbon\CarbonImmutable;

/**
 * Um ato de produção de um médico (consulta, exame ou procedimento), com o
 * valor base em centavos e — depois do DoctorPayoutRuleResolver — a regra
 * aplicada e o repasse. Imutável: cada etapa devolve uma cópia.
 */
final readonly class PayoutItemData
{
    /**
     * @param list<DoctorPayoutWarning> $warnings
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
    ) {
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
