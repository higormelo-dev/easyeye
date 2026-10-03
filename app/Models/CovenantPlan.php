<?php

namespace App\Models;

use App\Concerns\{HasEntityCode, HasUppercaseFields};
use App\Enums\CovenantSource;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\{Builder, Model, Relations\BelongsTo, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Plano de saúde (produto registrado na ANS) de um convênio.
 *
 * Global (entity_id nulo): sincronizado com os dados abertos da ANS
 * (AnsPlanImportService) ou cadastrado no manager. Da clínica (entity_id
 * preenchido): plano próprio, cadastrado em Configurações → Convênios →
 * Planos. EntityScope (TenantScopeServiceProvider) mostra à clínica os
 * globais + os dela.
 */
class CovenantPlan extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasEntityCode;
    use HasFactory;
    use HasUppercaseFields;
    use HasUuids;
    use SoftDeletes;

    /** Situações da ANS em que o plano ainda atende beneficiários (Suspenso = comercialização suspensa). */
    public const SELECTABLE_STATUSES = ['active', 'suspended'];

    protected string $codePrefix = 'PL';

    protected string $codePrefixGlobal = 'PLP';

    protected $fillable = ['entity_id', 'covenant_id', 'code', 'name', 'ans_code', 'active'];

    protected array $uppercaseFields = ['name'];

    protected function casts(): array
    {
        return [
            'ans_plan_id'       => 'integer',
            'ans_status_at'     => 'date',
            'ans_registered_at' => 'date',
            'source'            => CovenantSource::class,
            'active'            => 'boolean',
            'deleted_at'        => 'datetime',
        ];
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * Planos que a clínica pode escolher para um convênio: globais + os dela,
     * ativos. Sem clínica (manager/CLI) só os globais.
     */
    public function scopeSelectableFor(Builder $query, string $covenantId, ?string $entityId): Builder
    {
        return $query->withoutGlobalScopes()
            ->whereNull('covenant_plans.deleted_at')
            ->where('covenant_plans.covenant_id', $covenantId)
            ->where('covenant_plans.active', true)
            ->where(fn (Builder $q) => $q->whereNull('covenant_plans.entity_id')
                ->when($entityId, fn (Builder $q) => $q->orWhere('covenant_plans.entity_id', $entityId)));
    }

    /** Situação na ANS para exibição (null em plano sem dado oficial). */
    public function statusLabel(): ?string
    {
        return $this->ans_status ? __('covenant_plans.status_' . $this->ans_status) : null;
    }

    /** Nome + registro do produto na ANS (vários planos da mesma operadora têm o mesmo nome). */
    public function displayName(): string
    {
        if (! $this->ans_code) {
            return (string) $this->name;
        }

        // Plano anterior à Lei 9.656/98: código do cadastro antigo (pode ter texto), não nº de registro.
        $key = $this->regulation === 'A' ? 'covenant_plans.name_with_old_code' : 'covenant_plans.name_with_code';

        return __($key, ['name' => $this->name, 'code' => $this->ans_code]);
    }

    /**
     * Linha das listas de escolha (cadastro do paciente, manager): nome,
     * registro e as características que diferenciam planos homônimos.
     *
     * @return array<string, mixed>
     */
    public function toOption(): array
    {
        return [
            'id'        => $this->id,
            'label'     => $this->displayName(),
            'sub_label' => implode(' · ', array_filter([
                $this->contracting, $this->segmentation, $this->coverage_area, $this->moderatingFactorLabel(),
            ])),
            'name'              => $this->name,
            'ans_code'          => $this->ans_code,
            'contracting'       => $this->contracting,
            'segmentation'      => $this->segmentation,
            'coverage_area'     => $this->coverage_area,
            'accommodation'     => $this->accommodation,
            'moderating_factor' => $this->moderating_factor,
            'ans_status'        => $this->ans_status,
            'status_label'      => $this->statusLabel(),
            'active'            => (bool) $this->active,
            'is_own'            => $this->entity_id !== null,
        ];
    }

    /** Coparticipação/franquia interessam à recepção; "Ausente" não. */
    private function moderatingFactorLabel(): ?string
    {
        return $this->moderating_factor && $this->moderating_factor !== 'Ausente' ? $this->moderating_factor : null;
    }
}
