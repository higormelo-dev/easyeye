<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Enums\Surgery\AuthorizationEventType;
use App\Models\Concerns\BelongsToEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Evento IMUTÁVEL do log de autorização do caso. Só se cria: corrigir um
 * registro errado é lançar um novo evento (nota/retificação), nunca editar.
 * Sem `updated_at`. O estado corrente fica em `surgical_cases` (desnormalizado
 * pelo SurgeryAuthorizationService na MESMA transação).
 *
 * @property AuthorizationEventType $type
 */
class SurgicalAuthorizationEvent extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'type',
        'occurred_at',
        'authorization_number',
        'authorization_password',
        'valid_until',
        'authorized_items',
        'denial_reason',
        'protocol_number',
        'channel',
        'tiss_guide_id',
        'note',
        'recorded_by',
    ];

    protected $hidden = ['authorization_password'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Evento de autorização é imutável: lance um novo evento.');
        });

        static::deleting(function (): never {
            throw new LogicException('Evento de autorização não pode ser apagado.');
        });
    }

    protected function casts(): array
    {
        return [
            'type'             => AuthorizationEventType::class,
            'occurred_at'      => 'datetime',
            'valid_until'      => 'date',
            'authorized_items' => 'array',
        ];
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
