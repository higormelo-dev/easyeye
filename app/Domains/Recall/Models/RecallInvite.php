<?php

declare(strict_types=1);

namespace App\Domains\Recall\Models;

use App\Domains\Recall\Enums\{InviteOrigin, InviteResponse};
use App\Models\Concerns\BelongsToEntity;
use App\Models\Patient;
use App\Models\WhatsApp\WhatsAppMessage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasOne};

/**
 * A ONDA de convite de retorno por WhatsApp (ADR 0002, item 9): o convite de UM paciente em um dia, que cobre
 * todas as tarefas vencidas dele de uma vez (`recall_invite_items`). `wave_key` único por clínica garante uma
 * onda por paciente por dia, qualquer que seja a origem (rodada automática ou clique de uma pessoa).
 *
 * A mensagem de saída é `whatsapp_messages` (uma por onda); o estado da entrega vive nela, não aqui. A RESPOSTA
 * do paciente (botão "Quero agendar" / "Já agendei") fica nesta linha.
 *
 * @property string          $id
 * @property string          $entity_id
 * @property string          $patient_id
 * @property string          $wave_key
 * @property InviteOrigin    $origin
 * @property ?InviteResponse $response
 */
class RecallInvite extends Model
{
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['entity_id', 'patient_id', 'wave_key', 'origin', 'created_by', 'response', 'responded_at'];

    protected function casts(): array
    {
        return [
            'origin'       => InviteOrigin::class,
            'response'     => InviteResponse::class,
            'responded_at' => 'datetime',
        ];
    }

    /** A chave da onda de um paciente num dia. */
    public static function waveKey(string $patientId, string $day): string
    {
        return $day . ':' . $patientId;
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsToMany<PatientRecall> */
    public function recalls(): BelongsToMany
    {
        return $this->belongsToMany(PatientRecall::class, 'recall_invite_items', 'recall_invite_id', 'patient_recall_id');
    }

    public function message(): HasOne
    {
        return $this->hasOne(WhatsAppMessage::class, 'recall_invite_id')->where('direction', 'out');
    }
}
