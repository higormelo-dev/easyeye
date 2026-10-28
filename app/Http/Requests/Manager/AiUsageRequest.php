<?php

namespace App\Http\Requests\Manager;

use App\DTOs\AI\AiUsageFiltersData;
use App\Enums\AI\{AiProvider, AiRunStatus};
use App\Enums\EntityGate;
use App\Models\Entity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\{Rule, Validator};

/**
 * Filtros de Manager → Uso de IA (tela e exportação). Acesso: dono ou admin
 * do SaaS (Gate SaasOwnerFinancial, o mesmo do P&L) — checado aqui, antes da
 * validação, pra quem não pode ver receber 403 e não erros de campo.
 */
class AiUsageRequest extends FormRequest
{
    public const PRESETS = ['7d', '30d', 'this_month', 'last_month', '3m', '12m', 'custom'];

    public const SORTS = ['created_at', 'cost'];

    /** Recorte livre de até ~2 anos (consulta global de todas as clínicas). */
    private const MAX_CUSTOM_DAYS = 731;

    public function authorize(): bool
    {
        $entity = Entity::query()->find(session('selected_entity_id'));

        return $entity !== null && Gate::allows(EntityGate::SaasOwnerFinancial->value, $entity);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preset'    => ['nullable', 'string', Rule::in(self::PRESETS)],
            'from'      => ['nullable', 'required_if:preset,custom', 'date_format:Y-m-d'],
            'to'        => ['nullable', 'required_if:preset,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'entity_id' => ['bail', 'nullable', 'uuid', 'exists:entities,id'],
            'user_id'   => ['bail', 'nullable', 'uuid', 'exists:users,id'],
            'workflow'  => ['nullable', 'string', 'max:100'],
            'provider'  => ['nullable', 'string', Rule::enum(AiProvider::class)],
            'status'    => ['nullable', 'string', Rule::enum(AiRunStatus::class)],
            'sort'      => ['nullable', 'string', Rule::in(self::SORTS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('preset') !== 'custom' || $validator->errors()->isNotEmpty()) {
                return;
            }

            $days = Carbon::parse((string) $this->input('from'))->diffInDays(Carbon::parse((string) $this->input('to')));

            if ($days > self::MAX_CUSTOM_DAYS) {
                $validator->errors()->add('to', __('manager_ai_usage.period_too_long', ['days' => self::MAX_CUSTOM_DAYS]));
            }
        });
    }

    public function preset(): string
    {
        return (string) ($this->validated('preset') ?? 'this_month');
    }

    public function filters(): AiUsageFiltersData
    {
        [$from, $to] = $this->period();

        return new AiUsageFiltersData(
            from: $from,
            to: $to,
            entityId: $this->validated('entity_id'),
            userId: $this->validated('user_id'),
            workflow: $this->validated('workflow') ?: null,
            provider: $this->validated('provider'),
            status: $this->validated('status'),
        );
    }

    /** @return array{0: 'created_at'|'cost', 1: 'asc'|'desc'} */
    public function sort(): array
    {
        return [
            (string) ($this->validated('sort') ?? 'created_at'),
            (string) ($this->validated('direction') ?? 'desc'),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(): array
    {
        $now = Carbon::now();

        return match ($this->preset()) {
            '7d'         => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '30d'        => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            '3m'     => [$now->copy()->subMonthsNoOverflow(3)->startOfDay(), $now->copy()->endOfDay()],
            '12m'    => [$now->copy()->subMonthsNoOverflow(12)->startOfDay(), $now->copy()->endOfDay()],
            'custom' => [
                Carbon::parse((string) $this->validated('from'))->startOfDay(),
                Carbon::parse((string) $this->validated('to'))->endOfDay(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
        };
    }
}
