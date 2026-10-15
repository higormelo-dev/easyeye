<?php

declare(strict_types=1);

/*
 * Fixtures compartilhadas dos testes TISS do módulo cirúrgico (M3b). Funções globais com
 * prefixo `stc` — Pest carrega todos os arquivos no mesmo processo.
 */

use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\{TissEntityOperatorCredential, TissGuide};
use App\Domains\Tiss\Services\Transport\{TissMessageTransportContract, TissTransportContract};
use App\Domains\Tiss\Surgical\{SurgicalGuideService, SurgicalMessageXmlService};
use App\Models\Surgery\{SurgeryFacility, SurgicalCase};
use App\Services\Surgery\{SurgeryAuthorizationService, SurgicalCaseService};

/**
 * Mundo cirúrgico com convênio REAL (ANS), hospital-dia com código na operadora e caso
 * já enviado para autorização (aguardando). Clínica em SP; cirurgião com CRM e CBO.
 *
 * @param array<string, mixed> $caseOverrides
 */
function stcWorld(array $caseOverrides = [], bool $opme = true, bool $withModule = true): object
{
    seedActiveTissVersion();

    $w = surgeryWorld($withModule);

    $w->entity->update(['state' => 'SP', 'cnes' => '2345678']);
    $w->doctor->update(['record' => '123456', 'cbo_code' => '225265']);
    $w->doctor->person->update(['cellphone' => '11987654321']);
    $w->patient->update(['covenant_id' => $w->insurer->id, 'card_number' => '0099887766']);

    $w->facility = SurgeryFacility::query()->create([
        'entity_id'     => $w->entity->id,
        'name'          => 'HOSPITAL OFTALMOLOGICO SANTA LUZIA',
        'type'          => 'hospital',
        'cnes'          => '1234567',
        'operator_code' => 'HOSP-001',
    ]);

    $payload = array_merge([
        'patient_id'          => (string) $w->patient->id,
        'surgeon_doctor_id'   => (string) $w->doctor->id,
        'title'               => 'Facoemulsificação com LIO',
        'eye'                 => 'od',
        'regime'              => 'day_hospital',
        'urgency'             => 'elective',
        'days_requested'      => 1,
        'needs_opme'          => $opme,
        'surgery_facility_id' => (string) $w->facility->id,
        'clinical_indication' => 'Catarata senil nuclear com perda importante de acuidade visual.',
        'cid_codes'           => [['code' => 'H25.1', 'description' => 'Catarata senil nuclear']],
        'procedures'          => [['description' => 'Facoemulsificação com implante de LIO', 'tuss_code' => '30306019', 'quantity' => 1]],
        'materials'           => $opme ? [[
            'description'             => 'Lente intraocular monofocal dobrável',
            'quantity'                => 1,
            'manufacturer'            => 'ALCON',
            'anvisa_registration'     => '80117580005',
            'manufacturer_ref'        => 'SN60WF',
            'unit_value'              => 850.00,
            'technical_justification' => 'Paciente com catarata densa; LIO necessária para reabilitação visual.',
        ]] : [],
    ], $caseOverrides);

    $w->case = app(SurgicalCaseService::class)->create((string) $w->entity->id, $payload);

    return $w;
}

/** Envia o pedido à autorização (status "aguardando") e devolve o caso relido. */
function stcRequested(object $w): SurgicalCase
{
    return app(SurgeryAuthorizationService::class)->request($w->case);
}

/** Completa o que o caso não carrega (TUSS do material, data sugerida) para a guia passar na pré-validação. */
function stcComplete(TissGuide $guide, array $extra = []): TissGuide
{
    $items = $guide->guide_type === TissGuideType::OpmeAttachment
        ? $guide->items->map(fn ($i) => ['id' => (string) $i->id, 'tuss_code' => '70017031'])->all()
        : [];

    return app(SurgicalGuideService::class)->complete($guide, array_merge(['suggested_date' => '2026-11-03', 'items' => $items], $extra));
}

/**
 * Transporte de mensagem avulsa roteirizado: cada chamada consome o próximo resultado da
 * fila (ou o padrão "aceito"). `calls` guarda o que foi enviado (chave de idempotência e
 * XML) — NUNCA conteúdo de paciente em log; aqui é só teste.
 */
class StcFakeTransport implements TissMessageTransportContract, TissTransportContract
{
    /** @var list<array<string, mixed>> */
    public array $queue = [];

    /** @var list<array{key: string, xml: string, type: string}> */
    public array $calls = [];

    /** @var (callable(): void)|null executado DENTRO da chamada ao transporte (simula outra sessão em voo) */
    public $during;

    public function sendMessage(
        TissEntityOperatorCredential $credential,
        string $xml,
        string $idempotencyKey,
        string $transactionType,
        array $context = [],
    ): array {
        $this->calls[] = ['key' => $idempotencyKey, 'xml' => $xml, 'type' => $transactionType];

        if ($this->during !== null) {
            ($this->during)();
        }

        return array_shift($this->queue) ?? self::accepted();
    }

    public function sendBatch(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, array $context = []): array
    {
        return self::accepted();
    }

    /** @return array<string, mixed> */
    public static function accepted(?string $body = null, string $protocol = 'PRT-1'): array
    {
        return ['ok' => true, 'status_code' => 200, 'response_body' => $body ?? '<ansTISS><operadoraParaPrestador><protocoloRecebimento><numeroProtocolo>' . $protocol . '</numeroProtocolo><statusProtocolo>Aceito</statusProtocolo></protocoloRecebimento></operadoraParaPrestador></ansTISS>', 'protocol_number' => $protocol, 'protocol_status' => 'accepted', 'error_code' => null, 'error_message' => null, 'response_time_ms' => 10];
    }

    /** @return array<string, mixed> exceção de transporte / timeout: SEM resposta */
    public static function noResponse(): array
    {
        return ['ok' => false, 'status_code' => null, 'response_body' => null, 'protocol_number' => null, 'protocol_status' => null, 'error_code' => 'transport_exception', 'error_message' => 'cURL error 28', 'response_time_ms' => 30000];
    }

    /** @return array<string, mixed> */
    public static function http(int $status, ?string $protocolStatus = null): array
    {
        return ['ok' => false, 'status_code' => $status, 'response_body' => 'erro', 'protocol_number' => null, 'protocol_status' => $protocolStatus, 'error_code' => 'http_' . $status, 'error_message' => 'HTTP ' . $status, 'response_time_ms' => 20];
    }
}

/** Instala o transporte roteirizado no container (vale para o resto do teste). */
function stcFakeTransport(): StcFakeTransport
{
    $fake = new StcFakeTransport();

    app()->instance(TissMessageTransportContract::class, $fake);
    app()->instance(TissTransportContract::class, $fake);

    return $fake;
}

/** Caso aguardando autorização + guia de internação completa e com XML gerado, pronta para enviar. */
function stcReadyGuide(object $w): TissGuide
{
    stcRequested($w);

    $guide = stcComplete(app(SurgicalGuideService::class)->generate($w->case->refresh(), TissGuideType::InpatientRequest));
    app(SurgicalMessageXmlService::class)->generate($guide);

    return $guide->refresh();
}

/** Resposta de autorização da operadora (statusSolicitacao 1 autorizado, 3 negado, 7 parcial...). */
function stcAuthXml(string $guideNumber, string $status, array $extra = []): string
{
    $operator = $extra['operator_number'] ?? 'AUT-5544';
    $denial   = isset($extra['denial']) ? '<motivosNegativa><codigoGlosa>1802</codigoGlosa></motivosNegativa>' : '';
    $services = '';

    foreach ($extra['services'] ?? [] as $code => [$requested, $authorized]) {
        $services .= '<servicoAutorizado><sequencialItem>1</sequencialItem><procedimento><codigoTabela>22</codigoTabela><codigoProcedimento>' . $code . '</codigoProcedimento><descricaoProcedimento>X</descricaoProcedimento></procedimento><quantidadeSolicitada>' . $requested . '</quantidadeSolicitada><quantidadeAutorizada>' . $authorized . '</quantidadeAutorizada></servicoAutorizado>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?><mensagemTISS xmlns="http://www.ans.gov.br/padroes/tiss/schemas"><operadoraParaPrestador><autorizacaoServicos><autorizacaoInternacao><autorizacaoDosServicos>'
        . '<dadosAutorizacao><numeroGuiaPrestador>' . $guideNumber . '</numeroGuiaPrestador><numeroGuiaOperadora>' . $operator . '</numeroGuiaOperadora><dataAutorizacao>2026-10-15</dataAutorizacao><senha>' . ($extra['password'] ?? 'SENHA9') . '</senha><dataValidadeSenha>' . ($extra['valid'] ?? '2026-12-20') . '</dataValidadeSenha></dadosAutorizacao>'
        . '<statusSolicitacao>' . $status . '</statusSolicitacao>' . $denial . ($services !== '' ? '<servicosAutorizados>' . $services . '</servicosAutorizados>' : '')
        . '<observacao>' . ($extra['obs'] ?? 'ok') . '</observacao></autorizacaoDosServicos></autorizacaoInternacao></autorizacaoServicos></operadoraParaPrestador></mensagemTISS>';
}

/** Guia do anexo de OPME completa e com XML gerado (o caso já precisa estar aguardando autorização). */
function stcOpmeGuide(object $w): TissGuide
{
    $guide = stcComplete(app(SurgicalGuideService::class)->generate($w->case->refresh(), TissGuideType::OpmeAttachment));
    app(SurgicalMessageXmlService::class)->generate($guide);

    return $guide->refresh();
}

/**
 * Resposta de autorização do ANEXO DE OPME no envelope do XSD TISS 4.03
 * (`recebimentoAnexo/protocoloRecebimentoAnexo/anexosClinicos/anexoOPME`), válida contra o
 * schema oficial: cabeçalho, epílogo e itens em `servicosAutorizadosOPME`.
 * `extra`: operator_number, password, valid, denial, denial_text, obs, services [código => [pedido, autorizado]].
 */
function stcOpmeAuthXml(string $guideNumber, string $status, array $extra = []): string
{
    $denial   = isset($extra['denial']) ? '<motivosNegativa><motivoNegativa><codigoGlosa>1802</codigoGlosa><descricaoGlosa>' . ($extra['denial_text'] ?? 'Material sem cobertura') . '</descricaoGlosa></motivoNegativa></motivosNegativa>' : '';
    $services = '';
    $sequence = 0;

    foreach ($extra['services'] ?? [] as $code => [$requested, $authorized]) {
        $services .= '<servicosAutorizadosOPME><sequencialItem>' . ++$sequence . '</sequencialItem><procedimento><codigoTabela>19</codigoTabela><codigoProcedimento>' . $code . '</codigoProcedimento><descricaoProcedimento>LIO</descricaoProcedimento></procedimento>'
            . '<quantidadeSolicitada>' . $requested . '</quantidadeSolicitada><quantidadeAutorizada>' . $authorized . '</quantidadeAutorizada></servicosAutorizadosOPME>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?><mensagemTISS xmlns="http://www.ans.gov.br/padroes/tiss/schemas">'
        . '<cabecalho><identificacaoTransacao><tipoTransacao>PROTOCOLO_RECEBIMENTO_ANEXO</tipoTransacao><sequencialTransacao>1</sequencialTransacao><dataRegistroTransacao>2026-10-15</dataRegistroTransacao><horaRegistroTransacao>10:00:00</horaRegistroTransacao></identificacaoTransacao>'
        . '<origem><registroANS>326305</registroANS></origem><destino><identificacaoPrestador><codigoPrestadorNaOperadora>0001</codigoPrestadorNaOperadora></identificacaoPrestador></destino><Padrao>4.03.00</Padrao></cabecalho>'
        . '<operadoraParaPrestador><recebimentoAnexo><protocoloRecebimentoAnexo>'
        . '<nrProtocoloRecebimento>PRT-OPME-1</nrProtocoloRecebimento><dataEnvioAnexo>2026-10-15</dataEnvioAnexo><numeroLote>1</numeroLote><registroANS>326305</registroANS>'
        . '<dadosPrestador><codigoPrestadorNaOperadora>0001</codigoPrestadorNaOperadora></dadosPrestador><qtAnexosClinicos>1</qtAnexosClinicos>'
        . '<anexosClinicos><anexoOPME>'
        . '<dadosAutorizacao><numeroGuiaPrestador>' . $guideNumber . '</numeroGuiaPrestador><numeroGuiaOperadora>' . ($extra['operator_number'] ?? 'AUT-OPME-77') . '</numeroGuiaOperadora><dataAutorizacao>2026-10-15</dataAutorizacao><senha>' . ($extra['password'] ?? 'SENHAOP') . '</senha><dataValidadeSenha>' . ($extra['valid'] ?? '2026-11-30') . '</dataValidadeSenha></dadosAutorizacao>'
        . '<dadosBeneficiario><numeroCarteira>0099887766</numeroCarteira><atendimentoRN>N</atendimentoRN></dadosBeneficiario><nomebeneficiario>PACIENTE DE TESTE</nomebeneficiario>'
        . '<statusSolicitacao>' . $status . '</statusSolicitacao>' . $denial . $services
        . '</anexoOPME></anexosClinicos>'
        . '<observacao>' . ($extra['obs'] ?? 'recebido') . '</observacao>'
        . '</protocoloRecebimentoAnexo></recebimentoAnexo></operadoraParaPrestador>'
        . '<epilogo><hash>' . md5($guideNumber . $status) . '</hash></epilogo></mensagemTISS>';
}
