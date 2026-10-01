<?php

declare(strict_types=1);

/**
 * Mesmo usuário (secretária, financeiro, admin…) em várias clínicas, sem que
 * nenhuma clínica descubra quem tem conta no EasyEye: o admin informa e-mail +
 * perfil e a resposta é SEMPRE a mesma ("se este e-mail já tiver acesso, ele
 * receberá um convite"); o convite aparece na lista de pendentes exista conta
 * ou não; só quem tem conta recebe o e-mail, e só ele, logado, aceita.
 */

use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{DoctorInvitation, Entity, EntityUser, EntityUserInvitation, Plan, PlanFeature, Subscription, SystemProfile, User};
use App\Notifications\UserClinicInvitation;
use App\Services\FeatureGateService;
use Illuminate\Support\Facades\{DB, Notification, URL};

function uciEntity(string $name, string $maxUsers = '0'): Entity
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true, 'name' => $name]);
    $entity->skipAutoTrial = true;
    $entity->save();

    $plan = Plan::factory()->create(['active' => true]);
    PlanFeature::create(['plan_id' => $plan->id, 'feature' => FeatureKey::MaxUsers->value, 'value' => $maxUsers]);

    Subscription::factory()->create([
        'entity_id' => $entity->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
    ]);

    return $entity;
}

function uciLink(EntityUserInvitation $invitation, string $name = 'user-invitations.show'): string
{
    return URL::temporarySignedRoute($name, now()->addDays(7), ['invitation' => $invitation->id]);
}

beforeEach(function (): void {
    Notification::fake();

    $this->clinicA = uciEntity('Clínica A');
    $this->clinicB = uciEntity('Clínica B');
    $this->adminB  = User::factory()->create();
    $this->euB     = createEntityUser($this->clinicB, $this->adminB, ClientRule::Admin->value, true, true);

    $this->maria = User::factory()->create(['email' => 'maria.secretaria@example.com', 'name' => 'Maria', 'password' => 'SenhaAntigaForte123!']);
    createEntityUser($this->clinicA, $this->maria, ClientRule::Secretary->value);

    $this->inviteAsB = fn (array $data) => $this->actingAs($this->adminB)->withSession(panelSession($this->euB))
        ->post(route('panel.accesscontrol.users.invitations.store'), $data);
});

describe('envio — a resposta nunca revela quem tem conta', function (): void {
    it('e-mail com conta em outra clínica: resposta genérica e o e-mail vai para o login dela', function (): void {
        ($this->inviteAsB)(['email' => 'MARIA.secretaria@example.com', 'rule' => 'financial'])
            ->assertRedirect()->assertSessionHas('message', __('access_control.invitation.sent'));

        $invitation = EntityUserInvitation::query()->sole();
        expect($invitation->user_id)->toBe($this->maria->id)
            ->and($invitation->email)->toBe('maria.secretaria@example.com')
            ->and($invitation->rule)->toBe('financial')
            ->and(EntityUser::query()->where('entity_id', $this->clinicB->id)->where('user_id', $this->maria->id)->exists())->toBeFalse();

        Notification::assertSentTo($this->maria, UserClinicInvitation::class);
    });

    it('e-mail SEM conta: a mesma resposta e o mesmo registro pendente — só não há e-mail', function (): void {
        ($this->inviteAsB)(['email' => 'ninguem@example.com', 'rule' => 'secretary'])
            ->assertRedirect()->assertSessionHas('message', __('access_control.invitation.sent'));

        $invitation = EntityUserInvitation::query()->sole();
        expect($invitation->email)->toBe('ninguem@example.com')
            ->and($invitation->user_id)->toBeNull()
            ->and($invitation->status)->toBe('pending');

        Notification::assertNothingSent();
    });

    it('e-mail de quem já está nesta clínica: mesma resposta, nenhum e-mail', function (): void {
        ($this->inviteAsB)(['email' => $this->adminB->email, 'rule' => 'secretary'])
            ->assertSessionHas('message', __('access_control.invitation.sent'));

        expect(EntityUserInvitation::query()->sole()->user_id)->toBeNull();
        Notification::assertNothingSent();
    });

    it('a lista de pendentes mostra o e-mail digitado exista conta ou não', function (): void {
        ($this->inviteAsB)(['email' => 'maria.secretaria@example.com', 'rule' => 'financial']);
        ($this->inviteAsB)(['email' => 'ninguem@example.com', 'rule' => 'secretary']);

        $pending = $this->actingAs($this->adminB)->withSession(panelSession($this->euB))
            ->get(route('panel.accesscontrol.users.index'), inertiaHeaders())
            ->json('props.pendingInvitations');

        expect(collect($pending)->pluck('email')->sort()->values()->all())
            ->toBe(['maria.secretaria@example.com', 'ninguem@example.com'])
            ->and(json_encode($pending))->not->toContain('user_id')->not->toContain('Maria');
    });

    it('perfil médico não é convidado por aqui (tem o convite de médico, com CRM)', function (): void {
        ($this->inviteAsB)(['email' => 'maria.secretaria@example.com', 'rule' => 'doctor'])->assertSessionHasErrors('rule');

        expect(EntityUserInvitation::query()->count())->toBe(0);
    });

    it('só admin envia: secretária da clínica recebe 403', function (): void {
        $secretaryB = User::factory()->create();
        $euSecB     = createEntityUser($this->clinicB, $secretaryB, ClientRule::Secretary->value);

        $this->actingAs($secretaryB)->withSession(panelSession($euSecB))
            ->post(route('panel.accesscontrol.users.invitations.store'), ['email' => 'maria.secretaria@example.com', 'rule' => 'user'])
            ->assertForbidden();

        expect(EntityUserInvitation::query()->count())->toBe(0);
    });

    it('limite de usuários do plano atingido: recusa o envio (independe de existir conta)', function (): void {
        $clinicD = uciEntity('Clínica D', '1');
        $adminD  = User::factory()->create();
        $euD     = createEntityUser($clinicD, $adminD, ClientRule::Admin->value, true, true);

        $this->actingAs($adminD)->withSession(panelSession($euD))
            ->post(route('panel.accesscontrol.users.invitations.store'), ['email' => 'ninguem@example.com', 'rule' => 'user'])
            ->assertSessionHasErrors('email');

        expect(EntityUserInvitation::query()->count())->toBe(0);
    });

    it('reenvio antes de 24h não manda outro e-mail; depois de 24h manda', function (): void {
        ($this->inviteAsB)(['email' => 'maria.secretaria@example.com', 'rule' => 'user']);
        ($this->inviteAsB)(['email' => 'maria.secretaria@example.com', 'rule' => 'financial']);
        Notification::assertSentTimes(UserClinicInvitation::class, 1);
        expect(EntityUserInvitation::query()->sole()->rule)->toBe('financial');

        $this->travel(25)->hours();
        ($this->inviteAsB)(['email' => 'maria.secretaria@example.com', 'rule' => 'financial']);
        Notification::assertSentTimes(UserClinicInvitation::class, 2);
    });

    it('clínica cancela; outra clínica não consegue (404)', function (): void {
        ($this->inviteAsB)(['email' => 'maria.secretaria@example.com', 'rule' => 'user']);
        $invitation = EntityUserInvitation::query()->sole();

        $adminA = User::factory()->create();
        $euA    = createEntityUser($this->clinicA, $adminA, ClientRule::Admin->value, true, true);
        $this->actingAs($adminA)->withSession(panelSession($euA))
            ->delete(route('panel.accesscontrol.users.invitations.destroy', $invitation))->assertNotFound();

        $this->actingAs($this->adminB)->withSession(panelSession($this->euB))
            ->delete(route('panel.accesscontrol.users.invitations.destroy', $invitation))->assertRedirect();
        expect($invitation->fresh()->status)->toBe('cancelled');
    });
});

describe('resposta — só a própria pessoa, logada', function (): void {
    beforeEach(function (): void {
        ($this->inviteAsB)(['email' => 'maria.secretaria@example.com', 'rule' => 'financial']);
        $this->invitation = EntityUserInvitation::query()->sole();
    });

    it('sem login vai ao login; outro usuário logado recebe 404', function (): void {
        $this->app['auth']->forgetGuards();
        $this->get(uciLink($this->invitation))->assertRedirect(route('login'));

        $this->actingAs($this->adminB)->get(uciLink($this->invitation))->assertNotFound();
        $this->actingAs($this->adminB)->post(uciLink($this->invitation, 'user-invitations.accept'))->assertNotFound();
    });

    it('aceita: entra em B com o perfil escolhido por B; login intacto; as duas clínicas no seletor', function (): void {
        $hash = $this->maria->password;

        $this->actingAs($this->maria)->get(uciLink($this->invitation), inertiaHeaders())
            ->assertOk()
            ->assertJsonPath('component', 'Auth/ClinicInvitation')
            ->assertJsonPath('props.clinicName', $this->clinicB->fresh()->name);

        $this->actingAs($this->maria)->post(uciLink($this->invitation, 'user-invitations.accept'))
            ->assertRedirect(route('selectentity.create'))->assertSessionHas('success');

        $euB = EntityUser::query()->where('entity_id', $this->clinicB->id)->where('user_id', $this->maria->id)->sole();
        expect($euB->rule)->toBe('financial')
            ->and($euB->active)->toBeTrue()
            ->and($this->maria->fresh()->password)->toBe($hash)
            ->and($this->invitation->fresh()->status)->toBe('accepted');

        $entities = $this->actingAs($this->maria)->get(route('selectentity.create'), inertiaHeaders())->json('props.entities');
        expect(array_values($entities))->toContain($this->clinicA->fresh()->name, $this->clinicB->fresh()->name);
    });

    it('aceitar duas vezes não duplica', function (): void {
        $accept = uciLink($this->invitation, 'user-invitations.accept');
        $this->actingAs($this->maria)->post($accept);
        $this->actingAs($this->maria)->post($accept);

        expect(EntityUser::query()->where('entity_id', $this->clinicB->id)->where('user_id', $this->maria->id)->count())->toBe(1);
    });

    it('recusar: nada é criado', function (): void {
        $this->actingAs($this->maria)->post(uciLink($this->invitation, 'user-invitations.decline'))
            ->assertSessionHas('success', __('access_control.invitation.result.declined'));

        expect($this->invitation->fresh()->status)->toBe('declined')
            ->and(EntityUser::query()->where('entity_id', $this->clinicB->id)->where('user_id', $this->maria->id)->exists())->toBeFalse();
    });

    it('convite a e-mail que não tinha conta não é aceito por ninguém (nem por quem criar a conta depois)', function (): void {
        ($this->inviteAsB)(['email' => 'futuro@example.com', 'rule' => 'user']);
        $orphan = EntityUserInvitation::query()->where('email', 'futuro@example.com')->sole();
        $later  = User::factory()->create(['email' => 'futuro@example.com']);

        $this->actingAs($later)->post(uciLink($orphan, 'user-invitations.accept'))->assertNotFound();
        expect(EntityUser::query()->where('entity_id', $this->clinicB->id)->where('user_id', $later->id)->exists())->toBeFalse();
    });

    it('limite de usuários atingido depois do envio: aceite recusado com aviso', function (): void {
        PlanFeature::query()->where('feature', FeatureKey::MaxUsers->value)
            ->whereIn('plan_id', Subscription::query()->where('entity_id', $this->clinicB->id)->select('plan_id'))
            ->update(['value' => '1']);
        // O gate guarda a assinatura por request; em produção cada request é
        // novo — aqui o app é reaproveitado entre as chamadas do teste.
        app(FeatureGateService::class)->forgetCache($this->clinicB->id);

        $this->actingAs($this->maria)->post(uciLink($this->invitation, 'user-invitations.accept'))
            ->assertSessionHas('error');

        expect(EntityUser::query()->where('entity_id', $this->clinicB->id)->where('user_id', $this->maria->id)->exists())->toBeFalse()
            ->and($this->invitation->fresh()->status)->toBe('pending');
    });

    it('rotina diária expira convites vencidos (dos dois tipos)', function (): void {
        $this->travel(8)->days();
        $this->artisan('clinic-invitations:expire')->assertSuccessful();

        expect($this->invitation->fresh()->status)->toBe('expired');
    });
});

describe('endurecimento (revisão de segurança)', function (): void {
    it('recusa não muda a lista da clínica: o convite segue "pendente" até vencer e pode ser cancelado', function (): void {
        ($this->inviteAsB)(['email' => 'maria.secretaria@example.com', 'rule' => 'financial']);
        $invitation = EntityUserInvitation::query()->sole();
        $this->actingAs($this->maria)->post(uciLink($invitation, 'user-invitations.decline'));

        $asB     = fn () => $this->actingAs($this->adminB)->withSession(panelSession($this->euB));
        $pending = $asB()->get(route('panel.accesscontrol.users.index'), inertiaHeaders())->json('props.pendingInvitations');
        expect(collect($pending)->pluck('email')->all())->toBe(['maria.secretaria@example.com']);

        $asB()->delete(route('panel.accesscontrol.users.invitations.destroy', $invitation));
        expect($asB()->get(route('panel.accesscontrol.users.index'), inertiaHeaders())->json('props.pendingInvitations'))->toBe([]);
    });

    it('conta com e-mail NÃO verificado não recebe convite (squatting de e-mail)', function (): void {
        User::factory()->create(['email' => 'nao.verificado@example.com', 'email_verified_at' => null]);

        ($this->inviteAsB)(['email' => 'nao.verificado@example.com', 'rule' => 'user'])
            ->assertSessionHas('message', __('access_control.invitation.sent'));

        expect(EntityUserInvitation::query()->sole()->user_id)->toBeNull();
        Notification::assertNothingSent();
    });

    it('admin que convidou foi rebaixado antes do aceite: convite deixa de valer', function (): void {
        ($this->inviteAsB)(['email' => 'maria.secretaria@example.com', 'rule' => 'admin']);
        $invitation = EntityUserInvitation::query()->sole();
        $this->euB->forceFill(['rule' => ClientRule::Secretary->value])->save();

        $this->actingAs($this->maria)->post(uciLink($invitation, 'user-invitations.accept'))
            ->assertSessionHas('error', __('access_control.invitation.result.closed'));

        expect(EntityUser::query()->where('entity_id', $this->clinicB->id)->where('user_id', $this->maria->id)->exists())->toBeFalse();
    });

    it('a tela de aceite mostra o perfil oferecido', function (): void {
        ($this->inviteAsB)(['email' => 'maria.secretaria@example.com', 'rule' => 'admin']);
        $invitation = EntityUserInvitation::query()->sole();
        $label      = SystemProfile::labelMap(SystemProfile::CONTEXT_CLIENT)['admin'];

        $this->actingAs($this->maria)->get(uciLink($invitation), inertiaHeaders())
            ->assertJsonPath('props.detail', __('access_control.invitation.page.role', ['role' => $label]));
    });

    it('auditoria não registra o user_id do convite (não distingue e-mail com conta)', function (): void {
        ($this->inviteAsB)(['email' => 'maria.secretaria@example.com', 'rule' => 'user']);

        $logs = DB::table('audit_logs')->where('auditable_type', EntityUserInvitation::class)->get();
        expect($logs)->not->toBeEmpty();

        foreach ($logs as $log) {
            expect((string) $log->new_values . (string) $log->old_values)->not->toContain('user_id');
        }
    });

    it('retenção: convites encerrados há mais de 90 dias são apagados pela rotina diária', function (): void {
        ($this->inviteAsB)(['email' => 'ninguem@example.com', 'rule' => 'user']);
        $this->travel(DoctorInvitation::RETENTION_DAYS + 10)->days();

        $this->artisan('clinic-invitations:expire')->assertSuccessful(); // expira
        $this->travel(DoctorInvitation::RETENTION_DAYS + 1)->days();
        $this->artisan('clinic-invitations:expire')->assertSuccessful(); // apaga

        expect(EntityUserInvitation::query()->count())->toBe(0);
    });
});
