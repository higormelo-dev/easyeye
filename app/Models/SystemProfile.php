<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Perfis FIXOS da plataforma, pré-definidos pelo dono do SaaS.
 *
 * Substitui as referências hardcoded de apresentação (label/descrição) que
 * viviam espalhadas em ClientRule::label()/description(), SaasRule::label()
 * e User::$rolesOfClients/$rolesOfManager. A partir daqui as TELAS leem
 * desta tabela; os enums ClientRule/SaasRule permanecem como fonte das
 * CHAVES (`key` espelha o value do enum) e âncora dos Gates/middlewares —
 * autorização nunca sai do código (ver compliance em App\Enums\Permission).
 *
 * `context` separa os dois mundos do levantamento de ações:
 *   - 'saas'   → papéis do dono do SaaS (Admin, Financeiro, Suporte, Usuário Comum)
 *   - 'client' → papéis da clínica (Admin, Financeiro, Médico, Secretária, Usuário Comum)
 *
 * Sem SoftDeletes nem escopo por entity: catálogo global, read-mostly,
 * gerenciado exclusivamente pelo dono do SaaS (seed/migration hoje; tela no
 * manager na Fase 4 do plano).
 */
class SystemProfile extends Model
{
    use HasUuids;

    public const CONTEXT_SAAS = 'saas';

    public const CONTEXT_CLIENT = 'client';

    /**
     * Catálogo canônico — fonte única para migration e seeder. As keys
     * espelham SaasRule::values() e ClientRule::values(); labels/descrições
     * vieram dos enums e do levantamento de ações (Gates + rotas) de 27/08/2026.
     *
     * @var array<string, array<string, array{label: string, description: string}>>
     */
    public const CATALOG = [
        self::CONTEXT_SAAS => [
            'admin' => [
                'label'       => 'Administrador',
                'description' => 'Acesso total ao painel do dono do SaaS: empresas, planos, '
                    . 'gateways de pagamento, assinaturas, parceiros, modelos de documento, '
                    . 'finanças internas e impersonação.',
            ],
            'financial' => [
                'label'       => 'Financeiro',
                'description' => 'Operação de cobrança do SaaS: assinaturas, parceiros e '
                    . 'comissões, créditos de IA e dados financeiros.',
            ],
            'support' => [
                'label'       => 'Suporte',
                'description' => 'Suporte interno: usuários das empresas, impersonação e '
                    . 'tratamento de pedidos de créditos de IA.',
            ],
            'user' => [
                'label'       => 'Usuário Comum',
                'description' => 'Membro da entidade SaaS sem função administrativa no manager.',
            ],
        ],
        self::CONTEXT_CLIENT => [
            'admin' => [
                'label'       => 'Administrador',
                'description' => 'Acesso administrativo total: usuários e permissões, '
                    . 'configurações da clínica, financeiro, agenda e importação de exames. '
                    . 'Ações clínicas (laudos, prescrições) continuam exclusivas de médicos.',
            ],
            'financial' => [
                'label'       => 'Financeiro',
                'description' => 'Dados e relatórios financeiros da clínica.',
            ],
            'doctor' => [
                'label'       => 'Médico',
                'description' => 'Agenda, atendimento e prontuário, emissão de laudos e '
                    . 'prescrições, importação de exames.',
            ],
            'secretary' => [
                'label'       => 'Secretária',
                'description' => 'Agenda (criar, editar e remarcar), cadastro de pacientes '
                    . 'e importação de exames.',
            ],
            'user' => [
                'label'       => 'Usuário Comum',
                'description' => 'Acesso básico de membro da clínica, sem permissões '
                    . 'administrativas ou clínicas.',
            ],
        ],
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'context',
        'key',
        'label',
        'description',
        'sort_order',
    ];

    /**
     * Memo por request: as telas consultam o mapa várias vezes na mesma
     * requisição (listagem de usuários mapeia rule_label linha a linha).
     *
     * @var array<string, array<string, string>>
     */
    private static array $labelMapCache = [];

    public function scopeContext(Builder $query, string $context): Builder
    {
        return $query->where('context', $context);
    }

    /**
     * Mapa key => label do contexto, para selects e rule_label em listagens.
     * Fallback para o CATALOG quando a tabela ainda não foi migrada/seedada
     * (deploy antigo, testes sem seed) — a tela nunca quebra por catálogo vazio.
     *
     * @return array<string, string>
     */
    public static function labelMap(string $context): array
    {
        if (isset(self::$labelMapCache[$context])) {
            return self::$labelMapCache[$context];
        }

        $fromDb = self::query()
            ->context($context)
            ->orderBy('sort_order')
            ->pluck('label', 'key')
            ->all();

        if ($fromDb === []) {
            $fromDb = array_map(fn (array $row) => $row['label'], self::CATALOG[$context] ?? []);
        }

        return self::$labelMapCache[$context] = $fromDb;
    }

    /**
     * Label de um papel no contexto; devolve a própria key quando desconhecida
     * (nunca lança — rule legada/corrompida no banco não pode derrubar tela).
     */
    public static function labelFor(string $context, ?string $key): string
    {
        if (blank($key)) {
            return '';
        }

        return self::labelMap($context)[$key] ?? $key;
    }

    /**
     * Perfis completos do contexto para telas de catálogo
     * (value/label/description), ordenados.
     *
     * @return list<array{value: string, label: string, description: ?string}>
     */
    public static function catalogFor(string $context): array
    {
        $fromDb = self::query()
            ->context($context)
            ->orderBy('sort_order')
            ->get(['key', 'label', 'description'])
            ->map(fn (self $profile) => [
                'value'       => $profile->key,
                'label'       => $profile->label,
                'description' => $profile->description,
            ])
            ->all();

        if ($fromDb !== []) {
            return $fromDb;
        }

        return collect(self::CATALOG[$context] ?? [])
            ->map(fn (array $row, string $key) => [
                'value'       => $key,
                'label'       => $row['label'],
                'description' => $row['description'],
            ])
            ->values()
            ->all();
    }

    /**
     * Limpa o memo — necessário em testes que alteram a tabela no meio da
     * mesma request/processo.
     */
    public static function flushCache(): void
    {
        self::$labelMapCache = [];
    }
}
