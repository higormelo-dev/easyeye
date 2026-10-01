<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, User};
use App\Services\ContactLensCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Cálculo de lentes de contato no prontuário (saiu do Gerenciador de
 * Imagens): o resultado fica vinculado à consulta. O servidor recalcula a
 * partir das entradas (nunca aceita resultado do navegador), a assinatura
 * trava a alteração e o resultado aparece na visualização e no PDF.
 */
beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true]);
    $this->user    = User::factory()->create();
    $covenant      = Covenant::factory()->create();
    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => $covenant->id,
        'active'      => true,
    ]);
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Doctor->value);
    $this->doctor     = Doctor::create([
        'entity_user_id' => $this->entityUser->id,
        'person_id'      => People::factory()->create()->id,
        'record'         => '12345',
        'color'          => '#FF0000',
        'partner'        => false,
        'active'         => true,
    ]);

    $this->actingAs($this->user);
    session(['selected_entity_id' => $this->entity->id]);

    $this->asDoctor = fn () => $this->withSession(panelSession($this->entityUser));

    $this->inputs = [
        'vertex_distance_mm' => 12,
        'vertex_od'          => -6,
        'vertex_oe'          => 6,
        'se_od_sphere'       => -2,
        'se_od_cylinder'     => -1,
    ];
});

function clcRecord(): MedicalRecord
{
    return MedicalRecord::query()->where('patient_id', test()->patient->id)->firstOrFail();
}

it('salva o cálculo na consulta com os resultados calculados pelo servidor', function () {
    ($this->asDoctor)()->post(route('panel.patients.medicalrecords.store', $this->patient), [
        'doctor_id'                => $this->doctor->id,
        'main_complaint'           => 'Adaptação de lente de contato',
        'contact_lens_calculation' => $this->inputs,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $calc = clcRecord()->contact_lens_calculation;

    expect($calc['version'])->toBe(1)
        ->and($calc['vertex_distance_mm'])->toEqual(12)
        ->and($calc['vertex_od'])->toEqual(-6)
        ->and($calc['vertex_od_result'])->toEqual(-5.6)
        ->and($calc['vertex_oe_result'])->toEqual(6.47)
        ->and($calc['se_od_result'])->toEqual(-2.5)
        ->and($calc['se_oe_result'])->toBeNull();
});

it('edição recalcula e ignora resultado adulterado vindo do navegador', function () {
    $record = MedicalRecord::create([
        'entity_id'      => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'main_complaint' => 'Consulta',
    ]);

    ($this->asDoctor)()->put(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
        'contact_lens_calculation' => [...$this->inputs, 'vertex_od_result' => 99, 'se_od_result' => 99],
    ])->assertSessionHasNoErrors();

    $calc = $record->fresh()->contact_lens_calculation;
    expect($calc['vertex_od_result'])->toEqual(-5.6)
        ->and($calc['se_od_result'])->toEqual(-2.5);
});

it('sem nada digitado (ou removido) não grava cálculo', function () {
    ($this->asDoctor)()->post(route('panel.patients.medicalrecords.store', $this->patient), [
        'doctor_id'                => $this->doctor->id,
        'main_complaint'           => 'Consulta',
        'contact_lens_calculation' => ['vertex_distance_mm' => 12, 'vertex_od' => null],
    ])->assertSessionHasNoErrors();

    expect(clcRecord()->contact_lens_calculation)->toBeNull();
});

it('valores fora da faixa são recusados (422)', function () {
    ($this->asDoctor)()->postJson(route('panel.patients.medicalrecords.store', $this->patient), [
        'doctor_id'                => $this->doctor->id,
        'main_complaint'           => 'Consulta',
        'contact_lens_calculation' => ['vertex_distance_mm' => 200, 'vertex_od' => -90, 'se_od_cylinder' => 'abc'],
    ])->assertStatus(422)->assertJsonValidationErrors([
        'contact_lens_calculation.vertex_distance_mm',
        'contact_lens_calculation.vertex_od',
        'contact_lens_calculation.se_od_cylinder',
    ]);
});

it('prontuário assinado: o cálculo não muda', function () {
    $record = MedicalRecord::create([
        'entity_id'                => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'main_complaint'           => 'Consulta',
        'contact_lens_calculation' => app(ContactLensCalculator::class)->calculate($this->inputs),
    ]);
    $record->forceFill(['is_locked' => true, 'signed_at' => now(), 'signed_by' => $this->entityUser->id])->saveQuietly();

    ($this->asDoctor)()->put(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
        'contact_lens_calculation' => ['vertex_od' => -10],
    ]);

    expect($record->fresh()->contact_lens_calculation['vertex_od'])->toEqual(-6);
});

it('consulta posterior: visualização, edição e PDF trazem o cálculo', function () {
    $record = MedicalRecord::create([
        'entity_id'                => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'main_complaint'           => 'Consulta',
        'contact_lens_calculation' => app(ContactLensCalculator::class)->calculate($this->inputs),
    ]);

    ($this->asDoctor)()->getJson(route('panel.patients.medicalrecords.show', [$this->patient, $record]))
        ->assertOk()
        ->assertJsonPath('contact_lens_calculation.vertex_od_result', -5.6);

    ($this->asDoctor)()->get(route('panel.patients.medicalrecords.edit', [$this->patient, $record]), inertiaHeaders())
        ->assertOk()
        ->assertJsonPath('props.medicalrecord.contact_lens_calculation.se_od_result', -2.5);

    $html = view('pdf.medical_record', ['record' => $record->fresh(), 'setting' => null])->render();
    expect($html)->toContain(__('pdf.contact_lens_title'))
        ->toContain('-5.60')
        ->toContain('+6.47')
        ->toContain('-2.50');
});

/**
 * Grava a assinatura direto no banco com o hash calculado à mão (mesma fórmula
 * do Signable) — query builder não mexe em updated_at, que entra no hash.
 *
 * @param list<string> $withoutColumns colunas fora do hash (ex.: "antes da coluna existir")
 */
function clcSignRaw(MedicalRecord $record, string $signerId, array $withoutColumns = []): void
{
    $record   = $record->fresh();
    $signedAt = now()->startOfSecond();
    $content  = array_diff_key(
        $record->getAttributes(),
        array_flip(['signed_by', 'signed_at', 'signature_hash', 'is_locked', ...$withoutColumns]),
    );
    $hash = hash('sha256', implode('|', [
        $record->getKey(), $signerId, $signedAt->toIso8601String(), hash('sha256', serialize($content)),
    ]));

    DB::table('medical_records')->where('id', $record->getKey())->update([
        'signed_by' => $signerId, 'signed_at' => $signedAt, 'signature_hash' => $hash, 'is_locked' => true,
    ]);
}

it('assinatura feita antes da coluna existir continua conferindo (coluna vazia fica fora do hash)', function () {
    $record = MedicalRecord::create([
        'entity_id'      => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'main_complaint' => 'Consulta',
    ]);

    // Hash de antes do deploy: sem a chave contact_lens_calculation.
    clcSignRaw($record, $this->entityUser->id, withoutColumns: ['contact_lens_calculation']);

    expect($record->fresh()->verifyIntegrity())->toBeTrue();
});

it('assinatura com cálculo: confere, e adulterar o cálculo no banco invalida', function () {
    $record = MedicalRecord::create([
        'entity_id'                => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'main_complaint'           => 'Consulta',
        'contact_lens_calculation' => app(ContactLensCalculator::class)->calculate($this->inputs),
    ]);

    clcSignRaw($record, $this->entityUser->id);
    expect($record->fresh()->verifyIntegrity())->toBeTrue();

    $tampered = [...$record->fresh()->contact_lens_calculation, 'vertex_od_result' => -9.99];
    DB::table('medical_records')->where('id', $record->id)->update(['contact_lens_calculation' => json_encode($tampered)]);

    expect($record->fresh()->verifyIntegrity())->toBeFalse();
});
