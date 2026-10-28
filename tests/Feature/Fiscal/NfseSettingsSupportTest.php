<?php

use App\Enums\Fiscal\{NfseEnvironment, NfseStandard};
use App\Models\SubscriptionSetting;
use App\Support\Fiscal\NfseSettings;
use Database\Seeders\SubscriptionSettingSeeder;
use Illuminate\Support\Facades\{Cache, DB};

require_once __DIR__ . '/Support/NfseFixtures.php';

/**
 * Base da configuração global da NFS-e (NfseSettings): tipos, cache, padrão seguro e o contador da DPS. As rotas do
 * Manager que gravam estas chaves são testadas em NfseSettingsTest.
 */
describe('leitura', function () {
    it('devolve os padrões quando nada foi gravado, sempre em HOMOLOGAÇÃO', function () {
        $settings = new NfseSettings();

        expect($settings->standard())->toBe(NfseStandard::Municipal)
            ->and($settings->environment())->toBe(NfseEnvironment::Homologation)
            ->and($settings->autoIssue())->toBeFalse()
            ->and($settings->copyEmail())->toBeNull()
            ->and($settings->serieDps())->toBe(1)
            ->and($settings->isConfigured())->toBeFalse()
            ->and($settings->discriminacao('plano'))->toContain('{plano}')->toContain('{fatura}')
            ->and($settings->discriminacao('ia'))->toContain('{valor}')->not->toContain('{creditos}');
    });

    it('cai em homologação e município quando o valor gravado é inválido', function () {
        SubscriptionSetting::setValue('nfse.environment', 'producao-quase');
        SubscriptionSetting::setValue('nfse.standard', 'lixo');

        expect((new NfseSettings())->environment())->toBe(NfseEnvironment::Homologation)
            ->and((new NfseSettings())->standard())->toBe(NfseStandard::Municipal);
    });

    it('expõe tudo no formato aninhado que a tela consome (contrato com o front)', function () {
        $all = (new NfseSettings())->all();

        expect(array_keys($all))->toEqualCanonicalizing(['prestador', 'standard', 'environment', 'auto_issue', 'copy_email', 'discriminacao'])
            ->and(array_keys($all['prestador']))->toEqualCanonicalizing([
                'cnpj', 'inscricao_municipal', 'codigo_municipio', 'cnae', 'item_lista_servico', 'codigo_tributario_municipio',
                'codigo_tributacao_nacional_iss', 'tributacao_iss', 'aliquota_iss', 'iss_retido', 'natureza_operacao',
                'optante_simples_nacional', 'regime_especial_tributacao', 'serie_dps', 'proximo_numero_dps',
            ])
            ->and(array_keys($all['discriminacao']))->toEqualCanonicalizing(['plano', 'ia'])
            ->and($all['prestador']['iss_retido'])->toBeFalse()
            ->and($all['prestador']['aliquota_iss'])->toBe(0.0)
            ->and($all['prestador']['tributacao_iss'])->toBe(1)
            ->and($all['prestador']['regime_especial_tributacao'])->toBeNull();
    });
});

describe('gravação', function () {
    it('grava tipado, ignora chave desconhecida e devolve o que mudou', function () {
        $settings = new NfseSettings();

        $changes = $settings->save([
            'prestador'   => ['cnpj' => ' 11222333000181 ', 'aliquota_iss' => '0.05', 'iss_retido' => '1', 'tributacao_iss' => '2', 'segredo' => 'x'],
            'standard'    => 'nacional',
            'environment' => 'producao',
            'auto_issue'  => true,
            'copy_email'  => 'contador@dono.test',
            'invented'    => 'ignorada',
        ]);

        expect(array_keys($changes))->toEqualCanonicalizing([
            'nfse.prestador.cnpj', 'nfse.prestador.aliquota_iss', 'nfse.prestador.iss_retido', 'nfse.prestador.tributacao_iss',
            'nfse.standard', 'nfse.environment', 'nfse.auto_issue', 'nfse.copy_email',
        ])
            ->and($changes['nfse.standard'])->toBe(['old' => 'municipal', 'new' => 'nacional'])
            ->and($settings->prestador()['cnpj'])->toBe('11222333000181')
            ->and($settings->prestador()['aliquota_iss'])->toBe(0.05)
            ->and($settings->prestador()['iss_retido'])->toBeTrue()
            ->and($settings->prestador()['tributacao_iss'])->toBe(2)
            ->and($settings->standard())->toBe(NfseStandard::National)
            ->and($settings->environment())->toBe(NfseEnvironment::Production)
            ->and($settings->autoIssue())->toBeTrue()
            ->and($settings->copyEmail())->toBe('contador@dono.test');

        expect(SubscriptionSetting::query()->where('key', 'like', 'nfse.%')->count())->toBe(8)
            ->and(SubscriptionSetting::query()->whereKey('nfse.prestador.cnpj')->first())
            ->group->toBe('nfse')
            ->type->toBe('string')
            ->and(SubscriptionSetting::query()->whereKey('nfse.auto_issue')->value('value'))->toBe('1');
    });

    it('invalida o cache da chave que grava (lê o valor novo na hora)', function () {
        $settings = new NfseSettings();

        expect($settings->autoIssue())->toBeFalse(); // popula o cache com o padrão

        $settings->save(['auto_issue' => true]);

        expect($settings->autoIssue())->toBeTrue()
            ->and(Cache::has('subscription_setting:nfse.auto_issue'))->toBeTrue();

        $settings->save(['auto_issue' => false]);

        expect($settings->autoIssue())->toBeFalse();
    });

    it('não grava nem reporta quando o valor não mudou', function () {
        $settings = new NfseSettings();
        $settings->save(['prestador' => ['cnpj' => NFSE_TEST_CNPJ]]);

        expect($settings->save(['prestador' => ['cnpj' => NFSE_TEST_CNPJ]]))->toBe([])
            ->and($settings->save(['auto_issue' => false]))->toBe([]); // já é o padrão
    });

    it('limpa um campo opcional com texto vazio', function () {
        $settings = new NfseSettings();
        $settings->save(['copy_email' => 'x@y.test', 'prestador' => ['regime_especial_tributacao' => '3']]);

        $settings->save(['copy_email' => '  ', 'prestador' => ['regime_especial_tributacao' => null]]);

        expect($settings->copyEmail())->toBeNull()
            ->and($settings->prestador()['regime_especial_tributacao'])->toBeNull();
    });

    it('recusa ler chave que não existe', function () {
        expect(fn () => (new NfseSettings())->get('nfse.prestador.token'))->toThrow(InvalidArgumentException::class);
    });
});

describe('prestador configurado', function () {
    it('lista o que falta por padrão', function () {
        $settings = new NfseSettings();

        expect($settings->missing(NfseStandard::Municipal))->toEqualCanonicalizing([
            'prestador.cnpj', 'prestador.codigo_municipio', 'prestador.inscricao_municipal', 'prestador.item_lista_servico',
        ])
            ->and($settings->missing(NfseStandard::National))->toEqualCanonicalizing([
                'prestador.cnpj', 'prestador.codigo_municipio', 'prestador.codigo_tributacao_nacional_iss',
            ]);
    });

    it('fica pronto quando os campos do padrão estão preenchidos', function () {
        nfseConfigurePrestador();

        $settings = new NfseSettings();

        expect($settings->isConfigured())->toBeTrue()
            ->and($settings->isConfigured(NfseStandard::National))->toBeTrue();

        $settings->save(['prestador' => ['inscricao_municipal' => '']]);

        expect($settings->isConfigured(NfseStandard::Municipal))->toBeFalse()
            ->and($settings->isConfigured(NfseStandard::National))->toBeTrue();
    });

    it('trata CNPJ inválido e IBGE malformado como faltando', function () {
        nfseConfigurePrestador(['prestador' => ['cnpj' => '11222333000182', 'codigo_municipio' => '355030']]);

        expect((new NfseSettings())->missing())->toEqualCanonicalizing(['prestador.cnpj', 'prestador.codigo_municipio']);
    });

    it('aceita CNPJ alfanumérico (IN RFB 2.229/2024)', function () {
        nfseConfigurePrestador(['prestador' => ['cnpj' => '12.ABC.345/01DE-35']]);

        expect((new NfseSettings())->isConfigured())->toBeTrue();
    });
});

describe('contador da DPS', function () {
    it('entrega 1, 2, 3… com a série configurada e persiste o próximo', function () {
        $settings = new NfseSettings();
        $settings->save(['prestador' => ['serie_dps' => 3]]);

        expect($settings->reserveNextDpsNumber())->toBe(['series' => 3, 'number' => 1])
            ->and($settings->reserveNextDpsNumber())->toBe(['series' => 3, 'number' => 2])
            ->and($settings->reserveNextDpsNumber())->toBe(['series' => 3, 'number' => 3])
            ->and($settings->get(NfseSettings::COUNTER_KEY))->toBe(4)
            ->and(SubscriptionSetting::query()->whereKey(NfseSettings::COUNTER_KEY)->value('value'))->toBe('4');
    });

    it('continua do número que o dono definiu na configuração', function () {
        $settings = new NfseSettings();
        $settings->save(['prestador' => ['proximo_numero_dps' => 250]]);

        expect($settings->reserveNextDpsNumber()['number'])->toBe(250)
            ->and($settings->reserveNextDpsNumber()['number'])->toBe(251);
    });

    it('devolve o número quando a transação da nota volta (sem buraco na numeração)', function () {
        $settings = new NfseSettings();

        try {
            DB::transaction(function () use ($settings) {
                expect($settings->reserveNextDpsNumber()['number'])->toBe(1);

                throw new RuntimeException('falha depois de reservar');
            });
        } catch (RuntimeException) {
        }

        Cache::flush();

        expect($settings->reserveNextDpsNumber()['number'])->toBe(1);
    });

    it('nunca lê o contador do cache (lê sob FOR UPDATE)', function () {
        $settings = new NfseSettings();
        $settings->get(NfseSettings::COUNTER_KEY); // aquece o cache com o padrão (1)

        DB::table('system_settings')->updateOrInsert(['key' => NfseSettings::COUNTER_KEY], [
            'value' => '99', 'type' => 'integer', 'group' => 'nfse', 'created_at' => now(), 'updated_at' => now(),
        ]);

        expect($settings->reserveNextDpsNumber()['number'])->toBe(99);
    });

    it('trava a linha do contador na leitura', function () {
        DB::enableQueryLog();

        (new NfseSettings())->reserveNextDpsNumber();

        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        expect($sql)->toContain('for update');
    });
});

describe('seeder', function () {
    it('cria as chaves que faltam com grupo, tipo e rótulo', function () {
        (new SubscriptionSettingSeeder())->run();

        $rows = SubscriptionSetting::query()->where('group', 'nfse')->get()->keyBy('key');

        expect($rows)->toHaveCount(count(NfseSettings::definitions()))
            ->and($rows['nfse.auto_issue']->value)->toBe('0')
            ->and($rows['nfse.auto_issue']->type)->toBe('boolean')
            ->and($rows['nfse.environment']->value)->toBe('homologacao')
            ->and($rows[NfseSettings::COUNTER_KEY]->value)->toBe('1')
            ->and($rows['nfse.prestador.cnpj']->label)->not->toBeEmpty();
    });

    it('rodar de novo NUNCA sobrescreve o que o dono configurou', function () {
        (new SubscriptionSettingSeeder())->run();

        $settings = new NfseSettings();
        $settings->save(['prestador' => ['cnpj' => NFSE_TEST_CNPJ, 'proximo_numero_dps' => 42], 'auto_issue' => true, 'environment' => 'producao']);

        (new SubscriptionSettingSeeder())->run();
        Cache::flush();

        expect($settings->prestador()['cnpj'])->toBe(NFSE_TEST_CNPJ)
            ->and($settings->prestador()['proximo_numero_dps'])->toBe(42)
            ->and($settings->autoIssue())->toBeTrue()
            ->and($settings->environment())->toBe(NfseEnvironment::Production);
    });
});
