<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Models\{Doctor, Entity};
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDoctorPrompt extends Model
{
    use Auditable;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'ai_doctor_prompts';

    protected $fillable = [
        'doctor_id',
        'entity_id',
        'label',
        'prompt',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position'   => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }
}
