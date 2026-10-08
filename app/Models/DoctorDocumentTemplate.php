<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo PESSOAL do médico ("Salvar como modelo" — Fase 2 / B3).
 *
 * Por clínica (entity_id) e por médico (doctor_id): só o dono lista, edita e
 * exclui. `kind`: prescription | procedure | report. `body_format`: text
 * (corpo dos modais de receita/pedido) ou html (editor de Documentações).
 *
 * @property array<string, mixed>|null $payload
 */
class DoctorDocumentTemplate extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    public const KINDS = ['prescription', 'procedure', 'report'];

    public const FORMATS = ['text', 'html'];

    protected $fillable = [
        'entity_id',
        'doctor_id',
        'kind',
        'report_setting_content_id',
        'name',
        'body',
        'body_format',
        'payload',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function baseContent(): BelongsTo
    {
        return $this->belongsTo(ReportSettingContent::class, 'report_setting_content_id');
    }

    /** Modelos do médico NESTA clínica (nunca os de outro médico/clínica). */
    public function scopeOwnedBy(Builder $query, string $entityId, string $doctorId): Builder
    {
        return $query->where($this->qualifyColumn('entity_id'), $entityId)
            ->where($this->qualifyColumn('doctor_id'), $doctorId);
    }
}
