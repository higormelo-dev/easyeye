<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Models\Concerns\BelongsToEntity;
use App\Models\Doctor;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * O ato de registrar a cirurgia como REALIZADA: quem, quando, equipe confirmada,
 * intercorrências e as justificativas das exceções que o cirurgião assumiu
 * (autorização vencida, Pausa Cirúrgica não fechada, sem implante...). 1:1 com
 * o caso; escrito só por SurgeryPerformService::perform().
 */
class SurgicalOperation extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;

    protected array $auditExclude = ['complications'];

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'performed_by',
        'performed_by_doctor_id',
        'performed_at',
        'team_confirmed_at',
        'complications',
        'no_implant_reason',
        'override_reasons',
    ];

    protected static function booted(): void
    {
        // O ato registrado (quem, quando, equipe, justificativas) é histórico: não se reescreve nem se apaga.
        static::updating(function (): never {
            throw new LogicException('O registro da cirurgia realizada é imutável.');
        });

        static::deleting(function (): never {
            throw new LogicException('O registro da cirurgia realizada não pode ser apagado.');
        });
    }

    protected function casts(): array
    {
        return [
            'performed_at'      => 'datetime',
            'team_confirmed_at' => 'datetime',
            'override_reasons'  => 'array',
        ];
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'performed_by_doctor_id');
    }
}
