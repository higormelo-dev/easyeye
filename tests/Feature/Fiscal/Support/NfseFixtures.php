<?php

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Fiscal\{NfseEnvironment, NfseStandard, NfseStatus};
use App\Models\Billing\Invoice;
use App\Models\{Entity, Plan, Subscription, User};
use App\Models\Fiscal\{NfseCredential, NfseDocument};
use App\Services\Fiscal\{FocusNfeClient, NfseMunicipalityService};
use App\Support\Fiscal\NfseSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Fixtures da NFS-e do SaaS (tests/Feature/Fiscal)
|--------------------------------------------------------------------------
| Incluído por `require_once` nos arquivos de teste da pasta (mesmo padrão de tests/Pest.php).
*/

/** CNPJ válido e conhecido (11.222.333/0001-81) usado como documento da clínica pagadora e do prestador. */
const NFSE_TEST_CNPJ = '11222333000181';

/**
 * Clínica pagadora com documento e endereço completos: o que a NFS-e exige do tomador. O CNPJ vem da factory (válido e
 * único por clínica — `entities.national_registration` é único); passe `national_registration` para fixar um.
 */
function nfseClinic(array $overrides = []): Entity
{
    return Entity::factory()->create(array_merge([
        'is_client'  => true,
        'name'       => 'Clinica Oftalmo Teste',
        'zipcode'    => '01310100',
        'address'    => 'Avenida Paulista',
        'number'     => '1000',
        'complement' => 'Sala 12',
        'district'   => 'Bela Vista',
        'city'       => 'Sao Paulo',
        'state'      => 'SP',
        'ibge_code'  => '3550308',
        'email'      => 'financeiro@clinica.test',
        'telephone'  => '1133334444',
        'cellphone'  => '11999998888',
    ], $overrides));
}

/**
 * Fatura de assinatura paga. O plano e a assinatura existem (como no pagamento real); `$overrides` troca qualquer coluna
 * da fatura.
 */
function nfsePaidInvoice(?Entity $clinic = null, array $overrides = []): Invoice
{
    $clinic ??= nfseClinic();
    $plan = Plan::factory()->create(['name' => 'Plano Pro', 'price' => 199.90]);

    $subscription = Subscription::factory()->for($clinic)->for($plan)->create();

    return Invoice::query()->create(array_merge([
        'entity_id'       => $clinic->id,
        'subscription_id' => $subscription->id,
        'plan_id'         => $plan->id,
        'gateway_code'    => 'asaas',
        'reference'       => 'INV-' . strtoupper(Str::random(10)),
        'period_start'    => '2026-10-01',
        'period_end'      => '2026-10-31',
        'due_at'          => '2026-10-01 23:59:59',
        'paid_at'         => '2026-10-01 10:00:00',
        'amount'          => 199.90,
        'currency'        => 'BRL',
        'status'          => InvoiceStatus::Paid->value,
        'billing_reason'  => 'subscription_cycle',
    ], $overrides));
}

/** Linha de nota pronta para os testes de modelo/estado (sem passar pelo serviço de emissão). */
function nfseDocument(Invoice $invoice, array $overrides = []): NfseDocument
{
    return NfseDocument::query()->create(array_merge([
        'invoice_id'     => $invoice->id,
        'entity_id'      => $invoice->entity_id,
        'environment'    => NfseEnvironment::Homologation->value,
        'standard'       => NfseStandard::Municipal->value,
        'status'         => NfseStatus::PendingSend->value,
        'amount'         => $invoice->amount,
        'currency'       => $invoice->currency,
        'payer_snapshot' => ['name' => 'CLINICA OFTALMO TESTE', 'document' => '11444777000161'],
        'source'         => 'manual',
    ], $overrides));
}

/** Credencial com tokens de mentira reconhecíveis (para provar que nunca vazam). */
function nfseCredential(NfseEnvironment $environment = NfseEnvironment::Homologation, array $overrides = []): NfseCredential
{
    return NfseCredential::query()->create(array_merge([
        'environment'    => $environment->value,
        'token'          => 'tok-empresa-' . $environment->value . '-SEGREDO',
        'master_token'   => 'tok-master-' . $environment->value . '-SEGREDO',
        'webhook_token'  => 'wh-token-' . $environment->value . '-' . Str::random(24),
        'webhook_secret' => 'wh-secret-' . $environment->value . '-' . Str::random(24),
    ], $overrides));
}

/** Prestador configurado para os dois padrões (a dona do SaaS). */
function nfseConfigurePrestador(array $overrides = []): void
{
    (new NfseSettings())->save(array_replace_recursive([
        'prestador' => [
            'cnpj'                           => NFSE_TEST_CNPJ,
            'inscricao_municipal'            => '12345678',
            'codigo_municipio'               => '3550308',
            'cnae'                           => '6201501',
            'item_lista_servico'             => '01.07',
            'codigo_tributario_municipio'    => '62015',
            'codigo_tributacao_nacional_iss' => '010701',
            'tributacao_iss'                 => 1,
            'aliquota_iss'                   => 0.05,
            'iss_retido'                     => false,
            'natureza_operacao'              => '1',
            'optante_simples_nacional'       => true,
            'serie_dps'                      => 1,
            'proximo_numero_dps'             => 1,
        ],
        'standard'    => 'municipal',
        'environment' => 'homologacao',
        'auto_issue'  => false,
    ], $overrides));
}

/**
 * Tudo que a emissão exige pronto: prestador configurado e token do ambiente (homologação por padrão). `$settings`
 * sobrescreve a configuração (aninhada, como em NfseSettings::save).
 */
function nfseReady(array $settings = [], NfseEnvironment $environment = NfseEnvironment::Homologation): void
{
    nfseConfigurePrestador($settings);

    if (NfseCredential::query()->where('environment', $environment->value)->doesntExist()) {
        nfseCredential($environment);
    }
}

/** Grava no cache o que a Focus sabe do município do prestador (as guardas de emissão leem só o cache). */
function nfseCacheMunicipality(array $focus = [], string $ibge = '3550308', NfseEnvironment $environment = NfseEnvironment::Homologation): void
{
    Http::fake(['*/v2/municipios/*' => Http::response(array_replace([
        'codigo_municipio'                             => $ibge, 'nome_municipio' => 'Sao Paulo', 'sigla_uf' => 'SP', 'nfse_habilitada' => true,
        'possui_cancelamento_nfse'                     => true, 'endereco_obrigatorio_nfse' => null, 'cpf_cnpj_obrigatorio_nfse' => null,
        'codigo_cnae_obrigatorio_nfse'                 => null, 'item_lista_servico_obrigatorio_nfse' => null,
        'codigo_tributario_municipio_obrigatorio_nfse' => null, 'status_nfse' => 'ativo',
    ], $focus), 200)]);

    app(NfseMunicipalityService::class)->obligations($ibge, $environment, refresh: true);
}

/* Focus NFe: hosts e respostas EXATAS dos exemplos da documentação (briefing, lidos em 18/10/2026). */

const NFSE_XML = '<?xml version="1.0" encoding="UTF-8"?><CompNfse><Nfse><InfNfse><Numero>1</Numero></InfNfse></Nfse></CompNfse>';

const FOCUS_HOMOLOG = 'https://homologacao.focusnfe.com.br';
const FOCUS_PROD    = 'https://api.focusnfe.com.br';
const FOCUS_TOKEN   = 'tok-empresa-SEGREDO-123';

/* Respostas EXATAS dos exemplos da documentação da Focus (briefing, lidas em 18/10/2026). */
function focusProcessing(): array
{
    return ['cnpj_prestador' => '12345678000123', 'ref' => '123456', 'numero_rps' => '1', 'serie_rps' => '1', 'tipo_rps' => '1', 'status' => 'processando_autorizacao'];
}

function focusAuthorized(): array
{
    return [
        'cnpj_prestador'          => '12345678000123', 'ref' => '123456', 'numero_rps' => '1', 'serie_rps' => '1', 'tipo_rps' => '1',
        'status'                  => 'autorizado', 'numero' => '1', 'codigo_verificacao' => 'ABC123', 'data_emissao' => '2025-01-01T10:00:00-03:00',
        'url'                     => 'https://api.focusnfe.com.br/notas_fiscais_servico/chave_nfse.html',
        'caminho_xml_nota_fiscal' => '/arquivos/12345678901_123/202501/XMLsNFSe/chave_nfse.xml',
        'url_danfse'              => 'https://focusnfe.s3.sa-east-1.amazonaws.com/arquivos/12345678901_123/202501/DANFSEs/chave_nfse.pdf',
    ];
}

function focusClient(NfseEnvironment $environment = NfseEnvironment::Homologation): FocusNfeClient
{
    return FocusNfeClient::make($environment, FOCUS_TOKEN, 'corr-123');
}

function focusBasic(): string
{
    return 'Basic ' . base64_encode(FOCUS_TOKEN . ':');
}

/** Sessão do Manager (entidade do SaaS) com o perfil dado. */
function nfseManager(string $rule = 'admin'): mixed
{
    $user = User::factory()->create();
    createEntityUser(test()->saas, $user, $rule);

    return test()->actingAs($user)->withSession(['selected_entity_id' => test()->saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => $rule]);
}
