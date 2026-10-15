import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async () =>
    (await import('../Manager/RecallProtocols/support/inertiaMock.js')).buildInertiaMock(),
);
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div class="layout"><h1>{{ title }}</h1><slot /></div>' },
}));
// A regra e a diferença são testadas à parte (RecallRuleCard / RecallContentDiff); aqui importa quando aparecem.
vi.mock('@/Components/Recall/RecallRuleCard.vue', () => ({
    default: { props: ['content'], template: '<div data-testid="rule-card">{{ content.marker }}</div>' },
}));
vi.mock('@/Components/Recall/RecallContentDiff.vue', () => ({
    default: { props: ['changes'], template: '<div data-testid="diff">{{ changes.length }} mudanças</div>' },
}));

import { forms, resetInertiaMock } from '../Manager/RecallProtocols/support/inertiaMock.js';
import Settings from '@/Pages/Panel/Recall/Settings.vue';
import settingsT from './support/recall_settings.json';

// Em produção o ZiggyVue registra `route` como propriedade global (usada nos templates); no teste, só o global do script existe.
const global = {
    mocks: { route: (...args) => globalThis.route(...args) },
    stubs: { Teleport: true, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } },
};

const evidence = (overrides = {}) => ({
    version: 1,
    hash: '1:' + 'ab'.repeat(32),
    hash_short: 'abababababab',
    validated_at: '2026-10-10',
    accepted_at: '2026-10-11',
    released_at: '2026-10-12',
    review_due_at: '2028-10-12',
    review_overdue: false,
    reviewer: { name: 'DRA. ANA PAULA SOUZA', crm: 'CRM/SP 12345', rqe: 'RQE 6789', specialty: 'Oftalmologia' },
    ...overrides,
});

const version = (id, number, marker, overrides = {}) => ({
    id,
    version: number,
    content: { marker },
    evidence: evidence({ version: number }),
    ...overrides,
});

const tasks = (overrides = {}) => [
    {
        key: 'consulta',
        kind: 'consultation',
        exam_key: null,
        required: true,
        unit: 'months',
        default: 4,
        min: 3,
        max: 6,
        interval: 4,
        enabled: true,
    },
    {
        key: 'oct',
        kind: 'exam',
        exam_key: 'oct_optic_nerve',
        required: false,
        unit: 'months',
        default: 12,
        min: 6,
        max: 24,
        interval: 12,
        enabled: false,
    },
    ...(overrides.extra ?? []),
];

const protocol = (overrides = {}) => ({
    id: 'p-1',
    key: 'glaucoma',
    title: 'Glaucoma',
    summary: 'Consulta e campimetria periódicas.',
    status: 'available',
    released: version('v-1', 1, 'regra v1'),
    adoption: null,
    diff: [],
    tasks: tasks(),
    ...overrides,
});

const adoption = (overrides = {}) => ({
    id: 'a-1',
    version_id: 'v-1',
    version: version('v-1', 1, 'regra v1'),
    usable: true,
    pause_reason: null,
    parameters: { tasks: { consulta: { interval: 4, enabled: true } } },
    auto_contact: false,
    activated_at: '2026-10-13',
    activated_by: 'Dra. Helena Médica',
    adopted_hash: 'abababababab',
    ...overrides,
});

function props(overrides = {}) {
    return {
        breadcrumbs: [],
        general: {
            doctor_order_enabled: true,
            whatsapp_invites_enabled: false,
            invite_window_start: '09:00',
            invite_window_end: '18:00',
            invite_weekdays: [1, 2, 3, 4, 5],
            min_days_between_invites: 30,
            max_auto_invites_per_cycle: 3,
            daily_invite_cap: 20,
            auto_invite_max_overdue_days: 180,
            cap_today: 5,
            sent_today: 2,
            min_days_floor: 7,
        },
        invites: { available: true, blocker: null, label: null },
        protocols: [protocol()],
        exams: {
            keys: [
                { key: 'visual_field', label: 'Campo visual (campimetria)' },
                { key: 'oct_macula', label: 'OCT de mácula' },
            ],
            types: [
                { id: 't-1', name: 'CAMPIMETRIA COMPUTADORIZADA', global: true, unavailable: false },
                { id: 't-2', name: 'CAMPO VISUAL 24-2', global: false, unavailable: false },
            ],
            mappings: { visual_field: ['t-2'], oct_macula: [] },
            suggestions: { visual_field: ['t-1'], oct_macula: [] },
        },
        canDecide: true,
        canConfigure: true,
        isDoctor: true,
        doctorMissing: false,
        doctorMissingText: '',
        ruleT: { labels: { exams: { oct_optic_nerve: 'OCT de nervo óptico' }, kinds: { consultation: 'Consulta' } } },
        t: settingsT,
        ...overrides,
    };
}

const mountPage = (overrides = {}) => mount(Settings, { props: props(overrides), global });
const card = (wrapper, key = 'glaucoma') => wrapper.find(`[data-testid="protocol-${key}"]`);
const lastRequest = (index = 0) => forms.filter((form) => form.lastRequest)[index]?.lastRequest;

describe('Configurar retornos — protocolos', () => {
    beforeEach(() => resetInertiaMock());

    it('mostra o estado, a prova de quem validou (nome, CRM, RQE), a versão, o código e a revalidação', () => {
        const text = card(mountPage()).text();

        expect(card(mountPage()).attributes('data-status')).toBe('available');
        expect(text).toContain('Disponível');
        expect(text).toContain('Versão 1');
        expect(text).toContain('Validada por DRA. ANA PAULA SOUZA (CRM/SP 12345 · RQE 6789)');
        expect(text).toContain('Código da versão: abababababab');
        expect(text).toContain('Revalidação prevista até');
        expect(text).not.toContain('venceu');
    });

    it('sem autorização do Revisor: "oftalmologista revisor externo", nenhum nome', () => {
        const wrapper = mountPage({
            protocols: [protocol({ released: version('v-1', 1, 'x', { evidence: evidence({ reviewer: null }) }) })],
        });

        expect(card(wrapper).text()).toContain('Validada por um oftalmologista revisor externo');
        expect(card(wrapper).text()).not.toContain('DRA.');
    });

    it('revalidação vencida: aviso em destaque (não bloqueia)', () => {
        const wrapper = mountPage({
            protocols: [
                protocol({ released: version('v-1', 1, 'x', { evidence: evidence({ review_overdue: true }) }) }),
            ],
        });

        expect(wrapper.find('[data-testid="review-overdue"]').text()).toContain('A revalidação desta regra venceu em');
        expect(card(wrapper).text()).not.toContain('Revalidação prevista até'); // vencida: só o aviso, não as duas frases
    });

    it('"Ver a regra" abre e fecha a regra (aria-expanded) com o componente compartilhado', async () => {
        const wrapper = mountPage();
        const toggle = wrapper.find('[data-testid="toggle-rule"]');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[data-testid="rule-card"]').exists()).toBe(false);

        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(wrapper.find('[data-testid="rule-card"]').text()).toBe('regra v1');
        expect(toggle.text()).toContain('Esconder a regra');
    });

    it('ativo: mostra desde quando e por quem; versão nova: avisa, mostra o que mudou e o formulário é o da versão nova', () => {
        const wrapper = mountPage({
            protocols: [
                protocol({
                    status: 'update_available',
                    released: version('v-2', 2, 'regra v2'),
                    adoption: adoption(),
                    diff: [{ field: 'x' }],
                    tasks: tasks(),
                }),
            ],
        });
        const text = card(wrapper).text();

        expect(text).toContain('Ativo desde');
        expect(text).toContain('Dra. Helena Médica');
        expect(wrapper.find('[data-testid="update-notice"]').text()).toContain('A versão 2 foi validada e liberada');
        expect(wrapper.find('[data-testid="update-notice"]').text()).toContain('continua na versão 1');
        expect(wrapper.find('[data-testid="diff"]').text()).toBe('1 mudanças');
        expect(wrapper.find('[data-testid="submit-protocol"]').text()).toBe('Trocar para a versão 2');
    });

    it('parado: o aviso diz por quê; sem formulário de ativação e sem tarefas de uma versão que não vale', () => {
        const wrapper = mountPage({
            protocols: [
                protocol({
                    status: 'paused',
                    released: null,
                    adoption: adoption({ usable: false, pause_reason: 'suspended' }),
                    tasks: [],
                }),
            ],
        });

        expect(wrapper.find('[data-testid="paused-notice"]').text()).toContain('A EasyEye suspendeu esta regra');
        expect(wrapper.find('[data-testid^="form-"]').exists()).toBe(false);
        // O médico ainda pode DESATIVAR o que está parado.
        expect(wrapper.find('[data-testid="open-deactivate"]').exists()).toBe(true);
    });

    it('parado COM versão nova liberada: avisa, mostra o que mudou e deixa trocar (a clínica não fica presa)', () => {
        const wrapper = mountPage({
            protocols: [
                protocol({
                    status: 'paused',
                    released: version('v-2', 2, 'regra v2'),
                    adoption: adoption({ usable: false, pause_reason: 'suspended' }),
                    diff: [{ field: 'x' }],
                }),
            ],
        });

        expect(wrapper.find('[data-testid="paused-notice"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="update-notice"]').text()).toContain('seguem parados');
        expect(wrapper.find('[data-testid="update-notice"]').text()).toContain('da versão 1');
        expect(wrapper.find('[data-testid="diff"]').text()).toBe('1 mudanças');
        expect(wrapper.find('[data-testid="form-glaucoma"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="submit-protocol"]').text()).toBe('Trocar para a versão 2');
    });

    it('parado e a versão liberada É a adotada (integridade): sem formulário, só desativar', () => {
        const wrapper = mountPage({
            protocols: [
                protocol({
                    status: 'paused',
                    released: version('v-1', 1, 'regra v1'),
                    adoption: adoption({ usable: false, pause_reason: 'integrity' }),
                }),
            ],
        });

        expect(wrapper.find('[data-testid="paused-notice"]').text()).toContain('verificação de integridade');
        expect(wrapper.find('[data-testid="update-notice"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="form-glaucoma"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="open-deactivate"]').exists()).toBe(true);
    });

    it('sem protocolo liberado: mensagem de vazio', () => {
        expect(mountPage({ protocols: [] }).find('[data-testid="no-protocols"]').text()).toContain(
            'Nenhum protocolo foi liberado',
        );
    });
});

describe('Configurar retornos — ativar', () => {
    beforeEach(() => resetInertiaMock());

    it('só o médico vê o formulário; os demais veem o aviso e a regra', () => {
        const wrapper = mountPage({ canDecide: false, isDoctor: false });

        expect(wrapper.find('[data-testid="doctor-only"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="form-glaucoma"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="open-deactivate"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="toggle-rule"]').exists()).toBe(true);
    });

    it('quem não decide não vê "Desativar" nem num protocolo JÁ ativo', () => {
        const wrapper = mountPage({
            canDecide: false,
            isDoctor: false,
            protocols: [protocol({ status: 'active', adoption: adoption() })],
        });

        expect(card(wrapper).text()).toContain('Ativo desde');
        expect(wrapper.find('[data-testid="open-deactivate"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid^="form-"]').exists()).toBe(false);
    });

    it('a tarefa obrigatória não desliga; o intervalo tem a faixa validada; o envio exige a confirmação', async () => {
        const wrapper = mountPage();
        const form = wrapper.find('[data-testid="form-glaucoma"]');

        const required = form.find('[data-testid="task-consulta"] input[type="checkbox"]');
        expect(required.attributes('disabled')).toBeDefined();
        expect(required.element.checked).toBe(true);

        const interval = form.find('[data-testid="task-consulta"] input[type="number"]');
        expect(interval.attributes('min')).toBe('3');
        expect(interval.attributes('max')).toBe('6');
        expect(form.find('[data-testid="task-consulta"]').text()).toContain('de 3 a 6 meses (padrão 4)');
        expect(form.find('[data-testid="task-oct"] input[type="checkbox"]').attributes('disabled')).toBeUndefined();

        const submit = form.find('[data-testid="submit-protocol"]');
        expect(submit.attributes('disabled')).toBeDefined();

        await form.find('[data-testid="acknowledge"]').setValue(true);
        expect(submit.attributes('disabled')).toBeUndefined();
    });

    it('envia a versão, as tarefas ajustadas e a confirmação para a rota do protocolo', async () => {
        const wrapper = mountPage();
        const form = wrapper.find('[data-testid="form-glaucoma"]');

        await form.find('[data-testid="task-consulta"] input[type="number"]').setValue('3');
        await form.find('[data-testid="task-oct"] input[type="checkbox"]').setValue(true);
        await form.find('[data-testid="acknowledge"]').setValue(true);
        await form.trigger('submit');

        const request = lastRequest();
        expect(request.method).toBe('post');
        expect(request.url).toBe('/_routes/panel.recall.settings.protocols.activate/p-1');
        expect(request.data).toEqual({
            version_id: 'v-1',
            tasks: { consulta: { interval: 3, enabled: true }, oct: { interval: 12, enabled: true } },
            auto_contact: false,
            acknowledged: true,
        });
    });

    it('cada campo do formulário tem rótulo visível associado (ligada, intervalo) e a faixa descreve o campo', () => {
        const wrapper = mountPage();
        const row = wrapper.find('[data-testid="task-oct"]');
        const checkbox = row.find('input[type="checkbox"]');
        const interval = row.find('input[type="number"]');

        expect(row.findAll('label').map((label) => [label.text(), label.attributes('for')])).toEqual([
            ['Ligada', checkbox.attributes('id')],
            ['Intervalo', interval.attributes('id')],
        ]);
        expect(row.find(`#${interval.attributes('aria-describedby')}`).text()).toContain('de 6 a 24 meses (padrão 12)');
    });

    it('erro de campo do servidor aparece no campo certo, em alerta', async () => {
        const wrapper = mountPage();
        const form = forms.find((entry) => 'acknowledged' in entry);

        form.setError('tasks.consulta.interval', 'Fora da faixa validada.');
        await nextTick();

        const field = wrapper.find('[data-testid="task-consulta"]');
        expect(field.find('[role="alert"]').text()).toBe('Fora da faixa validada.');
        expect(field.find('input[type="number"]').classes()).toContain('is-invalid');
    });

    it('erro que nenhum campo mostra (tarefa desconhecida, versão inválida) aparece num aviso do formulário, sem repetir os de campo', async () => {
        const wrapper = mountPage();
        const form = forms.find((entry) => 'acknowledged' in entry);

        expect(wrapper.find('[data-testid="form-errors"]').exists()).toBe(false);

        form.setError('tasks.antiga', 'Tarefa desconhecida.');
        form.setError('tasks.consulta.interval', 'Fora da faixa validada.');
        form.setError('acknowledged', 'Confirme.');
        await nextTick();

        const summary = wrapper.find('[data-testid="form-errors"]');
        expect(summary.attributes('role')).toBe('alert');
        expect(summary.text()).toBe('Tarefa desconhecida.');
    });

    it('o formulário de quem já usa começa nos valores da clínica e o botão é "Salvar ajustes"', () => {
        const wrapper = mountPage({
            protocols: [
                protocol({
                    status: 'active',
                    adoption: adoption(),
                    tasks: [{ ...tasks()[0], interval: 5 }, tasks()[1]],
                }),
            ],
        });

        expect(wrapper.find('[data-testid="task-consulta"] input[type="number"]').element.value).toBe('5');
        expect(wrapper.find('[data-testid="submit-protocol"]').text()).toBe('Salvar ajustes');
    });
});

describe('Configurar retornos — desativar', () => {
    beforeEach(() => resetInertiaMock());

    it('abre o modal, exige o motivo e envia para a rota do protocolo', async () => {
        const wrapper = mountPage({ protocols: [protocol({ status: 'active', adoption: adoption() })] });

        await wrapper.find('[data-testid="open-deactivate"]').trigger('click');
        await nextTick();

        expect(wrapper.text()).toContain('Desativar este protocolo');
        expect(wrapper.find('#deactivate-reason').attributes('required')).toBeDefined();

        await wrapper.find('#deactivate-reason').setValue('Esta clínica não acompanha glaucoma');
        await wrapper.find('#deactivate-form').trigger('submit');

        const request = lastRequest();
        expect(request.url).toBe('/_routes/panel.recall.settings.protocols.deactivate/p-1');
        expect(request.data).toEqual({ reason: 'Esta clínica não acompanha glaucoma' });

        request.options.onSuccess();
        await nextTick();
        expect(wrapper.find('#deactivate-form').exists()).toBe(false);
    });
});

describe('Configurar retornos — desativar (cuidados)', () => {
    beforeEach(() => resetInertiaMock());

    const open = async (wrapper) => {
        await wrapper.find('[data-testid="open-deactivate"]').trigger('click');
        await nextTick();
    };

    it('o motivo digitado não volta ao reabrir o modal (cada desativação é uma decisão nova)', async () => {
        const wrapper = mountPage({ protocols: [protocol({ status: 'active', adoption: adoption() })] });

        await open(wrapper);
        await wrapper.find('#deactivate-reason').setValue('Motivo escrito antes de desistir');
        await wrapper.find('.btn-light').trigger('click'); // Cancelar
        await nextTick();
        expect(wrapper.find('#deactivate-form').exists()).toBe(false);

        await open(wrapper);
        expect(wrapper.find('#deactivate-reason').element.value).toBe('');
    });

    it('com o envio em andamento, o botão fica desabilitado e um segundo envio não sai', async () => {
        const wrapper = mountPage({ protocols: [protocol({ status: 'active', adoption: adoption() })] });

        await open(wrapper);
        await wrapper.find('#deactivate-reason').setValue('Esta clínica não acompanha glaucoma');
        forms.find((entry) => 'reason' in entry).processing = true;
        await nextTick();

        expect(wrapper.find('[data-testid="deactivate-submit"]').attributes('disabled')).toBeDefined();

        await wrapper.find('#deactivate-form').trigger('submit');
        expect(lastRequest()).toBeUndefined();
    });

    it('erro do servidor no motivo aparece em alerta e o modal continua aberto', async () => {
        const wrapper = mountPage({ protocols: [protocol({ status: 'active', adoption: adoption() })] });

        await open(wrapper);
        forms.find((entry) => 'reason' in entry).setError('reason', 'Informe o motivo.');
        await nextTick();

        expect(wrapper.find('#deactivate-form [role="alert"]').text()).toBe('Informe o motivo.');
        expect(wrapper.find('#deactivate-reason').classes()).toContain('is-invalid');
    });
});

describe('Configurar retornos — retorno pedido pelo médico e exames', () => {
    beforeEach(() => resetInertiaMock());

    it('a chave de retorno do médico: salvar só habilita quando muda; secretária não edita', async () => {
        const wrapper = mountPage();
        const save = wrapper.find('[data-testid="general-save"]');

        expect(save.attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="doctor-order-switch"]').setValue(false);
        expect(save.attributes('disabled')).toBeUndefined();

        await wrapper
            .find('[data-testid="doctor-order-switch"]')
            .element.closest('form')
            .dispatchEvent(new Event('submit'));
        await nextTick();
        const request = forms.find((entry) => entry.lastRequest && 'doctor_order_enabled' in entry)?.lastRequest;
        expect(request.method).toBe('put');
        expect(request.url).toBe('/_routes/panel.recall.settings.general');
        expect(request.data).toEqual({ doctor_order_enabled: false });

        const readonly = mountPage({ canConfigure: false, canDecide: false });
        expect(readonly.find('[data-testid="doctor-order-switch"]').attributes('disabled')).toBeDefined();
        expect(readonly.find('[data-testid="general-save"]').exists()).toBe(false);
        expect(readonly.find('[data-testid="mappings-save"]').exists()).toBe(false);
    });

    it('exames: mostra o associado, sugere o do sistema, adiciona e remove; só salva quando mudou', async () => {
        const wrapper = mountPage();
        const field = wrapper.find('[data-testid="exam-visual_field"]');

        expect(field.text()).toContain('CAMPO VISUAL 24-2');
        expect(wrapper.find('[data-testid="exam-oct_macula"]').text()).toContain('Nenhum exame associado');
        expect(wrapper.find('[data-testid="mappings-save"]').attributes('disabled')).toBeDefined();

        await field.find('[data-testid="suggestion"]').trigger('click');
        expect(field.text()).toContain('CAMPIMETRIA COMPUTADORIZADA');
        expect(field.find('[data-testid="suggestion"]').exists()).toBe(false); // já adicionado: a sugestão some

        await field.find('button[aria-label="Remover CAMPO VISUAL 24-2"]').trigger('click');
        expect(field.text()).not.toContain('CAMPO VISUAL 24-2 ');

        await wrapper.find('[data-testid="mappings-save"]').element.closest('form').dispatchEvent(new Event('submit'));
        await nextTick();

        const request = forms.find((entry) => entry.lastRequest && 'mappings' in entry)?.lastRequest;
        expect(request.method).toBe('put');
        expect(request.url).toBe('/_routes/panel.recall.settings.exam-mappings');
        expect(request.data.mappings).toEqual({ visual_field: ['t-1'], oct_macula: [] });
    });

    it('exame já associado que saiu do catálogo aparece com o nome e a marca, pode ser tirado e não é oferecido na lista', async () => {
        const base = props();
        const wrapper = mountPage({
            exams: {
                ...base.exams,
                types: [
                    ...base.exams.types,
                    { id: 't-3', name: 'CAMPO VISUAL ANTIGO', global: false, unavailable: true },
                ],
                mappings: { visual_field: ['t-3'], oct_macula: [] },
            },
        });
        const field = wrapper.find('[data-testid="exam-visual_field"]');

        expect(field.text()).toContain('CAMPO VISUAL ANTIGO (desativado)');
        expect(
            wrapper
                .find('#pick-oct_macula')
                .findAll('option')
                .map((option) => option.text()),
        ).not.toContain('CAMPO VISUAL ANTIGO');

        await field.find('button[aria-label="Remover CAMPO VISUAL ANTIGO"]').trigger('click');
        expect(field.text()).toContain('Nenhum exame associado');
    });

    it('o seletor de exame abre mostrando "Escolher exame…" (não em branco)', () => {
        const select = mountPage().find('#pick-oct_macula');

        expect(select.element.selectedIndex).toBe(0);
        expect(select.element.options[select.element.selectedIndex].text).toBe('Escolher exame…');
    });

    it('escolher um exame pela lista adiciona (e a lista deixa de oferecê-lo)', async () => {
        const wrapper = mountPage();
        const select = wrapper.find('#pick-oct_macula');

        expect(select.findAll('option').map((option) => option.text())).toContain('CAMPO VISUAL 24-2');

        await select.setValue('t-2');
        expect(wrapper.find('[data-testid="exam-oct_macula"]').text()).toContain('CAMPO VISUAL 24-2');
        expect(
            wrapper
                .find('#pick-oct_macula')
                .findAll('option')
                .map((option) => option.text()),
        ).not.toContain('CAMPO VISUAL 24-2');
    });
});

describe('Configurar retornos — convite por WhatsApp', () => {
    beforeEach(() => resetInertiaMock());

    const invites = (wrapper) => wrapper.find('[data-testid="invites-card"]');

    it('mostra o ritmo configurado, o teto de HOJE (ramp-up) e quantos já saíram', () => {
        const wrapper = mountPage();

        expect(invites(wrapper).text()).toContain('Convite por WhatsApp');
        expect(invites(wrapper).find('#rs-inv-start').element.value).toBe('09:00');
        expect(invites(wrapper).find('#rs-inv-end').element.value).toBe('18:00');
        expect(invites(wrapper).find('#rs-inv-min').element.value).toBe('30');
        expect(invites(wrapper).find('#rs-inv-cycle').element.value).toBe('3');
        expect(invites(wrapper).find('#rs-inv-cap').element.value).toBe('20');
        expect(invites(wrapper).find('#rs-inv-overdue').element.value).toBe('180');
        expect(invites(wrapper).find('[data-testid="invites-cap-hint"]').text()).toContain('limite de hoje: 5');
        expect(invites(wrapper).find('[data-testid="invites-cap-hint"]').text()).toContain('2 convites hoje.');
        expect(invites(wrapper).find('#rs-inv-min').attributes('min')).toBe('7');

        const checked = [1, 2, 3, 4, 5, 6, 7].filter(
            (day) => invites(wrapper).find(`#rs-inv-wd-${day}`).element.checked,
        );
        expect(checked).toEqual([1, 2, 3, 4, 5]);
    });

    it('sem teto do ramp-up, diz que não há limite do período de adaptação', () => {
        const wrapper = mountPage({ general: { ...props().general, cap_today: null } });

        expect(invites(wrapper).find('[data-testid="invites-cap-hint"]').text()).toContain(
            'sem limite do período de adaptação',
        );
    });

    it('salva no mesmo PUT da configuração geral, levando o retorno do médico COMO ESTÁ SALVO', async () => {
        const wrapper = mountPage();
        const save = invites(wrapper).find('[data-testid="invites-save"]');

        expect(save.attributes('disabled')).toBeDefined(); // nada mudou

        await invites(wrapper).find('#rs-inv-enabled').setValue(true);
        await invites(wrapper).find('#rs-inv-start').setValue('08:30');
        await invites(wrapper).find('#rs-inv-cap').setValue('15');
        await invites(wrapper).find('#rs-inv-wd-6').setValue(true);
        await invites(wrapper).find('#rs-inv-wd-1').setValue(false);
        // Edição do OUTRO cartão, ainda não salva: não pode ir junto.
        await wrapper.find('[data-testid="doctor-order-switch"]').setValue(false);

        expect(save.attributes('disabled')).toBeUndefined();

        await invites(wrapper).find('form').trigger('submit');

        const request = lastRequest();
        expect(request.method).toBe('put');
        expect(request.url).toBe('/_routes/panel.recall.settings.general');
        expect(request.data).toEqual({
            whatsapp_invites_enabled: true,
            invite_window_start: '08:30',
            invite_window_end: '18:00',
            invite_weekdays: [2, 3, 4, 5, 6],
            min_days_between_invites: 30,
            max_auto_invites_per_cycle: 3,
            daily_invite_cap: 15,
            auto_invite_max_overdue_days: 180,
            doctor_order_enabled: true,
        });
    });

    it('o EasyEye ainda não liberou: explica o motivo e não deixa LIGAR (mas deixa desligar)', async () => {
        const blocked = {
            available: false,
            blocker: 'clinic_not_enabled',
            label: 'O EasyEye ainda não liberou o convite por WhatsApp para esta clínica.',
        };

        const off = mountPage({ invites: blocked });
        expect(off.find('[data-testid="invites-unavailable"]').text()).toContain(
            'Ainda não dá para ligar: O EasyEye ainda não liberou',
        );
        expect(off.find('#rs-inv-enabled').attributes('disabled')).toBeDefined();

        const on = mountPage({ invites: blocked, general: { ...props().general, whatsapp_invites_enabled: true } });
        expect(on.find('#rs-inv-enabled').attributes('disabled')).toBeUndefined();

        expect(mountPage().find('[data-testid="invites-unavailable"]').exists()).toBe(false);
    });

    it('quem só vê (sem permissão de configurar) não edita nem salva', () => {
        const wrapper = mountPage({ canConfigure: false, canDecide: false });

        expect(invites(wrapper).find('#rs-inv-enabled').attributes('disabled')).toBeDefined();
        expect(invites(wrapper).find('#rs-inv-start').attributes('disabled')).toBeDefined();
        expect(invites(wrapper).find('#rs-inv-wd-1').attributes('disabled')).toBeDefined();
        expect(invites(wrapper).find('[data-testid="invites-save"]').exists()).toBe(false);
    });

    it('erro do servidor aparece no campo certo', async () => {
        const wrapper = mountPage();

        await invites(wrapper).find('#rs-inv-min').setValue('3');
        await invites(wrapper).find('form').trigger('submit');

        const form = forms.find((entry) => entry.lastRequest);
        form.setError('min_days_between_invites', 'O campo deve ser pelo menos 7.');
        await nextTick();

        expect(invites(wrapper).text()).toContain('O campo deve ser pelo menos 7.');
    });
});
