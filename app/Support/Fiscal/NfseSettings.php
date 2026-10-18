<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

use App\Enums\Fiscal\{NfseEnvironment, NfseStandard};
use App\Models\SubscriptionSetting;
use App\Rules\CpfCnpj;
use App\Support\BrazilianFormat;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{Cache, DB};
use InvalidArgumentException;

/**
 * Configuração global da NFS-e do SaaS (Manager → Nota fiscal): dados do prestador (a dona do SaaS), padrão, ambiente,
 * emissão automática e texto da discriminação. Mora em `system_settings` (grupo `nfse`, chave `nfse.*`) e reaproveita o
 * cache de 10 minutos de SubscriptionSetting — o `save()` invalida cada chave que grava.
 *
 * Os TOKENS não ficam aqui: são cifrados em `nfse_credentials`. Aqui só há dado fiscal público da empresa.
 *
 * O formato de entrada/saída é aninhado, igual às chaves sem o prefixo `nfse.`:
 * `prestador.cnpj`, `standard`, `environment`, `auto_issue`, `copy_email`, `discriminacao.plano` etc.
 */
final class NfseSettings
{
    public const GROUP = 'nfse';

    /** Contador da DPS (padrão nacional): próximo número a usar. Lido e incrementado sob FOR UPDATE. */
    public const COUNTER_KEY = 'nfse.prestador.proximo_numero_dps';

    /**
     * Fonte única das chaves: tipo, padrão e rótulo (o rótulo vai para `system_settings.label` no seeder).
     *
     * @return array<string, array{type: string, default: mixed, label: string}>
     */
    public static function definitions(): array
    {
        return [
            'nfse.prestador.cnpj'                           => ['type' => 'string', 'default' => '', 'label' => 'NFS-e: CNPJ do prestador (dona do SaaS)'],
            'nfse.prestador.inscricao_municipal'            => ['type' => 'string', 'default' => '', 'label' => 'NFS-e: inscrição municipal do prestador'],
            'nfse.prestador.codigo_municipio'               => ['type' => 'string', 'default' => '', 'label' => 'NFS-e: código IBGE do município do prestador'],
            'nfse.prestador.cnae'                           => ['type' => 'string', 'default' => '', 'label' => 'NFS-e: CNAE do serviço'],
            'nfse.prestador.item_lista_servico'             => ['type' => 'string', 'default' => '', 'label' => 'NFS-e: item da lista de serviços (LC 116)'],
            'nfse.prestador.codigo_tributario_municipio'    => ['type' => 'string', 'default' => '', 'label' => 'NFS-e: código tributário do município'],
            'nfse.prestador.codigo_tributacao_nacional_iss' => ['type' => 'string', 'default' => '', 'label' => 'NFS-e nacional: código de tributação nacional do ISS'],
            'nfse.prestador.tributacao_iss'                 => ['type' => 'integer', 'default' => 1, 'label' => 'NFS-e nacional: tributação do ISS (1 tributável, 2 imunidade, 3 exportação, 4 não incidência)'],
            'nfse.prestador.aliquota_iss'                   => ['type' => 'float', 'default' => 0.0, 'label' => 'NFS-e: alíquota do ISS (fração: 0.05 = 5%)'],
            'nfse.prestador.iss_retido'                     => ['type' => 'boolean', 'default' => false, 'label' => 'NFS-e: ISS retido pelo tomador'],
            'nfse.prestador.natureza_operacao'              => ['type' => 'string', 'default' => '1', 'label' => 'NFS-e municipal: natureza da operação (1 a 6)'],
            'nfse.prestador.optante_simples_nacional'       => ['type' => 'boolean', 'default' => false, 'label' => 'NFS-e: optante pelo Simples Nacional'],
            'nfse.prestador.regime_especial_tributacao'     => ['type' => 'nullable_string', 'default' => null, 'label' => 'NFS-e: regime especial de tributação'],
            'nfse.prestador.serie_dps'                      => ['type' => 'integer', 'default' => 1, 'label' => 'NFS-e nacional: série da DPS (1 a 49999)'],
            self::COUNTER_KEY                               => ['type' => 'integer', 'default' => 1, 'label' => 'NFS-e nacional: próximo número da DPS'],
            'nfse.standard'                                 => ['type' => 'string', 'default' => NfseStandard::Municipal->value, 'label' => 'NFS-e: padrão (municipal ou nacional)'],
            'nfse.environment'                              => ['type' => 'string', 'default' => NfseEnvironment::Homologation->value, 'label' => 'NFS-e: ambiente da Focus NFe'],
            'nfse.auto_issue'                               => ['type' => 'boolean', 'default' => false, 'label' => 'NFS-e: emitir automaticamente ao pagar a fatura'],
            'nfse.copy_email'                               => ['type' => 'nullable_string', 'default' => null, 'label' => 'NFS-e: e-mail que recebe cópia da nota'],
            'nfse.discriminacao.plano'                      => ['type' => 'string', 'default' => 'Assinatura do plano {plano} ({ciclo}) — período {periodo} — fatura {fatura}', 'label' => 'NFS-e: discriminação da nota de assinatura'],
            'nfse.discriminacao.ia'                         => ['type' => 'string', 'default' => 'Pacote de créditos de IA ({creditos} créditos) — fatura {fatura}', 'label' => 'NFS-e: discriminação da nota de pacote de IA'],
        ];
    }

    /**
     * Cria as linhas que ainda não existem em `system_settings` com o valor padrão (seeder). NUNCA sobrescreve: estas
     * chaves guardam dados fiscais digitados pelo dono (CNPJ, IM, série e contador da DPS) e rodar o seeder de novo não
     * pode zerá-los.
     */
    public static function ensureDefaults(): void
    {
        foreach (self::definitions() as $key => $definition) {
            SubscriptionSetting::query()->firstOrCreate(['key' => $key], [
                'value'       => self::serialize($definition['type'], $definition['default']),
                'type'        => self::columnType($definition['type']),
                'group'       => self::GROUP,
                'label'       => $definition['label'],
                'description' => 'Configuração da NFS-e do SaaS (Focus NFe). Edite em Manager → Nota fiscal.',
            ]);
        }
    }

    /** Valor tipado de uma chave completa (`nfse.prestador.cnpj`). */
    public function get(string $key): mixed
    {
        $definition = self::definitions()[$key] ?? throw new InvalidArgumentException("Chave de NFS-e desconhecida: {$key}");

        return self::cast($definition['type'], SubscriptionSetting::getValue($key, $definition['default']), $definition['default']);
    }

    /**
     * Dados do prestador, tipados, com as chaves sem o prefixo (`cnpj`, `codigo_municipio`, `aliquota_iss`…).
     *
     * @return array<string, mixed>
     */
    public function prestador(): array
    {
        return $this->all()['prestador'];
    }

    public function standard(): NfseStandard
    {
        return NfseStandard::tryFrom((string) $this->get('nfse.standard')) ?? NfseStandard::Municipal;
    }

    /** Sem valor válido, HOMOLOGAÇÃO: nunca cair em produção por configuração ausente ou corrompida. */
    public function environment(): NfseEnvironment
    {
        return NfseEnvironment::tryFrom((string) $this->get('nfse.environment')) ?? NfseEnvironment::Homologation;
    }

    public function autoIssue(): bool
    {
        return (bool) $this->get('nfse.auto_issue');
    }

    public function copyEmail(): ?string
    {
        return $this->get('nfse.copy_email');
    }

    public function serieDps(): int
    {
        return max(1, min(49999, (int) $this->get('nfse.prestador.serie_dps')));
    }

    /** Modelo de discriminação (`plano` ou `ia`), com os placeholders ainda por preencher. */
    public function discriminacao(string $kind): string
    {
        $text = (string) $this->get("nfse.discriminacao.{$kind}");

        return $text !== '' ? $text : (string) self::definitions()["nfse.discriminacao.{$kind}"]['default'];
    }

    /**
     * Todas as chaves, tipadas, no formato aninhado (`prestador.cnpj` → `['prestador' => ['cnpj' => …]]`).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $flat = [];

        foreach (array_keys(self::definitions()) as $key) {
            $flat[substr($key, strlen(self::GROUP) + 1)] = $this->get($key);
        }

        return Arr::undot($flat);
    }

    /**
     * Grava só as chaves conhecidas (aninhadas ou com ponto), normalizadas pelo tipo; o resto é ignorado. O contador da
     * DPS é gravado como qualquer outra chave — a validação de "não voltar atrás" é de quem chama.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, array{old: mixed, new: mixed}> o que mudou (para a auditoria)
     */
    public function save(array $values): array
    {
        $definitions = self::definitions();
        $changes     = [];

        DB::transaction(function () use ($values, $definitions, &$changes): void {
            foreach (Arr::dot($values) as $dotted => $value) {
                $key = self::GROUP . '.' . $dotted;

                if (! isset($definitions[$key])) {
                    continue;
                }

                $definition = $definitions[$key];
                $old        = $this->get($key);
                $new        = self::cast($definition['type'], $value, $definition['default']);

                if ($old === $new) {
                    continue;
                }

                SubscriptionSetting::query()->updateOrCreate(['key' => $key], [
                    'value' => self::serialize($definition['type'], $new),
                    'type'  => self::columnType($definition['type']),
                    'group' => self::GROUP,
                    'label' => $definition['label'],
                ]);

                Cache::forget("subscription_setting:{$key}");

                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        });

        return $changes;
    }

    /**
     * Chaves (sem o prefixo `nfse.`) que ainda faltam para emitir no padrão. Vazio = pronto. O que cada município exige
     * além disso (CNAE, código tributário, endereço) vem de NfseMunicipalityService e é conferido na emissão.
     *
     * @return list<string>
     */
    public function missing(?NfseStandard $standard = null): array
    {
        $standard ??= $this->standard();
        $prestador = $this->prestador();
        $missing   = [];

        $cnpj = (string) $prestador['cnpj'];

        if (strlen((string) BrazilianFormat::documentChars($cnpj)) !== 14 || ! CpfCnpj::isValid($cnpj)) {
            $missing[] = 'prestador.cnpj';
        }

        if (preg_match('/^\d{7}$/', (string) $prestador['codigo_municipio']) !== 1) {
            $missing[] = 'prestador.codigo_municipio';
        }

        $required = $standard === NfseStandard::National
            ? ['codigo_tributacao_nacional_iss']
            : ['inscricao_municipal', 'item_lista_servico'];

        foreach ($required as $field) {
            if (blank($prestador[$field])) {
                $missing[] = "prestador.{$field}";
            }
        }

        return $missing;
    }

    public function isConfigured(?NfseStandard $standard = null): bool
    {
        return $this->missing($standard) === [];
    }

    /**
     * Reserva o próximo número da DPS: lê o contador sob FOR UPDATE, devolve e incrementa. Chamar DENTRO da transação que
     * cria a nota — se ela voltar, o incremento volta junto e a numeração não tem buraco.
     *
     * @return array{series: int, number: int}
     */
    public function reserveNextDpsNumber(): array
    {
        return DB::transaction(function (): array {
            // Primeira vez: cria a linha do contador (concorrência: quem perder o INSERT só lê a do vencedor).
            DB::table('system_settings')->insertOrIgnore([
                'key'        => self::COUNTER_KEY,
                'value'      => '1',
                'type'       => 'integer',
                'group'      => self::GROUP,
                'label'      => self::definitions()[self::COUNTER_KEY]['label'],
                'is_public'  => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row    = SubscriptionSetting::query()->whereKey(self::COUNTER_KEY)->lockForUpdate()->firstOrFail();
            $number = max(1, (int) $row->value);

            $row->forceFill(['value' => (string) ($number + 1)])->save();

            Cache::forget('subscription_setting:' . self::COUNTER_KEY);

            return ['series' => $this->serieDps(), 'number' => $number];
        });
    }

    private static function cast(string $type, mixed $value, mixed $default): mixed
    {
        return match ($type) {
            'boolean'         => filter_var($value ?? $default, FILTER_VALIDATE_BOOLEAN),
            'integer'         => (int) ($value ?? $default),
            'float'           => (float) ($value ?? $default),
            'nullable_string' => ($text = trim((string) ($value ?? ''))) === '' ? null : $text,
            default           => trim((string) ($value ?? $default)),
        };
    }

    private static function serialize(string $type, mixed $value): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            default   => (string) ($value ?? ''),
        };
    }

    /** Valor da coluna `system_settings.type` (string | integer | boolean | json | float). */
    private static function columnType(string $type): string
    {
        return $type === 'nullable_string' ? 'string' : $type;
    }
}
