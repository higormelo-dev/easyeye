<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Enums\AI\AiProvider;
use App\Traits\Auditable;
use Database\Factories\AI\AiModelPriceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiModelPrice extends Model
{
    use Auditable;
    use HasFactory;
    use HasUuids;

    /** Padrão do sistema (seeder). */
    public const SOURCE_SEED = 'seed';

    /** Cadastrado à mão no painel (Manager → Provedores de IA). */
    public const SOURCE_MANUAL = 'manual';

    /** Descoberto pela sincronização com a API do provedor (entra inativo). */
    public const SOURCE_SYNC = 'sync';

    protected $table = 'ai_model_prices';

    protected $fillable = [
        'provider',
        'model',
        'input_usd_per_million',
        'output_usd_per_million',
        'reasoning_usd_per_million',
        'tool_call_usd',
        'effective_from',
        'effective_until',
        'active',
        'source',
        'price_locked',
        'synced_at',
        'unlisted_at',
    ];

    protected function casts(): array
    {
        return [
            'provider'                  => AiProvider::class,
            'input_usd_per_million'     => 'decimal:8',
            'output_usd_per_million'    => 'decimal:8',
            'reasoning_usd_per_million' => 'decimal:8',
            'tool_call_usd'             => 'decimal:8',
            'effective_from'            => 'datetime',
            'effective_until'           => 'datetime',
            'active'                    => 'boolean',
            'price_locked'              => 'boolean',
            'synced_at'                 => 'datetime',
            'unlisted_at'               => 'datetime',
            'created_at'                => 'datetime',
            'updated_at'                => 'datetime',
        ];
    }

    protected static function newFactory(): AiModelPriceFactory
    {
        return AiModelPriceFactory::new();
    }

    /**
     * Linha da tabela "Modelos e preços" (Manager → Provedores de IA). Datas
     * no formato do idioma da tela.
     *
     * @return array<string, mixed>
     */
    public function toCatalogRow(): array
    {
        return [
            'id'                        => (string) $this->id,
            'provider'                  => $this->provider->value,
            'provider_label'            => $this->provider->label(),
            'model'                     => $this->model,
            'input_usd_per_million'     => (float) $this->input_usd_per_million,
            'output_usd_per_million'    => (float) $this->output_usd_per_million,
            'reasoning_usd_per_million' => $this->reasoning_usd_per_million !== null ? (float) $this->reasoning_usd_per_million : null,
            'tool_call_usd'             => $this->tool_call_usd !== null ? (float) $this->tool_call_usd : null,
            'active'                    => (bool) $this->active,
            'source'                    => $this->source ?? self::SOURCE_SEED,
            'price_locked'              => (bool) $this->price_locked,
            'effective_from'            => $this->effective_from?->isoFormat('L'),
            'synced_at'                 => $this->synced_at?->isoFormat('L LT'),
            'unlisted_at'               => $this->unlisted_at?->isoFormat('L'),
        ];
    }
}
