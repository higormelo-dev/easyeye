<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\{Entity, EntityUser};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Log};
use Illuminate\Support\Str;
use JsonException;
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
        $this->insert([
            'entity_id'        => session('selected_entity_id'),
            'target_entity_id' => $targetEntityId,
            'user_id'          => Auth::id(),
            'target_user_id'   => $targetUserId,
            'auditable_type'   => $auditableType,
            'auditable_id'     => $auditableId,
            'event'            => $event,
            'status_code'      => 200,
            'route_name'       => $request->route()?->getName(),
            'old_values'       => $this->jsonOrNull($oldValues),
            'new_values'       => $this->jsonOrNull($newValues),
            'reason'           => $reason,
            'ip_address'       => $request->ip(),
            'user_agent'       => mb_substr((string) $request->userAgent(), 0, 1000),
            'created_at'       => now(),
        ]);
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
