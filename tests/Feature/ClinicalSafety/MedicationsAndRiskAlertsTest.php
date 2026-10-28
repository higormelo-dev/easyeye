<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Enums\ClinicalSafety\{MedicationSource, MedicationStatus};
use App\Models\{AuditLog, Covenant, Entity, EntityUser, MedicalRecord, Medicine, Patient, PatientAllergy, PatientMedication, People, User};
use App\Services\ClinicalSafety\PatientClinicalSafetyService;
use App\Services\Lgpd\PatientDataExporter;
use App\Services\MedicalRecords\MedicalRecordDraftService;
use App\Support\ClinicalSafety\{ClinicalRules, OcularRiskAlerts, TermNormalizer};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/*
 * Fase 2 / A2 — medicações em uso (lista do PACIENTE, persistente entre
 * consultas) + alertas de risco ocular pelo princípio ativo + retrato no
 * prontuário ao salvar (PDF/LGPD). Mesma regra das alergias: recepção
 * registra como informada; o médico confirma, suspende ou descarta.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-07 09:00:00'));

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->other  = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->secretary = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);
    $this->docEu     = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor    = doctorRecordForEntityUser($this->docEu);

    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create(['full_name' => 'JOAO DA SILVA'])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);

    $this->tansulosina = Medicine::query()->create([
        'name' => 'SECOTEX', 'active_ingredient' => 'CLORIDRATO DE TANSULOSINA', 'concentration' => '0,4 MG', 'active' => true,
    ]);
    $this->foreignMed = Medicine::query()->create([
        'entity_id' => $this->other->id, 'name' => 'REMEDIO DE OUTRA CLINICA', 'active_ingredient' => 'VARFARINA', 'active' => true,
    ]);
});

function cs2mAs(object $test, EntityUser $eu): object
{
    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

describe('lista estruturada', function () {
    it('busca CMED: medicine_id do catálogo preenche nome/princípio ativo; alerta sai do princípio ativo', function () {
        cs2mAs($this, $this->secretary)
            ->postJson(route('panel.patients.medications.store', $this->patient), ['medicine_id' => $this->tansulosina->id])
            ->assertCreated()
            ->assertJsonPath('data.medications.0.name', 'SECOTEX')
            ->assertJsonPath('data.medications.0.active_ingredient', 'CLORIDRATO DE TANSULOSINA')
            ->assertJsonPath('data.medications.0.source', 'patient')
            ->assertJsonPath('data.medications.0.confirmed', false)
            ->assertJsonPath('data.risk_alerts.0.key', 'alpha1_blockers')
            ->assertJsonPath('data.risk_alerts.0.medications', ['SECOTEX']);
    });

    it('medicine_id do catálogo vence active_ingredient desatualizado mandado junto (4ª revisão Codex, P2)', function () {
        cs2mAs($this, $this->secretary)
            ->postJson(route('panel.patients.medications.store', $this->patient), [
                'medicine_id' => $this->tansulosina->id, 'active_ingredient' => 'losartana',
            ])
            ->assertCreated()
            ->assertJsonPath('data.medications.0.active_ingredient', 'CLORIDRATO DE TANSULOSINA')
            ->assertJsonPath('data.risk_alerts.0.key', 'alpha1_blockers');
    });

    it('recepção corrige a fonte entre paciente/receita externa; "da clínica" continua só do médico (10ª revisão Codex, P2)', function () {
        cs2mAs($this, $this->secretary)
            ->postJson(route('panel.patients.medications.store', $this->patient), ['name' => 'Omeprazol', 'source' => 'patient'])
            ->assertCreated();
        $id = PatientMedication::query()->value('id');

        cs2mAs($this, $this->secretary)
            ->putJson(route('panel.patients.medications.update', [$this->patient, $id]), ['name' => 'Omeprazol', 'source' => 'external_prescription'])
            ->assertOk()
            ->assertJsonPath('data.medications.0.source', 'external_prescription');

        // "Da clínica" segue negado pra recepção — ignorado em silêncio, sem 422.
        cs2mAs($this, $this->secretary)
            ->putJson(route('panel.patients.medications.update', [$this->patient, $id]), ['name' => 'Omeprazol', 'source' => 'clinic'])
            ->assertOk()
            ->assertJsonPath('data.medications.0.source', 'external_prescription');
    });

    it('princípio ativo com combinação longa (até 1000, igual ao catálogo CMED) não é recusado (14ª revisão Codex, P2)', function () {
        // patient_medications.active_ingredient e a validação tinham ficado
        // em 500 — abaixo do limite real do catálogo
        // (medicines.active_ingredient, 1000), que o importador da Anvisa usa
        // pra combinação com vários princípios ativos.
        $longIngredient = rtrim(str_repeat('PRINCIPIO ATIVO COMBINADO, ', 20), ', ');
        expect(mb_strlen($longIngredient))->toBeGreaterThan(500)->toBeLessThanOrEqual(1000);

        cs2mAs($this, $this->docEu)
            ->postJson(route('panel.patients.medications.store', $this->patient), [
                'name' => 'Combinado longo', 'active_ingredient' => $longIngredient,
            ])
            ->assertCreated()
            ->assertJsonPath('data.medications.0.active_ingredient', $longIngredient);
    });

    it('recusa item do catálogo de outra clínica sem nome livre', function () {
        cs2mAs($this, $this->secretary)
            ->postJson(route('panel.patients.medications.store', $this->patient), ['medicine_id' => $this->foreignMed->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('patient_medications', 0);
    });

    it('recusa item inexistente ou excluído do catálogo sem nome livre', function (string $case) {
        $id = (string) Str::uuid();

        if ($case === 'deleted') {
            $id = $this->tansulosina->id;
            $this->tansulosina->delete();
        }

        cs2mAs($this, $this->secretary)
            ->postJson(route('panel.patients.medications.store', $this->patient), ['medicine_id' => $id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('patient_medications', 0);
    })->with(['inexistente' => ['missing'], 'excluído' => ['deleted']]);

    it('mantém a medicação existente ao recusar uma atualização sem identificação válida', function (string $case) {
        cs2mAs($this, $this->secretary)
            ->postJson(route('panel.patients.medications.store', $this->patient), ['medicine_id' => $this->tansulosina->id])
            ->assertCreated();
        $medication = PatientMedication::query()->sole();
        $id         = (string) Str::uuid();

        if ($case === 'foreign') {
            $id = $this->foreignMed->id;
        } elseif ($case === 'deleted') {
            $id = $this->tansulosina->id;
            $this->tansulosina->delete();
        }

        cs2mAs($this, $this->secretary)
            ->putJson(route('panel.patients.medications.update', [$this->patient, $medication]), ['medicine_id' => $id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        expect($medication->refresh()->name)->toBe('SECOTEX')
            ->and($medication->medicine_id)->toBe($this->tansulosina->id)
            ->and($medication->active_ingredient)->toBe('CLORIDRATO DE TANSULOSINA');
        $this->assertDatabaseCount('patient_medications', 1);
    })->with(['inexistente' => ['missing'], 'excluído' => ['deleted'], 'outra clínica' => ['foreign']]);

    it('sem item acessível do catálogo aceita o nome livre informado na requisição', function () {
        cs2mAs($this, $this->secretary)
            ->postJson(route('panel.patients.medications.store', $this->patient), [
                'medicine_id' => $this->foreignMed->id, 'name' => 'Nome digitado',
            ])
            ->assertCreated()
            ->assertJsonPath('data.medications.0.name', 'Nome digitado')
            ->assertJsonPath('data.medications.0.medicine_id', null);

        $med = PatientMedication::query()->sole();
        expect($med->medicine_id)->toBeNull()->and($med->active_ingredient)->toBeNull();
    });

    it('recusa nome vazio também em chamadas diretas ao serviço', function () {
        $this->actingAs($this->secretary->user);

        expect(fn () => app(PatientClinicalSafetyService::class)->createMedication(
            $this->patient,
            ['medicine_id' => $this->foreignMed->id, 'name' => '   '],
            asDoctor: false,
        ))->toThrow(ValidationException::class);

        $this->assertDatabaseCount('patient_medications', 0);
    });

    it('identifica o item inválido na lista de medicações do cadastro do paciente', function () {
        $this->actingAs($this->secretary->user);

        try {
            app(PatientClinicalSafetyService::class)->registerFromPatientForm(
                $this->patient,
                [],
                false,
                [['medicine_id' => $this->foreignMed->id]],
            );
            $this->fail('Esperava erro de validação da medicação sem nome.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('medications.0.name');
        }

        $this->assertDatabaseCount('patient_medications', 0);
    });

    it('item do catálogo de OUTRA clínica é ignorado também fora de requisição (sem escopo de tenant ativo)', function () {
        $this->actingAs($this->secretary->user);

        $med = app(PatientClinicalSafetyService::class)
            ->createMedication($this->patient, ['medicine_id' => $this->foreignMed->id, 'name' => 'Nome digitado'], asDoctor: false);

        expect($med->medicine_id)->toBeNull()->and($med->active_ingredient)->toBeNull()->and($med->name)->toBe('Nome digitado');
    });

    it('recepção registra como informada; não confirma/suspende/descarta; médico faz tudo', function () {
        cs2mAs($this, $this->secretary)
            ->postJson(route('panel.patients.medications.store', $this->patient), ['name' => 'Xarelto 20 mg', 'active_ingredient' => 'rivaroxabana', 'source' => 'clinic'])
            ->assertCreated()
            ->assertJsonPath('data.medications.0.source', 'patient');
        $id = PatientMedication::query()->value('id');

        cs2mAs($this, $this->secretary)->postJson(route('panel.patients.medications.confirm', [$this->patient, $id]))->assertForbidden();
        cs2mAs($this, $this->secretary)->postJson(route('panel.patients.medications.status', [$this->patient, $id]), ['status' => 'suspended'])->assertForbidden();

        cs2mAs($this, $this->docEu)->postJson(route('panel.patients.medications.confirm', [$this->patient, $id]))
            ->assertOk()->assertJsonPath('data.medications.0.confirmed', true);

        // Confirmada: recepção não corrige mais.
        cs2mAs($this, $this->secretary)
            ->putJson(route('panel.patients.medications.update', [$this->patient, $id]), ['name' => 'Outro'])
            ->assertForbidden();

        cs2mAs($this, $this->docEu)
            ->postJson(route('panel.patients.medications.status', [$this->patient, $id]), ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.medications.0.status', 'suspended')
            ->assertJsonPath('data.medications.0.ended_on', '2026-10-07')
            // Suspensa não alerta mais.
            ->assertJsonPath('data.risk_alerts', []);

        cs2mAs($this, $this->docEu)
            ->postJson(route('panel.patients.medications.status', [$this->patient, $id]), ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.risk_alerts.0.key', 'anticoagulants');

        cs2mAs($this, $this->docEu)
            ->postJson(route('panel.patients.medications.status', [$this->patient, $id]), ['status' => 'discarded'])
            ->assertOk()
            ->assertJsonPath('data.medications.0.status', 'discarded');

        expect(PatientMedication::query()->sole()->status)->toBe(MedicationStatus::Discarded)
            ->and(AuditLog::query()->where('auditable_type', PatientMedication::class)->count())->toBeGreaterThanOrEqual(4);
    });

    it('mesma medicação em uso não duplica (mesmo item do catálogo ou mesmo nome)', function () {
        cs2mAs($this, $this->secretary)->postJson(route('panel.patients.medications.store', $this->patient), ['medicine_id' => $this->tansulosina->id])->assertCreated();
        cs2mAs($this, $this->secretary)->postJson(route('panel.patients.medications.store', $this->patient), ['medicine_id' => $this->tansulosina->id])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        cs2mAs($this, $this->secretary)->postJson(route('panel.patients.medications.store', $this->patient), ['name' => 'secotex'])
            ->assertUnprocessable();

        // Suspensa não conta; reativar por cima de uma nova igual é recusado.
        $id = PatientMedication::query()->value('id');
        cs2mAs($this, $this->docEu)->postJson(route('panel.patients.medications.status', [$this->patient, $id]), ['status' => 'suspended'])->assertOk();
        cs2mAs($this, $this->secretary)->postJson(route('panel.patients.medications.store', $this->patient), ['medicine_id' => $this->tansulosina->id])->assertCreated();
        cs2mAs($this, $this->docEu)->postJson(route('panel.patients.medications.status', [$this->patient, $id]), ['status' => 'active'])->assertUnprocessable();
    });

    it('médico registra como da clínica, confirmada; fim antes do início é recusado', function () {
        cs2mAs($this, $this->docEu)
            ->postJson(route('panel.patients.medications.store', $this->patient), [
                'name' => 'Plaquinol', 'active_ingredient' => 'hidroxicloroquina', 'posology' => '400 mg/dia', 'started_on' => '2020-01-10',
            ])
            ->assertCreated()
            ->assertJsonPath('data.medications.0.source', 'clinic')
            ->assertJsonPath('data.medications.0.confirmed', true)
            ->assertJsonPath('data.risk_alerts.0.key', 'antimalarials');

        cs2mAs($this, $this->docEu)
            ->postJson(route('panel.patients.medications.store', $this->patient), ['name' => 'X', 'started_on' => '2026-01-10', 'ended_on' => '2025-01-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ended_on']);
    });
});

describe('alertas de risco ocular (arquivo de dados)', function () {
    it('cada grupo dispara pelo princípio ativo — sem acento e em maiúsculas', function (string $group, string $ingredient) {
        $alerts = OcularRiskAlerts::for([['text' => mb_strtoupper($ingredient), 'label' => 'item']]);

        expect(collect($alerts)->pluck('key')->all())->toContain($group);
    })->with(function () {
        $cases = [];

        // Dataset montado antes de a aplicação subir: lê o arquivo de dados direto.
        foreach ((require __DIR__ . '/../../../config/clinical_safety.php')['ocular_risk_rules'] as $group => $rule) {
            foreach ($rule['ingredients'] as $ingredient) {
                $cases["{$group}: {$ingredient}"] = [$group, $ingredient];
            }
        }

        return $cases;
    });

    it('acentos, sais e nome livre com dose também alertam; palavra parcial não', function () {
        $keys = fn (string $text) => collect(OcularRiskAlerts::for([['text' => $text, 'label' => $text]]))->pluck('key')->all();

        expect($keys('Ácido Zoledrônico 5 mg/100 mL'))->toBe(['bisphosphonates'])
            ->and($keys('Cloridrato de Tansulosina 0,4mg'))->toBe(['alpha1_blockers'])
            ->and($keys('Prednisona 20mg 1x/dia'))->toBe(['systemic_corticosteroids'])
            ->and($keys('Sildenafil'))->toBe(['pde5_inhibitors'])
            // "cloroquina" dentro de "hidroxicloroquina" não dispara duas vezes por engano de grupo
            ->and($keys('Hidroxicloroquina'))->toBe(['antimalarials'])
            // Sem regra: nada.
            ->and($keys('Losartana 50 mg'))->toBe([]);
    });

    it('arquivo de dados: grupo/alerta nos dois idiomas, princípios em minúsculas sem acento, fonte preenchida', function () {
        foreach (config('clinical_safety.ocular_risk_rules') as $key => $rule) {
            expect($rule['group'])->toHaveKeys(['pt_BR', 'en'], $key)
                ->and($rule['alert'])->toHaveKeys(['pt_BR', 'en'])
                ->and(trim($rule['source']))->not->toBe('');

            foreach ($rule['ingredients'] as $ingredient) {
                expect($ingredient)->toBe(TermNormalizer::plain($ingredient), "{$key}: {$ingredient}");
            }
        }

        foreach (config('clinical_safety.allergy_classes') as $key => $class) {
            expect($class['label'])->toHaveKeys(['pt_BR', 'en'], $key)->and(trim($class['source']))->not->toBe('');

            foreach ([...$class['members'], ...$class['aliases']] as $term) {
                expect($term)->toBe(TermNormalizer::plain($term), "{$key}: {$term}");
            }
        }

        // Mínimos da spec presentes.
        $all = collect(ClinicalRules::riskRules())->flatMap(fn ($r) => $r['ingredients']);

        foreach (['tansulosina', 'varfarina', 'clopidogrel', 'hidroxicloroquina', 'prednisona', 'topiramato', 'amiodarona', 'etambutol', 'isotretinoina', 'tamoxifeno', 'sildenafila', 'alendronato', 'acido zoledronico'] as $must) {
            expect($all)->toContain($must);
        }
    });

    it('texto livre de medicações da última consulta também alerta (registros antigos), marcado como texto livre', function () {
        MedicalRecord::create([
            'entity_id'      => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
            'main_complaint' => 'x', 'medications_in_use' => 'Usa topiramato para enxaqueca',
        ]);

        cs2mAs($this, $this->docEu)->getJson(route('panel.patients.clinical-safety.show', $this->patient))
            ->assertJsonPath('data.risk_alerts.0.key', 'topiramate')
            ->assertJsonPath('data.risk_alerts.0.origin', 'free_text');
    });
});

describe('retrato no prontuário (snapshot)', function () {
    beforeEach(function () {
        PatientMedication::query()->create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'name' => 'Secotex', 'active_ingredient' => 'tansulosina',
            'posology'  => '1 cp/noite', 'status' => MedicationStatus::Active, 'source' => MedicationSource::Patient,
        ]);
        PatientMedication::query()->create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'name' => 'Suspensa', 'status' => MedicationStatus::Suspended,
            'source'    => MedicationSource::Patient,
        ]);
        PatientAllergy::query()->create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'category' => 'medication', 'substance' => 'Dipirona',
            'reaction'  => 'Urticária', 'severity' => 'severe', 'status' => 'confirmed',
        ]);
    });

    it('salvar o prontuário grava as medicações ATIVAS e as alergias do momento; o texto livre segue como observação', function () {
        cs2mAs($this, $this->docEu)
            ->post(route('panel.patients.medicalrecords.store', $this->patient), [
                'main_complaint' => 'Baixa visual', 'medications_in_use' => 'Colírio lubrificante às vezes',
            ])
            ->assertRedirect();

        $record = MedicalRecord::query()->sole();
        expect($record->medications_snapshot)->toHaveCount(1)
            ->and($record->medications_snapshot[0])->toMatchArray(['name' => 'Secotex', 'active_ingredient' => 'tansulosina', 'confirmed' => false])
            ->and($record->allergies_snapshot['state'])->toBe('has')
            ->and($record->allergies_snapshot['items'][0])->toMatchArray(['substance' => 'Dipirona', 'severity' => 'severe'])
            ->and($record->medications_in_use)->toBe('Colírio lubrificante às vezes');

        // A lista muda depois; o prontuário só muda quando o médico salvar de novo.
        PatientMedication::query()->where('name', 'Secotex')->update(['status' => 'suspended']);
        expect($record->fresh()->medications_snapshot)->toHaveCount(1);

        cs2mAs($this, $this->docEu)
            ->put(route('panel.patients.medicalrecords.update', [$this->patient, $record]), ['main_complaint' => 'Baixa visual'])
            ->assertRedirect();
        expect($record->fresh()->medications_snapshot)->toBe([]);
    });

    it('o navegador não grava o retrato (campo fora do request)', function () {
        cs2mAs($this, $this->docEu)
            ->post(route('panel.patients.medicalrecords.store', $this->patient), [
                'main_complaint' => 'x', 'medications_snapshot' => [['name' => 'FORJADO']], 'allergies_snapshot' => ['state' => 'denied'],
            ])
            ->assertRedirect();

        $record = MedicalRecord::query()->sole();
        expect(collect($record->medications_snapshot)->pluck('name')->all())->toBe(['Secotex'])
            ->and($record->allergies_snapshot['state'])->toBe('has')
            ->and(MedicalRecordDraftService::FIELDS)->not->toContain('medications_snapshot');
    });

    it('histórico da consulta (show), PDF e exportação LGPD trazem o retrato e as listas do paciente', function () {
        cs2mAs($this, $this->docEu)
            ->post(route('panel.patients.medicalrecords.store', $this->patient), ['main_complaint' => 'x'])->assertRedirect();
        $record = MedicalRecord::query()->sole();

        cs2mAs($this, $this->docEu)->getJson(route('panel.patients.medicalrecords.show', [$this->patient, $record]))
            ->assertOk()
            ->assertJsonPath('medications_snapshot.0.name', 'Secotex')
            ->assertJsonPath('allergies_snapshot.items.0.substance', 'Dipirona')
            ->assertJsonPath('allergies_snapshot.items.0.severity_label', 'Grave / anafilaxia');

        $html = view('pdf.medical_record', ['record' => $record->fresh(), 'setting' => null])->render();
        expect($html)->toContain('ALERGIA')
            ->toContain('Dipirona (Urticária; Grave / anafilaxia; Confirmada pelo médico)')
            ->toContain('Medicações em uso (no momento da consulta)')
            ->toContain('Secotex (tansulosina) — 1 cp/noite');

        $export = app(PatientDataExporter::class)->export($this->patient->fresh());
        expect($export['clinical_safety']['allergies'][0])->toMatchArray(['substance' => 'Dipirona', 'reaction' => 'Urticária'])
            ->and(collect($export['clinical_safety']['medications'])->pluck('name')->sort()->values()->all())->toBe(['Secotex', 'Suspensa'])
            ->and($export['medical_records'][0]['medications_snapshot'][0]['name'])->toBe('Secotex')
            ->and($export['medical_records'][0]['allergies_snapshot']['state'])->toBe('has')
            // A v4 trouxe alergias/medicações (Fase 2); versões seguintes só acrescentam seções.
            ->and($export['format_version'])->toBeGreaterThanOrEqual(4);
    });

    it('PDF de prontuário anterior ao retrato mostra as alergias do cadastro atual, marcadas', function () {
        $record = MedicalRecord::create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'legado',
        ]);
        session(['selected_entity_id' => $this->entity->id]);

        $html = view('pdf.medical_record', ['record' => $record->fresh(), 'setting' => null])->render();
        expect($html)->toContain('Dipirona')->toContain('cadastro atual');
    });

    it('prontuário de paciente sem registro de alergias imprime "Sem registro de alergias"', function () {
        PatientAllergy::query()->delete();

        cs2mAs($this, $this->docEu)
            ->post(route('panel.patients.medicalrecords.store', $this->patient), ['main_complaint' => 'x'])->assertRedirect();

        $html = view('pdf.medical_record', ['record' => MedicalRecord::query()->sole(), 'setting' => null])->render();
        expect($html)->toContain('Sem registro de alergias');
    });

    it('assinatura feita antes das colunas novas continua conferindo (vazias ficam fora do hash)', function () {
        $record = MedicalRecord::create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'assinado',
        ])->fresh();

        // Hash "de antes do deploy": sem as chaves das colunas novas.
        $signedAt = now()->startOfSecond();
        $content  = array_diff_key($record->getAttributes(), array_flip([
            'signed_by', 'signed_at', 'signature_hash', 'is_locked', 'clinical_revision', 'contact_lens_calculation', 'medications_snapshot', 'allergies_snapshot', 'contact_lens_use',
        ]));
        $hash = hash('sha256', implode('|', [$record->getKey(), $this->docEu->id, $signedAt->toIso8601String(), hash('sha256', serialize($content))]));
        DB::table('medical_records')->where('id', $record->id)->update([
            'signed_by' => $this->docEu->id, 'signed_at' => $signedAt, 'signature_hash' => $hash, 'is_locked' => true,
        ]);

        expect($record->fresh()->verifyIntegrity())->toBeTrue();
    });
});

describe('medicamento na tela do prontuário', function () {
    it('props do prontuário trazem a lista e os alertas (médico vê o alerta durante a consulta)', function () {
        PatientMedication::query()->create([
            'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'name' => 'Topamax', 'active_ingredient' => 'TOPIRAMATO',
            'status'    => MedicationStatus::Active, 'source' => MedicationSource::Patient,
        ]);

        cs2mAs($this, $this->docEu)->get(route('panel.patients.medicalrecords.create', $this->patient))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clinicalSafety.medications.0.name', 'Topamax')
                ->where('clinicalSafety.risk_alerts.0.key', 'topiramate')
                ->where('clinicalSafety.risk_alerts.0.medications', ['Topamax'])
                ->where('clinicalSafety.allergy_state', 'unknown'));
    });
});

/*
 * Revisão Codex P1-2 — risco que PERSISTE depois de suspender (IFIS da
 * tansulosina, maculopatia dos antimaláricos, amiodarona por 180 dias).
 * Antes: suspender a tansulosina apagava o alerta de íris flácida.
 */
describe('risco que persiste após suspender a medicação', function () {
    function cs2mMed(object $test, string $name, string $ingredient, MedicationStatus $status, ?string $endedOn = null): PatientMedication
    {
        return PatientMedication::query()->create([
            'entity_id' => $test->entity->id, 'patient_id' => $test->patient->id, 'name' => $name, 'active_ingredient' => $ingredient,
            'status'    => $status, 'source' => MedicationSource::Clinic, 'ended_on' => $endedOn,
        ]);
    }

    it('tansulosina suspensa segue alertando IFIS ("suspensa em"); descartada não alerta', function () {
        cs2mAs($this, $this->docEu)->postJson(route('panel.patients.medications.store', $this->patient), ['medicine_id' => $this->tansulosina->id])->assertCreated();
        $id = PatientMedication::query()->value('id');

        $this->travelTo(Carbon::parse('2026-10-09 09:00:00'));

        cs2mAs($this, $this->docEu)
            ->postJson(route('panel.patients.medications.status', [$this->patient, $id]), ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.medications.0.status', 'suspended')
            ->assertJsonPath('data.risk_alerts.0.key', 'alpha1_blockers')
            ->assertJsonPath('data.risk_alerts.0.medications', ['SECOTEX (suspensa em 09/10/2026)']);

        // Telas que carregam só as ativas (agenda, impressão) também alertam.
        expect(app(PatientClinicalSafetyService::class)->summary($this->patient, withHistory: false)['risk_alerts'][0]['key'])->toBe('alpha1_blockers');

        cs2mAs($this, $this->docEu)
            ->postJson(route('panel.patients.medications.status', [$this->patient, $id]), ['status' => 'discarded'])
            ->assertOk()
            ->assertJsonPath('data.risk_alerts', []);
    });

    it('só as regras marcadas persistem: antimalárico anos depois, amiodarona até 180 dias, etambutol e anticoagulante não', function () {
        cs2mMed($this, 'Plaquinol', 'hidroxicloroquina', MedicationStatus::Suspended, '2023-03-01');
        cs2mMed($this, 'Ancoron', 'amiodarona', MedicationStatus::Suspended, '2026-06-01');      // 128 dias
        cs2mMed($this, 'Etambutol', 'etambutol', MedicationStatus::Suspended, '2026-10-01');
        cs2mMed($this, 'Marevan', 'varfarina', MedicationStatus::Suspended, '2026-10-06');

        $keys = fn () => collect(app(PatientClinicalSafetyService::class)->summary($this->patient)['risk_alerts'])->pluck('key')->sort()->values()->all();

        expect($keys())->toBe(['amiodarone', 'antimalarials']);

        // Amiodarona: 181 dias depois da suspensão sai do alerta.
        $this->travelTo(Carbon::parse('2026-11-29 09:00:00'));
        expect($keys())->toBe(['antimalarials']);
    });

    it('em inglês o rótulo diz "stopped on"', function () {
        cs2mMed($this, 'Secotex', 'tansulosina', MedicationStatus::Suspended, '2026-10-01');
        app()->setLocale('en');

        expect(app(PatientClinicalSafetyService::class)->summary($this->patient)['risk_alerts'][0]['medications'])->toBe(['Secotex (stopped on 10/01/2026)']);
    });

    it('config: alfa-1 bloqueador obrigatoriamente persiste; regras sem a chave não', function () {
        $rules = ClinicalRules::riskRules();

        expect($rules['alpha1_blockers']['persists_after_stop'])->toBeTrue()
            ->and($rules['antimalarials']['persists_after_stop'])->toBeTrue()
            ->and($rules['amiodarone']['persists_after_stop'])->toBe(180)
            ->and($rules['ethambutol']['persists_after_stop'])->toBeNull()
            ->and($rules['anticoagulants']['persists_after_stop'])->toBeNull();
    });

    it('menção antiga no texto livre não revive risco de substância já suspensa na lista estruturada (3ª revisão Codex, P2)', function () {
        // Consulta antiga (antes da lista estruturada existir): só texto livre.
        MedicalRecord::create([
            'entity_id'      => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
            'main_complaint' => 'x', 'medications_in_use' => 'Varfarina 5mg',
        ]);

        // Sem registro estruturado, o texto livre alerta normal.
        expect(collect(app(PatientClinicalSafetyService::class)->summary($this->patient)['risk_alerts'])->pluck('key')->all())
            ->toContain('anticoagulants');

        // Agora a varfarina ganha registro estruturado e é suspensa — não persiste
        // depois de parar; o texto velho não pode reviver o alerta que a lista já
        // encerrou corretamente.
        cs2mMed($this, 'Marevan', 'varfarina', MedicationStatus::Suspended, '2026-10-01');

        expect(collect(app(PatientClinicalSafetyService::class)->summary($this->patient)['risk_alerts'])->pluck('key')->all())
            ->not->toContain('anticoagulants');
    });

    it('substância sem princípio ativo próprio (nome livre com dose) também reconcilia o texto antigo, mesmo com dose diferente (4ª revisão Codex, P2)', function () {
        MedicalRecord::create([
            'entity_id'      => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
            'main_complaint' => 'x', 'medications_in_use' => 'Varfarina 10mg',
        ]);

        // Nome livre (sem princípio ativo cadastrado) com DOSE DIFERENTE do
        // texto antigo — "Varfarina 5 mg" inteiro nunca bateria com
        // "Varfarina 10mg"; só o nome sem a dose bate nos dois.
        cs2mMed($this, 'Varfarina 5 mg', '', MedicationStatus::Suspended, '2026-10-01');

        expect(collect(app(PatientClinicalSafetyService::class)->summary($this->patient)['risk_alerts'])->pluck('key')->all())
            ->not->toContain('anticoagulants');
    });

    it('substância repetida de forma adjacente no texto antigo (nome científico entre parênteses) reconcilia por completo, não só a 1ª ocorrência (9ª revisão Codex, P2)', function () {
        MedicalRecord::create([
            'entity_id'      => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
            'main_complaint' => 'x', 'medications_in_use' => 'Varfarina (varfarina sódica)',
        ]);

        // Normalizado, "sódica" é sal (stopword) e sobra "varfarina varfarina"
        // — as duas ocorrências compartilham o espaço entre elas.
        cs2mMed($this, 'Marevan', 'varfarina', MedicationStatus::Suspended, '2026-10-01');

        expect(collect(app(PatientClinicalSafetyService::class)->summary($this->patient)['risk_alerts'])->pluck('key')->all())
            ->not->toContain('anticoagulants');
    });

    it('editar outro campo da medicação suspensa não reinicia a janela de persistência do risco (3ª revisão Codex, P2)', function () {
        // 189 dias antes de "hoje" (07/10, viajado no beforeEach) — já fora
        // da janela de 180 dias da amiodarona.
        $med = cs2mMed($this, 'Ancoron', 'amiodarona', MedicationStatus::Suspended, '2026-04-01');

        expect(collect(app(PatientClinicalSafetyService::class)->summary($this->patient)['risk_alerts'])->pluck('key')->all())
            ->not->toContain('amiodarone');

        // Médico só corrige a posologia — o formulário geral não tem campo de
        // data de suspensão (isso é ação separada) e não manda `ended_on`.
        cs2mAs($this, $this->docEu)
            ->putJson(route('panel.patients.medications.update', [$this->patient, $med]), ['name' => 'Ancoron 200mg', 'posology' => '1 cp/dia'])
            ->assertOk();

        expect($med->fresh()->ended_on?->toDateString())->toBe('2026-04-01')
            ->and(collect(app(PatientClinicalSafetyService::class)->summary($this->patient)['risk_alerts'])->pluck('key')->all())
            ->not->toContain('amiodarone');
    });
});
