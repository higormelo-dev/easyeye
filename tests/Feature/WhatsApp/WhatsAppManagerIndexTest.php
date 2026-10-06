<?php

declare(strict_types=1);

use App\Enums\SaasRule;
use App\Models\{Entity, User};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut, WhatsAppSetting};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manager → WhatsApp (index reorganizado no padrão de Medicamentos): lista de
 * clínicas paginada no servidor com busca e filtros (número usado, automação),
 * KPIs agregados, estatísticas de 30 dias numa consulta só (sem N+1),
 * autorização (só Admin do SaaS) e nenhum segredo nas props.
 */
function wmSaas($test, string $rule = SaasRule::Admin->value): void
{
    $saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $user = User::factory()->create();
    createEntityUser($saas, $user, $rule);

    $test->actingAs($user);
    session(['selected_entity_id' => $saas->id]);
}

function wmClinic(string $name, ?array $setting = null, bool $active = true): Entity
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => $active, 'name' => $name]);

    if ($setting !== null) {
        WhatsAppSetting::create([
            'entity_id'                 => $entity->id,
            'app_id'                    => null,
            'webhook_token'             => Str::random(48),
            'webhook_secret'            => 'segredo-' . Str::random(8),
            'active'                    => true,
            'confirmation_enabled'      => true,
            'confirmation_hours_before' => 24,
            'survey_enabled'            => false,
            'survey_delay_hours'        => 2,
            ...$setting,
        ]);
    }

    return $entity;
}

function wmGlobal(array $overrides = []): WhatsAppSetting
{
    return WhatsAppSetting::create([
        'entity_id'                 => null,
        'app_id'                    => 'global-app-wm',
        'webhook_token'             => 'global-wm-token-' . Str::random(30),
        'webhook_secret'            => 'segredo-global-wm',
        'active'                    => true,
        'confirmation_enabled'      => false,
        'survey_enabled'            => false,
        'confirmation_hours_before' => 24,
        'survey_delay_hours'        => 2,
        ...$overrides,
    ]);
}

function wmMessage(Entity $entity, array $attributes = []): WhatsAppMessage
{
    // forceFill: created_at (fora do fillable) para mensagens antigas.
    $message = (new WhatsAppMessage())->forceFill([
        'entity_id' => $entity->id,
        'direction' => 'out',
        'kind'      => 'confirmation',
        'phone'     => '5561999990000',
        'body'      => 'teste',
        'status'    => 'sent',
        ...$attributes,
    ]);
    $message->save();

    return $message;
}

/** Props da página (Inertia), com query string opcional. */
function wmIndex($test, array $query = []): array
{
    return $test->get(route('manager.whatsapp.index', $query))->assertOk()->viewData('page')['props'];
}

function wmNames(array $props): array
{
    return collect($props['clinics']['data'])->pluck('name')->all();
}

beforeEach(function () {
    config()->set('whatsapp.driver', 'mock');
});

it('[SEGURANÇA] só o Admin do SaaS abre a página (Support/Financial recebem 403)', function () {
    wmSaas($this, SaasRule::Support->value);
    $this->get(route('manager.whatsapp.index'))->assertForbidden();

    wmSaas($this, SaasRule::Financial->value);
    $this->get(route('manager.whatsapp.index'))->assertForbidden();

    wmSaas($this);
    $this->get(route('manager.whatsapp.index'))->assertOk();
});

it('classifica o número usado (próprio / do EasyEye / sem envio) e filtra por ele', function () {
    wmSaas($this);
    wmGlobal();

    wmClinic('WM PROPRIA', ['app_id' => 'app-propria-wm']);
    wmClinic('WM GLOBAL', []);
    wmClinic('WM INATIVA', ['active' => false, 'app_id' => 'app-inativa-wm']);
    wmClinic('WM SEM CONFIG');
    wmClinic('WM CLINICA DESATIVADA', ['app_id' => 'app-off-wm'], active: false);

    $all  = wmIndex($this, ['search' => 'WM ']);
    $rows = collect($all['clinics']['data'])->keyBy('name');

    expect($rows->keys()->all())->toBe(['WM GLOBAL', 'WM INATIVA', 'WM PROPRIA', 'WM SEM CONFIG'])
        ->and($rows['WM PROPRIA']['sending'])->toBe('own')
        ->and($rows['WM GLOBAL']['sending'])->toBe('global')
        ->and($rows['WM INATIVA']['sending'])->toBe('none')
        ->and($rows['WM SEM CONFIG']['sending'])->toBe('none')
        ->and($rows['WM SEM CONFIG']['setting'])->toBeNull()
        ->and($rows['WM SEM CONFIG']['stats'])->toBeNull()
        ->and($rows['WM PROPRIA']['code'])->toStartWith('ENT-');

    expect(wmNames(wmIndex($this, ['search' => 'WM ', 'number' => 'own'])))->toBe(['WM PROPRIA'])
        ->and(wmNames(wmIndex($this, ['search' => 'WM ', 'number' => 'global'])))->toBe(['WM GLOBAL'])
        ->and(wmNames(wmIndex($this, ['search' => 'WM ', 'number' => 'none'])))->toBe(['WM INATIVA', 'WM SEM CONFIG']);
});

it('sem o número do EasyEye operacional, clínica sem app próprio é "sem envio"', function () {
    wmSaas($this);
    wmGlobal(['active' => false]);
    wmClinic('WM2 SEM APP', []);
    wmClinic('WM2 PROPRIA', ['app_id' => 'app-wm2']);

    $rows = collect(wmIndex($this, ['search' => 'WM2'])['clinics']['data'])->keyBy('name');

    expect($rows['WM2 SEM APP']['sending'])->toBe('none')
        ->and($rows['WM2 PROPRIA']['sending'])->toBe('own')
        ->and(wmNames(wmIndex($this, ['search' => 'WM2', 'number' => 'global'])))->toBe([])
        ->and(wmNames(wmIndex($this, ['search' => 'WM2', 'number' => 'none'])))->toBe(['WM2 SEM APP']);
});

it('filtra por automação (confirmação / pesquisa / nenhuma) e busca por nome ou código', function () {
    wmSaas($this);
    wmClinic('WM3 CONFIRMA', ['confirmation_enabled' => true, 'survey_enabled' => false]);
    wmClinic('WM3 PESQUISA', ['confirmation_enabled' => false, 'survey_enabled' => true]);
    wmClinic('WM3 AMBAS', ['confirmation_enabled' => true, 'survey_enabled' => true]);
    wmClinic('WM3 DESLIGADA', ['active' => false, 'confirmation_enabled' => true, 'survey_enabled' => true]);
    wmClinic('WM3 NENHUMA', ['confirmation_enabled' => false, 'survey_enabled' => false]);
    $bare = wmClinic('WM3 SEM CONFIG');

    expect(wmNames(wmIndex($this, ['search' => 'WM3', 'automation' => 'confirmation'])))->toBe(['WM3 AMBAS', 'WM3 CONFIRMA'])
        ->and(wmNames(wmIndex($this, ['search' => 'WM3', 'automation' => 'survey'])))->toBe(['WM3 AMBAS', 'WM3 PESQUISA'])
        ->and(wmNames(wmIndex($this, ['search' => 'WM3', 'automation' => 'none'])))->toBe(['WM3 DESLIGADA', 'WM3 NENHUMA', 'WM3 SEM CONFIG'])
        // Busca sem acento/caixa e pelo código da clínica.
        ->and(wmNames(wmIndex($this, ['search' => 'wm3 confírma'])))->toBe(['WM3 CONFIRMA'])
        ->and(wmNames(wmIndex($this, ['search' => $bare->code])))->toBe(['WM3 SEM CONFIG']);
});

it('pagina no servidor (20 por página), mantém os filtros na URL e ignora valores inválidos', function () {
    wmSaas($this);

    foreach (range(1, 23) as $i) {
        wmClinic(sprintf('WM4 CLINICA %02d', $i), []);
    }

    $first = wmIndex($this, ['search' => 'WM4', 'automation' => 'confirmation']);

    expect($first['clinics']['total'])->toBe(23)
        ->and($first['clinics']['per_page'])->toBe(20)
        ->and($first['clinics']['data'])->toHaveCount(20)
        ->and($first['clinics']['next_page_url'])->toContain('automation=confirmation')->toContain('search=WM4')
        ->and($first['filters'])->toBe(['search' => 'WM4', 'number' => '', 'automation' => 'confirmation', 'tab' => 'clinics']);

    $second = wmIndex($this, ['search' => 'WM4', 'automation' => 'confirmation', 'page' => 2]);
    expect(wmNames($second))->toBe(['WM4 CLINICA 21', 'WM4 CLINICA 22', 'WM4 CLINICA 23']);

    $invalid = wmIndex($this, ['search' => 'WM4', 'number' => 'qualquer', 'automation' => ['x'], 'tab' => 'hack']);
    expect($invalid['filters'])->toBe(['search' => 'WM4', 'number' => '', 'automation' => '', 'tab' => 'clinics'])
        ->and($invalid['clinics']['total'])->toBe(23);

    expect(wmIndex($this, ['tab' => 'templates'])['filters']['tab'])->toBe('templates');
});

it('KPIs agregados de todas as clínicas ativas (sem filtro)', function () {
    wmSaas($this);
    $before = wmIndex($this)['kpis'];

    wmGlobal();
    $own    = wmClinic('WM5 PROPRIA', ['app_id' => 'app-wm5', 'survey_enabled' => true]);
    $global = wmClinic('WM5 GLOBAL', ['confirmation_enabled' => true]);
    wmClinic('WM5 INATIVA', ['active' => false]);
    wmClinic('WM5 SEM CONFIG');

    wmMessage($own, ['kind' => 'confirmation', 'status' => 'sent']);
    wmMessage($own, ['kind' => 'survey', 'status' => 'answered']);
    wmMessage($global, ['kind' => 'confirmation', 'status' => 'failed']);
    wmMessage($global, ['kind' => 'ack', 'status' => 'sent']);
    wmMessage($global, ['kind' => 'confirmation', 'status' => 'sent', 'created_at' => now()->subDays(40)]);

    WhatsAppOptOut::create([
        'whatsapp_setting_id' => WhatsAppSetting::query()->where('entity_id', $own->id)->value('id'),
        'phone'               => '5561988887777',
        'source'              => WhatsAppOptOut::SOURCE_KEYWORD,
        'opted_out_at'        => now(),
    ]);

    // Filtro na URL não muda os KPIs (são da base toda).
    $kpis = wmIndex($this, ['number' => 'own'])['kpis'];

    expect($kpis['clinics'] - $before['clinics'])->toBe(4)
        ->and($kpis['own'] - $before['own'])->toBe(1)
        ->and($kpis['global'] - $before['global'])->toBe(1)
        ->and($kpis['none'] - $before['none'])->toBe(2)
        ->and($kpis['confirmations'] - $before['confirmations'])->toBe(2)
        ->and($kpis['surveys'] - $before['surveys'])->toBe(1)
        ->and($kpis['messages_sent'] - $before['messages_sent'])->toBe(2)
        ->and($kpis['opt_outs'] - $before['opt_outs'])->toBe(1);
});

it('estatísticas de 30 dias por clínica iguais às de antes, agregadas numa consulta só (sem N+1)', function () {
    wmSaas($this);

    $a = wmClinic('WM6 A', ['app_id' => 'app-wm6-a']);
    wmClinic('WM6 B', []);

    wmMessage($a, ['kind' => 'confirmation', 'status' => 'sent', 'delivered_at' => now(), 'read_at' => now()]);
    wmMessage($a, ['kind' => 'confirmation', 'status' => 'answered', 'delivered_at' => now()]);
    wmMessage($a, ['kind' => 'survey', 'status' => 'answered', 'survey_score' => 5]);
    wmMessage($a, ['kind' => 'survey', 'status' => 'answered', 'survey_score' => 4]);
    wmMessage($a, ['kind' => 'survey', 'status' => 'failed']);
    wmMessage($a, ['kind' => 'reply', 'direction' => 'in', 'status' => 'received']);
    wmMessage($a, ['kind' => 'confirmation', 'status' => 'sent', 'created_at' => now()->subDays(31)]);

    $settingA = WhatsAppSetting::query()->where('entity_id', $a->id)->firstOrFail();

    foreach (['5561911110000', '5561922220000'] as $phone) {
        WhatsAppOptOut::create(['whatsapp_setting_id' => $settingA->id, 'phone' => $phone, 'source' => WhatsAppOptOut::SOURCE_KEYWORD, 'opted_out_at' => now()]);
    }

    $rows = collect(wmIndex($this, ['search' => 'WM6'])['clinics']['data'])->keyBy('name');

    expect($rows['WM6 A']['stats'])->toBe([
        'confirmations_sent'     => 2,
        'confirmations_answered' => 1,
        'surveys_sent'           => 2,
        'surveys_answered'       => 2,
        'survey_average'         => 4.5,
        'delivered'              => 2,
        'read'                   => 1,
        'failed'                 => 1,
    ])
        ->and($rows['WM6 A']['setting']['opt_outs'])->toBe(2)
        ->and($rows['WM6 B']['stats']['confirmations_sent'])->toBe(0)
        ->and($rows['WM6 B']['setting']['opt_outs'])->toBe(0);

    // Consultas da listagem não crescem com o número de clínicas da página.
    $countQueries = function (int $clinics): int {
        foreach (range(1, $clinics) as $i) {
            $entity = wmClinic('WM6 EXTRA ' . Str::random(6), ['app_id' => 'app-extra-' . Str::random(10)]);
            wmMessage($entity);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('manager.whatsapp.index', ['search' => 'WM6']))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $few  = $countQueries(1);
    $many = $countQueries(8);

    expect($many)->toBe($few);
});

it('[SEGURANÇA] nenhum segredo nas props (segredo do webhook, credenciais do parceiro)', function () {
    wmSaas($this);
    config([
        'whatsapp.gupshup.partner_email'  => 'parceiro@example.com',
        'whatsapp.gupshup.partner_secret' => 'segredo-do-parceiro-wm',
    ]);
    wmGlobal(['webhook_secret' => 'segredo-global-wm-xyz']);
    wmClinic('WM7 PROPRIA', ['app_id' => 'app-wm7', 'webhook_secret' => 'segredo-clinica-wm-xyz']);

    $json = json_encode(wmIndex($this, ['search' => 'WM7']));

    expect($json)->not->toContain('segredo-global-wm-xyz')
        ->not->toContain('segredo-clinica-wm-xyz')
        ->not->toContain('segredo-do-parceiro-wm')
        ->not->toContain('webhook_secret')
        ->toContain('app-wm7');
});
