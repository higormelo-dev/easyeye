<?php

declare(strict_types=1);

/**
 * EE-14 (auditoria de encerramento 11/10/2026): report_setting_preview_html.
 * blade.php (prévia real, rota manager.report-settings.preview) e seu
 * irmão report_setting_preview.blade.php (variante pra geração de PDF —
 * confirmado ÓRFÃO hoje, nenhum controller renderiza; corrigido pelo mesmo
 * motivo de higiene de catálogo, testado aqui só por render() direto, sem
 * rota) tinham "Pág. X/Y"/"Página X de Y" e "Emitido em" fixos em PT, fora
 * do catálogo — nunca mudavam com o idioma do usuário.
 */

use App\Enums\{DocumentationType, ReportSettingStatus};
use App\Models\{Entity, ReportSetting, ReportSettingContent, User};

function reportSettingPreviewSaasAdminSession(Entity $saas): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ];
}

function reportSettingWithOneContent(): ReportSetting
{
    $setting = ReportSetting::create([
        'entity_id'   => null,
        'title'       => 'Modelo de teste',
        'status'      => ReportSettingStatus::Draft,
        'show_footer' => true,
        'footer_text' => 'Clínica Teste',
    ]);

    ReportSettingContent::query()->create([
        'report_setting_id' => $setting->id,
        'type'              => DocumentationType::Report,
        'label'             => 'Padrão',
        'content'           => '<p>Achados.</p>',
    ]);

    return $setting;
}

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, 'admin');
});

it('EE-14: prévia HTML mostra paginação e "emitido em" em pt_BR por padrão', function () {
    $setting = reportSettingWithOneContent();

    $html = $this->actingAs($this->admin)
        ->withSession(reportSettingPreviewSaasAdminSession($this->saas))
        ->get(route('manager.report-settings.preview', $setting))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Pág. 1/1')->toContain('Emitido em');
});

it('EE-14: prévia HTML respeita locale en na paginação e no "issued on"', function () {
    $setting = reportSettingWithOneContent();

    $html = $this->actingAs($this->admin)
        ->withSession(reportSettingPreviewSaasAdminSession($this->saas) + ['locale' => 'en'])
        ->get(route('manager.report-settings.preview', $setting))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('p. 1/1')->toContain('Issued on')
        ->and($html)->not->toContain('Pág.')
        ->and($html)->not->toContain('Emitido em');
});

it('EE-14: variante órfã (report_setting_preview.blade.php, sem rota) continua renderizando e localiza a paginação (com conteúdo)', function () {
    // Confirmado via grep em app/: nenhum controller referencia esta view
    // sem o sufixo _html — só smoke-test de render() direto, sem HTTP, pra
    // provar que a troca por __() não quebrou a sintaxe Blade. Com
    // conteúdo ativo, o rodapé ("Emitido em") é só um COMENTÁRIO nesta
    // branch (linha 253 do próprio arquivo: "renderizado via --footer-html
    // no controller") — testado na branch sem conteúdo abaixo, onde ele é
    // de fato inline.
    $setting = reportSettingWithOneContent();
    $setting->load(['activeContents' => fn ($q) => $q->orderBy('sort_order')->orderBy('label')]);

    $htmlPt = view('pdf.report_setting_preview', ['reportSetting' => $setting])->render();
    expect($htmlPt)->toContain('Página 1 de 1');

    app()->setLocale('en');
    $htmlEn = view('pdf.report_setting_preview', ['reportSetting' => $setting])->render();
    expect($htmlEn)->toContain('Page 1 of 1');
    app()->setLocale('pt_BR');
});

it('EE-14: variante órfã sem conteúdo ativo localiza o rodapé inline ("emitido em")', function () {
    $setting = ReportSetting::create([
        'entity_id'   => null,
        'title'       => 'Modelo sem conteúdo',
        'status'      => ReportSettingStatus::Draft,
        'show_footer' => true,
        'footer_text' => 'Clínica Teste',
    ]);
    $setting->load(['activeContents' => fn ($q) => $q->orderBy('sort_order')->orderBy('label')]);

    $htmlPt = view('pdf.report_setting_preview', ['reportSetting' => $setting])->render();
    expect($htmlPt)->toContain('Emitido em');

    app()->setLocale('en');
    $htmlEn = view('pdf.report_setting_preview', ['reportSetting' => $setting])->render();
    expect($htmlEn)->toContain('Issued on');
    app()->setLocale('pt_BR');
});
