<?php

namespace App\Models;

use App\Concerns\{HasEntityCode, HasUppercaseName};
use App\Presenters\SurgeryTypePresenter;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};
use Laracasts\Presenter\PresentableTrait;

class SurgeryType extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasEntityCode;
    use HasUppercaseName;
    use HasUuids;
    use PresentableTrait;
    use SoftDeletes;

    protected string $codePrefix = 'SURG';

    protected string $codePrefixGlobal = 'SURGP';

    protected $presenter = SurgeryTypePresenter::class;

    protected $fillable = ['entity_id', 'code', 'category', 'name', 'active'];

    public static array $categories = [
        0  => 'CIRURGIAS DE OUTROS',
        1  => 'CIRURGIAS DE CATARATA',
        2  => 'CIRURGIAS REFRATIVAS',
        3  => 'CIRURGIAS DE RETINA E VÍTREO',
        4  => 'CIRURGIAS DE GLAUCOMA',
        5  => 'CIRURGIAS DA CÓRNEA',
        6  => 'CIRURGIA DE PTERÍGIO',
        7  => 'CIRURGIAS PALPEBRAIS (OCULOPLASTIA)',
        8  => 'CIRURGIAS DO SISTEMA LACRIMAL',
        9  => 'CIRURGIAS DE ESTRABISMO',
        10 => 'CIRURGIAS ORBITÁRIAS',
        11 => 'CIRURGIAS DE TRAUMA OCULAR',
        12 => 'CIRURGIAS DE REMOÇÃO OCULAR',
        13 => 'PROCEDIMENTOS A LASER',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id', 'id');
    }
}
