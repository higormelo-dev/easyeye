import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import WhatsAppIndex from '@/Pages/Panel/Manager/WhatsApp/Index.vue';

/**
 * Manager → WhatsApp oficial (Gupshup), reorganizado no padrão de Manager →
 * Medicamentos: faixa de situação única (simulação / parceiro ausente / token
 * universal / número do EasyEye), KPIs que filtram, abas, filtros server-side
 * (router.get), tabela/cards, gaveta de detalhes, modal da clínica (mesmos
 * campos de antes), aba Número do EasyEye (salvar, webhook, verificar,
 * teste) e Modelos — sem nenhum segredo na tela.
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR' } }),
    router: { get: vi.fn(), reload: vi.fn() },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({
    default: {
        props: ['title', 'total', 'view', 'showViewToggle'],
        emits: ['set-view'],
        template: `<header><h1>{{ title }}</h1><span class="total">{{ total }}</span>
            <button v-if="showViewToggle" class="to-cards" @click="$emit('set-view', 'cards')" />
            <button v-if="showViewToggle" class="to-table" @click="$emit('set-view', 'table')" />
            <slot name="actions" /></header>`,
    },
}));
vi.mock('@/Components/Panel/TablePagination.vue', () => ({
    default: { props: ['data'], template: '<nav class="pagination-stub" :data-total="data.total" />' },
}));
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { props: ['title'], template: '<div class="dropdown-stub"><slot /></div>' },
}));

const t = {
    title: 'WhatsApp (API oficial)',
    save: 'Salvar',
    close: 'Fechar',
    manager: {
        configure: 'Configurar',
        status_via_global: 'Ativo (número do EasyEye)',
        status_inactive: 'Número próprio (inativo)',
        status_unconfigured: 'Não configurado',
        global_title: 'Número do EasyEye (padrão)',
        global_hint: 'Hint global',
        global_active: 'Número do EasyEye ativo',
        mock_warning: 'MODO SIMULAÇÃO',
        partner_missing: 'Credenciais do parceiro ausentes',
        partner_ok: 'Parceiro Gupshup configurado',
        auth_universal: 'token universal',
        auth_partner_token: 'e-mail + client secret',
        opt_outs: 'descadastrados (SAIR)',
        ut_expiring: 'Token universal vence em :date (faltam :days dias).',
        ut_expired: 'Token universal VENCEU em :date.',
        templates_title: 'Modelos do WhatsApp',
        templates_hint: 'Prévia',
        template_groups: { patients: 'Mensagens aos pacientes' },
        template_languages: { pt_BR: 'Português', en: 'Inglês' },
    },
    ui: {
        page_title: 'WhatsApp',
        total_label: 'Clínicas:',
        view_table: 'Tabela',
        view_cards: 'Cards',
        send_test: 'Mensagem de teste',
        send_test_hint: 'Enviar teste pelo número do EasyEye',
        tabs_label: 'Seções',
        tabs: { clinics: 'Clínicas', global: 'Número do EasyEye', templates: 'Modelos' },
        situation_label: 'Situação',
        problem_danger: 'Problema',
        problem_warning: 'Atenção',
        all_ok: 'Tudo certo',
        mode_real: 'Envio real',
        mode_simulated: 'Simulação',
        partner: 'Parceiro Gupshup',
        partner_not_set: 'sem credenciais',
        ut_until: 'token até :date',
        global: 'Número do EasyEye',
        global_missing: 'Número do EasyEye sem app',
        global_inactive: 'Número do EasyEye inativo',
        global_webhook_off: 'Webhook do número do EasyEye não registrado',
        global_unhealthy: 'App do EasyEye não saudável',
        global_check_failed: 'Falha ao verificar: :error',
        global_ok: 'ativo',
        global_off: 'inativo',
        global_not_set: 'não configurado',
        webhook_ok: 'webhook registrado',
        webhook_off: 'webhook não registrado',
        health_ok: 'app saudável',
        health_bad: 'app com problema',
        health_unchecked: 'saúde não verificada',
        action_configure: 'Configurar',
        action_register: 'Registrar webhook de novo',
        action_verify: 'Verificar app',
        kpis_label: 'Resumo',
        kpis: {
            clinics: 'Clínicas',
            own: 'Com número próprio',
            global: 'Usando o número do EasyEye',
            confirmations: 'Confirmações ativas',
            messages_sent: 'Mensagens enviadas (30 dias)',
            opt_outs: 'Descadastrados (SAIR)',
        },
        kpi_hints: {},
        search_placeholder: 'Buscar clínica',
        search_clear: 'Limpar busca',
        filters_label: 'Filtros',
        filter_number: 'Número',
        filter_number_all: 'Número: todos',
        filter_automation: 'Automação',
        filter_automation_all: 'Automação: todas',
        automation: { confirmation: 'Confirmação ativa', survey: 'Pesquisa ativa', none: 'Nenhuma' },
        filter_clear: 'Limpar filtros',
        sending: { own: 'Número próprio', global: 'Número do EasyEye', none: 'Sem envio' },
        sending_hint: { own: 'Envia pelo próprio.', global: 'Envia pelo EasyEye.', none: 'Não envia.' },
        none_reason: {
            unconfigured: 'Ainda não configurada.',
            inactive: 'Integração desligada.',
            own_inactive: 'Própria desligada.',
            global_unavailable: 'EasyEye indisponível.',
        },
        col_clinic: 'Clínica',
        col_number: 'Número usado',
        col_confirmation: 'Confirmação',
        col_survey: 'Pesquisa',
        col_sent: 'Enviadas / respondidas (30d)',
        col_opt_outs: 'Descadastrados',
        col_actions: 'Ações',
        hours_before: ':hours h antes',
        hours_after: ':hours h depois',
        off: 'Desligada',
        sent_answered: ':sent / :answered',
        sent_answered_sr: ':sent enviadas, :answered respondidas',
        action_view: 'Ver detalhes',
        more_actions: 'Mais ações',
        test_own_app: 'Testar app próprio',
        empty: 'Nenhuma clínica ativa.',
        empty_filtered: 'Nenhuma clínica com estes filtros.',
        detail_sending: 'Situação do envio',
        detail_integration: 'Integração',
        detail_number: 'Número usado',
        detail_own_app: 'App próprio',
        detail_no_own_app: 'Sem app próprio',
        detail_app_id: 'App ID',
        detail_webhook: 'Webhook',
        detail_health: 'Saúde do app',
        detail_automations: 'Automações',
        detail_stats: 'Últimos 30 dias',
        detail_opt_outs: 'Descadastrados',
        detail_opt_outs_hint: 'Hint',
        detail_no_setting: 'Sem WhatsApp configurado',
        detail_no_stats: 'Nenhuma mensagem',
        survey_average_value: ':value de 5',
        config_title: 'WhatsApp da clínica',
        section_automation: 'Automação',
        section_own_number: 'Número próprio (opcional)',
        active_hint: 'Desligada, nada sai.',
        hours_unit: 'horas',
        global_section_app: 'App da Gupshup',
        global_section_webhook: 'Webhook',
        global_section_test: 'Teste de envio',
        global_test_hint: 'Envia o teste',
        global_save_first: 'Salve o App ID primeiro',
    },
    app: {
        hint: 'Hint',
        app_id: 'App ID',
        app_id_placeholder: 'ex.',
        configured: 'Número próprio configurado',
        not_configured: 'Usa o número do EasyEye',
        replace_hint: 'Trocar',
        clear: 'Remover',
        clear_confirm: 'Remover?',
        clear_global_confirm: 'Remover global?',
    },
    connection: {
        test: 'Verificar app',
        testing: 'Verificando...',
        healthy: 'App saudável',
        unhealthy: 'Não saudável',
        fill_first: 'Informe o App ID',
        send_test: 'Enviar teste',
        test_phone: 'Celular',
        sent: 'Mensagem de teste enviada.',
        failed: 'Erro',
    },
    webhook: {
        title: 'Webhook',
        hint: 'Hint',
        ok: 'Registrado',
        pending: 'Não registrado',
        register: 'Registrar de novo',
        warn_failed: 'Falhou o registro',
    },
    toggles: {
        active: 'Integração ativa',
        confirmation_enabled: 'Confirmação de consulta',
        confirmation_hours_before: 'Horas antes',
        confirmation_hint: 'Hint',
        survey_enabled: 'Pesquisa de satisfação',
        survey_delay_hours: 'Horas depois',
        survey_hint: 'Hint',
    },
    stats: {
        confirmations_sent: 'Confirmações enviadas',
        confirmations_answered: 'Confirmações respondidas',
        surveys_sent: 'Pesquisas enviadas',
        surveys_answered: 'Pesquisas respondidas',
        survey_average: 'Nota média',
        delivered: 'Entregues',
        read: 'Lidas',
        failed: 'Falhas de envio',
    },
};

const routes = {
    update: '/panel/manager/whatsapp/__ID__',
    test: '/panel/manager/whatsapp/__ID__/test',
    global_update: '/panel/manager/whatsapp/global',
    global_test: '/panel/manager/whatsapp/global/test',
};

const stats = {
    confirmations_sent: 10,
    confirmations_answered: 7,
    surveys_sent: 4,
    surveys_answered: 3,
    survey_average: 4.5,
    delivered: 13,
    read: 9,
    failed: 1,
};

function clinicsPage(data) {
    return { data, total: data.length, last_page: 1, current_page: 1, links: [] };
}

const ownClinic = {
    id: 'c1',
    name: 'Clínica Própria',
    code: 'ENT-0000000001',
    sending: 'own',
    setting: {
        active: true,
        confirmation_enabled: true,
        confirmation_hours_before: 48,
        survey_enabled: true,
        survey_delay_hours: 2,
        has_app: true,
        app_id: 'app-c1',
        webhook_ok: true,
        webhook_url: 'https://x/api/whatsapp/gupshup/webhook/tok',
        opt_outs: 3,
    },
    stats,
};
const globalClinic = {
    id: 'c2',
    name: 'Clínica Global',
    code: 'ENT-0000000002',
    sending: 'global',
    setting: {
        active: true,
        confirmation_enabled: true,
        confirmation_hours_before: 24,
        survey_enabled: false,
        survey_delay_hours: 2,
        has_app: false,
        app_id: null,
        webhook_ok: false,
        webhook_url: 'https://x/api/whatsapp/gupshup/webhook/c2',
        opt_outs: 0,
    },
    stats: { ...stats, confirmations_sent: 0, surveys_sent: 0, confirmations_answered: 0, surveys_answered: 0 },
};
const bareClinic = { id: 'c3', name: 'Clínica Sem Config', code: null, sending: 'none', setting: null, stats: null };

const healthyGlobal = {
    active: true,
    has_app: true,
    app_id: 'global-app',
    webhook_ok: true,
    webhook_url: 'https://x/api/whatsapp/gupshup/webhook/g',
    opt_outs: 2,
};

function factory(props = {}) {
    return mount(WhatsAppIndex, {
        props: {
            clinics: clinicsPage([ownClinic, globalClinic, bareClinic]),
            filters: { search: '', number: '', automation: '', tab: 'clinics' },
            kpis: { clinics: 3, own: 1, global: 1, none: 1, confirmations: 2, messages_sent: 1234, opt_outs: 5 },
            global: healthyGlobal,
            driver: 'gupshup',
            simulated: false,
            partner: { configured: true, auth_mode: 'universal' },
            templates: [
                {
                    key: 'appointment_confirmation',
                    name: 'easyeye_confirmacao_consulta',
                    category: 'UTILITY',
                    group: 'patients',
                    texts: { pt_BR: { meta: 'Olá', preview: 'Olá', buttons: [], params: [] } },
                },
            ],
            routes,
            t,
            ...props,
        },
        global: { directives: { mask: {} }, stubs: { teleport: true } },
        attachTo: document.body,
    });
}

let wrapper;

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    router.get.mockClear();
    router.reload.mockClear();
    window.axios = {
        patch: vi.fn().mockResolvedValue({ data: { message: 'Salvo.', webhook_ok: true, has_app: true } }),
        post: vi.fn().mockResolvedValue({ data: { ok: true, healthy: true } }),
    };
    window.confirm = vi.fn(() => true);
    try {
        localStorage.clear();
    } catch {
        // sem storage
    }
    window.history.replaceState(null, '', '/panel/manager/whatsapp');
});

afterEach(() => {
    wrapper?.unmount();
    document.body.innerHTML = '';
    vi.useRealTimers();
});

const status = () => wrapper.find('[data-test="wa-status"]');

describe('faixa de situação única', () => {
    it('simulação aparece em amarelo (inclusive com driver vazio → simulated) e parceiro ausente não', () => {
        wrapper = factory({ driver: 'mock', simulated: true });

        const mock = status().find('[data-test="mock-warning"]');
        expect(mock.exists()).toBe(true);
        expect(mock.attributes('data-tone')).toBe('warning');
        expect(mock.text()).toContain('MODO SIMULAÇÃO');
        expect(status().find('[data-test="partner-missing"]').exists()).toBe(false);
        expect(status().text()).toContain('Simulação');
        expect(status().find('[data-test="status-ok"]').exists()).toBe(false);
    });

    it('envio real sem credenciais do parceiro avisa em vermelho', () => {
        wrapper = factory({ partner: { configured: false, auth_mode: null } });

        expect(status().find('[data-test="mock-warning"]').exists()).toBe(false);
        const missing = status().find('[data-test="partner-missing"]');
        expect(missing.text()).toContain('Credenciais do parceiro ausentes');
        expect(missing.attributes('data-tone')).toBe('danger');
    });

    it('token universal: amarelo perto de vencer, vermelho vencido, nada quando longe', () => {
        const partner = { configured: true, auth_mode: 'universal' };

        wrapper = factory({
            partner: { ...partner, ut_expires_at: '2026-10-10', ut_days_left: 5, ut_expiring: true, ut_expired: false },
        });
        let warn = status().find('[data-test="ut-expiring"]');
        expect(warn.text()).toContain('faltam 5 dias');
        expect(warn.text()).toContain('10/10/2026');
        expect(warn.attributes('data-tone')).toBe('warning');
        wrapper.unmount();

        wrapper = factory({
            partner: { ...partner, ut_expires_at: '2026-10-01', ut_days_left: 0, ut_expiring: false, ut_expired: true },
        });
        warn = status().find('[data-test="ut-expiring"]');
        expect(warn.attributes('data-tone')).toBe('danger');
        expect(warn.text()).toContain('VENCEU');
        wrapper.unmount();

        wrapper = factory({
            partner: {
                ...partner,
                ut_expires_at: '2026-12-31',
                ut_days_left: 80,
                ut_expiring: false,
                ut_expired: false,
            },
        });
        expect(status().find('[data-test="ut-expiring"]').exists()).toBe(false);
        expect(status().find('[data-test="partner-ok"]').text()).toContain('token até 31/12/2026');
    });

    it('tudo certo = uma linha verde discreta com modo, parceiro (modo de autenticação) e número do EasyEye', () => {
        wrapper = factory();

        expect(status().classes()).toContain('wa-status--ok');
        expect(status().find('[data-test="status-ok"]').text()).toContain('Tudo certo');
        expect(status().find('[data-test="partner-ok"]').text()).toContain('token universal');
        expect(status().find('[data-test="global-summary"]').text()).toContain('ativo · webhook registrado');
        expect(status().findAll('li')).toHaveLength(0);
    });

    it('número do EasyEye sem app: problema em vermelho com "Configurar" que abre a aba', async () => {
        wrapper = factory({ global: null });

        const item = status().find('[data-test="global-missing"]');
        expect(item.attributes('data-tone')).toBe('danger');
        await item.find('[data-test="status-action-configure"]').trigger('click');

        expect(wrapper.find('[data-test="tab-global"]').attributes('aria-selected')).toBe('true');
        expect(wrapper.find('[data-test="panel-global"]').isVisible()).toBe(true);
        expect(window.location.search).toBe('?tab=global');
    });

    it('webhook não registrado: "Registrar webhook de novo" direto da faixa', async () => {
        wrapper = factory({ global: { ...healthyGlobal, webhook_ok: false } });

        await status().find('[data-test="status-action-register-webhook"]').trigger('click');
        await flushPromises();

        expect(window.axios.patch).toHaveBeenCalledWith(
            routes.global_update,
            { active: true, register_webhook: true },
            expect.any(Object),
        );
    });

    it('verificar app pela faixa: app com problema vira destaque; saudável mostra no resumo', async () => {
        window.axios.post.mockResolvedValueOnce({ data: { ok: true, healthy: false } });
        wrapper = factory();

        await status().find('[data-test="status-verify"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(routes.global_test, {}, expect.any(Object));
        expect(status().find('[data-test="global-unhealthy"]').text()).toContain('App do EasyEye não saudável');

        await status().find('[data-test="status-action-verify"]').trigger('click');
        await flushPromises();

        expect(status().find('[data-test="global-unhealthy"]').exists()).toBe(false);
        expect(status().find('[data-test="global-summary"]').text()).toContain('app saudável');
    });
});

describe('KPIs', () => {
    it('mostra os seis números localizados; os de filtro são botões com aria-pressed', () => {
        wrapper = factory();

        expect(wrapper.find('[data-test="kpi-messages_sent"]').text()).toBe('1.234');
        expect(wrapper.find('[data-kpi="clinics"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.find('[data-kpi="own"]').attributes('aria-pressed')).toBe('false');
        expect(wrapper.find('[data-kpi="messages_sent"]').element.tagName).toBe('DIV');
        expect(wrapper.find('[data-kpi="opt_outs"]').element.tagName).toBe('DIV');
        expect(wrapper.findAll('[data-kpi]')).toHaveLength(6);
    });

    it('KPI filtra a lista (e alterna); "Clínicas" limpa os filtros', async () => {
        wrapper = factory();

        await wrapper.find('[data-kpi="own"]').trigger('click');
        expect(router.get).toHaveBeenLastCalledWith(
            '/_routes/manager.whatsapp.index',
            { search: undefined, number: 'own', automation: undefined },
            expect.objectContaining({ preserveState: true, preserveScroll: true, only: ['clinics', 'filters'] }),
        );
        expect(wrapper.find('[data-kpi="own"]').attributes('aria-pressed')).toBe('true');

        await wrapper.find('[data-kpi="confirmations"]').trigger('click');
        expect(router.get).toHaveBeenLastCalledWith(
            '/_routes/manager.whatsapp.index',
            { search: undefined, number: 'own', automation: 'confirmation' },
            expect.any(Object),
        );

        await wrapper.find('[data-kpi="global"]').trigger('click');
        expect(router.get.mock.lastCall[1].number).toBe('global');

        await wrapper.find('[data-kpi="global"]').trigger('click');
        expect(router.get.mock.lastCall[1].number).toBeUndefined();

        await wrapper.find('[data-kpi="clinics"]').trigger('click');
        expect(router.get.mock.lastCall[1]).toEqual({ search: undefined, number: undefined, automation: undefined });
    });
});

describe('abas', () => {
    it('abre na aba da URL (filters.tab) e troca com clique ou setas, lembrando na URL', async () => {
        wrapper = factory({ filters: { tab: 'templates' } });

        expect(wrapper.find('[data-test="panel-templates"]').isVisible()).toBe(true);
        expect(wrapper.find('[data-test="wa-templates"]').text()).toContain('easyeye_confirmacao_consulta');
        expect(wrapper.find('[data-test="panel-clinics"]').isVisible()).toBe(false);

        await wrapper.find('[data-test="tab-templates"]').trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.find('[data-test="panel-clinics"]').isVisible()).toBe(true);
        expect(window.location.search).toBe('');

        await wrapper.find('[data-test="tab-global"]').trigger('click');
        expect(wrapper.find('[data-test="panel-global"]').isVisible()).toBe(true);
        expect(window.location.search).toBe('?tab=global');
    });

    it('toggle tabela/cards só na aba Clínicas e lembrado no navegador', async () => {
        wrapper = factory();

        expect(wrapper.find('[data-test="wa-clinic-table"]').exists()).toBe(true);
        await wrapper.find('.to-cards').trigger('click');
        expect(wrapper.find('[data-test="wa-clinic-cards"]').exists()).toBe(true);
        expect(localStorage.getItem('mgr_whatsapp_view')).toBe('cards');

        await wrapper.find('[data-test="tab-global"]').trigger('click');
        expect(wrapper.find('.to-cards').exists()).toBe(false);
    });

    it('"Mensagem de teste" do cabeçalho só com o número do EasyEye cadastrado e leva à aba dele', async () => {
        wrapper = factory({ global: null });
        expect(wrapper.find('[data-test="header-send-test"]').exists()).toBe(false);
        wrapper.unmount();

        wrapper = factory();
        await wrapper.find('[data-test="header-send-test"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="panel-global"]').isVisible()).toBe(true);
        expect(document.activeElement?.id).toBe('g-test-phone');
    });
});

describe('filtros da lista (server-side)', () => {
    it('selects chamam router.get com os parâmetros; busca com debounce; "Limpar filtros" zera', async () => {
        wrapper = factory();

        await wrapper.find('[data-test="filter-number"]').setValue('none');
        expect(router.get).toHaveBeenLastCalledWith(
            '/_routes/manager.whatsapp.index',
            { search: undefined, number: 'none', automation: undefined },
            expect.objectContaining({ replace: true, only: ['clinics', 'filters'] }),
        );

        await wrapper.find('[data-test="filter-automation"]').setValue('survey');
        expect(router.get.mock.lastCall[1]).toEqual({ search: undefined, number: 'none', automation: 'survey' });

        router.get.mockClear();
        await wrapper.find('input[type="text"]').setValue('visão');
        expect(router.get).not.toHaveBeenCalled();
        vi.advanceTimersByTime(400);
        expect(router.get.mock.lastCall[1]).toEqual({ search: 'visão', number: 'none', automation: 'survey' });

        await wrapper.find('[data-test="filter-clear"]').trigger('click');
        vi.advanceTimersByTime(400);
        expect(router.get.mock.lastCall[1]).toEqual({ search: undefined, number: undefined, automation: undefined });
        expect(wrapper.find('[data-test="filter-clear"]').exists()).toBe(false);
    });

    it('lista vazia com filtro mostra a mensagem de filtro', () => {
        wrapper = factory({ clinics: clinicsPage([]), filters: { number: 'own' } });

        expect(wrapper.find('[data-test="wa-empty"]').text()).toContain('Nenhuma clínica com estes filtros.');
    });
});

describe('tabela e cards', () => {
    it('tabela: selo do número usado, automações com antecedência/atraso, enviadas/respondidas e descadastrados', () => {
        wrapper = factory();

        const own = wrapper.find('[data-test="wa-row-c1"]');
        expect(own.find('[data-test="wa-sending-c1"]').text()).toBe('Número próprio');
        expect(own.text()).toContain('48 h antes');
        expect(own.text()).toContain('2 h depois');
        expect(own.text()).toContain('14 / 10');
        expect(own.text()).toContain('3');
        expect(own.find('[data-test="test-c1"]').exists()).toBe(true);

        const global = wrapper.find('[data-test="wa-row-c2"]');
        expect(global.find('[data-test="wa-sending-c2"]').text()).toBe('Número do EasyEye');
        expect(global.text()).toContain('Desligada');
        expect(global.find('[data-test="test-c2"]').exists()).toBe(false);

        const bare = wrapper.find('[data-test="wa-row-c3"]');
        expect(bare.find('[data-test="wa-sending-c3"]').text()).toBe('Sem envio');
        expect(bare.text()).toContain('Ainda não configurada.');
    });

    it('cards: mesmas ações (ver, testar quando há app próprio, configurar)', async () => {
        wrapper = factory();
        await wrapper.find('.to-cards').trigger('click');

        expect(wrapper.find('[data-test="wa-card-c1"]').text()).toContain('14 enviadas, 10 respondidas');
        expect(wrapper.find('[data-test="card-test-c1"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="card-test-c2"]').exists()).toBe(false);

        await wrapper.find('[data-test="card-configure-c2"]').trigger('click');
        expect(wrapper.find('[data-test="wa-clinic-form"]').exists()).toBe(true);
    });
});

describe('gaveta de detalhes', () => {
    it('mostra situação, app próprio, automações, estatísticas e descadastrados; rodapé abre o modal', async () => {
        wrapper = factory();

        await wrapper.find('[data-test="view-c1"]').trigger('click');

        const own = wrapper.find('[data-test="drawer-own-app"]');
        expect(wrapper.find('[data-test="drawer-sending"]').text()).toBe('Número próprio');
        expect(own.text()).toContain('app-c1');
        expect(own.text()).toContain('Registrado');
        expect(own.text()).toContain('saúde não verificada');
        expect(wrapper.find('[data-test="drawer-stats"]').text()).toContain('4,5 de 5');
        expect(wrapper.find('[data-test="drawer-opt-outs"]').text()).toBe('3');

        await wrapper.find('[data-test="drawer-configure"]').trigger('click');
        expect(wrapper.find('[data-test="wa-clinic-form"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="drawer-own-app"]').exists()).toBe(false);
    });

    it('"Testar app próprio" do menu abre a gaveta e verifica o app salvo da clínica', async () => {
        window.axios.post.mockResolvedValueOnce({ data: { ok: false, error: 'App não encontrado' } });
        wrapper = factory();

        await wrapper.find('[data-test="test-c1"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/panel/manager/whatsapp/c1/test', {}, expect.any(Object));
        expect(wrapper.find('[data-test="drawer-health"]').text()).toContain('App não encontrado');

        await wrapper.find('[data-test="drawer-verify"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-test="drawer-health"]').text()).toContain('App saudável');
    });

    it('clínica sem configuração explica e oferece "Configurar"', async () => {
        wrapper = factory();

        await wrapper.find('[data-test="view-c3"]').trigger('click');

        expect(wrapper.find('[data-test="drawer-no-setting"]').text()).toContain('Sem WhatsApp configurado');
        expect(wrapper.find('[data-test="drawer-stats"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="drawer-configure"]').exists()).toBe(true);
    });
});

describe('modal da clínica', () => {
    it('salva os mesmos campos de antes; sem App ID novo não manda o campo; recarrega lista e KPIs', async () => {
        wrapper = factory();

        await wrapper.find('[data-test="configure-c2"]').trigger('click');
        await wrapper.find('[data-test="clinic-save"]').trigger('click');
        await flushPromises();

        expect(window.axios.patch).toHaveBeenLastCalledWith(
            '/panel/manager/whatsapp/c2',
            {
                active: true,
                confirmation_enabled: true,
                confirmation_hours_before: 24,
                survey_enabled: false,
                survey_delay_hours: 2,
            },
            expect.any(Object),
        );
        expect(router.reload).toHaveBeenCalledWith({ only: ['clinics', 'kpis'], preserveScroll: true });
        expect(wrapper.find('[data-test="clinic-saved"]').text()).toBe('Salvo.');

        await wrapper.find('[data-test="clinic-app-id"]').setValue('app-c2');
        await wrapper.find('#mgr-wpp-hours').setValue(12);
        await wrapper.find('[data-test="clinic-save"]').trigger('click');
        await flushPromises();

        expect(window.axios.patch).toHaveBeenLastCalledWith(
            '/panel/manager/whatsapp/c2',
            expect.objectContaining({ app_id: 'app-c2', confirmation_hours_before: 12, confirmation_enabled: true }),
            expect.any(Object),
        );
    });

    it('clínica sem configuração abre com os padrões de antes (24 h / 2 h, integração desligada)', async () => {
        wrapper = factory();

        await wrapper.find('[data-test="configure-c3"]').trigger('click');
        await wrapper.find('[data-test="clinic-save"]').trigger('click');
        await flushPromises();

        expect(window.axios.patch).toHaveBeenLastCalledWith(
            '/panel/manager/whatsapp/c3',
            {
                active: false,
                confirmation_enabled: true,
                confirmation_hours_before: 24,
                survey_enabled: true,
                survey_delay_hours: 2,
            },
            expect.any(Object),
        );
    });

    it('verificar app usa o App ID digitado (teste antes de salvar); mensagem de teste manda o celular', async () => {
        wrapper = factory();

        await wrapper.find('[data-test="configure-c2"]').trigger('click');
        await wrapper.find('[data-test="clinic-app-id"]').setValue('app-adhoc');
        await wrapper.find('[data-test="clinic-test"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            '/panel/manager/whatsapp/c2/test',
            { app_id: 'app-adhoc' },
            expect.any(Object),
        );
        expect(wrapper.find('[data-test="clinic-test-result"]').text()).toContain('App saudável');

        window.axios.post.mockResolvedValueOnce({ data: { ok: true, sent: true } });
        await wrapper.find('#clinic-test-phone').setValue('61999998888');
        await wrapper.find('[data-test="clinic-send-test"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenLastCalledWith(
            '/panel/manager/whatsapp/c2/test',
            { app_id: 'app-adhoc', phone: '61999998888' },
            expect.any(Object),
        );
        expect(wrapper.find('[data-test="clinic-test-result"]').text()).toContain('Mensagem de teste enviada.');
    });

    it('app próprio: registrar webhook de novo, remover (com confirmação) e aviso de webhook não registrado', async () => {
        window.axios.patch.mockResolvedValueOnce({ data: { message: 'Salvo.', webhook_ok: false, has_app: true } });
        wrapper = factory();

        await wrapper.find('[data-test="configure-c1"]').trigger('click');
        await wrapper.find('[data-test="clinic-register-webhook"]').trigger('click');
        await flushPromises();

        expect(window.axios.patch).toHaveBeenLastCalledWith(
            '/panel/manager/whatsapp/c1',
            expect.objectContaining({ register_webhook: true, confirmation_hours_before: 48 }),
            expect.any(Object),
        );
        expect(wrapper.find('[data-test="clinic-webhook"]').text()).toContain('Falhou o registro');

        window.axios.patch.mockResolvedValueOnce({ data: { message: 'Salvo.', webhook_ok: true, has_app: false } });
        await wrapper.find('[data-test="clinic-clear-app"]').trigger('click');
        await flushPromises();

        expect(window.confirm).toHaveBeenCalledWith('Remover?');
        expect(window.axios.patch).toHaveBeenLastCalledWith(
            '/panel/manager/whatsapp/c1',
            expect.objectContaining({ clear_app: true }),
            expect.any(Object),
        );
        expect(wrapper.find('[data-test="clinic-webhook"]').exists()).toBe(false);
    });

    it('erro de validação do App ID aparece no campo', async () => {
        window.axios.patch.mockRejectedValueOnce({ response: { data: { errors: { app_id: ['App ID já usado.'] } } } });
        wrapper = factory();

        await wrapper.find('[data-test="configure-c1"]').trigger('click');
        await wrapper.find('[data-test="clinic-app-id"]').setValue('app-repetido');
        await wrapper.find('[data-test="clinic-save"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="clinic-app-id"]').classes()).toContain('is-invalid');
        expect(wrapper.text()).toContain('App ID já usado.');
    });
});

describe('aba Número do EasyEye', () => {
    async function openGlobal(props = {}) {
        wrapper = factory({ filters: { tab: 'global' }, ...props });
        await flushPromises();
    }

    it('salva o App ID digitado, recarrega e avisa quando o webhook não foi registrado', async () => {
        window.axios.patch.mockResolvedValueOnce({ data: { message: 'Salvo.', webhook_ok: false } });
        await openGlobal({ global: { ...healthyGlobal, webhook_ok: false } });

        await wrapper.find('[data-test="global-app-id"]').setValue('novo-app');
        await wrapper.find('[data-test="global-save"]').trigger('click');
        await flushPromises();

        expect(window.axios.patch).toHaveBeenCalledWith(
            routes.global_update,
            { active: true, app_id: 'novo-app' },
            expect.any(Object),
        );
        expect(router.reload).toHaveBeenCalledWith({ only: ['global', 'clinics', 'kpis'], preserveScroll: true });
        expect(wrapper.find('[data-test="global-webhook"]').text()).toContain('Falhou o registro');
        expect(wrapper.find('[data-test="global-webhook"]').text()).toContain('Não registrado');
    });

    it('registrar o webhook de novo manda register_webhook', async () => {
        await openGlobal();

        await wrapper.find('[data-test="global-register-webhook"]').trigger('click');
        await flushPromises();

        expect(window.axios.patch).toHaveBeenCalledWith(
            routes.global_update,
            { active: true, register_webhook: true },
            expect.any(Object),
        );
    });

    it('verificar app chama o teste sem telefone (saúde) e mostra o resultado', async () => {
        await openGlobal();

        await wrapper.find('[data-test="global-test"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(routes.global_test, {}, expect.any(Object));
        expect(wrapper.find('[data-test="global-healthy"]').text()).toBe('App saudável');
    });

    it('mensagem de teste manda o celular digitado', async () => {
        window.axios.post.mockResolvedValueOnce({ data: { ok: true, sent: true } });
        await openGlobal();

        await wrapper.find('#g-test-phone').setValue('61999998888');
        await wrapper.find('[data-test="global-send-test"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            routes.global_test,
            { phone: '61999998888' },
            expect.any(Object),
        );
        expect(wrapper.find('[data-test="panel-global"]').text()).toContain('Mensagem de teste enviada.');
    });

    it('sem app cadastrado: sem webhook/teste, com orientação para salvar o App ID', async () => {
        await openGlobal({ global: null });

        expect(wrapper.find('[data-test="global-register-webhook"]').exists()).toBe(false);
        expect(wrapper.find('#g-test-phone').exists()).toBe(false);
        expect(wrapper.find('[data-test="global-status"]').text()).toBe('Não configurado');
        expect(wrapper.find('[data-test="global-webhook"]').text()).toContain('Salve o App ID primeiro');
    });

    it('nenhum segredo na tela: só App ID e URL do webhook', async () => {
        await openGlobal();

        expect(wrapper.html()).not.toContain('secret');
        expect(wrapper.find('[data-test="global-webhook"]').text()).toContain(healthyGlobal.webhook_url);
    });
});
