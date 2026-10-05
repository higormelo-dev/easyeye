<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager\Subscriptions;

use App\Http\Requests\Manager\DestructiveActionRequest;
use App\Services\Billing\SubscriptionManagementService;
use Illuminate\Validation\Rule;

/**
 * Adicionar período: +N dias, meses ou anos sobre o término atual.
 */
class ExtendSubscriptionRequest extends DestructiveActionRequest
{
    /** Teto por unidade: até 10 anos de uma vez. */
    private const MAX_QUANTITY = ['days' => 3650, 'months' => 120, 'years' => 10];

    public function rules(): array
    {
        $unit = (string) $this->input('unit');

        return [
            ...parent::rules(),
            'unit'     => ['required', Rule::in(SubscriptionManagementService::EXTENSION_UNITS)],
            'quantity' => ['required', 'integer', 'min:1', 'max:' . (self::MAX_QUANTITY[$unit] ?? 1)],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'quantity.required' => __('manager_subscriptions.errors.quantity_required'),
            'quantity.min'      => __('manager_subscriptions.errors.quantity_required'),
            'quantity.max'      => __('manager_subscriptions.errors.quantity_max'),
        ];
    }
}
