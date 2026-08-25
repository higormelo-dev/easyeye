<?php

declare(strict_types=1);

namespace App\Enums;

enum ClientRule: string
{
    case Admin     = 'admin';
    case Financial = 'financial';
    case Doctor    = 'doctor';
    case Secretary = 'secretary';
    case User      = 'user';

    /**
     * Human-readable label (pt-BR).
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin     => 'Administrador',
            self::Financial => 'Financeiro',
            self::Doctor    => 'Médico',
            self::Secretary => 'Secretária',
            self::User      => 'Usuário Comum',
        };
    }

    /**
     * O que cada perfil FIXO pode fazer, em pt-BR, derivado dos Gates em
     * AuthServiceProvider (EntityGate) e dos middlewares entity.role das
     * rotas. Exibido na tela de Perfis de acesso como catálogo somente
     * leitura — manter em sincronia ao alterar qualquer Gate/rota.
     */
    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Acesso administrativo total: usuários e permissões, '
                . 'configurações da clínica, financeiro, agenda e importação de exames. '
                . 'Ações clínicas (laudos, prescrições) continuam exclusivas de médicos.',
            self::Financial => 'Dados e relatórios financeiros da clínica.',
            self::Doctor => 'Agenda, atendimento e prontuário, emissão de laudos e '
                . 'prescrições, importação de exames.',
            self::Secretary => 'Agenda (criar, editar e remarcar), cadastro de pacientes '
                . 'e importação de exames.',
            self::User => 'Acesso básico de membro da clínica, sem permissões '
                . 'administrativas ou clínicas.',
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
     * Key-value map suitable for select inputs: ['admin' => 'Administrador', ...].
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
