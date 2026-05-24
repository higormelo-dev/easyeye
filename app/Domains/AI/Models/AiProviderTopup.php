<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Enums\AI\AiProvider;
use App\Models\User;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Recarga manual de saldo do EasyEye em um provedor de IA (lado supplier).
 *
 * NÃO é compra de crédito por cliente — é o EasyEye carregando saldo nas APIs.
 * Usado para calcular saldo_estimado = SUM(topups) - SUM(custo consumido).
 */
class AiProviderTopup extends Model
{
    use Auditable;
    use HasFactory;
    use HasUuids;

    protected $table = 'ai_provider_topups';

    protected $fillable = [
        'provider',
        'amount_usd',
        'topped_up_at',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'provider'     => AiProvider::class,
            'amount_usd'   => 'decimal:4',
            'topped_up_at' => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
