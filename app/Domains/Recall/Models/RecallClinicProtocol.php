<?php

declare(strict_types=1);

namespace App\Domains\Recall\Models;

use App\Domains\Recall\Engine\RecallCondition;
use App\Domains\Recall\Support\ProtocolContentSchema;
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ADOÇÃO de um protocolo por uma clínica: qual VERSÃO (e o hash que ela viu), os parâmetros ajustados
 * DENTRO da faixa validada e quem ativou. A adoção nunca troca o conteúdo por baixo da clínica — trocar de
 * versão é uma nova ativação (com o diff à vista). Uma adoção ativa por protocolo (índice único parcial).
 *
 * @property string               $id
 * @property string               $entity_id
 * @property string               $protocol_id
 * @property string               $version_id
 * @property string               $adopted_content_hash
 * @property array<string, mixed> $parameters
 */
class RecallClinicProtocol extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;

    protected $fillable = [
        'entity_id', 'protocol_id', 'version_id', 'adopted_content_hash', 'parameters', 'auto_contact',
        'activated_by', 'activated_by_doctor_id', 'activation_snapshot', 'activated_at',
        'deactivated_at', 'deactivated_by', 'deactivation_reason',
    ];

    protected function casts(): array
    {
        return [
            'parameters'          => 'array',
            'auto_contact'        => 'boolean',
            'activation_snapshot' => 'array',
            'activated_at'        => 'datetime',
            'deactivated_at'      => 'datetime',
        ];
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(RecallProtocol::class, 'protocol_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(RecallProtocolVersion::class, 'version_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * O conteúdo normalizado da versão que a clínica adotou, ou nulo quando a adoção NÃO pode calcular: versão
     * suspensa/aposentada/fora de uso, sem integridade ou diferente da que a clínica adotou (o freio de mão). É a
     * regra única de "esta adoção vale agora": o avaliador, a sugestão de retorno e a tela leem por aqui.
     *
     * @return array<string, mixed>|null
     */
    public function usableContent(): ?array
    {
        return $this->inUse() ? ProtocolContentSchema::normalize($this->version->contentArray()) : null;
    }

    /** A versão que a clínica adotou continua valendo agora (em uso, íntegra e a mesma que ela adotou)? */
    public function inUse(): bool
    {
        $version = $this->version;

        return $version->state->isInUse() && $version->integrityOk() && $version->content_hash === $this->adopted_content_hash;
    }

    /**
     * Esta adoção CALCULA a tarefa `$taskKey` agora? Versão em uso E a tarefa existe no conteúdo adotado E está ligada
     * (obrigatória sempre; opcional só se a clínica a ligou) — a mesma conta do motor (`RecallCondition::taskEnabled`).
     *
     * Existe porque a linha de retorno (`patient_recalls`) é DERIVADA e chega com atraso: desligar uma tarefa opcional
     * muda só os parâmetros da adoção, a linha persistida segue aberta até o avaliador reavaliar o paciente (a outbox
     * o chama em poucos minutos) e quem decide AGORA, sem esperar (o convite por WhatsApp, que não se desfaz), não pode
     * confiar nela.
     */
    public function computesTask(string $taskKey): bool
    {
        $content = $this->usableContent();

        if ($content === null) {
            return false;
        }

        foreach ((array) ($content['tasks'] ?? []) as $task) {
            if ((string) ($task['key'] ?? '') === $taskKey) {
                return RecallCondition::taskEnabled($task, (array) $this->parameters);
            }
        }

        return false;
    }
}
