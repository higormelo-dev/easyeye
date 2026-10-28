<?php

declare(strict_types=1);

use App\Domains\Tiss\Actions\AttachGuideToBatchAction;
use App\Domains\Tiss\Enums\{TissGuideStatus, TissGuideType, TissReturnStatus};
use App\Domains\Tiss\Exceptions\TissGuideTypeNotBatchableException;
use App\Domains\Tiss\Models\{TissBatch, TissBatchGuide, TissReturn};
use App\Domains\Tiss\Parsers\TissReturnParser;
use App\Domains\Tiss\Services\ReceiveTissResponseService;
use App\Domains\Tiss\Surgical\SurgicalAuthorizationResponseService;
use App\Domains\Tiss\Xml\Builders\V202603TissXmlBuilder;
use App\Enums\Surgery\{AuthorizationEventType, SurgicalCaseStatus};
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Services\Surgery\SurgeryAuthorizationService;
use Carbon\Carbon;

require_once __DIR__ . '/../../Support/TissSurgicalFixtures.php';

/*
 * M3b — resposta de AUTORIZAÇÃO da operadora (síncrona, anexada ou importada) vira evento no
 * log do caso: idempotente, sem regredir estado, e a guia cirúrgica nunca entra em lote.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    // Pedido só de procedimento: a guia de internação cobre tudo (o caso com OPME, em duas guias,
    // fica em TissSurgicalOpmeResponseTest).
    $this->w     = stcWorld([], opme: false);
    $this->guide = stcReadyGuide($this->w);
    $this->svc   = app(SurgicalAuthorizationResponseService::class);

    $events       = fn () => $this->w->case->refresh()->authorizationEvents()->get();
    $this->events = $events;
});

it('autorizada: vira evento "autorizada" (tiss, com a guia), o caso autoriza e a guia acompanha', function () {
    $result = $this->svc->applyFromXml($this->guide, stcAuthXml($this->guide->guide_number_provider, '1'), 'transmission');

    $case  = $this->w->case->refresh();
    $event = ($this->events)()->firstWhere('type', AuthorizationEventType::Authorized);

    expect($result)->toMatchArray(['applied' => true, 'outcome' => 'recorded'])
        ->and($case->status)->toBe(SurgicalCaseStatus::Authorized)
        ->and($case->authorization_number)->toBe('AUT-5544')
        ->and($case->authorization_valid_until->toDateString())->toBe('2026-12-20')
        ->and($event->channel)->toBe('tiss')
        ->and((string) $event->tiss_guide_id)->toBe((string) $this->guide->id)
        ->and($this->guide->refresh()->status)->toBe(TissGuideStatus::Authorized)
        ->and($this->guide->guide_number_operator)->toBe('AUT-5544');
});

it('o MESMO XML aplicado de novo não duplica evento nem regride a guia', function () {
    $xml = stcAuthXml($this->guide->guide_number_provider, '1');

    $this->svc->applyFromXml($this->guide, $xml, 'transmission');
    $again = $this->svc->applyFromXml($this->guide->refresh(), $xml, 'upload');

    expect($again)->toMatchArray(['applied' => false, 'outcome' => 'duplicate'])
        ->and(($this->events)()->where('type', AuthorizationEventType::Authorized)->count())->toBe(1)
        ->and($this->guide->refresh()->status)->toBe(TissGuideStatus::Authorized);
});

it('negada: motivo da operadora no evento; resposta repetida não duplica', function () {
    $xml = stcAuthXml($this->guide->guide_number_provider, '3', ['denial' => true, 'obs' => 'Sem cobertura']);

    $this->svc->applyFromXml($this->guide, $xml, 'upload');
    $this->svc->applyFromXml($this->guide->refresh(), $xml, 'upload');

    $denied = ($this->events)()->where('type', AuthorizationEventType::Denied);

    expect($this->w->case->refresh()->status)->toBe(SurgicalCaseStatus::Denied)
        ->and($denied)->toHaveCount(1)
        ->and($denied->first()->denial_reason)->toContain('1802')->toContain('Sem cobertura')
        ->and($this->guide->refresh()->status)->toBe(TissGuideStatus::Denied);
});

it('parcial: casa os itens por TUSS e autoriza só o que a operadora liberou', function () {
    // Pedido com dois procedimentos: a operadora libera 1 de 2 do primeiro e nenhum do segundo.
    $w = stcWorld(['procedures' => [
        ['description' => 'Facoemulsificação', 'tuss_code' => '30306019', 'quantity' => 2],
        ['description' => 'Vitrectomia', 'tuss_code' => '30307058', 'quantity' => 1],
    ]], opme: false);
    $guide = stcReadyGuide($w);

    $this->svc->applyFromXml($guide, stcAuthXml($guide->guide_number_provider, '7', ['services' => ['30306019' => [2, 1], '30307058' => [1, 0]]]), 'transmission');

    $case = $w->case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::PartiallyAuthorized)
        ->and($case->procedures->firstWhere('tuss_code', '30306019')->authorized_quantity)->toBe(1)
        ->and($case->procedures->firstWhere('tuss_code', '30307058')->authorized_quantity)->toBe(0);
});

it('parcial sem itens casáveis NÃO inventa quantidades: vira anotação para registro manual', function () {
    $this->svc->applyFromXml($this->guide, stcAuthXml($this->guide->guide_number_provider, '7', ['services' => ['99999999' => [1, 1]]]), 'upload');

    $case = $this->w->case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and(($this->events)()->where('type', AuthorizationEventType::PartiallyAuthorized))->toHaveCount(0)
        ->and(($this->events)()->where('type', AuthorizationEventType::Note)->first()->note)->toContain('parcial');
});

it('"em análise" só anota (uma vez) e não muda o estado', function () {
    $xml = stcAuthXml($this->guide->guide_number_provider, '2');

    $this->svc->applyFromXml($this->guide, $xml, 'upload');
    $this->svc->applyFromXml($this->guide->refresh(), $xml, 'upload');

    expect($this->w->case->refresh()->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and(($this->events)()->where('type', AuthorizationEventType::Note)->where('channel', 'tiss'))->toHaveCount(1)
        ->and($this->guide->refresh()->status)->not->toBe(TissGuideStatus::Authorized);
});

it('resposta que não cabe no estado do caso (já autorizado à mão) NÃO muda nada: vira anotação', function () {
    app(SurgeryAuthorizationService::class)->record($this->w->case->refresh(), ['type' => 'authorized', 'authorization_number' => 'MANUAL-1']);

    $this->svc->applyFromXml($this->guide->refresh(), stcAuthXml($this->guide->guide_number_provider, '3', ['denial' => true]), 'upload');

    $case = $this->w->case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::Authorized)
        ->and($case->authorization_number)->toBe('MANUAL-1')
        ->and(($this->events)()->where('type', AuthorizationEventType::Denied))->toHaveCount(0)
        ->and(($this->events)()->where('type', AuthorizationEventType::Note)->first()->note)->toContain('não aplicada');
});

it('mensagem de erro da operadora vira anotação; XML de outra guia é recusado; XML qualquer não é resposta', function () {
    $error = '<mensagemTISS xmlns="http://www.ans.gov.br/padroes/tiss/schemas"><operadoraParaPrestador><autorizacaoServicos><mensagemErro><codigoGlosa>1001</codigoGlosa><descricaoGlosa>Beneficiário inexistente</descricaoGlosa></mensagemErro></autorizacaoServicos></operadoraParaPrestador></mensagemTISS>';

    // A mensagem de erro não traz número de guia: só a resposta SÍNCRONA (amarrada à transmissão) a aplica.
    $this->svc->applyFromXml($this->guide, $error, 'transmission');

    expect(($this->events)()->where('type', AuthorizationEventType::Note)->first()->note)->toContain('1001')->toContain('Beneficiário inexistente');

    expect(fn () => $this->svc->applyFromXml($this->guide, stcAuthXml('GUI-OUTRA', '1'), 'upload'))
        ->toThrow(SurgeryRuleException::class, __('surgery_tiss.errors.response_other_guide'));

    // XML anexado à mão SEM número de guia não prova de qual guia veio: recusado, nada gravado.
    $before = ($this->events)()->count();

    expect(fn () => $this->svc->applyFromXml($this->guide, stcAuthXml('', '1'), 'upload'))
        ->toThrow(SurgeryRuleException::class, __('surgery_tiss.errors.response_other_guide'));
    expect(($this->events)()->count())->toBe($before)
        ->and($this->w->case->refresh()->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization);

    // A resposta SÍNCRONA do envio já está amarrada à guia pela própria transmissão: aceita sem número.
    expect($this->svc->applyFromXml($this->guide, stcAuthXml('', '1'), 'transmission'))->toMatchArray(['applied' => true, 'outcome' => 'recorded'])
        ->and($this->w->case->refresh()->status)->toBe(SurgicalCaseStatus::Authorized);

    expect($this->svc->applyFromXml($this->guide, '<ansTISS><x/></ansTISS>', 'upload'))->toBeNull();
    expect((new TissReturnParser())->parseAuthorization('não é xml'))->toBeNull();
});

it('importação de arquivo: vira evento, o retorno fica processado e o MESMO arquivo é "já importado"', function () {
    $xml    = stcAuthXml($this->guide->guide_number_provider, '1');
    $import = app(ReceiveTissResponseService::class);

    $first = $import->receive((string) $this->w->entity->id, (string) $this->guide->operator_id, $xml, processSynchronously: true);

    expect($first->alreadyImported)->toBeFalse()
        ->and($first->return->return_type)->toBe('authorization_response')
        ->and($first->return->status)->toBe(TissReturnStatus::Processed)
        ->and($this->w->case->refresh()->status)->toBe(SurgicalCaseStatus::Authorized);

    $second = $import->receive((string) $this->w->entity->id, (string) $this->guide->operator_id, $xml, processSynchronously: true);

    expect($second->alreadyImported)->toBeTrue()
        ->and((string) $second->return->id)->toBe((string) $first->return->id)
        ->and(TissReturn::query()->count())->toBe(1)
        ->and(($this->events)()->where('type', AuthorizationEventType::Authorized))->toHaveCount(1);
});

it('importação de resposta de guia que não existe nesta clínica fica em erro (retomável), sem evento', function () {
    $import = app(ReceiveTissResponseService::class);

    $received = $import->receive((string) $this->w->entity->id, (string) $this->guide->operator_id, stcAuthXml('GUI-INEXISTENTE', '1'), processSynchronously: true);

    expect($received->return->status)->toBe(TissReturnStatus::Error)
        ->and(($this->events)()->where('type', AuthorizationEventType::Authorized))->toHaveCount(0);
});

it('guia cirúrgica nunca entra em lote: o anexo recusa e o builder de lote não a converte em SP-SADT', function () {
    $batch = TissBatch::query()->create([
        'entity_id'       => $this->w->entity->id, 'operator_id' => $this->guide->operator_id, 'batch_number' => 'LOT-202610-0001',
        'reference_month' => '2026-10', 'status' => 'open', 'version_id' => $this->guide->version_id,
    ]);

    expect(fn () => app(AttachGuideToBatchAction::class)($batch, $this->guide))
        ->toThrow(InvalidArgumentException::class, 'mensagem avulsa');

    // Mesmo que o vínculo seja forçado por outro caminho, o builder de lote recusa com erro explícito.
    TissBatchGuide::query()->create(['entity_id' => $this->w->entity->id, 'batch_id' => $batch->id, 'guide_id' => $this->guide->id, 'attached_at' => now()]);

    expect(fn () => app(V202603TissXmlBuilder::class)->buildBatch($batch->refresh()))
        ->toThrow(TissGuideTypeNotBatchableException::class);
});

it('o tipo certo: internação e OPME são mensagens avulsas; consulta e SP-SADT seguem em lote', function () {
    expect(TissGuideType::InpatientRequest->isBatchable())->toBeFalse()
        ->and(TissGuideType::OpmeAttachment->isBatchable())->toBeFalse()
        ->and(TissGuideType::Consultation->isBatchable())->toBeTrue()
        ->and(TissGuideType::Sadt->isBatchable())->toBeTrue()
        ->and(TissGuideType::InpatientRequest->transactionType())->toBe('SOLICITACAO_PROCEDIMENTOS')
        ->and(TissGuideType::OpmeAttachment->transactionType())->toBe('ENVIO_ANEXO')
        ->and(TissGuideType::Sadt->transactionType())->toBeNull()
        ->and(TissGuideType::OpmeAttachment->documentType())->toBe('opme_attachment')
        ->and(TissGuideType::Consultation->documentType())->toBe('batch_submission');
});
