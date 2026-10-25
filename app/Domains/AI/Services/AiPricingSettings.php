<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Models\SubscriptionSetting;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Markup (global e por ação), pisos e limites do pacote personalizado, editáveis no manager (Manager → IA →
 * Precificação) sem deploy. Precedência: `system_settings` > env/config (config/ai.php). Valores fora da faixa são
 * ignorados na leitura (volta ao config) e recusados na gravação. NADA aqui vai para rota de clínica.
 *
 * Chaves em system_settings: ai.pricing.markup_multiplier, ai.pricing.markup_by_workflow (JSON workflow => 1–20, só
 * overrides), ai.pricing.minimum_charge_cents_default, ai.pricing.minimum_charge_cents_by_workflow (JSON),
 * ai.pricing.custom_min_cents, ai.pricing.custom_max_cents.
 */
final class AiPricingSettings
{
    /** Workflows canônicos, na ordem do manager (lang manager_ai_pricing.workflows). As 6 primeiras são cobradas da clínica. */
    public const WORKFLOWS = [
        'exam_assistant', 'report_drafting', 'consensus_review', 'eye_image_analysis', 'assistant_chat', 'record_assist',
        'medicine_posology', 'platform_finance_digest', 'platform_finance_chat',
    ];

    /** Ações internas do SaaS: markup configurável (conta teórica no manager), mas NÃO cobradas nem com snapshot. */
    public const INTERNAL_WORKFLOWS = ['medicine_posology', 'platform_finance_digest', 'platform_finance_chat'];

    public const KEY_MARKUP = 'ai.pricing.markup_multiplier';

    public const KEY_MARKUP_BY_WORKFLOW = 'ai.pricing.markup_by_workflow';

    public const KEY_MIN_DEFAULT = 'ai.pricing.minimum_charge_cents_default';

    public const KEY_MIN_BY_WORKFLOW = 'ai.pricing.minimum_charge_cents_by_workflow';

    public const KEY_CUSTOM_MIN = 'ai.pricing.custom_min_cents';

    public const KEY_CUSTOM_MAX = 'ai.pricing.custom_max_cents';

    public const MARKUP_MIN = 1.0;

    public const MARKUP_MAX = 20.0;

    public const MINIMUM_CHARGE_MIN = 1;

    public const MINIMUM_CHARGE_MAX = 10000;

    /** Teto absoluto do personalizado (R$ 100.000): limite de gateway/fraude, não editável pelo manager. */
    public const CUSTOM_HARD_MAX_CENTS = 10_000_000;

    /** Esquece o cache das 6 chaves (uma transação desfeita não pode deixar valor novo em cache). */
    public function forgetCache(): void
    {
        foreach ([self::KEY_MARKUP, self::KEY_MARKUP_BY_WORKFLOW, self::KEY_MIN_DEFAULT, self::KEY_MIN_BY_WORKFLOW, self::KEY_CUSTOM_MIN, self::KEY_CUSTOM_MAX] as $key) {
            Cache::forget("subscription_setting:{$key}");
        }
    }

    public function markupMultiplier(): float
    {
        $stored = SubscriptionSetting::getValue(self::KEY_MARKUP);

        if ($stored !== null && is_numeric($stored) && $this->markupValid((float) $stored)) {
            return (float) $stored;
        }

        $configured = (float) config('ai.pricing.markup_multiplier', 5.0);

        return $this->markupValid($configured) ? $configured : 5.0;
    }

    /**
     * Só os OVERRIDES válidos (config + gravado); quem não está aqui herda o global.
     *
     * @return array<string, float>
     */
    public function markupByWorkflow(): array
    {
        $configured = (array) config('ai.pricing.markup_by_workflow', []);
        $stored     = SubscriptionSetting::getValue(self::KEY_MARKUP_BY_WORKFLOW);
        $decoded    = is_string($stored) ? json_decode($stored, true) : null;

        $merged = is_array($decoded) ? array_merge($configured, $decoded) : $configured;
        $result = [];

        foreach ($merged as $workflow => $multiplier) {
            if (is_string($workflow) && in_array($workflow, self::WORKFLOWS, true) && is_numeric($multiplier) && $this->markupValid((float) $multiplier)) {
                $result[$workflow] = (float) $multiplier;
            }
        }

        return $result;
    }

    /** Markup efetivo da ação: override válido ou o global. */
    public function markupMultiplierFor(string $workflow): float
    {
        return $this->markupByWorkflow()[$workflow] ?? $this->markupMultiplier();
    }

    public function minimumChargeCentsDefault(): int
    {
        $stored = SubscriptionSetting::getValue(self::KEY_MIN_DEFAULT);

        if ($stored !== null && is_numeric($stored) && $this->minimumValid((int) $stored)) {
            return (int) $stored;
        }

        $configured = (int) config('ai.pricing.minimum_charge_cents_default', 10);

        return $this->minimumValid($configured) ? $configured : 10;
    }

    /** @return array<string, int> */
    public function minimumChargeCentsByWorkflow(): array
    {
        $configured = (array) config('ai.pricing.minimum_charge_cents_by_workflow', []);
        $stored     = SubscriptionSetting::getValue(self::KEY_MIN_BY_WORKFLOW);
        $decoded    = is_string($stored) ? json_decode($stored, true) : null;

        $merged = is_array($decoded) ? array_merge($configured, $decoded) : $configured;
        $result = [];

        foreach ($merged as $workflow => $cents) {
            if (is_numeric($cents) && $this->minimumValid((int) $cents)) {
                $result[(string) $workflow] = (int) $cents;
            }
        }

        return $result;
    }

    public function customMinCents(): int
    {
        return $this->customLimits()['min'];
    }

    public function customMaxCents(): int
    {
        return $this->customLimits()['max'];
    }

    public function customStepCents(): int
    {
        return max(100, (int) config('ai.credit_purchases.custom_package.step_cents', 100));
    }

    /**
     * Grava as configurações validadas. Só as chaves informadas mudam.
     *
     * @param array{markup_multiplier?: float|int|string, markup_by_workflow?: array<string, float|int|string>, minimum_charge_cents_default?: int|string, minimum_charge_cents_by_workflow?: array<string, int|string>, custom_min_cents?: int|string, custom_max_cents?: int|string} $data
     *
     * @throws InvalidArgumentException com o código do primeiro campo inválido
     */
    public function update(array $data): void
    {
        $this->assertValid($data);

        if (array_key_exists('markup_multiplier', $data)) {
            SubscriptionSetting::setValue(self::KEY_MARKUP, number_format((float) $data['markup_multiplier'], 3, '.', ''));
        }

        if (array_key_exists('markup_by_workflow', $data)) {
            SubscriptionSetting::setValue(
                self::KEY_MARKUP_BY_WORKFLOW,
                json_encode(array_map('floatval', $data['markup_by_workflow']), JSON_THROW_ON_ERROR),
            );
        }

        if (array_key_exists('minimum_charge_cents_default', $data)) {
            SubscriptionSetting::setValue(self::KEY_MIN_DEFAULT, (int) $data['minimum_charge_cents_default']);
        }

        if (array_key_exists('minimum_charge_cents_by_workflow', $data)) {
            SubscriptionSetting::setValue(
                self::KEY_MIN_BY_WORKFLOW,
                json_encode(array_map('intval', $data['minimum_charge_cents_by_workflow']), JSON_THROW_ON_ERROR),
            );
        }

        foreach ([self::KEY_CUSTOM_MIN => 'custom_min_cents', self::KEY_CUSTOM_MAX => 'custom_max_cents'] as $key => $field) {
            if (array_key_exists($field, $data)) {
                SubscriptionSetting::setValue($key, (int) $data[$field]);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException
     */
    public function assertValid(array $data): void
    {
        if (array_key_exists('markup_multiplier', $data)
            && (! is_numeric($data['markup_multiplier']) || ! $this->markupValid((float) $data['markup_multiplier']))) {
            throw new InvalidArgumentException('markup_multiplier');
        }

        foreach ((array) ($data['markup_by_workflow'] ?? []) as $workflow => $multiplier) {
            if (! is_string($workflow) || ! in_array($workflow, self::WORKFLOWS, true) || ! is_numeric($multiplier) || ! $this->markupValid((float) $multiplier)) {
                throw new InvalidArgumentException('markup_by_workflow');
            }
        }

        if (array_key_exists('minimum_charge_cents_default', $data)
            && (! is_numeric($data['minimum_charge_cents_default']) || ! $this->minimumValid((int) $data['minimum_charge_cents_default']))) {
            throw new InvalidArgumentException('minimum_charge_cents_default');
        }

        foreach ((array) ($data['minimum_charge_cents_by_workflow'] ?? []) as $workflow => $cents) {
            if (! is_string($workflow) || $workflow === '' || ! is_numeric($cents) || ! $this->minimumValid((int) $cents)) {
                throw new InvalidArgumentException('minimum_charge_cents_by_workflow');
            }
        }

        $min  = (int) ($data['custom_min_cents'] ?? $this->customMinCents());
        $max  = (int) ($data['custom_max_cents'] ?? $this->customMaxCents());
        $step = $this->customStepCents();

        foreach (['custom_min_cents' => $min, 'custom_max_cents' => $max] as $field => $value) {
            if (array_key_exists($field, $data) && (! is_numeric($data[$field]) || $value % $step !== 0 || $value < $step || $value > self::CUSTOM_HARD_MAX_CENTS)) {
                throw new InvalidArgumentException($field);
            }
        }

        if ((array_key_exists('custom_min_cents', $data) || array_key_exists('custom_max_cents', $data)) && $min > $max) {
            throw new InvalidArgumentException('custom_limits');
        }
    }

    /** @return array{min: int, max: int} */
    private function customLimits(): array
    {
        $step = max(100, (int) config('ai.credit_purchases.custom_package.step_cents', 100));
        $min  = (int) config('ai.credit_purchases.custom_package.min_cents', 100000);
        $max  = (int) config('ai.credit_purchases.custom_package.max_cents', 1000000);

        $storedMin = SubscriptionSetting::getValue(self::KEY_CUSTOM_MIN);
        $storedMax = SubscriptionSetting::getValue(self::KEY_CUSTOM_MAX);

        $candidateMin = is_numeric($storedMin) ? (int) $storedMin : $min;
        $candidateMax = is_numeric($storedMax) ? (int) $storedMax : $max;

        $valid = $candidateMin >= $step && $candidateMin <= $candidateMax && $candidateMax <= self::CUSTOM_HARD_MAX_CENTS
            && $candidateMin % $step === 0 && $candidateMax % $step === 0;

        if ($valid) {
            return ['min' => $candidateMin, 'max' => $candidateMax];
        }

        // Config inválido (ex.: teto acima de R$ 100.000) cai no padrão SEM ultrapassar o teto duro.
        $max = min(max($step, $max - $max % $step), self::CUSTOM_HARD_MAX_CENTS);
        $min = min(max($step, $min - $min % $step), $max);

        return ['min' => $min, 'max' => $max];
    }

    private function markupValid(float $value): bool
    {
        return is_finite($value) && $value >= self::MARKUP_MIN && $value <= self::MARKUP_MAX;
    }

    private function minimumValid(int $value): bool
    {
        return $value >= self::MINIMUM_CHARGE_MIN && $value <= self::MINIMUM_CHARGE_MAX;
    }
}
