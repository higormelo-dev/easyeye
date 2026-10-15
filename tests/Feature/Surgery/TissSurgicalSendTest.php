<?php

declare(strict_types=1);

use App\Domains\Tiss\Enums\{TissGuideStatus, TissTransmissionStatus};
use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\TissEntityOperatorCredential;
use App\Domains\Tiss\Models\{TissGuide, TissIdempotencyKey, TissTransmission};
use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, MockTissTransportClient, TissMessageTransportContract};
use App\Domains\Tiss\Surgical\{SendSurgicalGuideService, SurgicalGuideService, SurgicalMessageXmlService};
use App\Enums\Surgery\{AuthorizationEventType, SurgicalCaseStatus};
use App\Exceptions\Surgery\SurgeryRuleException;
use Carbon\Carbon;

require_once __DIR__ . '/../../Support/TissSurgicalFixtures.php';

/*
 * M3b — envio de UMA guia cirúrgica: uma transmissão por guia, idempotência por hash do XML,
 * entrega em dúvida (timeout/5xx travam a guia e o reenvio usa a MESMA chave), erro
 * definitivo (guia editável de novo), sem retentativa automática.
 */

function stcSend(TissGuide $guide): TissTransmission
{
    return app(SendSurgicalGuideService::class)->send($guide->refresh());
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w     = stcWorld();
    $this->fake  = stcFakeTransport();
    $this->guide = stcReadyGuide($this->w);
});

it('envia: uma transmissão confirmada, guia enviada, chave concluída e evento "enviada" (tiss) no log do caso', function () {
    $transmission = stcSend($this->guide);

    expect($transmission->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and((string) $transmission->guide_id)->toBe((string) $this->guide->id)
        ->and($transmission->batch_id)->toBeNull()
        ->and($transmission->attempt)->toBe(1)
        ->and($transmission->protocol->protocol_number)->toBe('PRT-1')
        ->and($this->fake->calls)->toHaveCount(1)
        ->and($this->fake->calls[0]['type'])->toBe('SOLICITACAO_PROCEDIMENTOS')
        ->and($this->guide->refresh()->status)->toBe(TissGuideStatus::Sent);

    expect(TissIdempotencyKey::query()->where('scope', 'guide_send')->value('status'))->toBe('completed');

    $event = $this->w->case->refresh()->authorizationEvents()->where('type', AuthorizationEventType::Submitted->value)->firstOrFail();

    expect($event->channel)->toBe('tiss')
        ->and((string) $event->tiss_guide_id)->toBe((string) $this->guide->id)
        ->and($event->protocol_number)->toBe('PRT-1');
});

it('enviar de novo uma guia já confirmada NÃO chama o transporte nem duplica evento', function () {
    $first  = stcSend($this->guide);
    $second = stcSend($this->guide);

    expect((string) $second->id)->toBe((string) $first->id)
        ->and($this->fake->calls)->toHaveCount(1)
        ->and(TissTransmission::query()->where('guide_id', $this->guide->id)->count())->toBe(1)
        ->and($this->w->case->refresh()->authorizationEvents()->where('type', 'submitted')->count())->toBe(1);
});

it('só envia com o caso aguardando autorização', function () {
    $w     = stcWorld();
    $guide = stcComplete(app(SurgicalGuideService::class)->generate($w->case, TissGuideType::InpatientRequest));
    app(SurgicalMessageXmlService::class)->generate($guide);

    expect(fn () => stcSend($guide))->toThrow(SurgeryRuleException::class, __('surgery_tiss.errors.case_not_awaiting', ['status' => SurgicalCaseStatus::Draft->label()]));
    expect($this->fake->calls)->toHaveCount(0)
        ->and(TissTransmission::query()->count())->toBe(0);
});

it('SEM resposta (timeout): transmissão em dúvida, guia TRAVADA e o reenvio usa a MESMA chave e o MESMO XML', function () {
    $this->fake->queue = [StcFakeTransport::noResponse(), StcFakeTransport::accepted()];

    expect(fn () => stcSend($this->guide))->toThrow(SurgeryRuleException::class);

    $transmission = TissTransmission::query()->where('guide_id', $this->guide->id)->firstOrFail();
    $service      = app(SurgicalGuideService::class);

    expect($transmission->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($service->isLocked($this->guide->refresh()))->toBeTrue()
        ->and($this->guide->status)->toBe(TissGuideStatus::XmlGenerated)
        ->and(TissIdempotencyKey::query()->where('scope', 'guide_send')->value('status'))->toBe('uncertain');

    // Travada: não edita, não atualiza, não reabre, não cancela, não regera o XML.
    foreach ([
        fn () => $service->complete($this->guide, ['observation' => 'x']),
        fn () => $service->refresh($this->guide),
        fn () => $service->reopen($this->guide),
        fn () => $service->cancel($this->guide, 'desisti'),
    ] as $mutation) {
        expect($mutation)->toThrow(SurgeryRuleException::class, __('surgery_tiss.errors.guide_locked'));
    }

    $documentBefore = $this->guide->xml_document_id;
    expect((string) app(SurgicalMessageXmlService::class)->generate($this->guide)->id)->toBe((string) $documentBefore);

    // O caso mudou enquanto a entrega está em dúvida: o reenvio NÃO muda XML nem chave.
    $this->w->case->refresh()->forceFill(['clinical_indication' => 'Texto novo que NÃO pode ir na retomada'])->save();

    $resent = stcSend($this->guide);

    expect((string) $resent->id)->toBe((string) $transmission->id)
        ->and($resent->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($resent->attempt)->toBe(2)
        ->and($this->fake->calls)->toHaveCount(2)
        ->and($this->fake->calls[1]['key'])->toBe($this->fake->calls[0]['key'])
        ->and($this->fake->calls[1]['xml'])->toBe($this->fake->calls[0]['xml'])
        ->and(TissIdempotencyKey::query()->where('scope', 'guide_send')->value('status'))->toBe('completed');
});

it('HTTP 500 sem recusa explícita também é dúvida; 500 com "rejeitado" no corpo é veredito (erro definitivo)', function () {
    $this->fake->queue = [StcFakeTransport::http(500)];
    expect(fn () => stcSend($this->guide))->toThrow(SurgeryRuleException::class);
    expect(TissTransmission::query()->value('status'))->toBe(TissTransmissionStatus::Timeout);

    $w2                = stcWorld();
    $g2                = stcReadyGuide($w2);
    $this->fake->queue = [StcFakeTransport::http(500, 'rejected')];
    expect(fn () => stcSend($g2))->toThrow(SurgeryRuleException::class, 'HTTP 500');

    $t2 = TissTransmission::query()->where('guide_id', $g2->id)->firstOrFail();
    expect($t2->status)->toBe(TissTransmissionStatus::Failed)
        ->and($g2->refresh()->status)->toBe(TissGuideStatus::Error);
});

it('a dúvida SOBREVIVE a uma falha posterior (401 não prova que o 1º envio não chegou) e só o aceite a encerra', function () {
    $this->fake->queue = [StcFakeTransport::noResponse(), StcFakeTransport::http(401), StcFakeTransport::accepted()];

    expect(fn () => stcSend($this->guide))->toThrow(SurgeryRuleException::class);
    expect(fn () => stcSend($this->guide))->toThrow(SurgeryRuleException::class, 'continua em dúvida');

    $transmission = TissTransmission::query()->where('guide_id', $this->guide->id)->firstOrFail();

    expect($transmission->status)->toBe(TissTransmissionStatus::Timeout)
        ->and(TissIdempotencyKey::query()->where('scope', 'guide_send')->value('status'))->toBe('uncertain')
        ->and(app(SurgicalGuideService::class)->isLocked($this->guide->refresh()))->toBeTrue();

    expect(stcSend($this->guide)->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and(TissTransmission::query()->where('guide_id', $this->guide->id)->count())->toBe(1);
});

it('erro DEFINITIVO (4xx): guia em erro e editável; sem mudar nada, o reenvio retoma a mesma transmissão', function () {
    $this->fake->queue = [StcFakeTransport::http(422), StcFakeTransport::accepted()];

    expect(fn () => stcSend($this->guide))->toThrow(SurgeryRuleException::class, 'HTTP 422');

    $service = app(SurgicalGuideService::class);
    $failed  = TissTransmission::query()->where('guide_id', $this->guide->id)->firstOrFail();

    expect($failed->status)->toBe(TissTransmissionStatus::Failed)
        ->and($this->guide->refresh()->status)->toBe(TissGuideStatus::Error)
        ->and($service->isLocked($this->guide))->toBeFalse()
        ->and(TissIdempotencyKey::query()->where('scope', 'guide_send')->value('status'))->toBe('failed');

    // Editável de novo: reabre, nada muda e o reenvio reaproveita o MESMO documento/chave.
    $service->reopen($this->guide);
    expect($this->guide->refresh()->status)->toBe(TissGuideStatus::Draft);

    $resent = stcSend($this->guide);

    expect((string) $resent->id)->toBe((string) $failed->id)
        ->and($resent->attempt)->toBe(2)
        ->and($resent->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($this->fake->calls[1]['key'])->toBe($this->fake->calls[0]['key']);
});

it('depois do erro definitivo, mudar a guia gera XML novo, chave nova e NOVA transmissão', function () {
    $this->fake->queue = [StcFakeTransport::http(400)];
    expect(fn () => stcSend($this->guide))->toThrow(SurgeryRuleException::class);

    $service = app(SurgicalGuideService::class);
    $service->complete($this->guide->refresh(), ['suggested_date' => '2026-11-20']);

    $second = stcSend($this->guide);

    expect($second->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and(TissTransmission::query()->where('guide_id', $this->guide->id)->count())->toBe(2)
        ->and($this->fake->calls[1]['key'])->not->toBe($this->fake->calls[0]['key']);
});

it('duas requisições do mesmo envio: a 2ª encontra a 1ª em voo e NÃO chama o transporte', function () {
    $this->fake->during = function () {
        // Estamos DENTRO do transporte da 1ª requisição: a transmissão está 'sending'.
        $this->fake->during = null;
        expect(fn () => stcSend($this->guide))->toThrow(SurgeryRuleException::class, __('surgery_tiss.errors.transmission_in_progress'));
    };

    $transmission = stcSend($this->guide);

    expect($this->fake->calls)->toHaveCount(1)
        ->and($transmission->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and(TissTransmission::query()->where('guide_id', $this->guide->id)->count())->toBe(1);
});

it('tentativa interrompida (lease vencido) é retomada como dúvida: mesma linha e mesma chave', function () {
    $transmission = stcSend($this->guide);

    // Simula worker morto: volta a transmissão para 'sending' com o lease já vencido.
    $transmission->update(['status' => 'sending', 'responded_at' => null, 'requested_at' => now()->subMinutes(10)]);
    TissIdempotencyKey::query()->where('scope', 'guide_send')->update(['status' => 'reserved']);
    $this->guide->refresh()->update(['status' => TissGuideStatus::XmlGenerated->value]);
    $this->fake->queue = [StcFakeTransport::http(401)];

    expect(fn () => stcSend($this->guide))->toThrow(SurgeryRuleException::class);

    // A retomada falhou, mas havia dúvida pendente (a tentativa morta): continua em dúvida.
    expect($transmission->refresh()->status)->toBe(TissTransmissionStatus::Timeout)
        ->and($transmission->attempt)->toBe(2)
        ->and($this->fake->calls)->toHaveCount(2)
        ->and($this->fake->calls[1]['key'])->toBe($this->fake->calls[0]['key']);
});

it('guia cancelada ou negada não envia; guia sem erro de pré-validação gera XML, com erro NÃO', function () {
    app(SurgicalGuideService::class)->cancel($this->guide, 'pedido refeito');

    expect(fn () => stcSend($this->guide))->toThrow(SurgeryRuleException::class, __('surgery_tiss.errors.guide_closed'));
    expect($this->fake->calls)->toHaveCount(0);

    $w = stcWorld();
    $w->facility->update(['operator_code' => null]);
    stcRequested($w);
    $guide = app(SurgicalGuideService::class)->generate($w->case->refresh(), TissGuideType::InpatientRequest);

    expect(fn () => app(SurgicalMessageXmlService::class)->generate($guide))
        ->toThrow(SurgeryRuleException::class, __('surgery_tiss.errors.prevalidation_failed'));
});

it('transporte HTTP real: XML sem evidência de schema é revalidado NO LUGAR, com o mesmo hash e a mesma chave', function () {
    $real = new class() extends HttpTissTransportClient {
        public int $calls = 0;

        public function sendMessage(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, string $transactionType, array $context = []): array
        {
            $this->calls++;

            return StcFakeTransport::accepted();
        }
    };
    app()->instance(TissMessageTransportContract::class, $real);

    $document = $this->guide->xmlDocument;
    $hash     = $document->file_hash;
    $document->update(['metadata' => ['schema' => null]]); // documento "legado": sem evidência

    $transmission = stcSend($this->guide);

    expect($real->calls)->toBe(1)
        ->and($transmission->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($document->refresh()->metadata['schema'])->not->toBeNull()
        ->and($document->file_hash)->toBe($hash);
});

it('mock de verdade (padrão do projeto): cada guia responde no envelope do XSD e o caso fecha quando as duas respondem', function () {
    app()->instance(TissMessageTransportContract::class, new class() extends MockTissTransportClient {
        public function sendMessage(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, string $transactionType, array $context = []): array
        {
            $number = $transactionType === 'ENVIO_ANEXO' ? 'AUT-OPME-1' : 'AUT-998877';

            return parent::sendMessage($credential, $xml, $idempotencyKey, $transactionType, $context + ['mock_authorization' => [
                'status' => '1', 'operator_guide_number' => $number, 'password' => 'SENHA1', 'valid_until' => '2026-12-31',
            ]]);
        }
    });

    // Internação: a resposta vale para os procedimentos; o OPME ainda espera, o caso segue aguardando.
    stcSend($this->guide);

    $case = $this->w->case->refresh();

    expect($case->status)->toBe(SurgicalCaseStatus::AwaitingAuthorization)
        ->and($case->procedures->first()->authorized_quantity)->toBe(1)
        ->and($case->materials->first()->authorized_quantity)->toBeNull()
        ->and($this->guide->refresh()->status)->toBe(TissGuideStatus::Authorized)
        ->and($this->guide->guide_number_operator)->toBe('AUT-998877');

    // Anexo de OPME (envelope `recebimentoAnexo/.../anexoOPME`): fecha o pedido.
    $opme = stcSend(stcOpmeGuide($this->w));

    $case = $this->w->case->refresh();

    expect($opme->status)->toBe(TissTransmissionStatus::Acknowledged)
        ->and($case->status)->toBe(SurgicalCaseStatus::Authorized)
        ->and($case->authorization_number)->toBe('AUT-998877 / AUT-OPME-1')
        ->and($case->authorization_valid_until->toDateString())->toBe('2026-12-31')
        ->and($case->materials->first()->authorized_quantity)->toBe(1);

    $types = $case->authorizationEvents->pluck('type')->map->value->all();
    expect($types)->toContain('submitted')->toContain('authorized');
    expect($case->authorizationEvents->firstWhere('type', AuthorizationEventType::Authorized)->channel)->toBe('tiss');
});
