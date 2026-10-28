<?php

use App\Enums\SaasRule;
use App\Models\{AuditLog, Entity, SubscriptionSetting, User};
use Illuminate\Support\Facades\Route;

/**
 * Manager → Planos: dias de trial das empresas novas. A configuração saiu da
 * tela de Assinaturas e "dias de graça" deixou de existir — acabou o trial,
 * o acesso é bloqueado.
 */
beforeEach(function () {
    $this->saas = Entity::factory()->create(['is_client' => false, 'active' => true]);
});

function trialSettingsAs(SaasRule $rule): mixed
{
    $user = User::factory()->create();
    createEntityUser(test()->saas, $user, $rule->value);

    return test()->actingAs($user)->withSession([
        'selected_entity_id'        => test()->saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => $rule->value,
    ]);
}

it('admin vê e altera os dias de trial na tela de Planos, com auditoria', function () {
    SubscriptionSetting::setValue('trial_days', 7);

    trialSettingsAs(SaasRule::Admin)->get(route('manager.plans.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('trialDays', 7)
            ->where('t.trial_title', __('manager_plans.trial_title')));

    trialSettingsAs(SaasRule::Admin)->putJson(route('manager.plans.trial-settings'), ['trial_days' => 14])
        ->assertOk()
        ->assertJsonPath('data.trial_days', 14)
        ->assertJsonPath('message', __('manager_plans.trial_saved'));

    $audit = AuditLog::where('event', 'manager.settings.trial_days')->sole();

    expect(SubscriptionSetting::trialDays())->toBe(14)
        ->and($audit->old_values)->toBe(['trial_days' => 7])
        ->and($audit->new_values)->toBe(['trial_days' => 14]);
});

it('valida os dias de trial', function (mixed $value) {
    SubscriptionSetting::setValue('trial_days', 7);

    trialSettingsAs(SaasRule::Admin)->putJson(route('manager.plans.trial-settings'), ['trial_days' => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('trial_days');

    expect(SubscriptionSetting::trialDays())->toBe(7);
})->with([0, 366, 'sete', null]);

it('só o admin altera os dias de trial', function (SaasRule $rule) {
    SubscriptionSetting::setValue('trial_days', 7);

    trialSettingsAs($rule)->putJson(route('manager.plans.trial-settings'), ['trial_days' => 30])->assertForbidden();

    expect(SubscriptionSetting::trialDays())->toBe(7);
})->with([SaasRule::Financial, SaasRule::Support]);

it('dias de graça não existem mais', function () {
    expect(Route::has('manager.subscriptions.settings'))->toBeFalse();

    trialSettingsAs(SaasRule::Admin)->get(route('manager.subscriptions.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('trialDays')
            ->missing('graceDays')
            ->missing('t.settings_grace_days'));

    // Enviar o campo antigo não grava nada.
    trialSettingsAs(SaasRule::Admin)->putJson(route('manager.plans.trial-settings'), [
        'trial_days'        => 9,
        'grace_period_days' => 5,
    ])->assertOk();

    expect(SubscriptionSetting::getValue('grace_period_days'))->toBeNull()
        ->and(SubscriptionSetting::trialDays())->toBe(9);
});
