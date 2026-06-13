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
            'created_at'                => 'datetime',
            'updated_at'                => 'datetime',
        ];
    }

    protected static function newFactory(): AiModelPriceFactory
    {
        return AiModelPriceFactory::new();
    }
}
