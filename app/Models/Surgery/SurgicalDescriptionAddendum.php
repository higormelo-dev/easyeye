<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Models\Concerns\BelongsToEntity;
use App\Models\Doctor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Adendo IMUTÁVEL a uma descrição cirúrgica finalizada: texto, motivo, médico e hash. Só se cria. */
class SurgicalDescriptionAddendum extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'surgical_description_addenda';

    protected $fillable = [
        'entity_id',
        'surgical_description_id',
        'surgical_case_id',
        'patient_id',
        'text',
        'reason',
        'doctor_id',
        'doctor_name',
        'doctor_registry',
        'content_hash',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Adendo é imutável.');
        });

        static::deleting(function (): never {
            throw new LogicException('Adendo não pode ser apagado.');
        });
    }

    public function description(): BelongsTo
    {
        return $this->belongsTo(SurgicalDescription::class, 'surgical_description_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
