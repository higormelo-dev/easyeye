<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\{ClientRule, EntityGate, SaasRule};
use App\Models\{Entity, User};
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Define todos os Gates de ACL contextual por entity.
 *
 * Princípios:
 * - Cada Gate recebe (User $user, Entity $entity) — sempre explícito.
 * - Gates SaaS só concedem acesso quando $entity->isSaas() === true.
 * - Gates de client só concedem acesso quando $entity->isClient() === true.
 * - Roles são verificados via HasEntityRoles trait (com cache por request).
 * - Um Gate nunca lê session() diretamente — isso é responsabilidade do chamador.
 *
 * Nomenclatura: EntityGate enum → sem magic strings em controllers/views.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->defineSaasGates();
        $this->defineClientGates();
    }

    // =========================================================================
    // SaaS gates — só válidos na entity dona do SaaS (is_client = false)
    // =========================================================================

    private function defineSaasGates(): void
    {
        /**
         * Qualquer membro ativo da entity SaaS.
         * Usado para bloquear totalmente usuários de clients que tentam
         * acessar rotas do painel manager.
         */
        Gate::define(EntityGate::SaasAccess->value, function (User $user, Entity $entity): bool {
            if (! $entity->isSaas()) {
                return false;
            }

            return $user->canAccessEntity($entity);
        });

        /**
         * Painel administrativo completo do SaaS.
         * Somente admin — gerencia entities, planos, assinaturas, integradores.
         */
        Gate::define(EntityGate::SaasAdminPanel->value, function (User $user, Entity $entity): bool {
            if (! $entity->isSaas()) {
                return false;
            }

            return $user->hasRoleInEntity($entity, SaasRule::Admin);
        });

        /**
         * Acesso a funções de suporte interno: visualizar entities de clientes,
         * gerenciar integradores, responder tickets.
         * Admin e Support têm acesso.
         */
        Gate::define(EntityGate::SaasSupport->value, function (User $user, Entity $entity): bool {
            if (! $entity->isSaas()) {
                return false;
            }

            return $user->hasAnyRoleInEntity($entity, [SaasRule::Admin, SaasRule::Support]);
        });

        /**
         * Acesso a dados financeiros do SaaS: cobranças, receitas, repasses.
         * Admin e Financial têm acesso.
         */
        Gate::define(EntityGate::SaasFinancial->value, function (User $user, Entity $entity): bool {
            if (! $entity->isSaas()) {
                return false;
            }

            return $user->hasAnyRoleInEntity($entity, [SaasRule::Admin, SaasRule::Financial]);
        });

        /**
         * Iniciar "usar como" para um usuário de uma entity cliente.
         * Admin e Support do SaaS têm acesso.
         */
        Gate::define(EntityGate::SaasImpersonate->value, function (User $user, Entity $entity): bool {
            if (! $entity->isSaas()) {
                return false;
            }

            return $user->hasAnyRoleInEntity($entity, [SaasRule::Admin, SaasRule::Support]);
        });
    }

    // =========================================================================
    // Client entity gates — só válidos em entities clientes (is_client = true)
    // =========================================================================

    private function defineClientGates(): void
    {
        /**
         * Qualquer membro ativo de uma entity cliente.
         * Ponto de entrada mínimo para o painel do cliente.
         */
        Gate::define(EntityGate::EntityAccess->value, function (User $user, Entity $entity): bool {
            if (! $entity->isClient()) {
                return false;
            }

            return $user->canAccessEntity($entity);
        });

        /**
         * Gerenciar usuários e papéis dentro da entity:
         * convidar, remover, alterar rule de membros.
         * Somente admin da entity cliente.
         */
        Gate::define(EntityGate::ManageUsers->value, function (User $user, Entity $entity): bool {
            if (! $entity->isClient()) {
                return false;
            }

            return $user->hasRoleInEntity($entity, ClientRule::Admin);
        });

        /**
         * Ver relatórios financeiros, faturamento, convênios e repasses.
         * Admin e Financial têm acesso.
         */
        Gate::define(EntityGate::ViewFinancial->value, function (User $user, Entity $entity): bool {
            if (! $entity->isClient()) {
                return false;
            }

            return $user->hasAnyRoleInEntity($entity, [ClientRule::Admin, ClientRule::Financial]);
        });

        /**
         * Criar, editar e excluir agendamentos na agenda da clínica.
         * Admin, Doctor e Secretary têm acesso.
         */
        Gate::define(EntityGate::EditSchedule->value, function (User $user, Entity $entity): bool {
            if (! $entity->isClient()) {
                return false;
            }

            return $user->hasAnyRoleInEntity($entity, [
                ClientRule::Admin,
                ClientRule::Doctor,
                ClientRule::Secretary,
            ]);
        });

        /**
         * Emitir laudos médicos e registrar prontuários.
         * Exclusivo para médicos (CFM Res. 2.227/2018) — admin não pode assinar.
         */
        Gate::define(EntityGate::IssueReport->value, function (User $user, Entity $entity): bool {
            if (! $entity->isClient()) {
                return false;
            }

            return $user->hasRoleInEntity($entity, ClientRule::Doctor);
        });

        /**
         * Importar exame externo (upload manual) no Gerenciador de Imagens.
         * Admin, Doctor e Secretary têm acesso — é ato administrativo de
         * anexar arquivo, não ato clínico — não requer IssueReport.
         */
        Gate::define(EntityGate::ImportExternalExam->value, function (User $user, Entity $entity): bool {
            if (! $entity->isClient()) {
                return false;
            }

            return $user->hasAnyRoleInEntity($entity, [
                ClientRule::Admin,
                ClientRule::Doctor,
                ClientRule::Secretary,
            ]);
        });

        /**
         * Gerenciar configurações da entity: dados cadastrais, convênios,
         * tipos de consulta, integrações.
         * Somente admin da entity cliente.
         */
        Gate::define(EntityGate::ManageSettings->value, function (User $user, Entity $entity): bool {
            if (! $entity->isClient()) {
                return false;
            }

            return $user->hasRoleInEntity($entity, ClientRule::Admin);
        });

        /**
         * Gerenciar configurações de SEGURANÇA da entity (2FA obrigatório,
         * futuras políticas de senha/sessão).
         *
         * Aceita DOIS contextos:
         *  1) Entity cliente (clínica) — admin da clínica configura para sua empresa.
         *  2) Entity SaaS — admin SaaS configura para a própria entidade SaaS,
         *     forçando 2FA nos admins/financial/support do sistema.
         *
         * Não é um gate só de cliente nem só de SaaS — é gerenciamento da
         * postura de segurança da empresa, válido em ambos os lados.
         */
        Gate::define(EntityGate::ManageSecuritySettings->value, function (User $user, Entity $entity): bool {
            if ($entity->isSaas()) {
                return $user->hasRoleInEntity($entity, SaasRule::Admin);
            }

            return $user->hasRoleInEntity($entity, ClientRule::Admin);
        });
    }
}
