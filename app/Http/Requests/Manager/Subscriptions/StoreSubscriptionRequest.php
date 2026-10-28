<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager\Subscriptions;

use App\Enums\Billing\GatewayCode;
use App\Enums\BillingCycle;
use App\Http\Requests\Manager\DestructiveActionRequest;
use App\Models\{Plan, PlanPrice};
use Illuminate\Validation\{Rule, Validator};

/**
 * Nova assinatura para uma empresa (substitui a vigente), em uma das
 * modalidades: trial, cobrança automática (gateway) ou cortesia. Cortesia
 * (acesso sem cobrança) exige justificativa (auditoria).
 */
class StoreSubscriptionRequest extends DestructiveActionRequest
{
    public const MODES = ['trial', 'gateway', 'complimentary'];

    /** Modalidades que liberam acesso sem cobrança. */
    private const MODES_REQUIRING_REASON = ['complimentary'];

    public function rules(): array
    {
        $sellable = PlanPrice::sellableCycleValues();

        return [
            'entity_id' => ['required', 'uuid', Rule::exists('entities', 'id')->where('is_client', true)->whereNull('deleted_at')],
            'plan_id'   => ['required', 'uuid', Rule::exists('plans', 'id')->whereNull('deleted_at')],
            'mode'      => ['required', Rule::in(self::MODES)],

            'billing_cycle' => ['nullable', 'required_if:mode,gateway', Rule::in($sellable)],
            'gateway'       => ['nullable', Rule::in(array_map(static fn (GatewayCode $g) => $g->value, GatewayCode::cases()))],
            'trial_days'    => ['nullable', 'required_if:mode,trial', 'integer', 'min:1', 'max:365'],
            'starts_at'     => ['nullable', 'date', 'before_or_equal:today'],
            'ends_at'       => ['nullable', 'required_if:mode,complimentary', 'date', 'after:today'],

            'reason' => [
                in_array($this->input('mode'), self::MODES_REQUIRING_REASON, true) ? 'required' : 'nullable',
                'string', 'min:20', 'max:1000',
            ],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // Cobrança automática só nos ciclos que o plano vende (com preço).
                if ($this->input('mode') === 'gateway') {
                    $plan  = Plan::query()->with('prices')->find($this->input('plan_id'));
                    $cycle = BillingCycle::from((string) $this->input('billing_cycle'));

                    if (! $plan?->offersCycle($cycle)) {
                        $validator->errors()->add('billing_cycle', __('manager_subscriptions.errors.cycle_not_offered', [
                            'cycle' => $cycle->label(),
                            'plan'  => $plan?->name,
                        ]));
                    }
                }

                if ($this->filled('ends_at') && $this->filled('starts_at') && strtotime((string) $this->input('ends_at')) <= strtotime((string) $this->input('starts_at'))) {
                    $validator->errors()->add('ends_at', __('manager_subscriptions.errors.ends_before_start'));
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'entity_id.exists'          => __('manager_subscriptions.errors.entity_invalid'),
            'billing_cycle.required_if' => __('manager_subscriptions.errors.cycle_required'),
            'trial_days.required_if'    => __('manager_subscriptions.errors.trial_days_required'),
            'ends_at.required_if'       => __('manager_subscriptions.errors.ends_at_required'),
            'ends_at.after'             => __('manager_subscriptions.errors.ends_at_future'),
            'starts_at.before_or_equal' => __('manager_subscriptions.errors.starts_at_not_future'),
        ];
    }

    public function optionalReason(): ?string
    {
        $reason = trim((string) $this->validated('reason'));

        return $reason !== '' ? $reason : null;
    }
}
