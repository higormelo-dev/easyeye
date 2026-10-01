<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cadastro (People) de uma clínica vinculado a uma conta do Portal do
 * Paciente pelo PRÓPRIO paciente (convite assinado + login + senha). Cada
 * clínica tem o seu People; a conta junta os vínculos para o paciente ver
 * todas as clínicas num login só. person_id é UNIQUE no banco: um cadastro
 * nunca fica em duas contas. Criado só por PatientAccountLinkService.
 */
class PatientAccountLink extends Model
{
    use Auditable;
    use HasUuids;

    protected $fillable = [
        'patient_account_id',
        'person_id',
        'entity_id',
        'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'linked_at'  => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PatientAccount::class, 'patient_account_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(People::class, 'person_id');
    }
}
