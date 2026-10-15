<?php

declare(strict_types=1);

use App\Enums\Surgery\SurgeryDocumentType;
use App\Models\Surgery\{SurgeryClinicSetting, SurgeryConsentTemplate, SurgicalCase};
use App\Models\SurgeryType;
use App\Services\Surgery\Documents\{SurgeryDefaultTemplates, SurgeryTemplateService, SurgeryTemplateVariables};
use App\Services\Surgery\SurgicalCaseService;

/*
 * Módulo cirúrgico (Fase 4) / M3a — modelos de TCLE e de orientações
 * pré-operatórias: catálogo padrão por categoria, adoção idempotente e não
 * destrutiva, CRUD por perfil e por clínica, sanitização, variáveis com
 * escape e versionamento.
 */

beforeEach(function () {
    $this->w = surgeryWorld();
});

function sdtPayload(array $over = []): array
{
    return array_merge([
        'type' => 'consent',
        'name' => 'TCLE Catarata da clínica',
        'body' => '<p>Eu, {{paciente}}, autorizo a cirurgia {{cirurgia}} no {{olho}}.</p>',
    ], $over);
}

function sdtTemplate(object $w, array $over = []): SurgeryConsentTemplate
{
    return app(SurgeryTemplateService::class)->create((string) $w->entity->id, sdtPayload($over));
}

// ── Catálogo padrão ─────────────────────────────────────────────────────────

it('o catálogo padrão cobre as 14 categorias de cirurgia com TCLE e orientações, nas duas línguas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        $catalog = SurgeryDefaultTemplates::catalog($locale);

        expect($catalog)->toHaveCount(28);
        expect(array_unique(array_column($catalog, 'key')))->toHaveCount(28);
        expect(array_values(array_unique(array_column($catalog, 'category'))))->toEqualCanonicalizing(array_keys(SurgeryType::$categories));
        expect(array_count_values(array_column($catalog, 'type')))->toBe(['consent' => 14, 'pre_op_guidance' => 14]);
    }

    expect(SurgeryDefaultTemplates::catalog('en')[0]['name'])->toStartWith('Informed consent')
        ->and(SurgeryDefaultTemplates::catalog('pt_BR')[0]['name'])->toStartWith('TCLE');
});

it('todo modelo padrão usa só variáveis que o sistema conhece e traz o conteúdo mínimo', function () {
    $variables = app(SurgeryTemplateVariables::class);

    foreach (['pt_BR', 'en'] as $locale) {
        foreach (SurgeryDefaultTemplates::catalog($locale) as $entry) {
            expect($variables->unknownIn($entry['body']))->toBe([], "{$entry['key']} ({$locale})");

            if ($entry['type'] === 'consent') {
                expect($entry['body'])->toContain('{{paciente}}')->toContain('{{procedimentos}}')->toContain('{{cirurgiao}}');
            } else {
                expect($entry['body'])->toContain('{{data_cirurgia}}')->toContain('{{anestesia}}');
            }
        }
    }

    $cataract = collect(SurgeryDefaultTemplates::catalog('pt_BR'))->firstWhere('key', 'consent.cat1');
    $preOp    = collect(SurgeryDefaultTemplates::catalog('pt_BR'))->firstWhere('key', 'pre_op.cat1');

    expect($cataract['body'])->toContain('endoftalmite')->toContain('tansulosina')->toContain('Não há garantia');
    // Nunca orienta suspender anticoagulante por conta própria.
    expect($preOp['body'])->toContain('Não suspenda por conta própria');
});

// ── Adoção dos padrões ──────────────────────────────────────────────────────

it('adotar os padrões cria os 28 modelos e a segunda adoção não cria nada', function () {
    $service = app(SurgeryTemplateService::class);

    expect($service->missingDefaults((string) $this->w->entity->id))->toBe(28);

    expect($service->adoptDefaults((string) $this->w->entity->id, 'pt_BR'))->toBe(['created' => 28, 'skipped' => 0]);
    expect($service->adoptDefaults((string) $this->w->entity->id, 'pt_BR'))->toBe(['created' => 0, 'skipped' => 28]);

    expect(SurgeryConsentTemplate::query()->count())->toBe(28)
        ->and($service->missingDefaults((string) $this->w->entity->id))->toBe(0);
});

it('adoção nunca sobrescreve modelo personalizado nem ressuscita o apagado', function () {
    $service = app(SurgeryTemplateService::class);
    $service->adoptDefaults((string) $this->w->entity->id, 'pt_BR');

    $custom  = SurgeryConsentTemplate::query()->where('default_key', 'consent.cat1')->firstOrFail();
    $deleted = SurgeryConsentTemplate::query()->where('default_key', 'consent.cat2')->firstOrFail();

    $service->update($custom, ['body' => '<p>Texto da clínica para {{paciente}}</p>', 'name' => 'TCLE da clínica']);
    $service->delete($deleted);

    expect($service->adoptDefaults((string) $this->w->entity->id, 'en'))->toBe(['created' => 0, 'skipped' => 28]);

    $custom->refresh();

    expect($custom->name)->toBe('TCLE da clínica')
        ->and($custom->body)->toContain('Texto da clínica')
        ->and(SurgeryConsentTemplate::query()->count())->toBe(27)
        ->and(SurgeryConsentTemplate::query()->withTrashed()->count())->toBe(28);
});

it('a adoção escreve no idioma pedido e cada clínica tem os seus modelos', function () {
    $service = app(SurgeryTemplateService::class);

    $service->adoptDefaults((string) $this->w->entity->id, 'en');
    $service->adoptDefaults((string) $this->w->other->id, 'pt_BR');

    expect(SurgeryConsentTemplate::query()->withoutGlobalScopes()->where('entity_id', $this->w->entity->id)->where('default_key', 'consent.cat1')->value('name'))
        ->toBe('Informed consent — Cataract')
        ->and(SurgeryConsentTemplate::query()->withoutGlobalScopes()->where('entity_id', $this->w->other->id)->where('default_key', 'consent.cat1')->value('name'))
        ->toBe('TCLE — Catarata');
});

// ── Variáveis ───────────────────────────────────────────────────────────────

it('as variáveis entram com escape de HTML e a que falta no caso vira linha em branco', function () {
    $this->w->patient->person->update(['full_name' => '<script>alert(1)</script> & Maria']);

    $case = app(SurgicalCaseService::class)->create((string) $this->w->entity->id, [
        'patient_id' => (string) $this->w->patient->id, 'surgeon_doctor_id' => (string) $this->w->doctor->id,
        'title'      => 'Facoemulsificação <b>OD</b>', 'eye' => 'od',
        'procedures' => [['description' => 'Facoemulsificação <i>com</i> LIO', 'tuss_code' => '30306019']],
    ]);

    $variables = app(SurgeryTemplateVariables::class);
    $resolved  = $variables->resolve('<p>{{paciente}} — {{cirurgia}} — {{ data_cirurgia }} — {{x_desconhecida}}</p>{{procedimentos}}', $variables->forCase($case));

    expect($resolved['html'])->not->toContain('<script>')->not->toContain('<SCRIPT>')
        ->and($resolved['html'])->toContain('&lt;SCRIPT&gt;ALERT(1)&lt;/SCRIPT&gt; &amp; MARIA')
        ->and($resolved['html'])->toContain('Facoemulsificação &lt;b&gt;OD&lt;/b&gt;')
        ->and($resolved['html'])->toContain('<li>30306019 — Facoemulsificação &lt;i&gt;com&lt;/i&gt; LIO</li>')
        ->and($resolved['html'])->toContain(SurgeryTemplateVariables::BLANK)
        ->and($resolved['html'])->toContain('{{x_desconhecida}}')
        ->and($resolved['pending'])->toBe(['data_cirurgia'])
        ->and($resolved['unknown'])->toBe(['x_desconhecida']);
});

it('datas e a LIO saem no idioma da pessoa', function () {
    $case = app(SurgicalCaseService::class)->create((string) $this->w->entity->id, [
        'patient_id' => (string) $this->w->patient->id, 'surgeon_doctor_id' => (string) $this->w->doctor->id,
        'title'      => 'Cirurgia', 'eye' => 'oe',
        'iol_plans'  => [['eye' => 'oe', 'model_label' => 'LIO X', 'power' => 21.5]],
    ]);
    $case->forceFill(['scheduled_start' => '2026-11-10 08:30:00', 'scheduled_end' => '2026-11-10 09:30:00'])->save();

    app()->setLocale('pt_BR');
    $pt = app(SurgeryTemplateVariables::class)->forCase($case->fresh());

    app()->setLocale('en');
    $en = app(SurgeryTemplateVariables::class)->forCase($case->fresh());

    expect($pt['data_nascimento'])->toBe('10/03/1950')
        ->and($pt['data_cirurgia'])->toContain('10/11/2026')
        ->and($pt['lio'])->toContain('+21,50 D')
        ->and($en['data_nascimento'])->toBe('03/10/1950')
        ->and($en['lio'])->toContain('+21.50 D');
});

// ── CRUD por HTTP ───────────────────────────────────────────────────────────

it('só quem tem settings.manage (admin) mexe nos modelos; os demais perfis levam 403', function () {
    foreach (['secretary', 'doctor', 'financial'] as $who) {
        surgeryAs($this->w, $who)->get(route('panel.setting.surgery-templates.index'))->assertForbidden();
        surgeryAs($this->w, $who)->postJson(route('panel.setting.surgery-templates.store'), sdtPayload())->assertForbidden();
    }

    surgeryAs($this->w, 'admin')->get(route('panel.setting.surgery-templates.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Panel/Settings/SurgeryTemplates/Index')
            ->where('missingDefaults', 28)
            ->where('notices.authorization_enabled', false)
            ->has('variables', 12)
            ->has('t.documents.templates.title'));

    expect(SurgeryConsentTemplate::query()->count())->toBe(0);
});

it('clínica sem o recurso has_surgery não acessa os modelos', function () {
    $w = surgeryWorld(withModule: false);

    surgeryAs($w, 'admin')->getJson(route('panel.setting.surgery-templates.index'))->assertForbidden();
});

it('cria, edita e versiona: a versão só sobe quando o corpo muda', function () {
    surgeryAs($this->w, 'admin')->post(route('panel.setting.surgery-templates.store'), sdtPayload(['category' => 1]))->assertRedirect();

    $template = SurgeryConsentTemplate::query()->firstOrFail();
    expect($template->version)->toBe(1)->and($template->category)->toBe(1)->and($template->active)->toBeTrue();

    surgeryAs($this->w, 'admin')->put(route('panel.setting.surgery-templates.update', $template), ['name' => 'Novo nome'])->assertRedirect();
    expect($template->refresh()->version)->toBe(1)->and($template->name)->toBe('Novo nome');

    // Mesmo corpo reenviado: continua na versão 1.
    surgeryAs($this->w, 'admin')->put(route('panel.setting.surgery-templates.update', $template), ['body' => $template->body])->assertRedirect();
    expect($template->refresh()->version)->toBe(1);

    surgeryAs($this->w, 'admin')->put(route('panel.setting.surgery-templates.update', $template), ['body' => '<p>Outro texto de {{paciente}}.</p>'])->assertRedirect();
    expect($template->refresh()->version)->toBe(2);
});

it('o tipo não muda na edição, o corpo é sanitizado e variável desconhecida é recusada', function () {
    $template = sdtTemplate($this->w);

    surgeryAs($this->w, 'admin')->putJson(route('panel.setting.surgery-templates.update', $template), ['type' => 'pre_op_guidance'])
        ->assertStatus(422)->assertJsonValidationErrors('type');

    surgeryAs($this->w, 'admin')->postJson(route('panel.setting.surgery-templates.store'), sdtPayload(['body' => '<p>Oi {{paciente}} {{cpf}}</p>']))
        ->assertStatus(422)
        ->assertJsonPath('message', __('surgery_documents.errors.unknown_variables', ['variables' => '{{cpf}}']));

    surgeryAs($this->w, 'admin')->post(route('panel.setting.surgery-templates.store'), sdtPayload([
        'name' => 'XSS',
        'body' => '<p onclick="x()">Texto {{paciente}}</p><script>alert(1)</script><iframe src="http://evil"></iframe>',
    ]))->assertRedirect();

    $stored = SurgeryConsentTemplate::query()->where('name', 'XSS')->firstOrFail();

    expect($stored->body)->not->toContain('<script')->not->toContain('onclick')->not->toContain('<iframe')
        ->and($stored->body)->toContain('Texto {{paciente}}');
});

it('valida tipo, categoria e tamanho mínimo', function () {
    surgeryAs($this->w, 'admin')->postJson(route('panel.setting.surgery-templates.store'), sdtPayload(['type' => 'implant_record']))
        ->assertStatus(422)->assertJsonValidationErrors('type');

    surgeryAs($this->w, 'admin')->postJson(route('panel.setting.surgery-templates.store'), sdtPayload(['category' => 99]))
        ->assertStatus(422)->assertJsonValidationErrors('category');

    surgeryAs($this->w, 'admin')->postJson(route('panel.setting.surgery-templates.store'), sdtPayload(['name' => 'x', 'body' => 'curto']))
        ->assertStatus(422)->assertJsonValidationErrors(['name', 'body']);
});

it('duplica, inativa e exclui sem mexer em documento já emitido', function () {
    $template = sdtTemplate($this->w);

    surgeryAs($this->w, 'admin')->post(route('panel.setting.surgery-templates.duplicate', $template))->assertRedirect();

    $copy = SurgeryConsentTemplate::query()->where('id', '!=', $template->id)->firstOrFail();
    expect($copy->name)->toContain('(cópia)')->and($copy->body)->toBe($template->body)->and($copy->default_key)->toBeNull();

    surgeryAs($this->w, 'admin')->patch(route('panel.setting.surgery-templates.active', $copy), ['active' => false])->assertRedirect();
    expect($copy->refresh()->active)->toBeFalse();

    surgeryAs($this->w, 'admin')->delete(route('panel.setting.surgery-templates.destroy', $copy))->assertRedirect();
    expect(SurgeryConsentTemplate::query()->count())->toBe(1)
        ->and(SurgeryConsentTemplate::query()->withTrashed()->count())->toBe(2);
});

it('o modelo de outra clínica é 404 para ver, editar, duplicar, ativar e excluir', function () {
    $foreign = app(SurgeryTemplateService::class)->create((string) $this->w->other->id, sdtPayload());

    surgeryAs($this->w, 'admin')->getJson(route('panel.setting.surgery-templates.show', $foreign))->assertNotFound();
    surgeryAs($this->w, 'admin')->putJson(route('panel.setting.surgery-templates.update', $foreign), ['name' => 'invasão'])->assertNotFound();
    surgeryAs($this->w, 'admin')->postJson(route('panel.setting.surgery-templates.duplicate', $foreign))->assertNotFound();
    surgeryAs($this->w, 'admin')->patchJson(route('panel.setting.surgery-templates.active', $foreign), ['active' => false])->assertNotFound();
    surgeryAs($this->w, 'admin')->deleteJson(route('panel.setting.surgery-templates.destroy', $foreign))->assertNotFound();

    expect(SurgeryConsentTemplate::query()->withoutGlobalScopes()->whereKey($foreign->id)->value('name'))->toBe('TCLE Catarata da clínica');
});

it('a lista só mostra os modelos da própria clínica', function () {
    sdtTemplate($this->w, ['name' => 'Meu modelo']);
    app(SurgeryTemplateService::class)->create((string) $this->w->other->id, sdtPayload(['name' => 'Modelo alheio']));

    surgeryAs($this->w, 'admin')->get(route('panel.setting.surgery-templates.index'))
        ->assertInertia(fn ($page) => $page->has('templates', 1)->where('templates.0.name', 'Meu modelo'));
});

it('a pré-visualização usa dados de exemplo, sanitiza e avisa de variável desconhecida', function () {
    $response = surgeryAs($this->w, 'admin')->postJson(route('panel.setting.surgery-templates.preview'), [
        'body' => '<p>{{paciente}}</p><script>alert(1)</script>{{nope}}',
    ])->assertOk();

    expect($response->json('html'))->toContain('Maria Exemplo')->not->toContain('<script')
        ->and($response->json('unknown'))->toBe(['nope']);

    surgeryAs($this->w, 'secretary')->postJson(route('panel.setting.surgery-templates.preview'), ['body' => '<p>x</p>'])->assertForbidden();
});

it('a adoção pelo painel cria os modelos, é limitada por perfil e idempotente', function () {
    surgeryAs($this->w, 'secretary')->post(route('panel.setting.surgery-templates.adopt-defaults'))->assertForbidden();

    surgeryAs($this->w, 'admin')->post(route('panel.setting.surgery-templates.adopt-defaults'))->assertRedirect();
    expect(SurgeryConsentTemplate::query()->count())->toBe(28);

    surgeryAs($this->w, 'admin')->post(route('panel.setting.surgery-templates.adopt-defaults'))->assertRedirect();
    expect(SurgeryConsentTemplate::query()->count())->toBe(28);
});

it('o aviso de autorização por WhatsApp nasce desligado e só o admin liga', function () {
    expect(SurgeryClinicSetting::authorizationNoticeEnabled((string) $this->w->entity->id))->toBeFalse();

    surgeryAs($this->w, 'secretary')->patch(route('panel.setting.surgery-templates.notices'), ['authorization_enabled' => true])->assertForbidden();
    expect(SurgeryClinicSetting::authorizationNoticeEnabled((string) $this->w->entity->id))->toBeFalse();

    surgeryAs($this->w, 'admin')->patch(route('panel.setting.surgery-templates.notices'), ['authorization_enabled' => true])->assertRedirect();
    expect(SurgeryClinicSetting::authorizationNoticeEnabled((string) $this->w->entity->id))->toBeTrue()
        ->and(SurgeryClinicSetting::authorizationNoticeEnabled((string) $this->w->other->id))->toBeFalse();

    surgeryAs($this->w, 'admin')->patch(route('panel.setting.surgery-templates.notices'), ['authorization_enabled' => false])->assertRedirect();
    expect(SurgeryClinicSetting::authorizationNoticeEnabled((string) $this->w->entity->id))->toBeFalse();
    expect(SurgeryClinicSetting::query()->count())->toBe(1);
});

it('sugere primeiro o modelo da categoria da cirurgia, depois os gerais', function () {
    $general = sdtTemplate($this->w, ['name' => 'Geral']);
    $other   = sdtTemplate($this->w, ['name' => 'Retina', 'category' => 3]);
    $cat     = sdtTemplate($this->w, ['name' => 'Catarata', 'category' => 1]);
    sdtTemplate($this->w, ['name' => 'Inativo', 'category' => 1]);
    SurgeryConsentTemplate::query()->where('name', 'Inativo')->update(['active' => false]);

    $type = SurgeryType::query()->create(['entity_id' => $this->w->entity->id, 'category' => 1, 'name' => 'FACO', 'active' => true]);

    /** @var SurgicalCase $case */
    $case = app(SurgicalCaseService::class)->create((string) $this->w->entity->id, [
        'patient_id' => (string) $this->w->patient->id, 'surgeon_doctor_id' => (string) $this->w->doctor->id,
        'title'      => 'Faco', 'eye' => 'od', 'surgery_type_id' => (string) $type->id,
    ]);

    $names = app(SurgeryTemplateService::class)->suggestionsFor($case, SurgeryDocumentType::Consent)->pluck('name')->all();

    expect($names)->toBe(['Catarata', 'Geral', 'Retina']);
    expect($general->id)->not->toBe($other->id)->and($cat->id)->not->toBe($general->id);
});
