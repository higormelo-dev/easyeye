<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Enums\Surgery\SurgeryDocumentType;
use App\Models\Concerns\{BelongsToEntity, BindsRouteToSessionEntity};
use App\Models\{Doctor, Patient, User};
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Documento cirúrgico CONGELADO (TCLE, orientações, termo do implante, alta).
 *
 * O conteúdo (`title`, `content`, `payload`, `content_hash`, tipo, autoria e
 * data) nunca muda depois de gravado e a linha nunca é apagada. Só duas coisas
 * se atualizam: `superseded_by_id` (reemissão aponta para a nova) e os
 * metadados da cópia digitalizada assinada (`signed_copy_*`), que são um
 * arquivo anexado — não fazem parte do hash do conteúdo.
 *
 * @property SurgeryDocumentType $type
 */
class SurgeryDocumentIssue extends Model
{
    use Auditable;
    use BelongsToEntity;
    use BindsRouteToSessionEntity;
    use HasUuids;

    /** Texto e retrato do documento ficam fora da trilha (volume e dado clínico; o hash já os identifica). */
    protected array $auditExclude = ['content', 'payload'];

    /** Colunas que podem mudar depois da emissão. */
    private const MUTABLE = [
        'superseded_by_id',
        'signed_copy_path',
        'signed_copy_name',
        'signed_copy_mime',
        'signed_copy_size',
        'signed_copy_hash',
        'signed_copy_uploaded_at',
        'signed_copy_uploaded_by',
        'updated_at',
    ];

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'patient_id',
        'type',
        'title',
        'content',
        'payload',
        'content_hash',
        'template_id',
        'template_version',
        'issued_by',
        'issued_by_doctor_id',
        'issued_at',
        'superseded_by_id',
        'signed_copy_path',
        'signed_copy_name',
        'signed_copy_mime',
        'signed_copy_size',
        'signed_copy_hash',
        'signed_copy_uploaded_at',
        'signed_copy_uploaded_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $issue): void {
            $frozen = array_diff(array_keys($issue->getDirty()), self::MUTABLE);

            if ($frozen !== []) {
                throw new LogicException('Documento cirúrgico emitido é imutável: reemita para corrigir (' . implode(', ', $frozen) . ').');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Documento cirúrgico emitido não pode ser apagado.');
        });
    }

    protected function casts(): array
    {
        return [
            'type'                    => SurgeryDocumentType::class,
            'payload'                 => 'array',
            'issued_at'               => 'datetime',
            'signed_copy_uploaded_at' => 'datetime',
            'template_version'        => 'integer',
            'signed_copy_size'        => 'integer',
        ];
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function issuerDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'issued_by_doctor_id');
    }

    public function isCurrent(): bool
    {
        return $this->superseded_by_id === null;
    }

    public function hasSignedCopy(): bool
    {
        return filled($this->signed_copy_path);
    }
}
