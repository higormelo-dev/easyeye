<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, User};
use App\Services\ContactLensCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Lente de contato (v2) no prontuário: a potência de LC SUGERIDA fica
 * vinculada à consulta. O servidor recalcula a partir das entradas (nunca
 * aceita resultado do navegador), a assinatura trava a alteração e o
 * resultado aparece na visualização e no PDF. Cálculos gravados pela versão 1
 * continuam exibidos como estão (não são migrados).
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

    // O que a tela envia: entradas (+ resultados, que o servidor ignora).
    $this->inputs = [
        'version'            => 2,
        'vertex_distance_mm' => 12,
        'profile'            => 'standard',
        'lens_mode'          => 'auto',
        'od'                 => ['sphere' => -5, 'cylinder' => -2, 'axis' => 180],
        'oe'                 => ['sphere' => -2.5, 'cylinder' => null, 'axis' => null],
    ];

    // Gravado pela versão 1 (antes da v2) — exatamente o formato de então.
    $this->legacy = [
        'version'            => 1,
        'vertex_distance_mm' => 12,
        'vertex_od'          => -6,
        'vertex_oe'          => 6,
        'vertex_od_result'   => -5.6,
        'vertex_oe_result'   => 6.47,
        'se_od_sphere'       => -2,
        'se_od_cylinder'     => -1,
        'se_od_result'       => -2.5,
        'se_oe_sphere'       => null,
        'se_oe_cylinder'     => null,
        'se_oe_result'       => null,
    ];
});

function clv2Record(): MedicalRecord
{
    return MedicalRecord::query()->where('patient_id', test()->patient->id)->firstOrFail();
}

function clv2NewRecord(?array $calculation = null): MedicalRecord
{
    return MedicalRecord::create([
        'entity_id'                => test()->entity->id, 'patient_id' => test()->patient->id, 'doctor_id' => test()->doctor->id,
        'main_complaint'           => 'Consulta',
        'contact_lens_calculation' => $calculation,
    ]);
}

function clv2Pdf(MedicalRecord $record, string $locale = 'pt_BR'): string
{
    app()->setLocale($locale);

    return view('pdf.medical_record', ['record' => $record->fresh(), 'setting' => null])->render();
}

it('salva a consulta com a potência sugerida calculada pelo servidor (version 2)', function () {
    ($this->asDoctor)()->post(route('panel.patients.medicalrecords.store', $this->patient), [
        'doctor_id'                => $this->doctor->id,
        'main_complaint'           => 'Adaptação de lente de contato',
        'contact_lens_calculation' => $this->inputs,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $calc = clv2Record()->contact_lens_calculation;

    expect($calc['version'])->toBe(2)
        ->and($calc['profile'])->toBe('standard')
        ->and($calc['od'])->toEqual(['sphere' => -5, 'cylinder' => -2, 'axis' => 180])
        ->and($calc['results']['od']['type'])->toBe('toric')
        ->and($calc['results']['od']['theoretical'])->toEqual(['sphere' => -4.72, 'cylinder' => -1.74, 'axis' => 180, 'se' => -5.59])
        ->and($calc['results']['od']['suggested'])->toEqual(['sphere' => -4.75, 'cylinder' => -1.75, 'axis' => 180])
        ->and($calc['results']['oe']['suggested'])->toEqual(['sphere' => -2.5, 'cylinder' => null, 'axis' => null])
        ->and($calc['results']['oe']['vertex_applied'])->toBeFalse();
});

it('edição recalcula e ignora resultado adulterado vindo do navegador', function () {
    $record = clv2NewRecord();
    $forged = ['od' => ['type' => 'spherical', 'suggested' => ['sphere' => -99, 'cylinder' => null, 'axis' => null]], 'oe' => null];

    ($this->asDoctor)()->put(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
        'contact_lens_calculation' => [...$this->inputs, 'results' => $forged],
    ])->assertSessionHasNoErrors();

    $calc = $record->fresh()->contact_lens_calculation;
    expect($calc['results']['od']['suggested'])->toEqual(['sphere' => -4.75, 'cylinder' => -1.75, 'axis' => 180])
        ->and($calc['results']['oe']['suggested']['sphere'])->toEqual(-2.5);
});

it('sem nada digitado (ou removido) não grava cálculo', function () {
    ($this->asDoctor)()->post(route('panel.patients.medicalrecords.store', $this->patient), [
        'doctor_id'                => $this->doctor->id,
        'main_complaint'           => 'Consulta',
        'contact_lens_calculation' => ['vertex_distance_mm' => 12, 'od' => ['sphere' => null, 'cylinder' => -1]],
    ])->assertSessionHasNoErrors();

    expect(clv2Record()->contact_lens_calculation)->toBeNull();
});

it('entradas inválidas são recusadas (422): faixas, 2 casas, eixo inteiro, linha e tipo', function () {
    ($this->asDoctor)()->postJson(route('panel.patients.medicalrecords.store', $this->patient), [
        'doctor_id'                => $this->doctor->id,
        'main_complaint'           => 'Consulta',
        'contact_lens_calculation' => [
            'vertex_distance_mm' => 200,
            'profile'            => 'premium',
            'lens_mode'          => 'rigid',
            'od'                 => ['sphere' => -90, 'cylinder' => 'abc', 'axis' => 90.5],
            'oe'                 => ['sphere' => -5.385, 'cylinder' => 16, 'axis' => 181],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors([
        'contact_lens_calculation.vertex_distance_mm',
        'contact_lens_calculation.profile',
        'contact_lens_calculation.lens_mode',
        'contact_lens_calculation.od.sphere',
        'contact_lens_calculation.od.cylinder',
        'contact_lens_calculation.od.axis',
        'contact_lens_calculation.oe.sphere',
        'contact_lens_calculation.oe.cylinder',
        'contact_lens_calculation.oe.axis',
    ]);

    $record = clv2NewRecord();

    ($this->asDoctor)()->putJson(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
        'contact_lens_calculation' => ['od' => ['sphere' => -2, 'cylinder' => -0.125]],
    ])->assertStatus(422)->assertJsonValidationErrors(['contact_lens_calculation.od.cylinder']);
});

it('tela aberta antes da v2 (envia o formato 1) é recusada com aviso para recarregar — nada é gravado errado', function () {
    $record = clv2NewRecord($this->legacy);

    ($this->asDoctor)()->putJson(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
        'contact_lens_calculation' => [...$this->legacy, 'vertex_od' => -7],
    ])->assertStatus(422)->assertJsonValidationErrors([
        'contact_lens_calculation.version' => __('actions.medical_records.contact_lens_outdated'),
    ]);

    expect($record->fresh()->contact_lens_calculation['vertex_od'])->toEqual(-6);
});

it('"Remover do prontuário": salvar com o cálculo nulo apaga o que estava gravado', function () {
    $record = clv2NewRecord(app(ContactLensCalculator::class)->calculate($this->inputs));

    ($this->asDoctor)()->put(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
        'contact_lens_calculation' => null,
    ])->assertSessionHasNoErrors();

    expect($record->fresh()->contact_lens_calculation)->toBeNull();
});

it('edição sem a chave do cálculo mantém o gravado — inclusive um cálculo da versão 1', function () {
    $record = clv2NewRecord([...$this->legacy, 'vertex_od' => -5.385]);

    ($this->asDoctor)()->put(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
        'main_complaint' => 'Retorno',
    ])->assertSessionHasNoErrors();

    expect($record->fresh()->main_complaint)->toBe('Retorno')
        ->and($record->fresh()->contact_lens_calculation)->toEqual([...$this->legacy, 'vertex_od' => -5.385]);
});

it('cálculo da versão 1 reaberto e usado de novo passa a ser gravado como v2', function () {
    $record = clv2NewRecord($this->legacy);

    ($this->asDoctor)()->put(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
        'contact_lens_calculation' => [
            'version' => 2,
            'od'      => ['sphere' => -2, 'cylinder' => -1, 'axis' => 180],
            'oe'      => ['sphere' => 6],
        ],
    ])->assertSessionHasNoErrors();

    $calc = $record->fresh()->contact_lens_calculation;
    expect($calc['version'])->toBe(2)
        ->and($calc['results']['od']['suggested'])->toEqual(['sphere' => -2, 'cylinder' => -0.75, 'axis' => 180])
        ->and($calc['results']['oe']['suggested'])->toEqual(['sphere' => 6.5, 'cylinder' => null, 'axis' => null])
        ->and($calc)->not->toHaveKey('vertex_od');
});

it('PDF (v2): potência sugerida por olho em destaque, teórico, linha e o aviso de lente de teste', function () {
    $record = clv2NewRecord(app(ContactLensCalculator::class)->calculate([...$this->inputs, 'vertex_distance_mm' => 12.5]));

    $pt = clv2Pdf($record);
    expect($pt)->toContain(__('pdf.contact_lens_title'))
        ->toContain('Lente de contato sugerida')
        ->toContain('OD −4,75 / −1,75 × 180° (tórica)  ·  OE −2,50 (esférica)')
        ->toContain('Cálculo teórico (vértice 12,5 mm)')
        ->toContain('vértice aplicado (acima de ±4,00 D)')
        ->toContain('Padrão de mercado')
        ->toContain('Sugestão para lente de teste — confirme com sobre-refração e com a tabela do fabricante.')
        ->not->toContain('versão anterior');

    expect(clv2Pdf($record, 'en'))->toContain('Suggested contact lens')
        ->toContain('OD −4.75 / −1.75 × 180° (toric)  ·  OS −2.50 (spherical)')
        ->toContain('Theoretical value (vertex 12.5 mm)');
});

it('PDF (v1): cálculo antigo segue no formato de então, marcado como versão anterior', function () {
    $record = clv2NewRecord([...$this->legacy, 'vertex_distance_mm' => 12.5]);

    expect(clv2Pdf($record))->toContain('Cálculo de lentes de contato (versão anterior)')
        ->toContain('vértice 12,5 mm')
        ->toContain('-5.60')
        ->toContain('+6.47')
        ->toContain('-2.50')
        ->not->toContain('Lente de contato sugerida');

    expect(clv2Pdf($record, 'en'))->toContain('vertex 12.5 mm');
});

it('prontuário assinado: o cálculo não muda', function () {
    $record = clv2NewRecord(app(ContactLensCalculator::class)->calculate($this->inputs));
    $record->forceFill(['is_locked' => true, 'signed_at' => now(), 'signed_by' => $this->entityUser->id])->saveQuietly();

    ($this->asDoctor)()->put(route('panel.patients.medicalrecords.update', [$this->patient, $record]), [
        'contact_lens_calculation' => [...$this->inputs, 'od' => ['sphere' => -10]],
    ]);

    expect($record->fresh()->contact_lens_calculation['od']['sphere'])->toEqual(-5)
        ->and($record->fresh()->contact_lens_calculation['results']['od']['suggested']['sphere'])->toEqual(-4.75);
});

it('consulta posterior: visualização e edição trazem o cálculo gravado (v2 e v1, como estão)', function () {
    $v2 = clv2NewRecord(app(ContactLensCalculator::class)->calculate($this->inputs));
    $v1 = clv2NewRecord($this->legacy);

    ($this->asDoctor)()->getJson(route('panel.patients.medicalrecords.show', [$this->patient, $v2]))
        ->assertOk()
        ->assertJsonPath('contact_lens_calculation.results.od.suggested.cylinder', -1.75);

    ($this->asDoctor)()->get(route('panel.patients.medicalrecords.edit', [$this->patient, $v2]), inertiaHeaders())
        ->assertOk()
        ->assertJsonPath('props.medicalrecord.contact_lens_calculation.results.oe.suggested.sphere', -2.5);

    ($this->asDoctor)()->getJson(route('panel.patients.medicalrecords.show', [$this->patient, $v1]))
        ->assertOk()
        ->assertJsonPath('contact_lens_calculation.version', 1)
        ->assertJsonPath('contact_lens_calculation.vertex_od_result', -5.6);
});

/**
 * Grava a assinatura direto no banco com o hash calculado à mão (mesma fórmula
 * do Signable) — query builder não mexe em updated_at, que entra no hash.
 *
 * @param list<string> $withoutColumns colunas fora do hash (ex.: "antes da coluna existir")
 */
function clv2SignRaw(MedicalRecord $record, string $signerId, array $withoutColumns = []): void
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
    $record = clv2NewRecord();

    // Hash de antes do deploy: sem a chave contact_lens_calculation.
    clv2SignRaw($record, $this->entityUser->id, withoutColumns: ['contact_lens_calculation']);

    expect($record->fresh()->verifyIntegrity())->toBeTrue();
});

it('assinatura com cálculo v1 (gravado antes da v2) continua conferindo — nada é migrado', function () {
    $record = clv2NewRecord($this->legacy);
    clv2SignRaw($record, $this->entityUser->id);

    expect($record->fresh()->verifyIntegrity())->toBeTrue();
});

it('assinatura com cálculo v2: confere, e adulterar a sugestão no banco invalida', function () {
    $record = clv2NewRecord(app(ContactLensCalculator::class)->calculate($this->inputs));

    clv2SignRaw($record, $this->entityUser->id);
    expect($record->fresh()->verifyIntegrity())->toBeTrue();

    $tampered                                         = $record->fresh()->contact_lens_calculation;
    $tampered['results']['od']['suggested']['sphere'] = -4.5;
    DB::table('medical_records')->where('id', $record->id)->update(['contact_lens_calculation' => json_encode($tampered)]);

    expect($record->fresh()->verifyIntegrity())->toBeFalse();
});
