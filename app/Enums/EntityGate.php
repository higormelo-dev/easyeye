<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Nomes canônicos de todos os Gates de ACL contextual por entity.
 *
 * Uso:
 *   Gate::check(EntityGate::EditSchedule->value, $entity)
 *
 *   @can(EntityGate::EditSchedule->value, $entity)
 *
 * Todos os Gates recebem (User $user, Entity $entity) como argumentos.
 * A entity deve ser resolvida pelo chamador (controller ou view) — nunca
 * lida diretamente da sessão dentro do Gate.
 */
enum EntityGate: string
{
    // ── SaaS (entity com is_client = false) ─────────────────────────────────

    /** Qualquer membro ativo da entity SaaS. */
    case SaasAccess = 'saas.access';

    /** Painel administrativo completo do SaaS (somente admin). */
    case SaasAdminPanel = 'saas.admin-panel';

    /** Acesso a funções de suporte interno (admin ou support). */
    case SaasSupport = 'saas.support';

    /** Acesso a dados financeiros do SaaS (admin ou financial). */
    case SaasFinancial = 'saas.financial';

    /**
     * P&L interno do EasyEye (receita/despesa/lucro do próprio SaaS) + análise
     * por IA. Mais restrito que SaasFinancial de propósito: o pedido de produto
     * é "exclusiva dos donos/administradores gerais" — nunca menciona o papel
     * Financial (que hoje também cobre um funcionário de cobrança/contas a
     * receber). Admin OU is_owner=true; Financial sozinho NÃO passa aqui.
     */
    case SaasOwnerFinancial = 'saas.owner-financial';

    /** Iniciar "usar como" para um usuário de uma entity cliente (admin ou support). */
    case SaasImpersonate = 'saas.impersonate';

    // ── Client entity (entity com is_client = true) ──────────────────────────

    /** Qualquer membro ativo da entity cliente. */
    case EntityAccess = 'entity.access';

    /** Gerenciar usuários e permissões da entity (admin). */
    case ManageUsers = 'entity.manage-users';

    /** Ver dados e relatórios financeiros da entity (admin, financial). */
    case ViewFinancial = 'entity.view-financial';

    /** Criar, editar e excluir agendamentos (admin, doctor, secretary). */
    case EditSchedule = 'entity.edit-schedule';

    /** Emitir laudos e prontuários médicos (doctor). */
    case IssueReport = 'entity.issue-report';

    /** Importar exame externo (upload manual) no Gerenciador de Imagens (admin, doctor, secretary). */
    case ImportExternalExam = 'entity.import-external-exam';

    /** Gerenciar configurações da entity (admin). */
    case ManageSettings = 'entity.manage-settings';

    /**
     * Gerenciar configurações de SEGURANÇA da entity (admin de entity cliente
     * OU admin de entity SaaS). 2FA obrigatório, política de senha futura, etc.
     *
     * Diferente de ManageSettings — este aceita admin SaaS na própria entity
     * SaaS (que é onde o admin gerencia 2FA dos próprios admins SaaS).
     */
    case ManageSecuritySettings = 'entity.manage-security-settings';
}
