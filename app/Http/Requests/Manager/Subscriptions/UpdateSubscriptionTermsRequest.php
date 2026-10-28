<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager\Subscriptions;

use App\Http\Requests\Manager\DestructiveActionRequest;
use Illuminate\Validation\Rule;

/**
 * Alterar assinatura: plano e período; ela passa a ser cortesia (sem
 * cobrança). Cobrança automática não é alterada aqui — usa a criação de
 * assinatura (passa pelo gateway).
 */
class UpdateSubscriptionTermsRequest extends DestructiveActionRequest
{
    public const MODES = ['complimentary'];

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'plan_id'   => ['required', 'uuid', Rule::exists('plans', 'id')->whereNull('deleted_at')],
            'mode'      => ['required', Rule::in(self::MODES)],
            'starts_at' => ['required', 'date'],
            'ends_at'   => ['required', 'date', 'after:starts_at'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'ends_at.required' => __('manager_subscriptions.errors.ends_at_required'),
            'ends_at.after'    => __('manager_subscriptions.errors.ends_before_start'),
        ];
    }
}
