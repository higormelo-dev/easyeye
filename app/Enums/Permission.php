<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Permissões granulares ADITIVAS do RBAC customizado por clínica.
 *
 * Fonte única de verdade de key/label/group — PermissionsSeeder lê daqui e
 * NÃO duplica textos. Para adicionar uma permissão nova: crie o case aqui,
 * preencha label()/group(), rode PermissionsSeeder novamente (firstOrCreate
 * por key, idempotente).
 *
 * COMPLIANCE — leia antes de adicionar um case novo:
 * Este enum é EXCLUSIVAMENTE para permissões administrativas/operacionais
 * não-clínicas (configurações, financeiro, gestão de usuários, importação
 * de exames, relatórios, dashboards). NUNCA modele aqui uma ação
 * clínica/regulatória travada (assinar laudo, prescrever, editar prontuário
 * assinado, etc.) — essas continuam hardcoded via ClientRule::Doctor direto
 * nos Gates (ex: EntityGate::IssueReport, CFM Res. 2.227/2018) e ficam fora
 * do alcance de qualquer Role customizada construída em cima deste enum.
 */
enum Permission: string
{
    case SettingsManage  = 'settings.manage';
    case UsersManage     = 'users.manage';
    case RolesManage     = 'roles.manage';
    case FinancialView   = 'financial.view';
    case ExamsImport     = 'exams.import';
    case PatientsManage  = 'patients.manage';
    case FinancialManage = 'financial.manage';

    /**
     * Human-readable label (pt-BR).
     */
    public function label(): string
    {
        return match ($this) {
            self::SettingsManage  => 'Gerenciar configurações',
            self::UsersManage     => 'Gerenciar usuários',
            self::RolesManage     => 'Gerenciar perfis de acesso',
            self::FinancialView   => 'Visualizar financeiro',
            self::ExamsImport     => 'Importar exames',
            self::PatientsManage  => 'Gerenciar pacientes e médicos',
            self::FinancialManage => 'Gerenciar financeiro e faturamento',
        };
    }

    /**
     * Agrupamento para UI (ex: telas de gestão de perfis customizados).
     */
    public function group(): string
    {
        return match ($this) {
            self::SettingsManage => 'Configurações',
            self::UsersManage, self::RolesManage => 'Usuários',
            self::FinancialView, self::FinancialManage => 'Financeiro',
            self::ExamsImport, self::PatientsManage => 'Pacientes',
        };
    }

    /**
     * All valid string values for this context.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Key-value map suitable for select inputs: ['settings.manage' => 'Gerenciar configurações', ...].
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
