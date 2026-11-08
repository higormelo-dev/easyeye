<?php

declare(strict_types=1);

/**
 * Pedidos de titulares (LGPD arts. 18–19) na clínica: só administrador, isolados
 * por clínica, prazo contado do recebimento (art. 19, II), resposta/justificativa
 * registradas e exportação dos dados do paciente com log de acesso.
 */

use App\Enums\{ClientRule, DataAccessPurpose, LgpdRequestStatus, LgpdRequestType};
use App\Models\{DataAccessLog, Entity, LgpdRequest, Patient, People, User};
use App\Services\LgpdService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

function lrClinic(): array
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $admin  = createEntityUser($entity, User::factory()->create(), ClientRule::Admin->value);

    return [$entity, $admin];
}

function lrPatient(Entity $entity, string $name = 'MARIA SOUZA'): Patient
{
    $person = People::factory()->create(['full_name' => $name, 'birth_date' => '1990-01-01']);

    return Patient::factory()->create(['entity_id' => $entity->id, 'person_id' => $person->id]);
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-10 10:00:00'));
    [$this->clinic, $this->admin] = lrClinic();
    $this->as                     = fn ($eu = null) => $this->actingAs(($eu ?? $this->admin)->user)->withSession(panelSession($eu ?? $this->admin));
});

it('lista só os pedidos da clínica, abertos primeiro, com contagem de vencidos', function (): void {
    $lgpd = app(LgpdService::class);
    $late = $lgpd->openRequest($this->clinic->id, LgpdRequestType::Access, 'Titular Atrasado', 'a@example.test', 'Quer cópia', requestedAt: now()->subDays(20));
    $lgpd->openRequest($this->clinic->id, LgpdRequestType::Correction, 'Titular Novo', 'b@example.test', 'Corrigir e-mail');
    $closed = $lgpd->openRequest($this->clinic->id, LgpdRequestType::Access, 'Titular Atendido', 'd@example.test', 'Já atendido', requestedAt: now()->subDays(30));
    $lgpd->complete($closed, $this->admin, 'Enviado por e-mail.');
    [$other] = lrClinic();
    $lgpd->openRequest($other->id, LgpdRequestType::Access, 'Outra Clínica', 'c@example.test', 'Não pode aparecer');

    ($this->as)()->get(route('panel.reports.compliance.lgpd-requests.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Panel/Compliance/LgpdRequests')
            ->has('requests.data', 3)
            ->where('requests.data.0.id', $late->id)
            ->where('requests.data.0.overdue', true)
            ->where('requests.data.1.overdue', false)
            // Encerrado vai para o fim, mesmo com prazo mais antigo, e nunca aparece como vencido.
            ->where('requests.data.2.id', $closed->id)
            ->where('requests.data.2.overdue', false)
            ->where('requests.data.2.open', false)
            ->where('counts.open', 2)
            ->where('counts.overdue', 1)
            ->has('t.types.opposition'));
});

it('registra o pedido com a data de recebimento: o prazo de 15 dias conta dela (art. 19, II)', function (): void {
    $patient = lrPatient($this->clinic);

    ($this->as)()->post(route('panel.reports.compliance.lgpd-requests.store'), [
        'requester_name'     => 'Maria Souza',
        'requester_email'    => 'maria@example.test',
        'requester_document' => '529.982.247-25',
        'request_type'       => LgpdRequestType::Access->value,
        'requested_at'       => '2026-11-03',
        'patient_id'         => $patient->id,
        'description'        => 'Pediu por e-mail cópia dos dados.',
    ])->assertRedirect()->assertSessionHas('success');

    $request = LgpdRequest::query()->where('entity_id', $this->clinic->id)->sole();

    expect($request->status)->toBe(LgpdRequestStatus::Pending)
        ->and($request->requester_document)->toBe('52998224725')
        ->and($request->patient_id)->toBe($patient->id)
        ->and($request->requested_at->toDateString())->toBe('2026-11-03')
        ->and($request->deadline_at->toDateString())->toBe('2026-11-18');

    // No dia do prazo ainda não venceu; vence no dia seguinte.
    $this->travelTo(Carbon::parse('2026-11-18 18:00:00'));
    ($this->as)()->get(route('panel.reports.compliance.lgpd-requests.index'))
        ->assertInertia(fn (Assert $page) => $page->where('requests.data.0.overdue', false)->where('counts.overdue', 0));

    $this->travelTo(Carbon::parse('2026-11-19 00:01:00'));
    ($this->as)()->get(route('panel.reports.compliance.lgpd-requests.index'))
        ->assertInertia(fn (Assert $page) => $page->where('requests.data.0.overdue', true)->where('counts.overdue', 1));
});

it('recusa paciente de outra clínica, data futura e campos obrigatórios', function (): void {
    [$other] = lrClinic();

    ($this->as)()->post(route('panel.reports.compliance.lgpd-requests.store'), [
        'requester_name'  => '',
        'requester_email' => 'sem-arroba',
        'request_type'    => 'inexistente',
        'requested_at'    => '2026-11-11',
        'patient_id'      => lrPatient($other)->id,
        'description'     => '',
    ])->assertSessionHasErrors(['requester_name', 'requester_email', 'request_type', 'requested_at', 'patient_id', 'description']);

    expect(LgpdRequest::query()->count())->toBe(0);
});

it('inicia, conclui com resposta e recusa com justificativa; pedido encerrado não muda', function (): void {
    $lgpd    = app(LgpdService::class);
    $request = $lgpd->openRequest($this->clinic->id, LgpdRequestType::Deletion, 'Titular', 't@example.test', 'Apagar dados');
    $update  = fn (array $data) => ($this->as)()->patch(route('panel.reports.compliance.lgpd-requests.update', $request), $data);

    $update(['action' => 'start'])->assertSessionHas('success');
    expect($request->fresh()->status)->toBe(LgpdRequestStatus::InProgress);

    $update(['action' => 'complete', 'text' => ''])->assertSessionHasErrors('text');

    $update(['action' => 'complete', 'text' => 'Dados de contato eliminados; prontuário mantido por obrigação legal.'])
        ->assertSessionHas('success');

    $done = $request->fresh();
    expect($done->status)->toBe(LgpdRequestStatus::Completed)
        ->and($done->response)->toContain('prontuário mantido')
        ->and($done->responded_by)->toBe($this->admin->id)
        ->and($done->responded_at)->not->toBeNull();

    $update(['action' => 'reject', 'text' => 'Tarde demais'])->assertSessionHasErrors('action');
    expect($request->fresh()->status)->toBe(LgpdRequestStatus::Completed);

    $other = $lgpd->openRequest($this->clinic->id, LgpdRequestType::Access, 'Outro', 'o@example.test', 'Acesso');
    ($this->as)()->patch(route('panel.reports.compliance.lgpd-requests.update', $other), ['action' => 'reject', 'text' => 'Identidade não confirmada.'])
        ->assertSessionHas('success');
    expect($other->fresh())->status->toBe(LgpdRequestStatus::Rejected)
        ->rejection_reason->toBe('Identidade não confirmada.');
});

it('pedido de outra clínica: 404 em atualizar e exportar', function (): void {
    [$other, $otherAdmin] = lrClinic();
    $foreign              = app(LgpdService::class)->openRequest($other->id, LgpdRequestType::Access, 'Alheio', 'x@example.test', 'Acesso', lrPatient($other));

    ($this->as)()->patch(route('panel.reports.compliance.lgpd-requests.update', $foreign), ['action' => 'start'])->assertNotFound();
    ($this->as)()->get(route('panel.reports.compliance.lgpd-requests.export', $foreign))->assertNotFound();

    expect($foreign->fresh()->status)->toBe(LgpdRequestStatus::Pending);
});

it('exporta os dados do paciente vinculado e registra o acesso com finalidade LGPD', function (): void {
    $patient = lrPatient($this->clinic, 'JOAO TITULAR');
    $request = app(LgpdService::class)->openRequest($this->clinic->id, LgpdRequestType::Portability, 'João', 'joao@example.test', 'Portabilidade', $patient);

    $response = ($this->as)()->get(route('panel.reports.compliance.lgpd-requests.export', $request))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(json_decode((string) $response->getContent(), true)['personal_data']['full_name'] ?? null)->toBe('JOAO TITULAR')
        ->and(DataAccessLog::query()->withoutGlobalScopes()
            ->where('patient_id', $patient->id)
            ->where('purpose', DataAccessPurpose::LgpdRequest->value)
            ->exists())->toBeTrue();

    $withoutPatient = app(LgpdService::class)->openRequest($this->clinic->id, LgpdRequestType::Information, 'Sem', 's@example.test', 'Compartilhamento');
    ($this->as)()->get(route('panel.reports.compliance.lgpd-requests.export', $withoutPatient))->assertNotFound();
});

it('só administrador acessa: secretária recebe 403', function (): void {
    $secretary = createEntityUser($this->clinic, User::factory()->create(), ClientRule::Secretary->value);
    $request   = app(LgpdService::class)->openRequest($this->clinic->id, LgpdRequestType::Access, 'T', 't@example.test', 'Acesso');

    ($this->as)($secretary)->get(route('panel.reports.compliance.lgpd-requests.index'))->assertForbidden();
    ($this->as)($secretary)->patch(route('panel.reports.compliance.lgpd-requests.update', $request), ['action' => 'start'])->assertForbidden();

    expect($request->fresh()->status)->toBe(LgpdRequestStatus::Pending);
});
