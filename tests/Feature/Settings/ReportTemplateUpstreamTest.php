<?php

declare(strict_types=1);

use App\Enums\{ClientRule, ReportSettingStatus, SaasRule};
use App\Models\{AuditLog, Entity, EntityUser, ReportSetting, ReportSettingContent, User};
use App\Services\ReportSettingService;
use Database\Seeders\{ReportSettingContentSeeder, ReportSettingSeeder, ReportSettingVariableSeeder};
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Modelos adotados × atualização do modelo padrão (Fase 2 — deploy do Bloco
 * B mudou os globais de comparecimento, atestado médico e solicitação).
 * Antes, sincronizar as cópias (seeders, `reports:sync-adopted`)
 * sobrescrevia QUALQUER texto diferente do global — inclusive o que a
 * clínica personalizou. Agora:
 *  - cópia intocada (igual à versão de que veio) acompanha o padrão;
 *  - cópia personalizada nunca é sobrescrita: fica com "atualização
 *    disponível" (ver padrão, aplicar ou manter o meu — auditados);
 *  - a migration marca as cópias atuais (igual ao global → versão; diferente
 *    → personalizada).
 */
beforeEach(function () {
    $this->seed([ReportSettingSeeder::class, ReportSettingContentSeeder::class, ReportSettingVariableSeeder::class]);

    // Clínica criada depois dos globais: nasce com cópia de todos (EntityObserver).
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->admin  = createEntityUser($this->entity, User::factory()->create(), ClientRule::Admin->value);

    $this->global = ReportSetting::query()->whereNull('entity_id')->where('title', 'ATESTADO DE COMPARECIMENTO')->firstOrFail();
    $this->copy   = ReportSetting::query()->withoutEntityScope()->where('entity_id', $this->entity->id)->where('source_setting_id', $this->global->id)->firstOrFail();
});

function rtuGlobalContent(object $test): ReportSettingContent
{
    return $test->global->contents()->where('slug', 'comparecimento')->firstOrFail();
}

function rtuLocal(object $test, ?ReportSetting $copy = null): ReportSettingContent
{
    return ($copy ?? $test->copy)->contents()->where('slug', 'comparecimento')->firstOrFail();
}

function rtuAs(object $test, EntityUser $eu): object
{
    return $test->actingAs($eu->user)->withSession(panelSession($eu));
}

/** A clínica edita o texto pela tela de Modelos (mesmo payload do formulário). */
function rtuCustomize(object $test, string $text): void
{
    $local = rtuLocal($test);

    rtuAs($test, $test->admin)->patch(route('panel.setting.report-settings.update', $test->copy), [
        'title'    => $test->copy->title,
        'contents' => [['id' => $local->id, 'type' => $local->type->value, 'label' => $local->label, 'content' => $text, 'active' => true]],
    ])->assertRedirect();
}

it('cópia adotada nasce marcada com a versão do padrão', function () {
    $local = rtuLocal($this);

    expect($local->source_content_hash)->toBe(rtuGlobalContent($this)->currentFingerprint())
        ->and($local->isUntouchedCopy())->toBeTrue();
});

it('padrão ARQUIVADO (status, não active) editado depois não oferece "atualização disponível" (13ª revisão Codex, P2)', function () {
    $this->global->update(['status' => ReportSettingStatus::Archived]);
    rtuGlobalContent($this)->update(['content' => '<p>Mudou depois de arquivado</p>']);

    expect(app(ReportSettingService::class)->pendingUpstreamUpdates($this->copy))->toBe([]);
});

it('renomear o rótulo de uma cópia de sistema sobrepõe a tradução padrão, sem perder o vínculo (11ª revisão Codex, P2)', function () {
    $local = rtuLocal($this);
    expect($local->is_system)->toBeTrue()->and($local->display_label)->not->toBe('Meu nome personalizado');

    rtuAs($this, $this->admin)->patch(route('panel.setting.report-settings.update', $this->copy), [
        'title'    => $this->copy->title,
        'contents' => [['id' => $local->id, 'type' => $local->type->value, 'label' => 'Meu nome personalizado', 'content' => $local->content, 'active' => true]],
    ])->assertRedirect();

    $local->refresh();
    expect($local->is_system)->toBeTrue() // ID e vínculo com o padrão continuam
        ->and($local->label)->toBe('Meu nome personalizado')
        ->and($local->display_label)->toBe('Meu nome personalizado');
});

it('cópia INTOCADA acompanha o padrão (mesmo ID) e fica com a nova versão', function () {
    $local = rtuLocal($this);
    rtuGlobalContent($this)->update(['content' => '<p>Novo padrão {{PACIENTE_NOME}}</p>']);

    $stats = app(ReportSettingService::class)->syncAdoptedContentsWithGlobal();

    $local->refresh();
    expect($local->content)->toBe('<p>Novo padrão {{PACIENTE_NOME}}</p>')
        ->and($local->source_content_hash)->toBe(rtuGlobalContent($this)->currentFingerprint())
        ->and($stats['contents_updated'])->toBeGreaterThan(0)
        ->and(app(ReportSettingService::class)->pendingUpstreamUpdates($this->copy->fresh()))->toBe([]);
});

it('cópia PERSONALIZADA não é sobrescrita e fica com "atualização disponível"', function () {
    $baseline = rtuGlobalContent($this)->currentFingerprint();
    rtuCustomize($this, '<p>Texto da nossa clínica {{PACIENTE_NOME}}</p>');
    $local = rtuLocal($this);

    // Editar pela tela personaliza (o conteúdo não bate mais com o padrão) e
    // mantém o mesmo conteúdo/slug. O hash de origem FICA (a baseline de
    // quando foi adotada) — zerá-lo aqui era o bug (2ª revisão Codex, P2-4):
    // a clínica via "atualização disponível" na hora, mesmo sem o padrão ter
    // mudado (ver teste dedicado abaixo).
    expect($local->source_content_hash)->toBe($baseline)
        ->and($local->isUntouchedCopy())->toBeFalse();

    rtuGlobalContent($this)->update(['content' => '<p>Padrão novo do EasyEye</p>']);
    $stats = app(ReportSettingService::class)->syncAdoptedContentsWithGlobal();

    expect($local->fresh()->content)->toBe('<p>Texto da nossa clínica {{PACIENTE_NOME}}</p>')
        ->and($stats['contents_skipped_customized'])->toBeGreaterThan(0);

    rtuAs($this, $this->admin)->get(route('panel.setting.report-settings.edit', $this->copy))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('reportSetting.contents', fn ($contents) => collect($contents)->firstWhere('id', $local->id)['upstream']['label'] === rtuGlobalContent($this)->label)
            ->where('t.upstream_badge', 'Atualização disponível do modelo padrão'));

    rtuAs($this, $this->admin)->get(route('panel.setting.report-settings.index', ['search' => 'COMPARECIMENTO']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.data.0.pending_updates', 1)
            ->where('items.data.0.has_update', true));
});

it('personalizar sem o padrão mudar NÃO acende "atualização disponível" (2ª revisão Codex, P2-4)', function () {
    // Antes, `syncContents()` zerava `source_content_hash` em qualquer
    // edição manual — perdendo a baseline (versão do padrão de que a cópia
    // veio) bem na hora em que ela mais importa: sem ela, a checagem seguinte
    // não tinha como saber que o padrão GLOBAL não mudou, e acusava
    // "atualização disponível" mesmo a clínica só tendo editado o próprio texto.
    rtuCustomize($this, '<p>Texto da nossa clínica {{PACIENTE_NOME}}</p>');
    $local = rtuLocal($this);

    expect(app(ReportSettingService::class)->pendingUpstreamUpdates($this->copy->fresh()))->toBe([]);

    // Só QUANDO o padrão de fato muda é que o aviso deve aparecer.
    rtuGlobalContent($this)->update(['content' => '<p>Padrão realmente novo</p>']);

    expect(array_keys(app(ReportSettingService::class)->pendingUpstreamUpdates($this->copy->fresh())))->toBe([$local->id]);
});

it('"Ver modelo padrão" mostra os dois textos lado a lado', function () {
    rtuCustomize($this, '<p>Meu texto</p>');
    rtuGlobalContent($this)->update(['content' => '<p>Padrão novo</p>']);
    $local = rtuLocal($this);

    rtuAs($this, $this->admin)->getJson(route('panel.setting.report-settings.contents.upstream', [$this->copy, $local->id]))
        ->assertOk()
        ->assertJsonPath('local.content', '<p>Meu texto</p>')
        ->assertJsonPath('global.content', '<p>Padrão novo</p>');
});

it('"Aplicar atualização" troca pelo padrão, religa a versão e registra na auditoria', function () {
    rtuCustomize($this, '<p>Meu texto</p>');
    rtuGlobalContent($this)->update(['content' => '<p>Padrão novo</p>']);
    $local = rtuLocal($this);

    rtuAs($this, $this->admin)->post(route('panel.setting.report-settings.contents.apply-update', [$this->copy, $local->id]))
        ->assertRedirect()->assertSessionHas('success', 'Atualização do modelo padrão aplicada.');

    $local->refresh();
    expect($local->content)->toBe('<p>Padrão novo</p>')
        ->and($local->isUntouchedCopy())->toBeTrue()
        ->and(app(ReportSettingService::class)->pendingUpstreamUpdates($this->copy->fresh()))->toBe([])
        ->and(AuditLog::query()->where('event', 'template_update_applied')->where('auditable_id', $local->id)->value('old_values'))
        ->toMatchArray(['content' => '<p>Meu texto</p>']);

    // Sem atualização pendente, aplicar de novo → 404.
    rtuAs($this, $this->admin)->post(route('panel.setting.report-settings.contents.apply-update', [$this->copy, $local->id]))->assertNotFound();
});

it('"Manter o meu" dispensa esta versão; o aviso volta quando o padrão mudar de novo', function () {
    rtuCustomize($this, '<p>Meu texto</p>');
    rtuGlobalContent($this)->update(['content' => '<p>Padrão v2</p>']);
    $local = rtuLocal($this);

    rtuAs($this, $this->admin)->post(route('panel.setting.report-settings.contents.keep', [$this->copy, $local->id]))->assertRedirect();

    expect($local->fresh()->content)->toBe('<p>Meu texto</p>')
        ->and(app(ReportSettingService::class)->pendingUpstreamUpdates($this->copy->fresh()))->toBe([])
        ->and(AuditLog::query()->where('event', 'template_update_kept')->where('auditable_id', $local->id)->exists())->toBeTrue();

    rtuGlobalContent($this)->update(['content' => '<p>Padrão v3</p>']);
    app(ReportSettingService::class)->syncAdoptedContentsWithGlobal();

    expect($local->fresh()->content)->toBe('<p>Meu texto</p>')
        ->and(array_keys(app(ReportSettingService::class)->pendingUpstreamUpdates($this->copy->fresh())))->toBe([$local->id]);
});

it('"Manter o meu" sobrevive à edição do texto local: o mesmo padrão não volta a ser anunciado (2ª revisão, P2-E)', function () {
    rtuCustomize($this, '<p>Meu texto</p>');
    rtuGlobalContent($this)->update(['content' => '<p>Padrão v2</p>']);
    $local = rtuLocal($this);

    rtuAs($this, $this->admin)->post(route('panel.setting.report-settings.contents.keep', [$this->copy, $local->id]))->assertRedirect();

    // A clínica edita o texto dela (rótulo e conteúdo) depois de dispensar.
    rtuCustomize($this, '<p>Meu texto revisado</p>');

    expect($local->fresh()->dismissed_source_hash)->toBe(rtuGlobalContent($this)->currentFingerprint())
        ->and(app(ReportSettingService::class)->pendingUpstreamUpdates($this->copy->fresh()))->toBe([]);

    // Padrão muda de novo → o aviso volta (versão diferente da dispensada).
    rtuGlobalContent($this)->update(['content' => '<p>Padrão v3</p>']);
    expect(array_keys(app(ReportSettingService::class)->pendingUpstreamUpdates($this->copy->fresh())))->toBe([$local->id]);
});

it('outra clínica não vê, não aplica e não mantém a atualização (404)', function () {
    rtuCustomize($this, '<p>Meu texto</p>');
    rtuGlobalContent($this)->update(['content' => '<p>Padrão novo</p>']);
    $local = rtuLocal($this);

    $other   = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $otherEu = createEntityUser($other, User::factory()->create(), ClientRule::Admin->value);

    rtuAs($this, $otherEu)->getJson(route('panel.setting.report-settings.contents.upstream', [$this->copy, $local->id]))->assertNotFound();
    rtuAs($this, $otherEu)->post(route('panel.setting.report-settings.contents.apply-update', [$this->copy, $local->id]))->assertNotFound();
    rtuAs($this, $otherEu)->post(route('panel.setting.report-settings.contents.keep', [$this->copy, $local->id]))->assertNotFound();

    // Conteúdo de OUTRO modelo da própria clínica no lugar → 404.
    $otherCopyContent = ReportSettingContent::query()
        ->whereHas('reportSetting', fn ($q) => $q->withoutEntityScope()->where('entity_id', $this->entity->id)->where('id', '!=', $this->copy->id))
        ->firstOrFail();
    rtuAs($this, $this->admin)->post(route('panel.setting.report-settings.contents.apply-update', [$this->copy, $otherCopyContent->id]))->assertNotFound();

    expect($local->fresh()->content)->toBe('<p>Meu texto</p>');
});

it('backfill da migration: cópia igual ao global ganha a versão; diferente fica personalizada', function () {
    $untouched = rtuLocal($this);

    $otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $customCopy  = ReportSetting::query()->withoutEntityScope()->where('entity_id', $otherEntity->id)->where('source_setting_id', $this->global->id)->firstOrFail();
    // Personalização antiga, feita antes da coluna existir (direto no banco).
    $customized = rtuLocal($this, $customCopy);
    $customized->forceFill(['content' => '<p>Personalizado antigo</p>'])->saveQuietly();

    $migration = require database_path('migrations/2026_10_17_000300_add_source_tracking_to_report_setting_contents.php');
    $migration->down();
    $migration->up();

    expect($untouched->fresh()->source_content_hash)->toBe(rtuGlobalContent($this)->currentFingerprint())
        ->and($customized->fresh()->source_content_hash)->toBeNull();

    // Deploy: o seed muda o padrão → só a intocada acompanha.
    rtuGlobalContent($this)->update(['content' => '<p>Padrão do deploy</p>']);
    Artisan::call('reports:sync-adopted');

    expect($untouched->fresh()->content)->toBe('<p>Padrão do deploy</p>')
        ->and($customized->fresh()->content)->toBe('<p>Personalizado antigo</p>')
        ->and(Artisan::output())->toContain('personalizados preservados');
});

it('salvar o formulário da clínica mantém o conteúdo (mesmo ID e slug) — antes recriava tudo sem slug', function () {
    $local = rtuLocal($this);

    rtuCustomize($this, '<p>Editado</p>');

    $after = $this->copy->contents()->get();
    expect($after->pluck('id')->all())->toContain($local->id)
        ->and($after->firstWhere('id', $local->id)->slug)->toBe('comparecimento')
        ->and($after->firstWhere('id', $local->id)->content)->toBe('<p>Editado</p>');
});

it('manager: salvar o cabeçalho/layout de um modelo padrão não apaga os conteúdos dele', function () {
    $saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $admin = User::factory()->create();
    createEntityUser($saas, $admin, SaasRule::Admin->value);
    $before = $this->global->contents()->count();

    $this->actingAs($admin)->withSession(['selected_entity_id' => $saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => 'admin'])
        ->putJson(route('manager.report-settings.update', $this->global), ['title' => $this->global->title, 'paper_size' => 'A5'])
        ->assertOk();

    expect($this->global->contents()->count())->toBe($before)->and($before)->toBeGreaterThan(0);
});
