<?php

declare(strict_types=1);

namespace App\Domains\ClinicalReview\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O Revisor Clínico: oftalmologista externo que valida as regras de intervalo (ADR 0002).
 * Global (sem entity_id) e SEM usuário no sistema — age por convite. Os dados são os que a
 * EasyEye cadastrou; o que o Revisor DECLARA no parecer fica no atestado, e a conferência no
 * portal do CFM no aceite.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $crm_number
 * @property string $crm_uf
 * @property string $rqe
 * @property bool   $active
 */
class ClinicalReviewer extends Model
{
    use HasAuditColumns;
    use HasUuids;

    protected $fillable = ['name', 'email', 'crm_number', 'crm_uf', 'rqe', 'specialty', 'phone', 'notes', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(ClinicalReviewInvitation::class, 'reviewer_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** "CRM 12345/SP". */
    public function crmLabel(): string
    {
        return sprintf('CRM %s/%s', $this->crm_number, $this->crm_uf);
    }
}
