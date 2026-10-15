<?php

declare(strict_types=1);

namespace App\Domains\Recall\Observers;

use App\Domains\Recall\Services\RecallOutbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Marca o paciente na outbox do recall quando um ato clínico que pode mexer no retorno é gravado (ADR 0002,
 * item 7). Um observador genérico, configurado por modelo: o motivo (diagnóstico), as colunas que importam e
 * a coluna do paciente.
 *
 * A marca é gravada NA MESMA transação do ato (RecallOutbox::mark roda em savepoint e nunca derruba o ato).
 * Escrita que não dispara evento (`saveQuietly`, `update()` em massa) não passa por aqui: quem sabe disso marca
 * explicitamente, e a conciliação noturna cobre o resto.
 */
final class MarksRecallDirty
{
    /**
     * @param list<string> $watched colunas cuja mudança afeta o retorno (vazio = qualquer gravação)
     */
    public function __construct(
        private readonly string $reason,
        private readonly array $watched = [],
        private readonly string $patientColumn = 'patient_id',
    ) {
    }

    public function created(Model $model): void
    {
        $this->mark($model, $model->getAttribute($this->patientColumn));
    }

    public function updated(Model $model): void
    {
        // Só a edição de coluna que importa (`wasChanged` vale dentro de `updated`; na criação ele fica vazio).
        if ($this->watched !== [] && ! $model->wasChanged($this->watched)) {
            return;
        }

        $this->mark($model, $model->getAttribute($this->patientColumn));

        // O ato mudou de paciente (agendamento/exame remarcado para outro): o paciente ANTERIOR também muda.
        if ($model->wasChanged($this->patientColumn)) {
            $this->mark($model, $model->getPrevious()[$this->patientColumn] ?? null);
        }
    }

    public function deleted(Model $model): void
    {
        $this->mark($model, $model->getAttribute($this->patientColumn));
    }

    public function restored(Model $model): void
    {
        $this->mark($model, $model->getAttribute($this->patientColumn));
    }

    private function mark(Model $model, mixed $patientId): void
    {
        if (! is_string($patientId) || $patientId === '') {
            return;
        }

        $entityId = $model->getAttribute('entity_id');

        // Exame não tem `entity_id`: a clínica é a do paciente.
        $entityId ??= DB::table('patients')->where('id', $patientId)->value('entity_id');

        if (! is_string($entityId) || $entityId === '') {
            return;
        }

        RecallOutbox::mark($entityId, $patientId, $this->reason);
    }
}
