<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, DoctorDocumentTemplate, Entity, EntityUser, Indication, MedicalRecord, MedicalRecordDocumentation, Medicine, Patient, People, Procedure, ReportSettingContent, User};
use App\Models\PatientAllergy;
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};
use Illuminate\Support\Carbon;

/*
 * Fase 2 / B3 — "Salvar como modelo": modelos PESSOAIS do médico (por
 * clínica, com nome) para receita, pedido e relatório; listados junto dos
 * modelos da clínica no seletor de Documentações; só o dono edita/exclui;
 * secretária e outra clínica não veem.
 */
beforeEach(function () {
    $this->seed([ReportSettingSeeder::class, ReportSettingContentSeeder::class, ReportSettingVariableSeeder::class]);

    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->docEu   = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor  = doctorRecordForEntityUser($this->docEu);
    $this->doc2Eu  = createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value);
    $this->doctor2 = doctorRecordForEntityUser($this->doc2Eu);
    $this->secEu   = createEntityUser($this->entity, User::factory()->create(), ClientRule::Secretary->value);

    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id, 'person_id' => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id, 'active' => true,
    ]);
    $this->record = MedicalRecord::create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'main_complaint' => 'Ardência',
    ]);

    $this->report = ReportSettingContent::query()->where('slug', 'declaracao_medica')
        ->whereHas('reportSetting', fn ($q) => $q->withoutGlobalScopes()->whereNull('entity_id'))->firstOrFail();
});

function fb3tAs(object $test, EntityUser $eu): object
{
    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

function fb3tCreate(object $test, array $data, ?EntityUser $eu = null)
{
    // Todo "Salvar como modelo" nasce num atendimento (prontuário de origem).
    $data += ['medical_record_id' => $test->record->id];

    return fb3tAs($test, $eu ?? $test->docEu)->postJson(route('panel.doctor-document-templates.store'), $data);
}

it('salva modelo de receita (texto + itens conferidos no catálogo) e lista só os do médico', function () {
    $med = Medicine::query()->create(['name' => 'LACRIFILM', 'active_ingredient' => 'CARMELOSE SÓDICA', 'active' => true]);

    fb3tCreate($this, [
        'kind'    => 'prescription', 'name' => 'Olho seco',
        'body'    => 'LACRIFILM — 1 gota 4x ao dia',
        'payload' => ['items' => [['medicine_id' => $med->id, 'name' => 'Forjado', 'active_ingredient' => 'X'], ['name' => 'Compressa morna']]],
    ])->assertCreated()->assertJsonPath('data.payload.items.0', ['medicine_id' => $med->id, 'name' => 'Forjado', 'active_ingredient' => 'CARMELOSE SÓDICA']);

    fb3tCreate($this, ['kind' => 'prescription', 'name' => 'Do colega', 'body' => 'X'], $this->doc2Eu)->assertCreated();

    $list = fb3tAs($this, $this->docEu)->getJson(route('panel.doctor-document-templates.index', ['kind' => 'prescription']))->assertOk()->json('data');

    expect(collect($list)->pluck('name')->all())->toBe(['Olho seco'])
        ->and(DoctorDocumentTemplate::query()->withoutGlobalScopes()->where('name', 'Olho seco')->value('report_setting_content_id'))->not->toBeNull();
});

it('item SEM catálogo com princípio ativo combinado longo não é truncado abaixo do limite do catálogo (14ª revisão Codex, P2)', function () {
    // Item digitado solto (sem medicine_id) cortava o active_ingredient em
    // 500 — abaixo do limite real do catálogo (medicines.active_ingredient,
    // 1000), que o item COM medicine_id (copiado direto) já respeitava.
    $longIngredient = rtrim(str_repeat('PRINCIPIO ATIVO COMBINADO, ', 20), ', ');
    expect(mb_strlen($longIngredient))->toBeGreaterThan(500)->toBeLessThanOrEqual(1000);

    fb3tCreate($this, [
        'kind'    => 'prescription', 'name' => 'Combinado longo',
        'body'    => 'Fórmula combinada — 1 cp ao dia',
        'payload' => ['items' => [['name' => 'Fórmula combinada', 'active_ingredient' => $longIngredient]]],
    ])->assertCreated()->assertJsonPath('data.payload.items.0.active_ingredient', $longIngredient);
});

it('nome do modelo (criar ou renomear) também não pode levar dado do paciente (9ª revisão Codex, P2)', function () {
    $name = $this->patient->person->full_name;

    fb3tCreate($this, ['kind' => 'report', 'name' => $name, 'body' => 'Texto qualquer', 'report_setting_content_id' => $this->report->id])
        ->assertUnprocessable()->assertJsonValidationErrors('name');

    $id = fb3tCreate($this, ['kind' => 'report', 'name' => 'Nome limpo', 'body' => 'Texto qualquer', 'report_setting_content_id' => $this->report->id])
        ->assertCreated()->json('data.id');

    // Renomear sem o prontuário de origem (igual corpo/payload) é recusado
    // antes mesmo de checar o nome.
    fb3tAs($this, $this->docEu)->putJson(route('panel.doctor-document-templates.update', $id), ['name' => $name])
        ->assertUnprocessable()->assertJsonValidationErrors('medical_record_id');

    // Com o prontuário: recusa pelo CONTEÚDO do nome.
    fb3tAs($this, $this->docEu)->putJson(route('panel.doctor-document-templates.update', $id), ['name' => $name, 'medical_record_id' => $this->record->id])
        ->assertUnprocessable()->assertJsonValidationErrors('name');

    // Nome limpo, com prontuário: funciona.
    fb3tAs($this, $this->docEu)->putJson(route('panel.doctor-document-templates.update', $id), ['name' => 'Outro nome limpo', 'medical_record_id' => $this->record->id])
        ->assertOk()->assertJsonPath('data.name', 'Outro nome limpo');
});

it('nome único por médico e tipo (sem diferenciar maiúsculas); outro médico pode repetir', function () {
    fb3tCreate($this, ['kind' => 'procedure', 'name' => 'Glaucoma', 'body' => 'Campimetria'])->assertCreated();
    fb3tCreate($this, ['kind' => 'procedure', 'name' => 'GLAUCOMA', 'body' => 'Outro'])->assertUnprocessable()->assertJsonValidationErrors('name');
    fb3tCreate($this, ['kind' => 'procedure', 'name' => 'Glaucoma', 'body' => 'Outro'], $this->doc2Eu)->assertCreated();
});

it('só o dono edita e exclui; de outro médico → 404', function () {
    $id = fb3tCreate($this, ['kind' => 'procedure', 'name' => 'Pré-op', 'body' => 'Biometria'])->json('data.id');

    fb3tAs($this, $this->doc2Eu)->putJson(route('panel.doctor-document-templates.update', $id), ['name' => 'Roubado'])->assertNotFound();
    fb3tAs($this, $this->doc2Eu)->deleteJson(route('panel.doctor-document-templates.destroy', $id))->assertNotFound();

    fb3tAs($this, $this->docEu)->putJson(route('panel.doctor-document-templates.update', $id), ['name' => 'Pré-op catarata', 'body' => 'Biometria + microscopia', 'medical_record_id' => $this->record->id])
        ->assertOk()->assertJsonPath('data.name', 'Pré-op catarata');
    fb3tAs($this, $this->docEu)->deleteJson(route('panel.doctor-document-templates.destroy', $id))->assertOk();

    expect(DoctorDocumentTemplate::query()->withoutGlobalScopes()->whereNull('deleted_at')->count())->toBe(0);
});

it('secretária (sem cadastro de médico) não usa modelos pessoais', function () {
    fb3tAs($this, $this->secEu)->getJson(route('panel.doctor-document-templates.index'))->assertForbidden();
    fb3tCreate($this, ['kind' => 'prescription', 'name' => 'X', 'body' => 'Y'], $this->secEu)->assertForbidden();
});

it('modelo de outra clínica do MESMO médico não aparece aqui (tenant)', function () {
    $other       = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherEu     = createEntityUser($other, $this->docEu->user, ClientRule::Doctor->value);
    $otherDoctor = doctorRecordForEntityUser($otherEu);
    $otherRecord = MedicalRecord::create([
        'entity_id'  => $other->id, 'doctor_id' => $otherDoctor->id, 'main_complaint' => 'x',
        'patient_id' => Patient::create(['entity_id' => $other->id, 'person_id' => People::factory()->create()->id, 'covenant_id' => Covenant::factory()->create()->id, 'active' => true])->id,
    ]);

    fb3tCreate($this, ['kind' => 'prescription', 'name' => 'Da outra clínica', 'body' => 'Y', 'medical_record_id' => $otherRecord->id], $otherEu)->assertCreated();

    expect(fb3tAs($this, $this->docEu)->getJson(route('panel.doctor-document-templates.index'))->json('data'))->toBe([]);
});

it('relatório salvo pelo editor: HTML sanitizado, aparece no seletor junto dos modelos da clínica e a prévia devolve o conteúdo', function () {
    $id = fb3tCreate($this, [
        'kind'                      => 'report', 'name' => 'Relatório para perícia',
        'body'                      => '<p>Paciente com <strong>baixa visual</strong></p><script>alert(1)</script>',
        'report_setting_content_id' => $this->report->id,
        'medical_record_id'         => $this->record->id,
    ])->assertCreated()->json('data.id');

    expect(DoctorDocumentTemplate::query()->withoutGlobalScopes()->find($id)->body)->not->toContain('<script>');

    $groups = fb3tAs($this, $this->docEu)->getJson(route('panel.patients.medicalrecords.templates', [$this->patient, $this->record]))->assertOk()->json();

    expect($groups[0])->toMatchArray(['report_setting_id' => 'personal', 'personal' => true])
        ->and($groups[0]['contents'][0])->toMatchArray(['template_id' => $id, 'base_id' => $this->report->id, 'label' => 'Relatório para perícia'])
        ->and(count($groups))->toBeGreaterThan(5);

    // Colega não vê.
    $colleague = fb3tAs($this, $this->doc2Eu)->getJson(route('panel.patients.medicalrecords.templates', [$this->patient, $this->record]))->json();
    expect(collect($colleague)->pluck('report_setting_id'))->not->toContain('personal');

    $preview = fb3tAs($this, $this->docEu)->postJson(route('panel.patients.medicalrecords.template-preview', [$this->patient, $this->record]), [
        'report_setting_content_id' => $this->report->id, 'template_id' => $id,
    ])->assertOk()->json('content');

    expect($preview)->toContain('<strong>baixa visual</strong>');

    // Modelo de outro médico na prévia → 404.
    fb3tAs($this, $this->doc2Eu)->postJson(route('panel.patients.medicalrecords.template-preview', [$this->patient, $this->record]), [
        'report_setting_content_id' => $this->report->id, 'template_id' => $id,
    ])->assertNotFound();
});

it('listagem do editor devolve os itens do catálogo do modelo PESSOAL de receita; modelo de pedido não leva "items" (3ª revisão Codex, P1)', function () {
    $med = Medicine::query()->create(['name' => 'LACRIMA PLUS', 'active_ingredient' => 'DEXTRANA + HIPROMELOSE', 'pharmaceutical_form' => 'sol_oft', 'is_ophthalmic' => true, 'active' => true]);

    $rxId = fb3tCreate($this, [
        'kind'    => 'prescription', 'name' => 'Olho seco padrão',
        'body'    => 'Lacrima Plus — 1 gota 4x ao dia',
        'payload' => ['items' => [['medicine_id' => $med->id, 'name' => 'LACRIMA PLUS']]],
    ])->assertCreated()->json('data.id');

    $procId = fb3tCreate($this, ['kind' => 'procedure', 'name' => 'Pedido padrão', 'body' => '- Campimetria'])->assertCreated()->json('data.id');

    $groups   = fb3tAs($this, $this->docEu)->getJson(route('panel.patients.medicalrecords.templates', [$this->patient, $this->record]))->assertOk()->json();
    $contents = collect($groups)->firstWhere('report_setting_id', 'personal')['contents'];

    expect(collect($contents)->firstWhere('template_id', $rxId)['items'])
        ->toBe([['medicine_id' => $med->id, 'name' => 'LACRIMA PLUS', 'active_ingredient' => 'DEXTRANA + HIPROMELOSE']])
        ->and(collect($contents)->firstWhere('template_id', $procId)['items'])->toBeNull();
});

it('prévia do modelo PESSOAL de pedido no editor mostra caráter/indicação/observações, não só a lista de exames (3ª revisão Codex, P2)', function () {
    $proc       = Procedure::query()->create(['entity_id' => $this->entity->id, 'name' => 'CAMPIMETRIA', 'code' => '40103137', 'active' => true]);
    $indication = Indication::query()->create(['entity_id' => $this->entity->id, 'description' => 'Suspeita de glaucoma', 'active' => true]);

    // Sem report_setting_content_id explícito: igual ao modal de pedido
    // (ProcedureRequestModal), que resolve a base pelo kind — body_format
    // fica 'text' (explícito vira 'html', o caminho do editor; é o modal
    // quem tem o P2 da revisão).
    $data = fb3tCreate($this, [
        'kind'    => 'procedure', 'name' => 'Glaucoma padrão',
        'body'    => "- {$proc->name}\n",
        'payload' => [
            'items'           => [['procedure_id' => $proc->id]],
            'type'            => 'urgencia',
            'indications'     => [$indication->id],
            'indication_text' => 'Pressão alta',
            'notes'           => 'Trazer exames anteriores',
        ],
    ])->assertCreated()->json('data');

    $preview = fb3tAs($this, $this->docEu)->postJson(route('panel.patients.medicalrecords.template-preview', [$this->patient, $this->record]), [
        'report_setting_content_id' => $data['report_setting_content_id'], 'template_id' => $data['id'],
    ])->assertOk()->json('content');

    expect($preview)->toContain('CAMPIMETRIA')
        ->toContain('Caráter:</strong> Urgência')
        ->toContain('Indicação clínica:</strong> Suspeita de glaucoma; Pressão alta')
        ->toContain('Observações:</strong> Trazer exames anteriores');
});

it('prévia do modelo de PEDIDO sem payload (só corpo) usa o corpo salvo, não lista vazia (11ª revisão Codex, P2)', function () {
    $data = fb3tCreate($this, ['kind' => 'procedure', 'name' => 'Pré-op simples', 'body' => '- Biometria'])
        ->assertCreated()->json('data');

    $preview = fb3tAs($this, $this->docEu)->postJson(route('panel.patients.medicalrecords.template-preview', [$this->patient, $this->record]), [
        'report_setting_content_id' => $data['report_setting_content_id'], 'template_id' => $data['id'],
    ])->assertOk()->json('content');

    expect($preview)->toContain('Biometria');
});

it('prévia do modelo de PEDIDO sem payload.items também mostra caráter/indicação/observações do payload (16ª revisão Codex, P2)', function () {
    $data = fb3tCreate($this, [
        'kind'    => 'procedure', 'name' => 'Pré-op com observações', 'body' => '- Biometria',
        'payload' => ['type' => 'rotina', 'indication_text' => 'PIO 26 mmHg', 'notes' => 'Ambos os olhos'],
    ])->assertCreated()->json('data');

    $preview = fb3tAs($this, $this->docEu)->postJson(route('panel.patients.medicalrecords.template-preview', [$this->patient, $this->record]), [
        'report_setting_content_id' => $data['report_setting_content_id'], 'template_id' => $data['id'],
    ])->assertOk()->json('content');

    expect($preview)->toContain('Biometria')
        ->and($preview)->toContain('<strong>Caráter:</strong> Rotina')
        ->and($preview)->toContain('PIO 26 mmHg')
        ->and($preview)->toContain('Ambos os olhos');
});

it('emitir com o modelo registra qual modelo foi usado; modelo de outro médico → 422', function () {
    $mine   = fb3tCreate($this, ['kind' => 'prescription', 'name' => 'Conjuntivite', 'body' => 'Colírio X'])->json('data.id');
    $theirs = fb3tCreate($this, ['kind' => 'prescription', 'name' => 'Do colega', 'body' => 'Colírio Y'], $this->doc2Eu)->json('data.id');

    $url = route('panel.patients.medicalrecords.quick-actions.issue', [$this->patient, $this->record, 'medication-prescription']);

    fb3tAs($this, $this->docEu)->postJson($url, ['content' => 'Colírio X', 'template_id' => $theirs])
        ->assertUnprocessable()->assertJsonValidationErrors('template_id');

    fb3tAs($this, $this->docEu)->postJson($url, ['content' => 'Colírio X', 'template_id' => $mine])->assertCreated();

    expect(MedicalRecordDocumentation::query()->value('metadata'))->toMatchArray(['personal_template' => ['id' => $mine, 'name' => 'Conjuntivite']]);
});

it('documentação salva pelo editor a partir de modelo pessoal guarda a referência', function () {
    $id = fb3tCreate($this, ['kind' => 'report', 'name' => 'Laudo livre', 'body' => '<p>Texto</p>', 'report_setting_content_id' => $this->report->id, 'medical_record_id' => $this->record->id])->json('data.id');

    fb3tAs($this, $this->docEu)->postJson(route('panel.patients.medicalrecords.documentations.store', [$this->patient, $this->record]), [
        'report_setting_content_id' => $this->report->id, 'content' => '<p>Texto</p>', 'template_id' => $id,
    ])->assertCreated();

    expect(MedicalRecordDocumentation::query()->value('metadata')['personal_template']['id'])->toBe($id);
});

it('modelo-base de outra clínica é recusado', function () {
    $other = Entity::factory()->create(['is_client' => true]);
    // Cópia adotada dos modelos pela outra clínica (EntityObserver) — pega uma.
    $foreign = ReportSettingContent::query()
        ->whereHas('reportSetting', fn ($q) => $q->withoutGlobalScopes()->where('entity_id', $other->id))
        ->first();

    expect($foreign)->not->toBeNull();

    fb3tCreate($this, ['kind' => 'report', 'name' => 'Indevido', 'body' => '<p>x</p>', 'report_setting_content_id' => $foreign->id, 'medical_record_id' => $this->record->id])
        ->assertUnprocessable()->assertJsonValidationErrors('report_setting_content_id');
});

it('receita com alergia: 422 sem motivo; reenvio com motivo mantém a data alternativa e o modelo usado', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 10:00', 'America/Sao_Paulo'));
    PatientAllergy::query()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'category' => 'medication',
        'substance' => 'Moxifloxacino', 'reaction' => 'Urticária', 'severity' => 'moderate', 'status' => 'reported',
    ]);
    $tpl = fb3tCreate($this, ['kind' => 'prescription', 'name' => 'Pós-op', 'body' => 'Vigamox (moxifloxacino) 4x/dia'])->json('data.id');

    $url     = route('panel.patients.medicalrecords.quick-actions.issue', [$this->patient, $this->record, 'medication-prescription']);
    $payload = ['content' => 'Vigamox (moxifloxacino) 4x/dia', 'document_date' => '2026-10-01', 'template_id' => $tpl];

    fb3tAs($this, $this->docEu)->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('allergy_override_reason');
    expect(MedicalRecordDocumentation::query()->count())->toBe(0);

    fb3tAs($this, $this->docEu)->postJson($url, [...$payload, 'allergy_override_reason' => 'Sem alternativa; paciente orientada'])->assertCreated();

    $doc = MedicalRecordDocumentation::query()->sole();
    expect($doc->document_date->toDateString())->toBe('2026-10-01')
        ->and($doc->metadata['personal_template']['id'])->toBe($tpl)
        ->and($doc->safety_override['reason'])->toBe('Sem alternativa; paciente orientada');

    Carbon::setTestNow();
});
