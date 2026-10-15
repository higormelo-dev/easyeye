<?php

declare(strict_types=1);

/**
 * O convite por WhatsApp na tela "Retornos" (ADR 0002, item 9): a prévia, o envio por uma pessoa, o que a lista mostra
 * de cada convite e quem pode operar. Relógio fixo: domingo, 01/02/2026, 10:00.
 */

use App\Domains\Recall\Enums\InviteResponse;
use App\Domains\Recall\Models\{RecallClinicSettings, RecallInvite};
use App\Domains\Recall\Services\RecallInviteResponder;
use App\Enums\ClientRule;
use App\Models\{DataAccessLog, EntityUser, User};
use App\Models\WhatsApp\{WhatsAppMessage, WhatsAppOptOut};
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Notification};
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-02-01 10:00:00'));

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    $this->sender  = rcfInviteChannel($this->entity);
    $this->patient = rcfInvitePatient($this->entity);
    $this->recall  = rcfOverdueRecall($this->entity, $this->patient, $this->doctor);

    [$this->secretary, $this->secretaryEu] = rivMember($this->entity, ClientRule::Secretary);
    [$this->financial, $this->financialEu] = rivMember($this->entity, ClientRule::Financial);
});

function rivMember($entity, ClientRule $rule): array
{
    $user = User::factory()->create();

    return [$user, createEntityUser($entity, $user, $rule->value)];
}

function rivAs(object $test, User $user, EntityUser $eu): object
{
    return $test->actingAs($user)->withSession(panelSession($eu));
}

/** Props da tela de Retornos. */
function rivPage(object $test, ?User $user = null, ?EntityUser $eu = null): array
{
    $props = null;

    rivAs($test, $user ?? $test->secretary, $eu ?? $test->secretaryEu)
        ->get(route('panel.recall.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $page->component('Panel/Recall/Index');
            $props = $page->toArray()['props'];
        });

    return $props;
}

// ── a prévia ────────────────────────────────────────────────────────────────

it('a secretária vê a prévia: texto exato, telefone mascarado, sem o diagnóstico', function () {
    $response = rivAs($this, $this->secretary, $this->secretaryEu)->getJson(route('panel.recall.invite-preview', $this->recall->id));

    $response->assertOk()
        ->assertJsonPath('can_send', true)
        ->assertJsonPath('phone', '(11) 9****-7777')
        ->assertJsonPath('recalls', 1)
        ->assertJsonPath('message.buttons', ['Quero agendar', 'Já agendei']);

    expect($response->getContent())->not->toContain('11988887777')->not->toContain('glaucoma');
});

it('a prévia diz por que não sai', function () {
    DB::table('patient_consents')->where('patient_id', $this->patient->id)->delete();

    rivAs($this, $this->secretary, $this->secretaryEu)->getJson(route('panel.recall.invite-preview', $this->recall->id))
        ->assertOk()
        ->assertJsonPath('can_send', false)
        ->assertJsonPath('blockers.0.code', 'no_consent');
});

it('o financeiro não vê nem manda; retorno de outra clínica é 404; sem login é redirecionado', function () {
    rivAs($this, $this->financial, $this->financialEu)->getJson(route('panel.recall.invite-preview', $this->recall->id))->assertForbidden();
    rivAs($this, $this->financial, $this->financialEu)->post(route('panel.recall.invite', $this->recall->id))->assertForbidden();

    ['entity' => $other] = rcfClinic(recall: true);
    rcfInviteChannel($other);
    [$user, $eu] = rivMember($other, ClientRule::Admin);

    rivAs($this, $user, $eu)->getJson(route('panel.recall.invite-preview', $this->recall->id))->assertNotFound();
    rivAs($this, $user, $eu)->post(route('panel.recall.invite', $this->recall->id))->assertRedirect();

    expect(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(0);

    auth()->logout();
    $this->flushSession();
    $this->get(route('panel.recall.invite-preview', $this->recall->id))->assertRedirect();
});

it('sem a feature de recall no plano, nem a prévia nem o envio', function () {
    ['entity' => $noPlan] = rcfClinic();
    rcfGrantRecall($noPlan, enabled: false);
    [$user, $eu] = rivMember($noPlan, ClientRule::Secretary);

    rivAs($this, $user, $eu)->getJson(route('panel.recall.invite-preview', $this->recall->id))->assertForbidden();
    rivAs($this, $user, $eu)->postJson(route('panel.recall.invite', $this->recall->id))->assertForbidden();
});

// ── o envio ─────────────────────────────────────────────────────────────────

it('a secretária manda o convite: onda manual com o autor, mensagem enviada e acesso registrado', function () {
    rivAs($this, $this->secretary, $this->secretaryEu)
        ->from(route('panel.recall.index'))
        ->post(route('panel.recall.invite', $this->recall->id))
        ->assertRedirect(route('panel.recall.index'))
        ->assertSessionHas('success', __('recall_list.done.invited'));

    $invite  = RecallInvite::query()->withoutGlobalScopes()->sole();
    $message = WhatsAppMessage::query()->where('recall_invite_id', $invite->id)->where('direction', 'out')->sole();

    expect($invite->origin->value)->toBe('manual')
        ->and($invite->created_by)->toBe((string) $this->secretary->id)
        ->and($message->status)->toBe('sent') // fila síncrona + driver mock
        ->and(DataAccessLog::query()->where('patient_id', $this->patient->id)->where('user_id', $this->secretary->id)->exists())->toBeTrue();
});

it('um 2º envio no mesmo dia volta com o motivo, sem gravar nada', function () {
    $post = fn () => rivAs($this, $this->secretary, $this->secretaryEu)->from(route('panel.recall.index'))->post(route('panel.recall.invite', $this->recall->id));

    $post()->assertSessionHas('success');
    $post()->assertSessionHas('error', __('recall_invite.refused', ['reasons' => __('recall_invite.blockers.too_soon')]));

    expect(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('envio recusado por consentimento: o aviso traz o rótulo, nada sai', function () {
    DB::table('patient_consents')->where('patient_id', $this->patient->id)->delete();

    rivAs($this, $this->secretary, $this->secretaryEu)
        ->from(route('panel.recall.index'))
        ->post(route('panel.recall.invite', $this->recall->id))
        ->assertSessionHas('error', __('recall_invite.refused', ['reasons' => __('recall_invite.blockers.no_consent')]));

    expect(RecallInvite::query()->withoutGlobalScopes()->count())->toBe(0);
});

// ── o que a lista mostra ────────────────────────────────────────────────────

it('a lista traz o canal da clínica e, por linha, consentimento, SAIR e o último convite', function () {
    $props = rivPage($this);
    $row   = $props['rows']['data'][0];

    expect($props['invites'])->toBe(['available' => true, 'blocker' => null, 'label' => null])
        ->and($row['invite'])->toBe(['consent' => true, 'opted_out' => false, 'can_try' => true, 'last' => null, 'requested' => false]);
});

it('canal fechado (o EasyEye não liberou): a tela sabe e não oferece o botão', function () {
    $this->sender->update(['recall_enabled' => false]);

    $props = rivPage($this);

    expect($props['invites']['available'])->toBeFalse()
        ->and($props['invites']['blocker'])->toBe('clinic_not_enabled')
        ->and($props['invites']['label'])->toBe(__('recall_invite.blockers.clinic_not_enabled'))
        ->and($props['rows']['data'][0]['invite']['can_try'])->toBeFalse();
});

it('sem consentimento ou com SAIR a linha mostra o porquê e não oferece o convite', function () {
    DB::table('patient_consents')->where('patient_id', $this->patient->id)->delete();

    expect(rivPage($this)['rows']['data'][0]['invite'])->toMatchArray(['consent' => false, 'can_try' => false]);

    rcfConsent($this->entity, $this->patient);
    WhatsAppOptOut::create(['whatsapp_setting_id' => $this->sender->id, 'phone' => '5511988887777', 'source' => 'keyword', 'opted_out_at' => now()]);

    expect(rivPage($this)['rows']['data'][0]['invite'])->toMatchArray(['consent' => true, 'opted_out' => true, 'can_try' => false]);
});

it('depois do envio a linha mostra o convite; quando o paciente pede para agendar, ele vai para o topo', function () {
    $second = rcfInvitePatient($this->entity, 'AAA PRIMEIRA POR NOME', '11977770001');
    rcfRecord($this->entity, $second, $this->doctor, '2025-11-20', 30); // vencido há mais tempo: viria ANTES pelo prazo
    rcfEvaluate($this->entity, $second, '2026-02-01');

    $names = fn (array $props) => array_map(fn (array $row) => $row['patient']['name'], $props['rows']['data']);

    expect($names(rivPage($this)))->toBe(['AAA PRIMEIRA POR NOME', 'MARIA DA SILVA']);

    rivAs($this, $this->secretary, $this->secretaryEu)->from(route('panel.recall.index'))->post(route('panel.recall.invite', $this->recall->id));

    $message = WhatsAppMessage::query()->where('kind', 'recall')->sole();
    $rows    = collect(rivPage($this)['rows']['data'])->keyBy(fn (array $row) => $row['patient']['name']);

    expect($rows['MARIA DA SILVA']['invite']['last'])->toMatchArray(['origin' => 'manual', 'status' => 'sent', 'response' => null])
        ->and($rows['MARIA DA SILVA']['invite']['requested'])->toBeFalse();

    // O paciente toca "Quero agendar".
    app(RecallInviteResponder::class)->record((string) $message->recall_invite_id, InviteResponse::WantsToSchedule);

    $props = rivPage($this);

    expect($names($props))->toBe(['MARIA DA SILVA', 'AAA PRIMEIRA POR NOME'])
        ->and($props['rows']['data'][0]['invite']['requested'])->toBeTrue()
        ->and($props['rows']['data'][0]['invite']['last']['response'])->toBe('wants_to_schedule');
});

it('o pedido para agendar vale por 30 dias: depois, a linha volta à ordem normal', function () {
    rivAs($this, $this->secretary, $this->secretaryEu)->from(route('panel.recall.index'))->post(route('panel.recall.invite', $this->recall->id));
    $message = WhatsAppMessage::query()->where('kind', 'recall')->sole();
    app(RecallInviteResponder::class)->record((string) $message->recall_invite_id, InviteResponse::WantsToSchedule);

    $this->travelTo(Carbon::parse('2026-03-05 10:00:00'));

    expect(rivPage($this)['rows']['data'][0]['invite']['requested'])->toBeFalse();
});

it('o status do último convite acompanha a entrega: enviado, entregue, lido, falhou, cancelado', function (array $columns, string $expected) {
    rivAs($this, $this->secretary, $this->secretaryEu)->from(route('panel.recall.index'))->post(route('panel.recall.invite', $this->recall->id));
    DB::table('whatsapp_messages')->where('kind', 'recall')->update($columns);

    expect(rivPage($this)['rows']['data'][0]['invite']['last']['status'])->toBe($expected);
})->with([
    'na fila'    => [['status' => 'pending', 'sent_at' => null], 'queued'],
    'enviado'    => [['status' => 'sent'], 'sent'],
    'entregue'   => [['status' => 'sent', 'delivered_at' => '2026-02-01 10:01:00'], 'delivered'],
    'lido'       => [['status' => 'sent', 'delivered_at' => '2026-02-01 10:01:00', 'read_at' => '2026-02-01 10:02:00'], 'read'],
    'respondido' => [['status' => 'answered', 'delivered_at' => '2026-02-01 10:01:00'], 'delivered'],
    'falhou'     => [['status' => 'failed'], 'failed'],
    'cancelado'  => [['status' => 'suppressed'], 'cancelled'],
]);

it('a lista continua com um número fixo de consultas qualquer que seja a quantidade de linhas', function () {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        rivPage($this);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    rivPage($this); // aquece os caches (assinatura, perfil): a 1ª requisição faz mais consultas que as seguintes
    $few = $countQueries();

    foreach (range(1, 8) as $i) {
        $p = rcfInvitePatient($this->entity, "PACIENTE {$i}", '1197777' . str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        rcfRecord($this->entity, $p, $this->doctor, '2025-12-01', 30);
        rcfEvaluate($this->entity, $p, '2026-02-01');
    }

    expect($countQueries())->toBe($few);
});

// ── as configurações da clínica ─────────────────────────────────────────────

it('a clínica guarda as configurações do convite; ligar recomeça o ramp-up; os campos são validados', function () {
    RecallClinicSettings::query()->withoutGlobalScopes()->where('entity_id', $this->entity->id)->update(['whatsapp_invites_enabled' => false, 'ramp_started_on' => '2025-01-01']);
    [$admin, $adminEu] = rivMember($this->entity, ClientRule::Admin);

    $valid = [
        'doctor_order_enabled'         => true,
        'whatsapp_invites_enabled'     => true,
        'invite_window_start'          => '08:30',
        'invite_window_end'            => '17:30',
        'invite_weekdays'              => [1, 2, 3],
        'min_days_between_invites'     => 45,
        'max_auto_invites_per_cycle'   => 2,
        'daily_invite_cap'             => 15,
        'auto_invite_max_overdue_days' => 120,
    ];

    rivAs($this, $admin, $adminEu)->put(route('panel.recall.settings.general'), $valid)->assertSessionHasNoErrors();

    $settings = RecallClinicSettings::forEntity((string) $this->entity->id);

    expect($settings->whatsapp_invites_enabled)->toBeTrue()
        ->and($settings->invite_window_start)->toBe('08:30')
        ->and($settings->invite_weekdays)->toBe([1, 2, 3])
        ->and($settings->min_days_between_invites)->toBe(45)
        ->and($settings->max_auto_invites_per_cycle)->toBe(2)
        ->and($settings->daily_invite_cap)->toBe(15)
        ->and($settings->auto_invite_max_overdue_days)->toBe(120)
        ->and($settings->ramp_started_on->toDateString())->toBe('2026-02-01');

    // Salvar de novo, já ligado, NÃO recomeça o ramp-up.
    $this->travelTo(Carbon::parse('2026-02-10 10:00:00'));
    rivAs($this, $admin, $adminEu)->put(route('panel.recall.settings.general'), $valid)->assertSessionHasNoErrors();

    expect(RecallClinicSettings::forEntity((string) $this->entity->id)->ramp_started_on->toDateString())->toBe('2026-02-01');

    foreach ([
        ['invite_window_end' => '08:00'],
        ['invite_window_start'          => '25:00'],
        ['invite_weekdays'              => []],
        ['invite_weekdays'              => [0, 8], 'expect' => 'invite_weekdays.0'],
        ['min_days_between_invites'     => 3],
        ['max_auto_invites_per_cycle'   => 0],
        ['daily_invite_cap'             => 0],
        ['auto_invite_max_overdue_days' => 5],
    ] as $bad) {
        $expect = $bad['expect'] ?? array_key_first($bad);
        unset($bad['expect']);

        rivAs($this, $admin, $adminEu)->put(route('panel.recall.settings.general'), $bad + $valid)->assertSessionHasErrors($expect);
    }
});

it('quem manda só o retorno do médico (como antes) continua funcionando e não mexe nos campos do convite', function () {
    [$admin, $adminEu] = rivMember($this->entity, ClientRule::Admin);
    $before            = RecallClinicSettings::forEntity((string) $this->entity->id)->only(['whatsapp_invites_enabled', 'daily_invite_cap', 'invite_window_start']);

    rivAs($this, $admin, $adminEu)->put(route('panel.recall.settings.general'), ['doctor_order_enabled' => false])->assertSessionHasNoErrors();

    $after = RecallClinicSettings::forEntity((string) $this->entity->id);

    expect($after->doctor_order_enabled)->toBeFalse()
        ->and($after->only(['whatsapp_invites_enabled', 'daily_invite_cap', 'invite_window_start']))->toBe($before);
});

it('a tela de configuração mostra o que falta do lado do EasyEye e o teto de hoje', function () {
    $this->sender->update(['recall_enabled' => false]);
    [$admin, $adminEu] = rivMember($this->entity, ClientRule::Admin);

    $props = null;
    rivAs($this, $admin, $adminEu)->get(route('panel.recall.settings.index'))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
        $page->component('Panel/Recall/Settings');
        $props = $page->toArray()['props'];
    });

    expect($props['invites'])->toBe(['available' => false, 'blocker' => 'clinic_not_enabled', 'label' => __('recall_invite.blockers.clinic_not_enabled')])
        ->and($props['general'])->toMatchArray(['whatsapp_invites_enabled' => true, 'daily_invite_cap' => 100, 'cap_today' => 100, 'sent_today' => 0, 'min_days_floor' => 7]);
});
