<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Enums\Surgery\SurgeryTeamRole;
use App\Models\Concerns\BelongsToEntity;
use App\Models\Doctor;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membro da equipe do caso. Nome e registro (CRM/COREN) são COPIADOS: a
 * descrição cirúrgica e o termo do implante não mudam se o cadastro mudar.
 *
 * @property SurgeryTeamRole $role
 */
class SurgicalTeamMember extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['entity_id', 'surgical_case_id', 'role', 'doctor_id', 'name', 'registry', 'position'];

    protected function casts(): array
    {
        return ['role' => SurgeryTeamRole::class, 'position' => 'integer'];
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
