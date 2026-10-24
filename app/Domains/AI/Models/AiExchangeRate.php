<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Enums\AI\AiExchangeRateSource;
use App\Models\User;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma cotação US$→R$ gravada pelo manager (histórico append-only: corrigir = nova linha).
 * Nunca é exposta a rotas de clínica.
 */
class AiExchangeRate extends Model
{
    use Auditable;
    use HasUuids;

    protected $table = 'ai_exchange_rates';

    protected $fillable = [
        'source',
        'rate_brl_per_usd',
        'effective_at',
        'recorded_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'source'           => AiExchangeRateSource::class,
            'rate_brl_per_usd' => 'decimal:6',
            'effective_at'     => 'datetime',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
