<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Covenant, Entity, Patient, People, ReportSettingContent, ReportSettingVariable, User};
use App\Services\TemplateVariableResolver;
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder};

/**
 * {{PACIENTE_CPF}} saía sempre vazio: o resolver lia `person->cpf`, campo que
 * não existe — o CPF da pessoa fica em `people.national_registry`. Vale para a
 * variável do sistema e para a variável da tabela (source_field person.cpf).
 */
beforeEach(function () {
    $this->seed([ReportSettingSeeder::class, ReportSettingContentSeeder::class]);

    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->doctor = doctorRecordForEntityUser(createEntityUser($this->entity, User::factory()->create(), ClientRule::Doctor->value));

    $this->patient = Patient::create([
        'entity_id'   => $this->entity->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
        'person_id'   => People::factory()->create(['full_name' => 'MARIA SOUZA', 'national_registry' => '52998224725'])->id,
    ]);

    $base = ReportSettingContent::query()
        ->whereHas('reportSetting', fn ($q) => $q->withoutGlobalScopes()->whereNull('entity_id'))
        ->firstOrFail();

    $this->content = $base->replicate(['id']);
    $this->content->fill(['slug' => 'cpf-teste', 'content' => '<p>CPF: {{PACIENTE_CPF}} / {{CPF_TABELA}}</p>'])->save();

    ReportSettingVariable::create([
        'report_setting_content_id' => $this->content->id,
        'placeholder'               => '{{CPF_TABELA}}',
        'label'                     => 'CPF',
        'source_type'               => 'patient',
        'source_field'              => 'person.cpf',
        'active'                    => true,
        'sort_order'                => 1,
    ]);
});

function pcpfResolve(object $test): string
{
    return app(TemplateVariableResolver::class)
        ->resolve($test->content, $test->patient, $test->doctor, $test->entity)['html'];
}

it('preenche {{PACIENTE_CPF}} e a variável person.cpf com o CPF formatado', function () {
    expect(pcpfResolve($this))->toContain('CPF: 529.982.247-25 / 529.982.247-25');
});

it('paciente sem CPF continua com o campo vazio, sem quebrar', function () {
    $this->patient->person->update(['national_registry' => null]);
    $this->patient->refresh();

    expect(pcpfResolve($this))->toContain('CPF:  / ');
});

it('o mapa de identidade do modelo pessoal inclui o CPF (para trocar de volta pela variável)', function () {
    $map = app(TemplateVariableResolver::class)
        ->identityReplacements($this->content, $this->patient, $this->doctor, $this->entity);

    expect($map['{{PACIENTE_CPF}}'] ?? null)->toBe('529.982.247-25');
});
