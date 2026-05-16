<?php

namespace App\Models;

use App\Enums\FeatureKey;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFeature extends Model
{
    use HasAuditColumns;
    use Auditable;
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

        return match ($feature) {
            FeatureKey::MaxDoctors          => $n === 0 ? 'Médicos ilimitados'               : "Até {$n} médico(s)",
            FeatureKey::MaxPatients         => $n === 0 ? 'Pacientes ilimitados'              : "Até {$n} pacientes",
            FeatureKey::MaxUsers            => $n === 0 ? 'Usuários ilimitados'               : "Até {$n} usuários",
            FeatureKey::MaxStorageGB        => $n === 0 ? 'Armazenamento ilimitado'           : "{$n} GB de armazenamento",
            FeatureKey::AiMonthlyCredits    => $n === 0 ? 'Sem créditos de IA'               : "{$n} créditos de IA/mês",
            FeatureKey::ApiMonthlyExamSends => $n === 0 ? 'Envios de exame ilimitados'       : "Até {$n} envios via API/mês",
            default                         => $n === 0 ? $feature->label() . ' ilimitado(a)' : $feature->label() . ': ' . $n,
        };
    }
}
