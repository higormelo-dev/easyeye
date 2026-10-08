<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{AuditLog, Covenant, DoctorDocumentTemplate, Entity, EntityUser, MedicalRecord, MedicalRecordDocumentation, Medicine, MedicinePresentation, Patient, PatientAllergy, People, ReportSettingContent, User};
use App\Models\PatientExam;
use App\Services\Lgpd\PatientDataExporter;
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};

/*
 * Fase 2 / A4 — checagem de alergia na prescrição, NO SERVIDOR.
 *
 * Toda emissão do tipo receita (receituário com itens estruturados + texto,
 * receitas de catarata/pterígio, modelo de receita salvo pela Documentação)
 * refaz a checagem contra as alergias ATIVAS (informadas ou confirmadas) por
 * princípio ativo, classe, texto livre (sem acento) e nome comercial do
 * catálogo. Conflito sem `allergy_override_reason` → 422 com os conflitos; com
 * motivo → 201, motivo no documento e na auditoria. Não bloqueia.
 */
beforeEach(function () {
    $this->seed([ReportSettingSeeder::class, ReportSettingContentSeeder::class, ReportSettingVariableSeeder::class]);

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->docEu  = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor = doctorRecordForEntityUser($this->docEu);

    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'person_id'   => People::factory()->create(['full_name' => 'PACIENTE ALERGICO'])->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ]);
    $this->record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'Olho vermelho',
    ]);

    $this->amoxil   = Medicine::query()->create(['name' => 'AMOXIL', 'active_ingredient' => 'AMOXICILINA TRI-HIDRATADA', 'concentration' => '500 MG', 'active' => true]);
    $this->keflex   = Medicine::query()->create(['name' => 'KEFLEX', 'active_ingredient' => 'CEFALEXINA', 'active' => true]);
    $this->maxitrol = Medicine::query()->create(['name' => 'MAXITROL', 'active_ingredient' => 'DEXAMETASONA + SULFATO DE NEOMICINA + SULFATO DE POLIMIXINA B', 'pharmaceutical_form' => 'susp_oft', 'is_ophthalmic' => true, 'active' => true]);
    $this->voltaren = Medicine::query()->create(['name' => 'VOLTAREN', 'active_ingredient' => 'DICLOFENACO SÓDICO', 'active' => true]);
    $this->lacrima  = Medicine::query()->create(['name' => 'LACRIMA PLUS', 'active_ingredient' => 'DEXTRANA + HIPROMELOSE', 'pharmaceutical_form' => 'sol_oft', 'is_ophthalmic' => true, 'active' => true]);
});

function cs2pAs(object $test, EntityUser $eu): object
{
    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

function cs2pAllergy(object $test, string $substance, array $extra = []): PatientAllergy
{
    return PatientAllergy::query()->create(array_merge([
        'entity_id' => $test->entity->id, 'patient_id' => $test->patient->id, 'category' => 'medication',
        'substance' => $substance, 'reaction' => 'Urticária', 'severity' => 'severe', 'status' => 'reported',
    ], $extra));
}

function cs2pIssue(object $test, string $action, array $payload)
{
    return cs2pAs($test, $test->docEu)->postJson(
        route('panel.patients.medicalrecords.quick-actions.issue', [$test->patient, $test->record, $action]),
        $payload,
    );
}

describe('receituário de medicamentos', function () {
    it('conflito por princípio ativo (item do catálogo) → 422 sem motivo; nada é emitido', function () {
        cs2pAllergy($this, 'Amoxicilina');

        $res = cs2pIssue($this, 'medication-prescription', [
            'content' => 'AMOXIL 500 MG — 1 cápsula de 8/8 h por 7 dias',
            'items'   => [['medicine_id' => $this->amoxil->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('allergy_override_reason');

        expect($res->json('allergy_conflicts.0'))->toMatchArray([
            'substance' => 'Amoxicilina', 'reaction' => 'Urticária', 'severity' => 'severe', 'status' => 'reported', 'match' => 'ingredient',
        ])->and(MedicalRecordDocumentation::query()->count())->toBe(0);
    });

    it('conflito por CLASSE (alergia a penicilina → cefalexina) → 422', function () {
        cs2pAllergy($this, 'Penicilina (Benzetacil)');

        $res = cs2pIssue($this, 'medication-prescription', [
            'content' => 'KEFLEX 500 mg — 1 cp 6/6 h',
            'items'   => [['medicine_id' => $this->keflex->id]],
        ])->assertUnprocessable();

        expect(collect($res->json('allergy_conflicts'))->pluck('match')->all())->toContain('class')
            ->and($res->json('allergy_conflicts.0.class'))->toContain('beta-lactâmicos');
    });

    it('conflito por CLASSE: alergia a diclofenaco (AINE) → bromfenaco (3ª revisão Codex, P1 — "bronfenaco" era erro de digitação)', function () {
        cs2pAllergy($this, 'Diclofenaco');
        $bromfenaco = Medicine::query()->create(['name' => 'BROMFENACO SÓDICO', 'active_ingredient' => 'BROMFENACO SÓDICO', 'pharmaceutical_form' => 'sol_oft', 'is_ophthalmic' => true, 'active' => true]);

        $res = cs2pIssue($this, 'medication-prescription', [
            'content' => 'Bromfenaco sódico — 1 gota 2x/dia',
            'items'   => [['medicine_id' => $bromfenaco->id]],
        ])->assertUnprocessable();

        expect(collect($res->json('allergy_conflicts'))->pluck('match')->all())->toContain('class');
    });

    it('conflito pelo TEXTO livre (sem acento, maiúsculas) mesmo sem itens estruturados → 422', function () {
        cs2pAllergy($this, 'Dipírona');

        $res = cs2pIssue($this, 'medication-prescription', ['content' => "1) DIPIRONA SÓDICA 500MG\nTomar 1 comprimido se dor"])
            ->assertUnprocessable();

        expect($res->json('allergy_conflicts.0.match'))->toBe('text');
    });

    it('formatação (negrito) NO MEIO do nome do remédio digitado no TinyMCE não escapa a checagem (13ª revisão Codex, P1)', function () {
        cs2pAllergy($this, 'Dipirona');

        $prescription = ReportSettingContent::query()->whereHas('reportSetting', fn ($q) => $q->where('title', 'PRESCRIÇÃO DE MEDICAMENTOS'))->firstOrFail();
        $url          = route('panel.patients.medicalrecords.documentations.store', [$this->patient, $this->record]);

        // Purifier preserva tag de LINHA (strong) — só o editor de Documentação
        // (TinyMCE) recebe HTML de verdade; a quick-action de receituário trata
        // `content` como texto puro e escapa a tag antes de chegar aqui.
        cs2pAs($this, $this->docEu)->postJson($url, [
            'report_setting_content_id' => $prescription->id,
            'content'                   => '<p>DIPI<strong>RONA</strong> 500mg — 1 cp se dor</p>',
        ])->assertUnprocessable()->assertJsonPath('allergy_conflicts.0.match', 'text');
    });

    it('remédio removido do texto depois de aplicar o modelo não conta mais pra alergia (13ª revisão Codex, P2)', function () {
        cs2pAllergy($this, 'Amoxicilina');

        $prescription = ReportSettingContent::query()->whereHas('reportSetting', fn ($q) => $q->where('title', 'PRESCRIÇÃO DE MEDICAMENTOS'))->firstOrFail();
        $template     = DoctorDocumentTemplate::query()->create([
            'entity_id'                 => $this->entity->id, 'doctor_id' => $this->doctor->id, 'kind' => 'prescription', 'body_format' => 'html',
            'report_setting_content_id' => $prescription->id, 'name' => 'Padrão amoxil',
            'body'                      => '<p>AMOXIL 500 MG — 1 cápsula de 8/8h</p>',
            'payload'                   => ['items' => [['medicine_id' => $this->amoxil->id, 'name' => 'AMOXIL', 'active_ingredient' => 'AMOXICILINA TRI-HIDRATADA']]],
        ]);

        $url = route('panel.patients.medicalrecords.documentations.store', [$this->patient, $this->record]);

        // Aplicou o modelo e DEPOIS tirou o Amoxil, trocando por outra coisa.
        // O reconcileTemplateItems() fixado é da Documentação — a quick-action
        // de receituário nem lê `template_id` pra isso.
        cs2pAs($this, $this->docEu)->postJson($url, [
            'report_setting_content_id' => $prescription->id,
            'content'                   => '<p>Dipirona 500mg se dor</p>',
            'template_id'               => $template->id,
        ])->assertCreated();
    });

    it('modelo de colírio mantido (texto NÃO mudou) continua avisando mesmo se o remédio for desativado depois (14ª revisão Codex, P1)', function () {
        cs2pAllergy($this, 'Cloreto de benzalcônio', ['category' => 'preservative']);

        $prescription = ReportSettingContent::query()->whereHas('reportSetting', fn ($q) => $q->where('title', 'PRESCRIÇÃO DE MEDICAMENTOS'))->firstOrFail();
        $template     = DoctorDocumentTemplate::query()->create([
            'entity_id'                 => $this->entity->id, 'doctor_id' => $this->doctor->id, 'kind' => 'prescription', 'body_format' => 'html',
            'report_setting_content_id' => $prescription->id, 'name' => 'Lágrima padrão',
            'body'                      => '<p>Lacrima Plus — solução oftálmica, 1 gota 4x/dia</p>',
            'payload'                   => ['items' => [['medicine_id' => $this->lacrima->id, 'name' => 'LACRIMA PLUS', 'active_ingredient' => 'DEXTRANA + HIPROMELOSE']]],
        ]);

        // Catálogo desativou o produto DEPOIS do modelo salvo — a busca por
        // texto livre (AllergyConflictChecker exige active=true) não acha
        // mais; só o item estruturado do modelo, reconciliado pelo NOME (o
        // texto não cita "dextrana"/"hipromelose", só a marca), ainda pega.
        $this->lacrima->update(['active' => false]);

        $url = route('panel.patients.medicalrecords.documentations.store', [$this->patient, $this->record]);

        cs2pAs($this, $this->docEu)->postJson($url, [
            'report_setting_content_id' => $prescription->id,
            'content'                   => '<p>Lacrima Plus — solução oftálmica, 1 gota 4x/dia</p>',
            'template_id'               => $template->id,
        ])->assertUnprocessable()->assertJsonPath('allergy_conflicts.0.match', 'eye_drops');
    });

    it('item livre (sem catálogo) com princípio ativo informado também é checado', function () {
        cs2pAllergy($this, 'Neomicina', ['category' => 'eye_drop']);

        cs2pIssue($this, 'medication-prescription', [
            'content' => 'Colírio composto — 1 gota 4x/dia',
            'items'   => [['name' => 'Colírio composto', 'active_ingredient' => 'dexametasona + neomicina']],
        ])->assertUnprocessable()->assertJsonPath('allergy_conflicts.0.match', 'ingredient');
    });

    it('item com princípio ativo combinado longo (até 1000, igual ao catálogo CMED) não é recusado (14ª revisão Codex, P2)', function () {
        // items.*.active_ingredient tinha ficado em 500 — abaixo do limite
        // real do catálogo (medicines.active_ingredient, 1000).
        $longIngredient = str_repeat('PRINCIPIO ATIVO COMBINADO, ', 20);
        expect(mb_strlen($longIngredient))->toBeGreaterThan(500)->toBeLessThanOrEqual(1000);

        cs2pIssue($this, 'medication-prescription', [
            'content' => 'Colírio composto — 1 gota 4x/dia',
            'items'   => [['name' => 'Colírio composto', 'active_ingredient' => $longIngredient]],
        ])->assertCreated();
    });

    it('com motivo → 201; o motivo fica no documento e na auditoria (allergy_override) e sai na exportação LGPD', function () {
        $allergy = cs2pAllergy($this, 'Amoxicilina');

        $res = cs2pIssue($this, 'medication-prescription', [
            'content'                 => 'AMOXIL 500 MG',
            'items'                   => [['medicine_id' => $this->amoxil->id]],
            'allergy_override_reason' => 'Alergia descartada por teste de provocação em 2025',
        ])->assertCreated();

        $doc = MedicalRecordDocumentation::query()->findOrFail($res->json('id'));
        expect($doc->safety_override['reason'])->toBe('Alergia descartada por teste de provocação em 2025')
            ->and($doc->safety_override['conflicts'][0]['allergy_id'])->toBe($allergy->id)
            ->and($doc->safety_override['by'])->toBe($this->docEu->user_id);

        $audit = AuditLog::query()->where('auditable_type', MedicalRecordDocumentation::class)->where('auditable_id', $doc->id)->where('event', 'allergy_override')->sole();
        expect($audit->new_values['reason'])->toBe('Alergia descartada por teste de provocação em 2025')
            ->and($audit->user_id)->toBe($this->docEu->user_id);

        $export = app(PatientDataExporter::class)->export($this->patient->fresh());
        expect($export['medical_records'][0]['documentations'][0]['allergy_override']['reason'])->toBe('Alergia descartada por teste de provocação em 2025');
    });

    it('motivo curto demais não vale (mín. 5 caracteres)', function () {
        cs2pAllergy($this, 'Amoxicilina');

        cs2pIssue($this, 'medication-prescription', [
            'content' => 'AMOXIL 500 MG', 'items' => [['medicine_id' => $this->amoxil->id]], 'allergy_override_reason' => 'ok',
        ])->assertUnprocessable();
    });

    it('sem conflito passa direto (201, sem override); alergia DESCARTADA e de outro paciente não contam', function () {
        cs2pAllergy($this, 'Amoxicilina', ['status' => 'discarded']);

        $otherPatient = Patient::create([
            'entity_id'   => $this->entity->id, 'person_id' => People::factory()->create()->id,
            'covenant_id' => Covenant::factory()->create()->id, 'active' => true,
        ]);
        PatientAllergy::query()->create([
            'entity_id' => $this->entity->id, 'patient_id' => $otherPatient->id, 'category' => 'medication', 'substance' => 'Amoxicilina', 'status' => 'confirmed',
        ]);

        $res = cs2pIssue($this, 'medication-prescription', [
            'content' => 'AMOXIL 500 MG', 'items' => [['medicine_id' => $this->amoxil->id]],
        ])->assertCreated();

        expect(MedicalRecordDocumentation::query()->findOrFail($res->json('id'))->safety_override)->toBeNull();
    });

    it('alergia ao conservante (cloreto de benzalcônio): qualquer colírio avisa', function () {
        cs2pAllergy($this, 'Cloreto de benzalcônio', ['category' => 'preservative']);

        cs2pIssue($this, 'medication-prescription', [
            'content' => 'LACRIMA PLUS — 1 gota 4x/dia',
            'items'   => [['medicine_id' => $this->lacrima->id]],
        ])->assertUnprocessable()->assertJsonPath('allergy_conflicts.0.match', 'eye_drops');

        cs2pIssue($this, 'medication-prescription', ['content' => 'Colírio lubrificante sem marca — 1 gota 4x/dia'])
            ->assertUnprocessable();
    });

    it('alergia ao conservante também dispara pelo nome comercial no texto livre, SEM a palavra "colírio" escrita (11ª revisão Codex, P1)', function () {
        cs2pAllergy($this, 'Cloreto de benzalcônio', ['category' => 'preservative']);

        // "solução oftálmica" — não tem "colírio" — e o conservante não é o
        // princípio ativo de ninguém, então brandNames() também não achava.
        cs2pIssue($this, 'medication-prescription', ['content' => 'Lacrima Plus — solução oftálmica, 1 gota 4x/dia'])
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.match', 'eye_drops');
    });

    it('alergia ao conservante dispara pra colírio sem forma farmacêutica CMED — cadastro manual, só com apresentação (12ª revisão Codex, P1)', function () {
        // OphthalmicMedicinesSeeder: colírio cadastrado à mão marca a
        // apresentação (medicine_presentation_id), não a forma farmacêutica
        // CMED — pharmaceutical_form fica nulo.
        $presentation = MedicinePresentation::query()->create(['name' => 'Colírio 5 ml', 'active' => true]);
        Medicine::query()->create([
            'name'                     => 'TIMOLOL', 'active_ingredient' => 'MALEATO DE TIMOLOL',
            'medicine_presentation_id' => $presentation->id, 'active' => true,
        ]);
        cs2pAllergy($this, 'Cloreto de benzalcônio', ['category' => 'preservative']);

        cs2pIssue($this, 'medication-prescription', ['content' => 'Timolol 0,5% — 1 gota 2x/dia'])
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.match', 'eye_drops');
    });

    it('alergia ao conservante dispara pra colírio cadastrado com a tela em inglês — search_text não tem "colirio" (14ª revisão Codex, P1)', function () {
        // search_text grava a tradução de QUEM salvou o remédio — cadastro
        // feito em inglês grava "eye drops", sem a palavra "colirio" (sem
        // acento) que o filtro de texto livre procurava; pharmaceutical_form
        // é o código CMED, não traduz, bate em qualquer idioma salvo.
        $originalLocale = app()->getLocale();
        app()->setLocale('en');
        $medicine = Medicine::query()->create([
            'name'                => 'OPTIVE', 'active_ingredient' => 'CARBOXIMETILCELULOSE SÓDICA',
            'pharmaceutical_form' => 'sol_oft', 'active' => true,
        ]);
        app()->setLocale($originalLocale);

        expect($medicine->search_text)->not->toContain('colirio');

        cs2pAllergy($this, 'Cloreto de benzalcônio', ['category' => 'preservative']);

        cs2pIssue($this, 'medication-prescription', ['content' => 'Optive — 1 gota 4x/dia'])
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.match', 'eye_drops');
    });

    it('nome comercial no texto livre (catálogo) bate com o princípio ativo da alergia', function () {
        cs2pAllergy($this, 'Diclofenaco');

        cs2pIssue($this, 'medication-prescription', ['content' => 'Voltaren 50 mg — 1 cp 12/12 h por 5 dias'])
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.match', 'brand')
            ->assertJsonPath('allergy_conflicts.0.matched', 'VOLTAREN');
    });

    it('nome comercial bate mesmo com princípio ativo de VÁRIAS palavras com conector no meio (3ª revisão Codex, P1)', function () {
        // "Hidróxido DE alumínio": TermNormalizer::core() tira o "de" pra
        // comparar ("hidroxido aluminio"), mas o search_text do catálogo
        // (Medicine::normalizeSearch(), sem esse filtro) continua com o "de"
        // no meio — buscar o termo inteiro como substring nunca achava o
        // MAALOX, e a alergia por nome comercial não disparava.
        Medicine::query()->create(['name' => 'MAALOX', 'active_ingredient' => 'Hidróxido de alumínio + Hidróxido de magnésio', 'active' => true]);
        cs2pAllergy($this, 'Hidróxido de alumínio');

        cs2pIssue($this, 'medication-prescription', ['content' => 'Maalox — 1 colher de sopa após as refeições'])
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.match', 'brand')
            ->assertJsonPath('allergy_conflicts.0.matched', 'MAALOX');
    });

    it('remédio DESATIVADO no catálogo continua disparando pelo nome comercial no texto livre (16ª revisão Codex, P1)', function () {
        // Descontinuado no catálogo, mas um modelo/receita já existente pode
        // continuar citando o nome — sem o item estruturado (texto livre
        // puro), a busca por marca não pode ficar cega pra ele.
        $this->amoxil->update(['active' => false]);
        cs2pAllergy($this, 'Amoxicilina');

        cs2pIssue($this, 'medication-prescription', ['content' => 'AMOXIL 500 MG — 1 cápsula de 8/8h'])
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.match', 'brand')
            ->assertJsonPath('allergy_conflicts.0.matched', 'AMOXIL');
    });

    it('colírio DESATIVADO no catálogo continua disparando a alergia ao conservante pelo nome comercial (16ª revisão Codex, P1)', function () {
        $this->lacrima->update(['active' => false]);
        cs2pAllergy($this, 'Cloreto de benzalcônio', ['category' => 'preservative']);

        // "solução oftálmica" — sem a palavra "colírio" — só o pharmaceutical_form
        // (CMED) e o nome comercial dizem que é colírio.
        cs2pIssue($this, 'medication-prescription', ['content' => 'Lacrima Plus — solução oftálmica, 1 gota 4x/dia'])
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.match', 'eye_drops');
    });

    it('suspensão oftálmica cadastrada à mão (OphthalmicMedicinesSeeder) também dispara a alergia ao conservante (17ª revisão Codex, P1)', function () {
        // Mesmo padrão do colírio manual (12ª revisão): sem pharmaceutical_form
        // CMED, só a apresentação — "Suspensão oftálmica" não tem a palavra
        // "colírio", mas é o mesmo risco de conservante.
        $presentation = MedicinePresentation::query()->create(['name' => 'Suspensão oftálmica', 'active' => true]);
        Medicine::query()->create([
            'name'                     => 'PREDNISOLONA 1%', 'active_ingredient' => 'ACETATO DE PREDNISOLONA',
            'medicine_presentation_id' => $presentation->id, 'active' => true,
        ]);
        cs2pAllergy($this, 'Cloreto de benzalcônio', ['category' => 'preservative']);

        cs2pIssue($this, 'medication-prescription', ['content' => 'Prednisolona 1% — 1 gota de 6/6h'])
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.match', 'eye_drops');
    });

    it('suspensão oftálmica como ITEM ESTRUTURADO do catálogo também dispara (Medicine::isEyeDrop(), 17ª revisão Codex, P1)', function () {
        $presentation = MedicinePresentation::query()->create(['name' => 'Suspensão oftálmica', 'active' => true]);
        $prednisolona = Medicine::query()->create([
            'name'                     => 'PREDNISOLONA 1%', 'active_ingredient' => 'ACETATO DE PREDNISOLONA',
            'medicine_presentation_id' => $presentation->id, 'active' => true,
        ]);
        cs2pAllergy($this, 'Cloreto de benzalcônio', ['category' => 'preservative']);

        // Texto livre NEUTRO (sem o nome do remédio) — isola o item
        // estruturado (Medicine::isEyeDrop()) do texto livre
        // (eyeDropMentioned()), que são caminhos independentes.
        cs2pIssue($this, 'medication-prescription', [
            'content' => '1 gota de 6/6h',
            'items'   => [['medicine_id' => $prednisolona->id]],
        ])->assertUnprocessable()->assertJsonPath('allergy_conflicts.0.match', 'eye_drops');
    });

    it('alergia digitada com a dose junto ("Dipirona 500 mg") bate com receita de dose DIFERENTE (16ª revisão Codex, P1)', function () {
        // Sem catálogo nem princípio ativo próprio: a substância "crua" virava
        // o termo de comparação, dose incluída — exigia a frase inteira.
        cs2pAllergy($this, 'Dipirona 500 mg');

        cs2pIssue($this, 'medication-prescription', ['content' => 'Dipirona 1 g — se dor, até 4x/dia'])
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.match', 'text');
    });
});

describe('outros documentos que prescrevem medicamento', function () {
    it('receita de catarata pré-op (moxifloxacino) com alergia a quinolona → 422; com motivo → 201', function () {
        cs2pAllergy($this, 'Ciprofloxacino');

        cs2pIssue($this, 'cataract-prescription', ['eye' => 'right', 'template' => 'pre_operatorio'])
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.match', 'class');

        cs2pIssue($this, 'cataract-prescription', ['eye' => 'right', 'template' => 'pre_operatorio', 'allergy_override_reason' => 'Paciente tolera moxifloxacino (usou em 2024)'])
            ->assertCreated();
    });

    it('receita de pterígio (Voltaren) com alergia a diclofenaco → 422 pelo nome comercial', function () {
        cs2pAllergy($this, 'Diclofenaco sódico');

        cs2pIssue($this, 'pterygium-prescription', [])
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.match', 'brand');
    });

    it('receita de catarata pós-op (cefadroxila) com alergia a amoxicilina → 422 por classe', function () {
        cs2pAllergy($this, 'Amoxicilina');

        cs2pIssue($this, 'cataract-prescription', ['eye' => 'left', 'template' => 'pos_operatorio'])
            ->assertUnprocessable();
    });

    it('modelo de RECEITA salvo pela Documentação passa pela mesma checagem; laudo não', function () {
        cs2pAllergy($this, 'Tobramicina');

        $prescription = ReportSettingContent::query()->whereHas('reportSetting', fn ($q) => $q->where('title', 'PRESCRIÇÃO DE MEDICAMENTOS'))->firstOrFail();
        $report       = ReportSettingContent::query()->whereHas('reportSetting', fn ($q) => $q->where('title', 'RELATÓRIO OFTALMOLÓGICO'))->firstOrFail();
        $url          = route('panel.patients.medicalrecords.documentations.store', [$this->patient, $this->record]);

        cs2pAs($this, $this->docEu)->postJson($url, ['report_setting_content_id' => $prescription->id, 'content' => '<p>Tobrex colírio — 1 gota 4x/dia (tobramicina)</p>'])
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.substance', 'Tobramicina');

        cs2pAs($this, $this->docEu)->postJson($url, [
            'report_setting_content_id' => $prescription->id, 'content' => '<p>Tobrex colírio (tobramicina)</p>',
            'allergy_override_reason'   => 'Reação prévia foi ao conservante, não ao antibiótico',
        ])->assertCreated();

        // Relatório que só CITA a alergia não é receita: não pede motivo.
        cs2pAs($this, $this->docEu)->postJson($url, ['report_setting_content_id' => $report->id, 'content' => '<p>Paciente alérgico a tobramicina.</p>'])
            ->assertCreated();
    });

    it('modelo PESSOAL de receita aplicado no editor: item do catálogo entra na checagem mesmo sem "colírio" no texto (3ª revisão Codex, P1)', function () {
        // Conservante (benzalcônio): QUALQUER colírio avisa — mas o texto
        // final não tem a palavra "colírio", só o nome comercial. Sem o
        // vínculo do modelo com o catálogo (payload.items), a checagem por
        // texto livre não reconhece LACRIMA PLUS como colírio e não avisava.
        cs2pAllergy($this, 'Cloreto de benzalcônio', ['category' => 'preservative']);

        $prescription = ReportSettingContent::query()->whereHas('reportSetting', fn ($q) => $q->where('title', 'PRESCRIÇÃO DE MEDICAMENTOS'))->firstOrFail();
        $template     = DoctorDocumentTemplate::query()->create([
            'entity_id'                 => $this->entity->id, 'doctor_id' => $this->doctor->id, 'kind' => 'prescription', 'body_format' => 'html',
            'report_setting_content_id' => $prescription->id, 'name' => 'Lágrima padrão',
            'body'                      => '<p>Lacrima Plus — solução oftálmica, 1 gota 4x/dia</p>',
            'payload'                   => ['items' => [['medicine_id' => $this->lacrima->id, 'name' => 'LACRIMA PLUS', 'active_ingredient' => 'DEXTRANA + HIPROMELOSE']]],
        ]);

        $url = route('panel.patients.medicalrecords.documentations.store', [$this->patient, $this->record]);

        cs2pAs($this, $this->docEu)->postJson($url, [
            'report_setting_content_id' => $prescription->id,
            'content'                   => '<p>Lacrima Plus — solução oftálmica, 1 gota 4x/dia</p>',
            'template_id'               => $template->id,
        ])->assertUnprocessable()->assertJsonPath('allergy_conflicts.0.match', 'eye_drops');
    });

    it('modelo de RECEITA usado pelo laudo do Gerenciador de Imagens também é checado', function () {
        cs2pAllergy($this, 'Tobramicina');

        $prescription             = ReportSettingContent::query()->whereHas('reportSetting', fn ($q) => $q->where('title', 'PRESCRIÇÃO DE MEDICAMENTOS'))->firstOrFail();
        ['schedule' => $schedule] = createScheduleForEntity($this->entity, ['patient_id' => $this->patient->id, 'date_time' => now()]);
        $exam                     = PatientExam::factory()->create(['patient_id' => $this->patient->id, 'schedule_id' => $schedule->id, 'laterality' => 1]);
        $payload                  = [
            'patient_id' => $this->patient->id, 'exam_ids' => [$exam->id], 'report_setting_content_id' => $prescription->id,
            'content'    => '<p>Tobrex (tobramicina) 1 gota 4x/dia</p>', 'confirm_open_record' => true,
        ];

        cs2pAs($this, $this->docEu)->postJson(route('panel.eye-images.reports.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('allergy_conflicts.0.substance', 'Tobramicina');

        cs2pAs($this, $this->docEu)->postJson(route('panel.eye-images.reports.store'), [...$payload, 'allergy_override_reason' => 'Reação foi ao conservante'])
            ->assertCreated();
    });

    it('receita de óculos (tipo receita, sem medicamento) não é afetada', function () {
        cs2pAllergy($this, 'Látex', ['category' => 'latex']);

        cs2pIssue($this, 'lens-prescription', ['mode' => 'dynamic'])->assertCreated();
    });
});
