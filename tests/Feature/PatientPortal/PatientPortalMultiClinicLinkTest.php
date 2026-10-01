<?php

declare(strict_types=1);

/**
 * Portal do Paciente com várias clínicas: cada clínica tem o SEU cadastro
 * (People) do paciente e o próprio paciente junta as clínicas numa conta —
 * convite assinado da clínica + login + confirmação da senha. A clínica nunca
 * vincula nada; um cadastro nunca fica em duas contas; o portal só enxerga os
 * cadastros vinculados à conta.
 */

use App\Http\Controllers\PatientPortal\InvitationController;
use App\Models\{Entity, LgpdRequest, Patient, PatientAccount, PatientAccountLink, People, User};
use App\Notifications\PatientPortalInvitation;
use App\Services\PatientAccountLinkService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{Auth, Notification, URL};

function pmlInvite(People $person): string
{
    return URL::temporarySignedRoute('patient-portal.invitation.accept', now()->addDays(3), ['person_id' => $person->id]);
}

beforeEach(function (): void {
    $this->clinicA = Entity::factory()->create(['is_client' => true, 'name' => 'Clínica A']);
    $this->clinicB = Entity::factory()->create(['is_client' => true, 'name' => 'Clínica B']);

    // Mesmo paciente, um cadastro por clínica (mesmo CPF e e-mail).
    $this->personA = People::factory()->create(['national_registry' => '52998224725', 'email' => 'maria@example.com']);
    $this->personB = People::factory()->create(['national_registry' => '52998224725', 'email' => 'maria@example.com']);

    $this->patientA = Patient::factory()->create(['entity_id' => $this->clinicA->id, 'person_id' => $this->personA->id]);
    $this->patientB = Patient::factory()->create(['entity_id' => $this->clinicB->id, 'person_id' => $this->personB->id]);

    // Conta criada pelo convite da clínica A.
    $this->account = PatientAccount::factory()->create([
        'person_id' => $this->personA->id, 'email' => 'maria@example.com', 'password' => 'senha-atual-123',
    ]);
});

it('paciente leigo, deslogado: abre o convite, faz só o login e a clínica B já entra na conta', function (): void {
    $url    = pmlInvite($this->personB);
    $clinic = $this->clinicB->fresh()->name;

    // O login avisa QUAL clínica será adicionada.
    $this->get($url)
        ->assertRedirect(route('patient-portal.login'))
        ->assertSessionHas('status', __('patient_portal.invitation.login_to_link', ['clinic' => $clinic]));
    expect(session(InvitationController::INTENDED_KEY))->toBe($url);

    // Um único login conclui: sem outra tela, sem digitar a senha de novo.
    $this->post(route('patient-portal.login.store'), ['email' => 'maria@example.com', 'password' => 'senha-atual-123'])
        ->assertRedirect(route('patient-portal.dashboard'))
        ->assertSessionHas('status', __('patient_portal.invitation.linked', ['clinic' => $clinic]));

    $link = PatientAccountLink::where('person_id', $this->personB->id)->sole();
    expect($link->patient_account_id)->toBe($this->account->id)
        ->and($link->entity_id)->toBe($this->clinicB->id)
        ->and(PatientAccount::count())->toBe(1); // nunca uma segunda conta

    $clinics = collect($this->get(route('patient-portal.dashboard'), inertiaHeaders())->json('props.clinics'))
        ->pluck('entity_id')->sort()->values()->all();
    expect($clinics)->toBe(collect([$this->clinicA->id, $this->clinicB->id])->sort()->values()->all());
});

it('senha errada no login não vincula nada', function (): void {
    $this->get(pmlInvite($this->personB));

    $this->post(route('patient-portal.login.store'), ['email' => 'maria@example.com', 'password' => 'senha-errada'])
        ->assertSessionHasErrors('email');

    expect(PatientAccountLink::where('person_id', $this->personB->id)->exists())->toBeFalse();
});

it('já logado com o mesmo e-mail: tela mostra a clínica e um clique vincula (sem pedir senha de novo)', function (): void {
    loginAsPatient($this->account, 'senha-atual-123');
    $url = pmlInvite($this->personB);

    $this->get($url, inertiaHeaders())
        ->assertOk()
        ->assertJsonPath('component', 'PatientPortal/Auth/LinkClinic')
        ->assertJsonPath('props.clinics', [$this->clinicB->fresh()->name])
        ->assertJsonPath('props.emailMatches', true);

    // GET nunca vincula (leitores de link do e-mail abrem URLs sozinhos).
    expect(PatientAccountLink::where('person_id', $this->personB->id)->exists())->toBeFalse();

    $this->post($url)
        ->assertRedirect(route('patient-portal.dashboard'))
        ->assertSessionHas('status', __('patient_portal.invitation.linked', ['clinic' => $this->clinicB->fresh()->name]));

    expect(PatientAccountLink::where('person_id', $this->personB->id)->sole()->patient_account_id)->toBe($this->account->id);
});

it('convite adulterado guardado na sessão não vincula após o login', function (): void {
    $forged = pmlInvite($this->personB) . 'x';

    $this->withSession([InvitationController::INTENDED_KEY => $forged])
        ->post(route('patient-portal.login.store'), ['email' => 'maria@example.com', 'password' => 'senha-atual-123'])
        ->assertRedirect($forged);

    expect(PatientAccountLink::where('person_id', $this->personB->id)->exists())->toBeFalse();
});

it('sem o vínculo, a clínica B não aparece e o acesso direto a ela é 404', function (): void {
    loginAsPatient($this->account, 'senha-atual-123');

    $this->get(route('patient-portal.dashboard'), inertiaHeaders())->assertJsonCount(1, 'props.clinics');
    $this->get(route('patient-portal.clinics.show', $this->patientB))->assertNotFound();
    $this->get(route('patient-portal.clinics.export', $this->patientB))->assertNotFound();
});

it('com o vínculo, a clínica B abre; paciente de OUTRA pessoa continua 404', function (): void {
    app(PatientAccountLinkService::class)->link($this->account, $this->personB);
    loginAsPatient($this->account, 'senha-atual-123');

    $stranger = Patient::factory()->create(['entity_id' => $this->clinicB->id]);

    $this->get(route('patient-portal.clinics.show', $this->patientB), inertiaHeaders())->assertOk();
    $this->get(route('patient-portal.clinics.show', $stranger))->assertNotFound();
    $this->get(route('patient-portal.clinics.export', $stranger))->assertNotFound();
});

it('cadastro já em OUTRA conta não é vinculado de novo (convite já usado)', function (): void {
    // personB é titular de outra conta (ex.: criada antes pelo convite de B).
    $other = PatientAccount::factory()->create(['person_id' => $this->personB->id, 'email' => 'outra@example.com']);

    loginAsPatient($this->account, 'senha-atual-123');
    $url = pmlInvite($this->personB);

    $this->get($url)->assertRedirect(route('patient-portal.login'));
    $this->post($url, ['password' => 'senha-atual-123'])->assertRedirect(route('patient-portal.login'));

    expect(PatientAccountLink::where('person_id', $this->personB->id)->exists())->toBeFalse()
        ->and($this->account->fresh()->linkedPersonIds())->not->toContain($this->personB->id)
        ->and($other->fresh()->linkedPersonIds())->toContain($this->personB->id);
});

it('um cadastro nunca fica em duas contas (UNIQUE no banco)', function (): void {
    PatientAccountLink::create(['patient_account_id' => $this->account->id, 'person_id' => $this->personB->id, 'linked_at' => now()]);
    $other = PatientAccount::factory()->create();

    expect(fn () => PatientAccountLink::create(['patient_account_id' => $other->id, 'person_id' => $this->personB->id, 'linked_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('o login só retoma URL do próprio convite — nunca um destino arbitrário da sessão', function (): void {
    $this->withSession([InvitationController::INTENDED_KEY => 'https://evil.example.com/phish'])
        ->post(route('patient-portal.login.store'), ['email' => 'maria@example.com', 'password' => 'senha-atual-123'])
        ->assertRedirect(route('patient-portal.dashboard'));
});

it('conta desativada logada não vincula (kill-switch): vai ao login', function (): void {
    loginAsPatient($this->account, 'senha-atual-123');
    $this->account->forceFill(['active' => false])->save();

    $this->post(pmlInvite($this->personB), ['password' => 'senha-atual-123'])
        ->assertRedirect(route('patient-portal.login'));

    expect(PatientAccountLink::where('person_id', $this->personB->id)->exists())->toBeFalse();
});

it('primeira conta criada pelo convite grava o vínculo do titular com a clínica', function (): void {
    $personC = People::factory()->create(['email' => 'novo@example.com']);
    Patient::factory()->create(['entity_id' => $this->clinicB->id, 'person_id' => $personC->id]);
    $url = pmlInvite($personC);

    $this->post($url, ['password' => 'senha-super-segura-1', 'password_confirmation' => 'senha-super-segura-1'])
        ->assertRedirect(route('patient-portal.dashboard'));

    $account = PatientAccount::where('person_id', $personC->id)->sole();
    $link    = PatientAccountLink::where('person_id', $personC->id)->sole();

    expect($link->patient_account_id)->toBe($account->id)
        ->and($link->entity_id)->toBe($this->clinicB->id)
        ->and(Auth::guard('patient')->id())->toBe($account->id);
});

it('staff: convite de cadastro já vinculado avisa; e-mail com conta de outra clínica ainda recebe o convite', function (): void {
    Notification::fake();
    $staffB = User::factory()->create();
    $euB    = createEntityUser($this->clinicB, $staffB, 'admin');

    // personB ainda não vinculado (a conta com o mesmo e-mail é da clínica A): envia.
    $this->actingAs($staffB)->withSession(panelSession($euB))
        ->post(route('panel.patients.portal-invitation.store', $this->patientB))
        ->assertRedirect()->assertSessionHas('success');
    Notification::assertSentTo($this->personB, PatientPortalInvitation::class);

    app(PatientAccountLinkService::class)->link($this->account, $this->personB);
    Notification::fake();

    $this->actingAs($staffB)->withSession(panelSession($euB))
        ->post(route('panel.patients.portal-invitation.store', $this->patientB))
        ->assertRedirect()->assertSessionHas('error');
    Notification::assertNothingSent();
});

it('convite enviado a OUTRO e-mail não entra na conta logada: tela sem formulário e POST recusado (403)', function (): void {
    $this->personB->forceFill(['email' => 'outro.email@example.com'])->save();
    loginAsPatient($this->account, 'senha-atual-123');
    $url = pmlInvite($this->personB);

    $this->get($url, inertiaHeaders())
        ->assertOk()
        ->assertJsonPath('component', 'PatientPortal/Auth/LinkClinic')
        ->assertJsonPath('props.emailMatches', false);

    $this->post($url, ['password' => 'senha-atual-123'])->assertForbidden();
    expect(PatientAccountLink::where('person_id', $this->personB->id)->exists())->toBeFalse();
});

it('o serviço também recusa vincular cadastro de e-mail diferente (defesa em profundidade)', function (): void {
    $this->personB->forceFill(['email' => 'outro.email@example.com'])->save();

    expect(app(PatientAccountLinkService::class)->link($this->account, $this->personB->fresh()))->toBeFalse();
});

it('cadastro já vinculado não vira titular de conta nova (cruza as duas tabelas)', function (): void {
    app(PatientAccountLinkService::class)->link($this->account, $this->personB);

    expect(app(PatientAccountLinkService::class)->createAccount($this->personB, 'senha-super-segura-1'))->toBeNull()
        ->and(PatientAccount::where('person_id', $this->personB->id)->exists())->toBeFalse();
});

it('e-mail do convite com conta desativada: mensagem clara, sem loop de login', function (): void {
    $this->account->forceFill(['active' => false])->save();

    $this->get(pmlInvite($this->personB))
        ->assertRedirect(route('patient-portal.login'))
        ->assertSessionHas('status', __('patient_portal.invitation.account_disabled'));

    expect(session(InvitationController::INTENDED_KEY))->toBeNull();
});

it('convite vencido guardado na sessão não é retomado após o login', function (): void {
    $expired = URL::temporarySignedRoute('patient-portal.invitation.accept', now()->subMinute(), ['person_id' => $this->personB->id]);

    $this->withSession([InvitationController::INTENDED_KEY => $expired])
        ->post(route('patient-portal.login.store'), ['email' => 'maria@example.com', 'password' => 'senha-atual-123'])
        ->assertRedirect(route('patient-portal.dashboard'));
});

it('exportação LGPD da clínica B usa o cadastro de B (nome/CPF de B), não o titular da conta', function (): void {
    $this->personA->forceFill(['full_name' => 'NOME NA CLINICA A'])->save();
    $this->personB->forceFill(['full_name' => 'NOME NA CLINICA B', 'national_registry' => '11144477735'])->save();
    app(PatientAccountLinkService::class)->link($this->account, $this->personB);
    loginAsPatient($this->account, 'senha-atual-123');

    $this->get(route('patient-portal.clinics.export', $this->patientB))->assertOk();

    $request = LgpdRequest::withoutGlobalScopes()->where('patient_id', $this->patientB->id)->sole();
    expect($request->requester_name)->toBe('NOME NA CLINICA B')
        ->and($request->requester_document)->toBe('11144477735')
        ->and($request->entity_id)->toBe($this->clinicB->id);
});
