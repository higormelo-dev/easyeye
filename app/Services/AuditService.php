<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Support\AuditContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AuditService
{
    /**
     * Campos nunca auditados, independente do model.
     */
    private array $globalExclude = [
        'password',
        'remember_token',
        'created_at',
        'updated_at',
        'deleted_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * Registra um evento de auditoria para o model dado.
     *
     * @param string     $event created | updated | deleted | restored
     * @param Model      $model O model afetado
     * @param array|null $old   Valores anteriores (null em created/restored)
     * @param array|null $new   Valores novos (null em deleted)
     */
    public function log(string $event, Model $model, ?array $old, ?array $new): void
    {
        $exclude = array_merge(
            $this->globalExclude,
            method_exists($model, 'getAuditExclude') ? $model->getAuditExclude() : [],
        );

        $attributes = [
            'entity_id'      => $this->resolveEntityId($model),
            'user_id'        => AuditContext::userId(),
            'auditable_type' => get_class($model),
            'auditable_id'   => $model->getKey(),
            'event'          => $event,
            'old_values'     => $old !== null ? $this->filter($old, $exclude) : null,
            'new_values'     => $new !== null ? $this->filter($new, $exclude) : null,
            'ip_address'     => request()->ip(),
            'user_agent'     => request()->userAgent(),
            'created_at'     => now(),
        ];

        // BUG resolvido — 500 no /register: a sessão pode carregar um
        // selected_entity_id de uma entity já excluída (empresa de teste
        // apagada no manager, banco re-seedado). O INSERT em audit_logs
        // estourava a FK entity_id e derrubava a OPERAÇÃO DE NEGÓCIO
        // inteira (registro, cadastro de paciente, qualquer CUD).
        //
        // Auditoria é trilha, não gate. Cada tentativa roda em
        // DB::transaction ANINHADO: dentro de uma transação externa (caso do
        // register) o Laravel emite SAVEPOINT — no PostgreSQL um INSERT
        // falho aborta a transação inteira (SQLSTATE 25P02) e sem savepoint
        // nem o retry nem o restante do fluxo conseguiriam prosseguir.
        // Falhou a FK → rollback só do savepoint, regrava com entity_id
        // NULL (trilha preservada: auditable/valores continuam) e reporta ao
        // Sentry. Se nem assim gravar, reporta e segue — falha de auditoria
        // não pode abortar o fluxo principal.
        try {
            DB::transaction(fn () => AuditLog::create($attributes));
        } catch (QueryException $e) {
            report($e);

            try {
                DB::transaction(fn () => AuditLog::create(array_merge($attributes, ['entity_id' => null])));
            } catch (QueryException $retry) {
                report($retry);
            }
        }
    }

    /**
     * Resolve o entity_id para o log.
     * Prioridade: sessão ativa → coluna entity_id no model → null.
     */
    private function resolveEntityId(Model $model): ?string
    {
        return session('selected_entity_id')
            ?? ($model->getAttribute('entity_id') ?: null);
    }

    /**
     * Remove campos excluídos do array de valores.
     */
    private function filter(array $values, array $exclude): array
    {
        return array_diff_key($values, array_flip($exclude));
    }
}
