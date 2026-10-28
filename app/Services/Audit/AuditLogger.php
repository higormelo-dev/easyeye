<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\{Entity, EntityUser};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Log};
use Illuminate\Support\Str;
use JsonException;
use LogicException;
use Throwable;

/**
 * Centraliza a escrita estruturada em `audit_logs` para eventos
 * sensíveis do painel Manager SaaS.
 *
 * Por que um service e não um trait/listener:
 *  - Eventos do Manager (impersonação, cancel subscription, etc.) precisam
 *    de contexto que o Auditable trait genérico não captura (target_user_id,
 *    reason, ator real durante impersonação).
 *  - Falhas não podem quebrar o request — todas envolvidas em try/catch,
 *    com Log::warning para diagnóstico.
 *
 * Eventos emitidos:
 *   - impersonation.start  : admin SaaS começou a impersonar um usuário
 *   - impersonation.end    : admin SaaS saiu da impersonação
 *   - admin.action         : ação destrutiva no Manager (cancel, block, destroy)
 *                            sempre acompanhada de reason explícita
 */
class AuditLogger
{
    /**
     * Registra início de impersonação. Chamado ANTES da troca de sessão,
     * para que o ator ainda seja o admin real.
     */
    public function recordImpersonationStart(Entity $clinic, EntityUser $targetEntityUser, Request $request): void
    {
        $this->insert([
            'entity_id'        => session('selected_entity_id'),        // entity SaaS no momento
            'target_entity_id' => (string) $clinic->id,                  // entity da clínica alvo
            'user_id'          => Auth::id(),                            // admin real
            'target_user_id'   => (string) $targetEntityUser->user_id,   // usuário impersonado
            'auditable_type'   => 'entity_user',
            'auditable_id'     => (string) $targetEntityUser->id,
            'event'            => 'impersonation.start',
            'status_code'      => 200,
            'route_name'       => $request->route()?->getName(),
            'old_values'       => null,
            'new_values'       => $this->jsonOrNull([
                'impersonated_user_name'   => $targetEntityUser->user?->name,
                'impersonated_entity_name' => $clinic->name,
                'impersonated_entity_id'   => $clinic->id,
                'original_user_name'       => Auth::user()?->name,
            ]),
            'reason'     => null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }

    /**
     * Registra fim de impersonação. Chamado DEPOIS da restauração da sessão.
     * O ator no log é o admin real (restaurado).
     */
    public function recordImpersonationEnd(array $impersonatingContext, Request $request): void
    {
        // BUGFIX (revisao de seguranca): Auth::id() pode ainda refletir o
        // usuário impersonado (HandleImpersonation troca o Auth::user() a
        // cada request). O ator real é sempre o admin que iniciou a
        // impersonação, gravado em 'original_user_id' na sessão.
        $this->insert([
            'entity_id'        => $impersonatingContext['original_entity_id'] ?? null,
            'target_entity_id' => $impersonatingContext['original_entity_id'] ?? null,
            'user_id'          => $impersonatingContext['original_user_id'] ?? Auth::id(),
            'target_user_id'   => $this->extractTargetUserIdFromContext($impersonatingContext),
            'auditable_type'   => 'entity_user',
            'auditable_id'     => (string) ($impersonatingContext['entity_user_id'] ?? Str::orderedUuid()),
            'event'            => 'impersonation.end',
            'status_code'      => 200,
            'route_name'       => $request->route()?->getName(),
            'old_values'       => null,
            'new_values'       => $this->jsonOrNull([
                'impersonated_user_name'   => $impersonatingContext['impersonated_user_name'] ?? null,
                'impersonated_entity_name' => $impersonatingContext['impersonated_entity_name'] ?? null,
                'original_user_name'       => $impersonatingContext['original_user_name'] ?? null,
            ]),
            'reason'     => null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }

    /**
     * Registra ação destrutiva ou de alto impacto no Manager.
     * Exige reason — chamador é responsável por validar tamanho mínimo.
     *
     * @param array<string, mixed> $newValues payload da ação (sem PII desnecessária)
     */
    public function recordAdminAction(
        string $event,
        ?string $targetEntityId,
        ?string $targetUserId,
        string $auditableType,
        string $auditableId,
        string $reason,
        array $newValues,
        Request $request,
        ?array $oldValues = null,
    ): void {
        $this->insert($this->adminActionRow(
            $event,
            $targetEntityId,
            $targetUserId,
            $auditableType,
            $auditableId,
            $reason,
            $newValues,
            $request,
            $oldValues,
            strict: false,
        ));
    }

    /**
     * Como recordAdminAction(), mas FALHA ALTO e devolve o id da linha: para a
     * trilha que precisa existir ANTES de uma transferência que não dá para
     * desfazer (ex.: áudio de saúde saindo para um provedor de IA) — se não
     * gravou, quem chama não pode seguir. Ao contrário de recordAdminAction(),
     * que engole a exceção para não derrubar a requisição, aqui qualquer erro
     * sobe — inclusive valor que não serializa em JSON (UTF-8 inválido, por
     * exemplo: recordAdminAction() gravaria a linha SEM new_values) e new_values
     * vazio (a trilha sem o que saiu não prova nada).
     *
     * Só vale COMITADA antes da transferência: o chamador abre UMA transação
     * (DB::transaction), grava e fecha antes de enviar. Com transação externa
     * aberta a gravação viraria savepoint, sem commit real até depois do envio —
     * então é recusada: o nível tem de ser o de base (config audit.durable_
     * transaction_baseline, 0 em produção) + 1, o da transação do próprio chamador.
     *
     * @param array<string, mixed> $newValues
     */
    public function recordAdminActionOrFail(
        string $event,
        ?string $targetEntityId,
        ?string $targetUserId,
        string $auditableType,
        string $auditableId,
        string $reason,
        array $newValues,
        Request $request,
        ?array $oldValues = null,
    ): string {
        if (DB::transactionLevel() !== (int) config('audit.durable_transaction_baseline', 0) + 1) {
            throw new LogicException('A trilha durável é gravada numa transação do próprio chamador e sem transação externa: só assim o commit acontece antes da transferência.');
        }

        if ($newValues === []) {
            throw new LogicException('A trilha durável exige new_values: sem o que saiu, ela não prova nada.');
        }

        $id = Str::orderedUuid()->toString();

        DB::table('audit_logs')->insert(array_merge(['id' => $id], $this->adminActionRow(
            $event,
            $targetEntityId,
            $targetUserId,
            $auditableType,
            $auditableId,
            $reason,
            $newValues,
            $request,
            $oldValues,
            strict: true,
        )));

        return $id;
    }

    /**
     * Completa o resultado de uma ação gravada por recordAdminActionOrFail()
     * (funde em new_values). Melhor esforço: a intenção já está comitada, então
     * falhar aqui só deixa a linha com o último estado conhecido, e não derruba
     * a requisição.
     *
     * @param array<string, mixed> $newValues
     */
    public function completeAdminAction(string $id, array $newValues): void
    {
        try {
            // Savepoint (transação aninhada): no PostgreSQL um erro de SQL sem ele deixa a
            // transação CORRENTE abortada, e qualquer consulta seguinte da requisição (ou
            // do teste) falha com "current transaction is aborted".
            DB::transaction(function () use ($id, $newValues): void {
                $current = json_decode((string) DB::table('audit_logs')->where('id', $id)->value('new_values'), true);

                DB::table('audit_logs')->where('id', $id)->update([
                    'new_values' => json_encode(
                        array_merge(is_array($current) ? $current : [], $newValues),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                ]);
            });
        } catch (Throwable $e) {
            Log::warning('AuditLogger: falha ao completar o resultado do evento.', [
                'audit_log_id' => $id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $newValues
     *
     * @return array<string, mixed>
     */
    private function adminActionRow(
        string $event,
        ?string $targetEntityId,
        ?string $targetUserId,
        string $auditableType,
        string $auditableId,
        string $reason,
        array $newValues,
        Request $request,
        ?array $oldValues,
        bool $strict,
    ): array {
        // strict: a serialização falha ALTO (JsonException) em vez de gravar a linha sem o
        // valor — usado pela trilha que precisa existir antes de uma transferência.
        $encode = $strict
            ? static fn (?array $data): ?string => $data === null || $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            : fn (?array $data): ?string => $this->jsonOrNull($data);

        return [
            'entity_id'        => session('selected_entity_id'),
            'target_entity_id' => $targetEntityId,
            'user_id'          => Auth::id(),
            'target_user_id'   => $targetUserId,
            'auditable_type'   => $auditableType,
            'auditable_id'     => $auditableId,
            'event'            => $event,
            'status_code'      => 200,
            'route_name'       => $request->route()?->getName(),
            'old_values'       => $encode($oldValues),
            'new_values'       => $encode($newValues),
            'reason'           => $reason,
            'ip_address'       => $request->ip(),
            'user_agent'       => mb_substr((string) $request->userAgent(), 0, 1000),
            'created_at'       => now(),
        ];
    }

    /**
     * Insert idempotente — não pode quebrar o request mesmo que o DB
     * esteja indisponível. Log::warning sinaliza para alerta de operação.
     */
    private function insert(array $data): void
    {
        try {
            DB::table('audit_logs')->insert(array_merge([
                'id' => Str::orderedUuid()->toString(),
            ], $data));
        } catch (Throwable $e) {
            Log::warning('AuditLogger: falha ao gravar evento.', [
                'event' => $data['event'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function jsonOrNull(?array $data): ?string
    {
        if ($data === null || $data === []) {
            return null;
        }

        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return null;
        }
    }

    private function extractTargetUserIdFromContext(array $context): ?string
    {
        $entityUserId = $context['entity_user_id'] ?? null;

        if ($entityUserId === null) {
            return null;
        }

        $entityUser = EntityUser::query()->find($entityUserId);

        return $entityUser?->user_id !== null ? (string) $entityUser->user_id : null;
    }
}
