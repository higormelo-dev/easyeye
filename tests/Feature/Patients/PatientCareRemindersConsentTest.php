<?php

declare(strict_types=1);

/**
 * Autorização de lembretes de retorno por WhatsApp, marcada no cadastro do paciente (LGPD art. 8; ADR 0002, item 9):
 * marcar registra o consentimento (quem coletou, canal, IP), desmarcar revoga o vigente, campo ausente/nulo não mexe, e o
 * formulário de edição parte do estado atual. Mesmo cadastro via Pacientes e via Agenda (panel.patients.store/update).
 */

use App\Enums\{ClientRule, ConsentType, FeatureKey, LegalBasis, SubscriptionStatus};
use App\Models\{Covenant, Entity, EntityUser, Patient, PatientConsent, Plan, PlanFeature, Subscription, User};

function pcrEntity(): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxPatients->value, 'value' => '0']);
    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);
    Covenant::factory()->create(['entity_id' => $entity->id, 'name' => 'Particular']);

    return $entity;
}

beforeEach(function (): void {
    $this->clinic   = pcrEntity();
    $this->eu       = createEntityUser($this->clinic, User::factory()->create(), ClientRule::Secretary->value);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->clinic->id]);

    $this->payload = fn (array $overrides = []) => array_merge([
        'covenant_id'       => $this->covenant->id, 'name' => 'Maria Souza', 'birth_date' => '1990-01-01',
        'gender'            => 1, 'marital_status' => 1, 'email' => 'maria@example.com',
        'national_registry' => '52998224725', 'cellphone' => '11911112222', 'whatsapp' => true,
    ], $overrides);

    $this->as = fn () => $this->actingAs($this->eu->user)->withSession(panelSession($this->eu));

    $this->consents = fn (Patient $patient) => PatientConsent::query()->withoutGlobalScopes()
        ->where('patient_id', $patient->id)->where('consent_type', ConsentType::CareReminders->value)->orderBy('created_at')->get();
});

function pcrPatient(object $test): Patient
{
    return Patient::withoutGlobalScopes()->where('entity_id', $test->clinic->id)->sole();
}

it('cadastro com a caixa marcada registra o consentimento: quem coletou, canal, base legal, versão e IP', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['care_reminders_consent' => true]))->assertSuccessful();

    $consent = ($this->consents)(pcrPatient($this))->sole();

    expect($consent->status)->toBe('granted')
        ->and($consent->entity_id)->toBe((string) $this->clinic->id)
        ->and($consent->legal_basis)->toBe(LegalBasis::Consent)
        ->and($consent->channel)->toBe('in_person')
        ->and($consent->document_version)->toBe('care_reminders_v1')
        ->and($consent->granted_by)->toBe((string) $this->eu->id)
        ->and($consent->granted_at)->not->toBeNull()
        ->and($consent->revoked_at)->toBeNull()
        ->and($consent->ip_address)->not->toBeNull();
});

it('cadastro sem o campo, ou com a caixa desmarcada, não registra nada', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)())->assertSuccessful();
    expect(PatientConsent::query()->withoutGlobalScopes()->count())->toBe(0);

    PatientConsent::query()->withoutGlobalScopes()->delete();
    Patient::withoutGlobalScopes()->forceDelete();

    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['care_reminders_consent' => false, 'national_registry' => '11144477735']))->assertSuccessful();
    expect(PatientConsent::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('a edição marca, não duplica quando já está marcado, desmarca (revoga com autor e motivo) e marca de novo', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)())->assertSuccessful();
    $patient = pcrPatient($this);
    $put     = fn (?bool $consent, bool $absent = false) => ($this->as)()->putJson(
        route('panel.patients.update', $patient->id),
        ($this->payload)($absent ? ['active' => true] : ['active' => true, 'care_reminders_consent' => $consent]),
    )->assertSuccessful();

    $put(true);
    $put(true); // idempotente

    expect(($this->consents)($patient))->toHaveCount(1);

    $put(null);         // nulo: não mexe
    $put(null, true);   // ausente: não mexe

    expect(($this->consents)($patient)->sole()->status)->toBe('granted');

    $put(false);

    $revoked = ($this->consents)($patient)->sole();

    expect($revoked->status)->toBe('revoked')
        ->and($revoked->revoked_at)->not->toBeNull()
        ->and($revoked->revoked_by)->toBe((string) $this->eu->id)
        ->and($revoked->revocation_reason)->toBe(trans('consent.revoked.patient_form', [], 'pt_BR'));

    $put(false); // já está sem: nada

    expect(($this->consents)($patient))->toHaveCount(1);

    $put(true);

    $all = ($this->consents)($patient);

    expect($all)->toHaveCount(2)
        ->and($all->pluck('status')->sort()->values()->all())->toBe(['granted', 'revoked']);
});

it('os dados de edição e de detalhe devolvem o estado atual da autorização', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)())->assertSuccessful();
    $patient = pcrPatient($this);

    ($this->as)()->getJson(route('panel.patients.editData', $patient->id))->assertOk()->assertJsonPath('data.care_reminders_consent', false);
    ($this->as)()->getJson(route('panel.patients.show', $patient->id))->assertOk()->assertJsonPath('data.care_reminders_consent', false);

    ($this->as)()->putJson(route('panel.patients.update', $patient->id), ($this->payload)(['active' => true, 'care_reminders_consent' => true]))->assertSuccessful();

    ($this->as)()->getJson(route('panel.patients.editData', $patient->id))->assertOk()->assertJsonPath('data.care_reminders_consent', true);
    ($this->as)()->getJson(route('panel.patients.show', $patient->id))->assertOk()->assertJsonPath('data.care_reminders_consent', true);
});

it('valor que não é booleano é recusado (422)', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['care_reminders_consent' => 'talvez']))
        ->assertStatus(422)->assertJsonValidationErrors('care_reminders_consent');
});

it('a revogação de um paciente não atinge outro com o mesmo tipo de consentimento', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['care_reminders_consent' => true]))->assertSuccessful();
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['name' => 'Joana Lima', 'email' => 'joana@example.com', 'national_registry' => '11144477735', 'cellphone' => '11933334444', 'care_reminders_consent' => true]))->assertSuccessful();

    $first  = Patient::withoutGlobalScopes()->whereHas('person', fn ($q) => $q->where('full_name', 'MARIA SOUZA'))->sole();
    $second = Patient::withoutGlobalScopes()->whereHas('person', fn ($q) => $q->where('full_name', 'JOANA LIMA'))->sole();

    ($this->as)()->putJson(route('panel.patients.update', $first->id), ($this->payload)(['active' => true, 'care_reminders_consent' => false]))->assertSuccessful();

    expect(($this->consents)($first)->sole()->status)->toBe('revoked')
        ->and(($this->consents)($second)->sole()->status)->toBe('granted')
        ->and(EntityUser::query()->find($this->eu->id))->not->toBeNull();
});
