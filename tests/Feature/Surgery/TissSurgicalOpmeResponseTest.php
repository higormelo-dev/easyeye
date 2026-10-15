<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\{TissGuideStatus, TissReturnStatus, TissTransmissionStatus};
use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\Parsers\TissReturnParser;
use App\Domains\Tiss\Services\ReceiveTissResponseService;
use App\Domains\Tiss\Surgical\{SendSurgicalGuideService, SurgicalAuthorizationResponseService, SurgicalGuideSource};
use App\Enums\Surgery\{AuthorizationEventType, AuthorizationStatus, SurgicalCaseStatus};
use App\Models\Surgery\{SurgicalAuthorizationEvent, SurgicalCase};
use App\Services\Surgery\Sections\AuthorizationSection;
use App\Services\Surgery\SurgeryAuthorizationService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

require_once __DIR__ . '/../../Support/TissSurgicalFixtures.php';

/*
 * Resposta de autorização por GUIA. O pedido com OPME vai em duas mensagens (internação =
 * procedimentos; anexo de OPME = materiais) e cada resposta vale só para os itens da própria guia:
 * o caso fecha quando nenhum item fica sem resposta. Antes, a aprovação da internação autorizava
 * os materiais também e tirava o caso de "aguardando", barrando o envio do OPME. E o envelope da
 * resposta do OPME (recebimentoAnexo/anexoOPME) nem era reconhecido.
 */

/**
 * Mundo com OPME, internação pronta e (opcional) pedido de procedimentos próprio.
 *
 * @param array<string, mixed> $caseOverrides
 *
 * @return array{0: object, 1: TissGuide}
 */
function ogsWorld(array $caseOverrides = []): array
{
    $w = stcWorld($caseOverrides);

    return [$w, stcReadyGuide($w)];
}

/** Aplica a resposta SÍNCRONA (amarrada à transmissão) de uma guia. */
function ogsApply(TissGuide $guide, string $xml): array
{
    return app(SurgicalAuthorizationResponseService::class)->applyFromXml($guide->refresh(), $xml, 'transmission') ?? [];
}

function ogsCase(object $w): SurgicalCase
{
    return $w->case->refresh();
}

/** @return Collection<int, SurgicalAuthorizationEvent> */
function ogsEvents(object $w): Collection
{
    return ogsCase($w)->authorizationEvents()->get();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->fake = stcFakeTransport();
});

// ── O envelope do anexo de OPME ─────────────────────────────────────────────

it('lê o envelope do anexo de OPME (recebimentoAnexo/anexoOPME): situação, número, senha, validade e itens', function () {
    $parsed = (new TissReturnParser())->parseAuthorization(
        stcOpmeAuthXml('GUI-OPME-1', '7', ['services' => ['70017031' => [2, 1], '70017032' => [1, 0]], 'obs' => 'Parte liberada']),
    );

    expect($parsed)->toMatchArray([
        'kind'                  => 'authorization',
        'guide_number_provider' => 'GUI-OPME-1',
        'guide_number_operator' => 'AUT-OPME-77',
        'password'              => 'SENHAOP',
        'authorization_date'    => '2026-10-15',
        'valid_until'           => '2026-11-30',
        'status_code'           => '7',
        'status'                => 'partially_authorized',
        'observation'           => 'Parte liberada', // a observação do recibo fica fora do anexoOPME
    ])->and($parsed['authorized_services'])->toBe([
        ['code' => '70017031', 'requested' => 2, 'authorized' => 1],
        ['code' => '70017032', 'requested' => 1, 'authorized' => 0],
    ]);
});

it('a fixture do OPME é o formato real: valida limpa contra o XSD oficial TISS 4.03', function () {
    $previous = libxml_use_internal_errors(true);
    $dom      = new DOMDocument();
    $dom->loadXML(stcOpmeAuthXml('GUI-OPME-1', '1', ['services' => ['70017031' => [1, 1]]]));

    $valid  = $dom->schemaValidate(storage_path('app/tiss/schemas/202603/tissV4_03_00.xsd'));
    $errors = array_map(fn (LibXMLError $e) => trim($e->message), libxml_get_errors());

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($errors)->toBe([])->and($valid)->toBeTrue();
});

it('negativa e mensagem de erro do anexo de OPME são lidas; anexo de outra especialidade não é autorização cirúrgica', function () {
    $parser = new TissReturnParser();

    expect($parser->parseAuthorization(stcOpmeAuthXml('GUI-OPME-1', '3', ['denial' => true])))->toMatchArray([
        'kind'                => 'authorization',
        'status'              => 'denied',
        'denial_codes'        => ['1802'],
        'denial_descriptions' => ['Material sem cobertura'],
    ]);

    $error = '<mensagemTISS xmlns="http://www.ans.gov.br/padroes/tiss/schemas"><operadoraParaPrestador><recebimentoAnexo><mensagemErro><codigoGlosa>1001</codigoGlosa><descricaoGlosa>Beneficiário inexistente</descricaoGlosa></mensagemErro></recebimentoAnexo></operadoraParaPrestador></mensagemTISS>';

    expect($parser->parseAuthorization($error))->toMatchArray(['kind' => 'error', 'error' => ['code' => '1001', 'description' => 'Beneficiário inexistente']]);

    $chemo = '<mensagemTISS xmlns="http://www.ans.gov.br/padroes/tiss/schemas"><operadoraParaPrestador><recebimentoAnexo><protocoloRecebimentoAnexo><nrProtocoloRecebimento>1</nrProtocoloRecebimento><anexosClinicos><anexoQuimio><dadosAutorizacao><numeroGuiaPrestador>X</numeroGuiaPrestador></dadosAutorizacao><statusSolicitacao>1</statusSolicitacao></anexoQuimio></anexosClinicos></protocoloRecebimentoAnexo></recebimentoAnexo></operadoraParaPrestador></mensagemTISS>';

    expect($parser->parseAuthorization($chemo))->toBeNull();
});

it('anexar a resposta do OPME pela aba aplica a autorização (antes era recusada como "não é resposta")', function () {
    [$w, $inpatient] = ogsWorld();

    ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '1'));

    $opme = stcOpmeGuide($w);

    surgeryAs($w, 'secretary')->post(route('panel.surgery.tiss.guides.send', ['surgicalCase' => $w->case, 'guide' => $opme]))->assertRedirect();

    $file = UploadedFile::fake()->createWithContent('resposta-opme.xml', stcOpmeAuthXml($opme->guide_number_provider, '1'));

    surgeryAs($w, 'secretary')
        ->post(route('panel.surgery.tiss.guides.response', ['surgicalCase' => $w->case, 'guide' => $opme]), ['xml_file' => $file])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(ogsCase($w)->status)->toBe(SurgicalCaseStatus::Authorized)
        ->and($opme->refresh()->status)->toBe(TissGuideStatus::Authorized);
});

it('importar a resposta do OPME pela tela de retorno aplica a autorização, uma vez só', function () {
    [$w, $inpatient] = ogsWorld();

    ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '1'));

    $opme   = stcOpmeGuide($w);
    $xml    = stcOpmeAuthXml($opme->guide_number_provider, '1');
    $import = app(ReceiveTissResponseService::class);

    $first = $import->receive((string) $w->entity->id, (string) $opme->operator_id, $xml, processSynchronously: true);

    expect($first->alreadyImported)->toBeFalse()
        ->and($first->return->return_type)->toBe('authorization_response')
        ->and($first->return->status)->toBe(TissReturnStatus::Processed)
        ->and(ogsCase($w)->status)->toBe(SurgicalCaseStatus::Authorized);

    $second = $import->receive((string) $w->entity->id, (string) $opme->operator_id, $xml, processSynchronously: true);

    expect($second->alreadyImported)->toBeTrue()
        ->and(ogsEvents($w)->where('type', AuthorizationEventType::Authorized))->toHaveCount(1);
});

// ── Escopo da aprovação: só os itens da guia respondida ────────────────────

it('a aprovação da internação autoriza só os procedimentos: o caso segue aguardando e o OPME ainda é enviado', function () {
    [$w, $inpatient] = ogsWorld();

    $result = ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '1'));
    $case   = ogsCase($w);

    expect($result)->toMatchArray(['applied' => true, 'outcome' => 'recorded'])
        ->and($case->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and($case->authorization_status)->toBe(AuthorizationStatus::Pending)
        ->and($case->procedures->first()->authorized_quantity)->toBe(1)
        ->and($case->materials->first()->authorized_quantity)->toBeNull() // o material é da OUTRA guia
        ->and($case->authorization_number)->toBe('AUT-5544')
        ->and($case->authorization_valid_until->toDateString())->toBe('2026-12-20')
        ->and($case->authorized_at)->toBeNull()
        ->and($inpatient->refresh()->status)->toBe(TissGuideStatus::Authorized);

    // O log guarda a resposta como anotação (com número, validade, guia e itens): não é desfecho do caso.
    $events = ogsEvents($w);
    $note   = $events->where('type', AuthorizationEventType::Note)->first();

    expect($events->where('type', AuthorizationEventType::Authorized))->toHaveCount(0)
        ->and($note->authorization_number)->toBe('AUT-5544')
        ->and((string) $note->tiss_guide_id)->toBe((string) $inpatient->id)
        ->and($note->authorized_items)->toEqual([['kind' => 'procedure', 'id' => (string) $case->procedures->first()->id, 'quantity' => 1]]) // jsonb não guarda a ordem das chaves
        ->and($note->note)->toContain('Lente intraocular monofocal dobrável');

    // O anexo de OPME ainda pode ser enviado — e leva o número e a senha da autorização da internação.
    $sent = app(SendSurgicalGuideService::class)->send(stcOpmeGuide($w)->refresh());

    expect($sent->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($this->fake->calls)->toHaveCount(1)
        ->and($this->fake->calls[0]['type'])->toBe('ENVIO_ANEXO')
        ->and($this->fake->calls[0]['xml'])->toContain('>AUT-5544<')->toContain('>SENHA9<');
});

it('o OPME respondido depois fecha o pedido: números lado a lado, menor validade e um evento de desfecho', function () {
    [$w, $inpatient] = ogsWorld();

    ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '1'));

    $opme = stcOpmeGuide($w);

    expect(ogsApply($opme, stcOpmeAuthXml($opme->guide_number_provider, '1', ['valid' => '2026-11-30'])))
        ->toMatchArray(['applied' => true, 'outcome' => 'recorded']);

    $case = ogsCase($w);

    expect($case->status)->toBe(SurgicalCaseStatus::Authorized)
        ->and($case->authorization_status)->toBe(AuthorizationStatus::Authorized)
        ->and($case->authorization_number)->toBe('AUT-5544 / AUT-OPME-77')
        ->and($case->authorization_valid_until->toDateString())->toBe('2026-11-30') // a cirurgia precisa das duas vigentes
        ->and($case->authorized_at)->not->toBeNull()
        ->and($case->procedures->first()->authorized_quantity)->toBe(1)
        ->and($case->materials->first()->authorized_quantity)->toBe(1)
        ->and($inpatient->refresh()->status)->toBe(TissGuideStatus::Authorized)
        ->and($opme->refresh()->status)->toBe(TissGuideStatus::Authorized);

    $outcome = ogsEvents($w)->where('type', AuthorizationEventType::Authorized);

    expect($outcome)->toHaveCount(1)
        ->and($outcome->first()->authorized_items)->toHaveCount(2); // o desfecho leva o pedido inteiro
});

it('a ordem não importa: o OPME respondido antes também só autoriza os materiais', function () {
    [$w, $inpatient] = ogsWorld();

    $opme = stcOpmeGuide($w);

    ogsApply($opme, stcOpmeAuthXml($opme->guide_number_provider, '1'));

    $case = ogsCase($w);

    expect($case->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and($case->materials->first()->authorized_quantity)->toBe(1)
        ->and($case->procedures->first()->authorized_quantity)->toBeNull();

    ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '1'));

    expect(ogsCase($w)->status)->toBe(SurgicalCaseStatus::Authorized)
        ->and(ogsCase($w)->authorization_number)->toBe('AUT-OPME-77 / AUT-5544');
});

it('internação parcial + OPME autorizado = parcialmente autorizado, com a quantidade parcial preservada', function () {
    [$w, $inpatient] = ogsWorld(['procedures' => [
        ['description' => 'Facoemulsificação', 'tuss_code' => '30306019', 'quantity' => 2],
        ['description' => 'Vitrectomia', 'tuss_code' => '30307058', 'quantity' => 1],
    ]]);

    ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '7', ['services' => ['30306019' => [2, 1], '30307058' => [1, 1]]]));

    $case = ogsCase($w);

    expect($case->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization) // falta o OPME
        ->and($case->procedures->firstWhere('tuss_code', '30306019')->authorized_quantity)->toBe(1)
        ->and($case->procedures->firstWhere('tuss_code', '30307058')->authorized_quantity)->toBe(1)
        ->and($case->materials->first()->authorized_quantity)->toBeNull();

    $opme = stcOpmeGuide($w);

    ogsApply($opme, stcOpmeAuthXml($opme->guide_number_provider, '1'));

    $case = ogsCase($w);

    expect($case->status)->toBe(SurgicalCaseStatus::PartiallyAuthorized)
        ->and($case->authorization_status)->toBe(AuthorizationStatus::PartiallyAuthorized)
        ->and($case->procedures->firstWhere('tuss_code', '30306019')->authorized_quantity)->toBe(1)
        ->and($case->materials->first()->authorized_quantity)->toBe(1);

    $outcome = ogsEvents($w)->whereIn('type', [AuthorizationEventType::Authorized, AuthorizationEventType::PartiallyAuthorized]);

    expect($outcome)->toHaveCount(1)
        ->and($outcome->first()->type)->toBe(AuthorizationEventType::PartiallyAuthorized);
});

it('negativa do OPME depois da internação autorizada nega o caso; o recurso começa uma rodada nova do zero', function () {
    [$w, $inpatient] = ogsWorld();

    ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '1'));

    $opme = stcOpmeGuide($w);

    ogsApply($opme, stcOpmeAuthXml($opme->guide_number_provider, '3', ['denial' => true]));

    $case = ogsCase($w);

    expect($case->status)->toBe(SurgicalCaseStatus::Denied)
        ->and($case->authorization_number)->toBeNull()
        ->and($opme->refresh()->status)->toBe(TissGuideStatus::Denied);

    // Recurso: nada do que a operadora respondeu na rodada anterior vale para o pedido reenviado.
    app(SurgeryAuthorizationService::class)->request($case);

    $case = ogsCase($w);

    expect($case->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and($case->procedures->first()->authorized_quantity)->toBeNull()
        ->and($case->materials->first()->authorized_quantity)->toBeNull();
});

it('resposta parcial só casa os itens da guia que têm código TUSS: o item sem código segue sem resposta, nunca negado por palpite', function () {
    [$w, $inpatient] = ogsWorld(['procedures' => [
        ['description' => 'Facoemulsificação', 'tuss_code' => '30306019', 'quantity' => 2],
        ['description' => 'Vitrectomia', 'tuss_code' => '30307058', 'quantity' => 1],
    ]]);

    // Cadastro incompleto: a vitrectomia ficou sem código na guia, então a resposta não tem como citá-la.
    $inpatient->items()->get()->firstWhere('tuss_code', '30307058')->update(['tuss_code' => '']);

    ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '7', ['services' => ['30306019' => [2, 1]]]));

    $case = ogsCase($w);

    expect($case->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and($case->procedures->firstWhere('tuss_code', '30306019')->authorized_quantity)->toBe(1)
        ->and($case->procedures->firstWhere('tuss_code', '30307058')->authorized_quantity)->toBeNull();
});

it('registro manual depois da resposta de uma guia vale só para o que falta e não refaz o que a operadora já respondeu', function () {
    [$w, $inpatient] = ogsWorld(['procedures' => [
        ['description' => 'Facoemulsificação', 'tuss_code' => '30306019', 'quantity' => 2],
    ]]);

    // A operadora libera 1 de 2 da facoemulsificação, por TISS.
    ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '7', ['services' => ['30306019' => [2, 1]]]));

    $case    = ogsCase($w);
    $section = (new AuthorizationSection(app(SurgeryAuthorizationService::class)))->props($case, ['manage' => true]);

    // A tela mostra o que já foi respondido como travado e o que falta como aberto.
    expect(collect($section['items'])->pluck('locked', 'kind')->all())->toBe(['procedure' => true, 'material' => false]);

    // O OPME veio por telefone: registro manual "autorizado" (sem escopo) cobre só o material.
    app(SurgeryAuthorizationService::class)->record($case, ['type' => 'authorized', 'authorization_number' => 'MANUAL-1', 'valid_until' => '2026-12-31']);

    $case = ogsCase($w);

    expect($case->status)->toBe(SurgicalCaseStatus::PartiallyAuthorized)
        ->and($case->procedures->first()->authorized_quantity)->toBe(1) // não voltou para 2
        ->and($case->materials->first()->authorized_quantity)->toBe(1)
        ->and($case->authorization_number)->toBe('AUT-5544 / MANUAL-1');
});

it('registro manual PARCIAL depois da resposta de uma guia ignora a citação do que já foi respondido e vale só para o que falta', function () {
    [$w, $inpatient] = ogsWorld([
        'procedures' => [['description' => 'Facoemulsificação', 'tuss_code' => '30306019', 'quantity' => 2]],
        'materials'  => [['description' => 'Anel de tensão', 'quantity' => 2, 'manufacturer' => 'X', 'technical_justification' => 'Subluxação do cristalino']],
    ]);

    // Por TISS, a operadora libera 1 de 2 da facoemulsificação.
    ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '7', ['services' => ['30306019' => [2, 1]]]));

    $case = ogsCase($w);

    // O anel veio por telefone, 1 de 2. A lista cita também a facoemulsificação (com 2): isso não refaz o que a operadora respondeu.
    app(SurgeryAuthorizationService::class)->record($case, [
        'type'                 => 'partially_authorized',
        'authorization_number' => 'MANUAL-2',
        'authorized_items'     => [
            ['kind' => 'procedure', 'id' => (string) $case->procedures->first()->id, 'quantity' => 2],
            ['kind' => 'material', 'id' => (string) $case->materials->first()->id, 'quantity' => 1],
        ],
    ]);

    $case = ogsCase($w);

    expect($case->status)->toBe(SurgicalCaseStatus::PartiallyAuthorized)
        ->and($case->procedures->first()->authorized_quantity)->toBe(1)
        ->and($case->materials->first()->authorized_quantity)->toBe(1);
});

it('autorização vencida que é renovada começa rodada nova: nada da anterior é somado', function () {
    [$w, $inpatient] = ogsWorld();

    ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '1', ['valid' => '2026-11-30']));

    $opme = stcOpmeGuide($w);

    ogsApply($opme, stcOpmeAuthXml($opme->guide_number_provider, '1', ['valid' => '2026-11-30']));

    expect(ogsCase($w)->status)->toBe(SurgicalCaseStatus::Authorized);

    $this->travelTo(Carbon::parse('2026-12-01 09:00:00'));
    app(SurgeryAuthorizationService::class)->expireDue();

    expect(ogsCase($w)->authorization_status)->toBe(AuthorizationStatus::Expired);

    // A operadora renova só a internação, com outro número.
    ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '1', ['operator_number' => 'AUT-NOVA', 'password' => 'SENHA-N', 'valid' => '2027-01-10']));

    $case = ogsCase($w);

    expect($case->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and($case->authorization_status)->toBe(AuthorizationStatus::Pending)
        ->and($case->authorization_number)->toBe('AUT-NOVA')
        ->and($case->authorization_valid_until->toDateString())->toBe('2027-01-10')
        ->and($case->procedures->first()->authorized_quantity)->toBe(1)
        ->and($case->materials->first()->authorized_quantity)->toBeNull(); // o OPME antigo venceu
});

it('o anexo de OPME gerado com a autorização VENCIDA não leva o número velho', function () {
    [$w, $inpatient] = ogsWorld();

    ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '1', ['valid' => '2026-11-30']));

    $opme = stcOpmeGuide($w);
    ogsApply($opme, stcOpmeAuthXml($opme->guide_number_provider, '1', ['valid' => '2026-11-30']));

    $this->travelTo(Carbon::parse('2026-12-01 09:00:00'));
    app(SurgeryAuthorizationService::class)->expireDue();

    expect(ogsCase($w)->authorization_status)->toBe(AuthorizationStatus::Expired);

    $source = app(SurgicalGuideSource::class)->build(ogsCase($w), TissGuideType::OpmeAttachment, $inpatient->refresh());

    expect($source['data']['authorization'])->toBe(['operator_guide_number' => null, 'password' => null, 'date' => null]);
});

it('a mesma resposta aplicada de novo não duplica nada, nem a anotação nem o desfecho', function () {
    [$w, $inpatient] = ogsWorld();

    $internacao = stcAuthXml($inpatient->guide_number_provider, '1');

    ogsApply($inpatient, $internacao);

    expect(ogsApply($inpatient, $internacao))->toMatchArray(['applied' => false, 'outcome' => 'duplicate'])
        ->and(ogsEvents($w)->where('type', AuthorizationEventType::Note)->where('channel', 'tiss'))->toHaveCount(1);

    $opme  = stcOpmeGuide($w);
    $anexo = stcOpmeAuthXml($opme->guide_number_provider, '1');

    ogsApply($opme, $anexo);

    expect(ogsApply($opme, $anexo))->toMatchArray(['applied' => false, 'outcome' => 'duplicate'])
        ->and(ogsEvents($w)->where('type', AuthorizationEventType::Authorized))->toHaveCount(1)
        ->and(ogsCase($w)->authorization_number)->toBe('AUT-5544 / AUT-OPME-77');
});

it('guia cujos itens não existem mais no pedido não inventa autorização: vira anotação', function () {
    [$w, $inpatient] = ogsWorld();

    $w->case->procedures()->delete();

    $result = ogsApply($inpatient, stcAuthXml($inpatient->guide_number_provider, '1'));
    $case   = ogsCase($w);

    expect($result['outcome'])->toBe('noted')
        ->and($case->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and($case->materials->first()->authorized_quantity)->toBeNull()
        ->and(ogsEvents($w)->where('type', AuthorizationEventType::Note)->last()->note)->toContain('AUT-5544');
});
