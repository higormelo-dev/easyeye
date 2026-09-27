<?php

namespace App\Models;

use App\Enums\FeatureKey;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

class PlanFeature extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'plan_id',
        'feature',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'feature' => FeatureKey::class,
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * Retorna o valor como inteiro (para features numéricas).
     */
    public function intValue(): int
    {
        return (int) $this->value;
    }

    /**
     * Retorna o valor como booleano (para features booleanas).
     */
    public function boolValue(): bool
    {
        return $this->value === '1';
    }

    /**
     * Texto legível para exibição em cards de precificação.
     * Booleanas: retorna o label da feature (visibilidade controlada por boolValue).
     * Numéricas: "Ilimitado" quando 0, ou a quantidade formatada com unidade.
     */
    public function formatForDisplay(): string
    {
        $feature = $this->feature;

        if ($feature->isBoolean()) {
            return $feature->label();
        }

        $n = $this->intValue();
        // Plural e separador de milhar no idioma: "Até 1 médico", "Até 10.000 pacientes"
        // (antes "Até 1 médico(s)" e "10000").
        $count = fn (string $key): string => trans_choice("subscriptions.features.{$key}", $n, [
            'n' => Number::format($n, locale: app()->getLocale()),
        ]);

        return match ($feature) {
            FeatureKey::MaxDoctors          => $n === 0 ? __('subscriptions.features.max_doctors_unlimited') : $count('max_doctors_count'),
            FeatureKey::MaxPatients         => $n === 0 ? __('subscriptions.features.max_patients_unlimited') : $count('max_patients_count'),
            FeatureKey::MaxUsers            => $n === 0 ? __('subscriptions.features.max_users_unlimited') : $count('max_users_count'),
            FeatureKey::MaxStorageGB        => $n === 0 ? __('subscriptions.features.max_storage_unlimited') : $count('max_storage_count'),
            FeatureKey::AiMonthlyCredits    => $n === 0 ? __('subscriptions.features.ai_credits_none') : $count('ai_credits_count'),
            FeatureKey::ApiMonthlyExamSends => $n === 0 ? __('subscriptions.features.api_exam_sends_unlimited') : $count('api_exam_sends_count'),
            default                         => $n === 0
                ? __('subscriptions.features.generic_unlimited', ['label' => $feature->label()])
                : __('subscriptions.features.generic_count', ['label' => $feature->label(), 'n' => Number::format($n, locale: app()->getLocale())]),
        };
    }
}
