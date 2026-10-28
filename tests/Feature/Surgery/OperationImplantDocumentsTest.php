<?php

declare(strict_types=1);

require_once __DIR__ . '/OperationFixtures.php';

use App\Enums\Surgery\SurgeryDocumentType;
use App\Models\Surgery\{SurgeryDocumentIssue, SurgicalImplant};
use Carbon\Carbon;
use Illuminate\Support\Str;

/*
 * Fase 4 / M4 — documentos do implante: registro congelado (um por implante,
 * reemissão mantém o histórico), folha de etiquetas e cartão do implante.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    $this->w = surgeryWorld();

    [$this->product, $this->lot] = sopIol($this->w);
    $this->case                  = sopCase($this->w);
    $this->implantId             = sopStore($this, $this->case, sopImplantPayload($this->product, $this->lot))->json('implant_id');
});

function sopRecord(object $t, string $who = 'doctor', ?string $implantId = null)
{
    return surgeryAs($t->w, $who)->postJson(route('panel.surgery.operation.implants.record', [$t->case, $implantId ?? $t->implantId]));
}

it('emite o registro do implante como documento congelado, com os dados do implante', function () {
    sopRecord($this)->assertOk();

    $issue = SurgeryDocumentIssue::query()->firstOrFail();

    expect($issue->type)->toBe(SurgeryDocumentType::ImplantRecord)
        ->and($issue->payload['scope'])->toBe($this->implantId)
        ->and($issue->content_hash)->toHaveLength(64)
        ->and((string) $issue->patient_id)->toBe((string) $this->w->patient->id)
        ->and((string) $issue->issued_by_doctor_id)->toBe((string) $this->w->doctor->id)
        ->and($issue->payload['signer']['registry'])->toBe('12345')
        ->and(collect($issue->payload['data']['items'])->pluck('value')->all())
        ->toContain('LIO Monofocal', 'ACME', 'L-001', 'SN-0001', '80123456789');
});

it('o registro congela o conteúdo: mudar o estoque depois não muda o documento', function () {
    sopRecord($this)->assertOk();
    $before = SurgeryDocumentIssue::query()->firstOrFail();

    $this->lot->update(['lot_number' => 'OUTRO', 'expiry_date' => '2031-01-01']);
    $this->product->update(['name' => 'Renomeada']);

    $after = SurgeryDocumentIssue::query()->firstOrFail();

    expect($after->content_hash)->toBe($before->content_hash)
        ->and(collect($after->payload['data']['items'])->pluck('value')->all())->toContain('L-001')->not->toContain('OUTRO');
    expect(fn () => $after->update(['content' => 'adulterado']))->toThrow(LogicException::class);
});

it('mesmo conteúdo emitido de novo em até 10 minutos devolve a emissão existente; depois vira nova versão', function () {
    sopRecord($this)->assertOk();
    sopRecord($this)->assertOk();

    expect(SurgeryDocumentIssue::query()->count())->toBe(1);

    $this->travelTo(now()->addMinutes(11));
    sopRecord($this)->assertOk();

    $issues = SurgeryDocumentIssue::query()->orderBy('issued_at')->get();

    expect($issues)->toHaveCount(2)
        ->and($issues[0]->superseded_by_id)->toBe($issues[1]->id)
        ->and($issues[1]->superseded_by_id)->toBeNull();
});

it('um registro por implante: o segundo implante não substitui o primeiro', function () {
    $second = sopStore($this, $this->case, sopImplantPayload($this->product, $this->lot, ['serial_number' => 'SN-0002', 'eye' => 'od']))->json('implant_id');

    sopRecord($this)->assertOk();
    sopRecord($this, implantId: $second)->assertOk();

    expect(SurgeryDocumentIssue::query()->whereNull('superseded_by_id')->count())->toBe(2);
});

it('só médico emite; implante anulado não tem registro; implante de outro caso é 404', function () {
    sopRecord($this, 'secretary')->assertForbidden();
    sopRecord($this, 'financial')->assertForbidden();

    $other = sopCase($this->w, ['title' => 'B']);
    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.implants.record', [$other, $this->implantId]))->assertNotFound();

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.implants.void', [$this->case, $this->implantId]), ['reason' => 'Erro', 'return_to_stock' => true])->assertOk();
    sopRecord($this)->assertStatus(409);

    expect(SurgeryDocumentIssue::query()->count())->toBe(0);
});

it('PDF do registro: 409 antes de emitir, PDF depois; imprime quem gerencia, não o financeiro', function () {
    $url = route('panel.surgery.operation.implants.record-pdf', [$this->case, $this->implantId]);

    surgeryAs($this->w, 'secretary')->getJson($url)->assertStatus(409);

    sopRecord($this)->assertOk();

    $response = surgeryAs($this->w, 'secretary')->get($url)->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');

    surgeryAs($this->w, 'financial')->get($url)->assertForbidden();
    surgeryAs(surgeryWorld(), 'doctor')->get($url)->assertNotFound();
});

it('folha de etiquetas: PDF com a quantidade pedida (limitada) e só do implante vigente', function () {
    $url = route('panel.surgery.operation.implants.labels', [$this->case, $this->implantId]);

    $ok = surgeryAs($this->w, 'secretary')->get($url . '?count=8')->assertOk();
    expect($ok->headers->get('Content-Type'))->toContain('application/pdf');

    surgeryAs($this->w, 'secretary')->get($url . '?count=9999')->assertOk(); // limitado a 30
    surgeryAs($this->w, 'secretary')->get($url . '?count=abc')->assertOk();

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.implants.void', [$this->case, $this->implantId]), ['reason' => 'Erro', 'return_to_stock' => true])->assertOk();
    surgeryAs($this->w, 'secretary')->getJson($url)->assertStatus(409);
});

it('cartão do implante: todos os vigentes, ou um só; sem implante vigente é 409', function () {
    $card = route('panel.surgery.operation.card', $this->case);

    $all = surgeryAs($this->w, 'secretary')->get($card)->assertOk();
    expect($all->headers->get('Content-Type'))->toContain('application/pdf');

    surgeryAs($this->w, 'secretary')->get($card . '?implant=' . $this->implantId)->assertOk();
    surgeryAs($this->w, 'secretary')->get($card . '?implant=' . (string) Str::uuid())->assertNotFound();

    surgeryAs($this->w, 'doctor')->postJson(route('panel.surgery.operation.implants.void', [$this->case, $this->implantId]), ['reason' => 'Erro', 'return_to_stock' => true])->assertOk();
    surgeryAs($this->w, 'secretary')->getJson($card)->assertStatus(409);
});

it('a aba mostra o implante com o registro emitido', function () {
    sopRecord($this)->assertOk();

    surgeryAs($this->w, 'doctor')->get(route('panel.surgery.show', $this->case))
        ->assertInertia(fn ($page) => $page
            ->where('sections.operation.implants.0.serial_number', 'SN-0001')
            ->where('sections.operation.implants.0.record.id', fn ($id) => filled($id))
            ->where('sections.operation.state.can_register', true));

    expect(SurgicalImplant::query()->count())->toBe(1);
});
