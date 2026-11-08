<?php

declare(strict_types=1);

/**
 * LGPD art. 14 — responsável legal do paciente menor é OPCIONAL: o atendimento
 * não depende de consentimento (art. 11, II, f; Enunciado CD/ANPD nº 1/2023) e
 * a base legal é escolha da clínica controladora. O cadastro nunca é bloqueado
 * e nenhum "consentimento do responsável" é gravado por este formulário.
 */

use App\Enums\{ClientRule, ConsentType, FeatureKey, SubscriptionStatus};
use App\Models\{Covenant, Entity, Patient, PatientConsent, Plan, PlanFeature, Subscription, User};
use App\Services\Lgpd\PatientDataExporter;
use Illuminate\Support\Carbon;

function pmgEntity(): Entity
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
    $this->travelTo(Carbon::parse('2026-11-07'));
    $this->clinic   = pmgEntity();
    $this->eu       = createEntityUser($this->clinic, User::factory()->create(), ClientRule::Secretary->value);
    $this->covenant = Covenant::factory()->create(['entity_id' => $this->clinic->id]);

    $this->payload = fn (array $overrides = []) => array_merge([
        'covenant_id'       => $this->covenant->id,
        'name'              => 'Crianca Teste',
        'birth_date'        => '2015-05-01',
        'gender'            => 1,
        'marital_status'    => 1,
        'email'             => 'responsavel@example.com',
        'national_registry' => '39053344705',
        'cellphone'         => '11911112222',
        'whatsapp'          => true,
    ], $overrides);

    $this->as = fn () => $this->actingAs($this->eu->user)->withSession(panelSession($this->eu));
});

it('cadastra paciente menor sem responsável legal: o campo é opcional e nada bloqueia', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['legal_guardian_name' => null]))
        ->assertSuccessful()
        ->assertJsonMissingValidationErrors(['legal_guardian_name']);

    $patient = Patient::withoutGlobalScopes()->where('entity_id', $this->clinic->id)->sole();

    expect($patient->person->legal_guardian_name)->toBeNull()
        ->and(PatientConsent::query()->withoutGlobalScopes()
            ->where('patient_id', $patient->id)
            ->where('consent_type', ConsentType::MinorGuardian->value)
            ->exists())->toBeFalse();
});

it('grava o responsável legal informado, devolve na edição, permite apagar e o inclui na exportação LGPD', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['legal_guardian_name' => 'Responsavel Legal']))
        ->assertSuccessful();

    $patient = Patient::withoutGlobalScopes()->where('entity_id', $this->clinic->id)->sole();

    expect($patient->person->legal_guardian_name)->toBe('RESPONSAVEL LEGAL');

    ($this->as)()->getJson(route('panel.patients.editData', $patient))
        ->assertOk()
        ->assertJsonPath('data.legal_guardian_name', 'RESPONSAVEL LEGAL')
        ->assertJsonMissingPath('data.minor_guardian_consent');

    expect(app(PatientDataExporter::class)->export($patient->fresh())['personal_data']['legal_guardian_name'] ?? null)
        ->toBe('RESPONSAVEL LEGAL');

    // Tela com o bundle anterior (sem o campo): não apaga o que outra pessoa gravou.
    ($this->as)()->putJson(route('panel.patients.update', $patient), ($this->payload)(['active' => true, 'occupation' => 'Estudante']))
        ->assertSuccessful();

    expect($patient->person->fresh()->legal_guardian_name)->toBe('RESPONSAVEL LEGAL');

    // null explícito apaga (correção/eliminação pedida pelo titular — art. 18, III e VI).
    ($this->as)()->putJson(route('panel.patients.update', $patient), ($this->payload)(['legal_guardian_name' => null, 'active' => true]))
        ->assertSuccessful();

    expect($patient->person->fresh()->legal_guardian_name)->toBeNull();
});

it('autorização de lembretes de paciente menor exige o responsável e guarda quem autorizou (art. 14, §§ 1º e 5º)', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)([
        'legal_guardian_name'    => null,
        'care_reminders_consent' => true,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors([
            'legal_guardian_name'    => 'responsável legal que autorizou',
            'care_reminders_consent' => 'responsável legal que autorizou',
        ]);

    expect(Patient::withoutGlobalScopes()->where('entity_id', $this->clinic->id)->exists())->toBeFalse();

    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)([
        'legal_guardian_name'    => 'Responsavel Legal',
        'care_reminders_consent' => true,
    ]))->assertSuccessful();

    $patient = Patient::withoutGlobalScopes()->where('entity_id', $this->clinic->id)->sole();
    $consent = PatientConsent::query()->withoutGlobalScopes()
        ->where('patient_id', $patient->id)
        ->where('consent_type', ConsentType::CareReminders->value)
        ->sole();

    expect($consent->status)->toBe('granted')
        ->and($consent->notes)->toBe('Autorizado pelo responsável legal: RESPONSAVEL LEGAL');

    $exported = collect(app(PatientDataExporter::class)->export($patient->fresh())['consents']);

    expect($exported->firstWhere('notes', 'Autorizado pelo responsável legal: RESPONSAVEL LEGAL'))->not->toBeNull();
});

it('sem autorização de lembretes, o menor é salvo sem responsável; adulto autoriza sem evidência de responsável', function (): void {
    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)(['care_reminders_consent' => false]))
        ->assertSuccessful();

    ($this->as)()->postJson(route('panel.patients.store'), ($this->payload)([
        'name'                   => 'Adulta Teste',
        'birth_date'             => '1990-01-01',
        'national_registry'      => '52998224725',
        'email'                  => 'adulta@example.com',
        'care_reminders_consent' => true,
    ]))->assertSuccessful();

    $adult = Patient::withoutGlobalScopes()->where('entity_id', $this->clinic->id)
        ->whereHas('person', fn ($q) => $q->where('full_name', 'ADULTA TESTE'))->sole();

    expect(PatientConsent::query()->withoutGlobalScopes()->where('patient_id', $adult->id)->sole()->notes)->toBeNull();
});
