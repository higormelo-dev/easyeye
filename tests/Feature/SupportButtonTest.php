<?php

declare(strict_types=1);

use App\Enums\{ClientRule, SaasRule};
use App\Models\{Entity, User};
use App\Support\SupportContact;

/**
 * Botão de suporte no topo do painel da clínica: prop compartilhada `support`
 * (WhatsApp oficial com mensagem pré-preenchida; sem número válido, e-mail de
 * suporte; sem nenhum dos dois, null). Só nas telas panel.* da clínica.
 */
beforeEach(function () {
    $this->entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->user       = User::factory()->create();
    $this->entityUser = createEntityUser($this->entity, $this->user, ClientRule::Admin->value);

    config(['saas.support.whatsapp' => null, 'mail.support_address' => null]);
});

function supportDashboard(?string $locale = null)
{
    $session = panelSession(test()->entityUser) + ($locale ? ['locale' => $locale] : []);

    return test()->actingAs(test()->user)->withSession($session)->get(route('panel.dashboard'));
}

describe('Botão de suporte — prop compartilhada', function () {
    it('entrega o link do WhatsApp só com dígitos e a mensagem codificada', function () {
        config(['saas.support.whatsapp' => '+55 (61) 99999-0000', 'app.name' => 'EasyEye']);

        supportDashboard('pt_BR')->assertOk()->assertInertia(fn ($page) => $page
            ->where('support.kind', 'whatsapp')
            ->where('support.url', 'https://wa.me/5561999990000?text=' . rawurlencode('Olá! Preciso de ajuda com o EasyEye.'))
            ->where('support.label', 'Falar com o suporte'));
    });

    it('a mensagem e o rótulo seguem o idioma da sessão', function () {
        config(['saas.support.whatsapp' => '5561999990000', 'app.name' => 'EasyEye']);

        supportDashboard('en')->assertOk()->assertInertia(fn ($page) => $page
            ->where('support.url', 'https://wa.me/5561999990000?text=' . rawurlencode('Hi! I need help with EasyEye.'))
            ->where('support.label', 'Contact support'));
    });

    it('sem número, usa o e-mail de suporte', function () {
        config(['mail.support_address' => 'suporte@easyeye.app', 'app.name' => 'EasyEye']);

        supportDashboard('pt_BR')->assertOk()->assertInertia(fn ($page) => $page
            ->where('support.kind', 'email')
            ->where('support.url', 'mailto:suporte@easyeye.app?subject=' . rawurlencode('Ajuda com o EasyEye'))
            ->where('support.label', 'Falar com o suporte'));
    });

    it('número inválido (curto demais, longo demais ou sem dígitos) cai no e-mail', function () {
        config(['mail.support_address' => 'suporte@easyeye.app']);

        foreach (['12345', '1234567890123456', 'abc', '   '] as $invalid) {
            config(['saas.support.whatsapp' => $invalid]);

            supportDashboard()->assertOk()->assertInertia(fn ($page) => $page->where('support.kind', 'email'));
        }
    });

    it('sem número e sem e-mail válido, a prop é null (o botão some)', function () {
        supportDashboard()->assertOk()->assertInertia(fn ($page) => $page->where('support', null));

        config(['mail.support_address' => 'isto não é e-mail']);

        supportDashboard()->assertOk()->assertInertia(fn ($page) => $page->where('support', null));
    });

    it('chega em qualquer tela do painel da clínica, não só no Dashboard', function () {
        config(['saas.support.whatsapp' => '5561999990000']);

        $this->actingAs($this->user)->withSession(panelSession($this->entityUser))
            ->get(route('panel.setting.covenants.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('support.kind', 'whatsapp'));
    });

    it('o Manager não recebe o botão', function () {
        config(['saas.support.whatsapp' => '5561999990000', 'mail.support_address' => 'suporte@easyeye.app']);

        $saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
        $admin = User::factory()->create();
        createEntityUser($saas, $admin, SaasRule::Admin->value);

        $this->actingAs($admin)
            ->withSession(['selected_entity_id' => $saas->id, 'selected_entity_is_client' => false, 'selected_entity_user_rule' => 'admin'])
            ->get(route('manager.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('support', null));
    });

    it('visitante não recebe o botão', function () {
        config(['saas.support.whatsapp' => '5561999990000']);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('support', null));
    });
});

describe('SupportContact::props', function () {
    it('aceita de 10 a 15 dígitos', function () {
        foreach (['1234567890', '123456789012345'] as $valid) {
            config(['saas.support.whatsapp' => $valid]);

            expect(SupportContact::props()['kind'])->toBe('whatsapp');
        }
    });
});
