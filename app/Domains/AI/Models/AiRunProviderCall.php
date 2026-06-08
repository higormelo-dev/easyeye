<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Enums\AI\{AiProvider, AiProviderCallRole};
use App\Traits\Auditable;
use Database\Factories\AI\AiRunProviderCallFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRunProviderCall extends Model
{
    use Auditable;
    use HasFactory;
    use HasUuids;

    protected $table = 'ai_run_provider_calls';

    protected $fillable = [
        'ai_run_id',
        'provider',
        'model',
        'role',
        'status',
        'input_tokens',
        'output_tokens',
        'reasoning_tokens',
        'tool_calls_count',
        'raw_cost_usd',
        'normalized_credits',
        'latency_ms',
        'request_hash',
        'response_hash',
        'metadata',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'provider'           => AiProvider::class,
            'role'               => AiProviderCallRole::class,
            'input_tokens'       => 'integer',
            'output_tokens'      => 'integer',
            'reasoning_tokens'   => 'integer',
            'tool_calls_count'   => 'integer',
            'raw_cost_usd'       => 'decimal:8',
            'normalized_credits' => 'integer',
            'latency_ms'         => 'integer',
            'metadata'           => 'array',
            'created_at'         => 'datetime',
            'updated_at'         => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    protected static function newFactory(): AiRunProviderCallFactory
    {
        return AiRunProviderCallFactory::new();
    }
}
