import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async () =>
    (await import('../Manager/RecallProtocols/support/inertiaMock.js')).buildInertiaMock(),
);

import { resetInertiaMock } from '../Manager/RecallProtocols/support/inertiaMock.js';
import PatientConditionsCard from '@/Components/Panel/Recall/PatientConditionsCard.vue';
import conditionsT from './support/recall_conditions.json';

// Ziggy no teste: as rotas da gaveta levam [paciente, condição] como lista (o global do setup só entende um id).
const route = vi.fn(
    (name, params) => `/_routes/${name}${params === undefined ? '' : `/${[].concat(params).join('/')}`}`,
);

const condition = (overrides = {}) => ({
    id: 'c-1',
    protocol_key: 'glaucoma',
    title: 'Glaucoma',
    eye: 'both',
    eye_label: 'Ambos os olhos (AO)',
    status: 'active',
    status_label: 'Ativa',
    origin: 'cid',
    origin_label: 'Diagnóstico (CID)',
    started_on: '2025-10-01',
    evidence_text: 'CID H40.1 em prontuário assinado (desde 01/10/2025)',
    decided_at: '2025-10-05T10:00:00+00:00',
    discharge_reason: null,
    protocol_active: true,
    next: [
        {
            label: 'Consulta de retorno',
            due_at: '2026-02-01',
            state: 'due_soon',
            state_label: 'A vencer',
            lifecycle: 'open',
            missing: null,
        },
    ],
    can: { confirm: false, reject: false, discharge: true, edit: true },
    ...overrides,
});

const payload = (overrides = {}) => ({
    patient_id: 'p-1',
    today: '2026-01-15',
    conditions: [condition()],
    history: [],
    protocols: [
        { key: 'glaucoma', title: 'Glaucoma' },
        { key: 'dmri', title: 'Degeneração macular' },
    ],
    eyes: [
        { value: 'both', label: 'Ambos os olhos (AO)' },
        { value: 'right', label: 'Olho direito (OD)' },
        { value: 'left', label: 'Olho esquerdo (OE)' },
    ],
    t: conditionsT,
    ...overrides,
});

/** fetch que responde por "MÉTODO url": `{ status, body }`; chamada sem resposta combinada falha o teste. */
let calls;
function serve(routes) {
    calls = [];
    globalThis.fetch = vi.fn((url, init = {}) => {
        const key = `${init.method ?? 'GET'} ${url}`;
        calls.push({ key, body: init.body ? JSON.parse(init.body) : null });
        const handler = routes[key];

        if (!handler) return Promise.reject(new Error(`sem resposta combinada para ${key}`));

        const { status = 200, body = {} } = typeof handler === 'function' ? handler() : handler;

        return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
    });
}

const INDEX = (id = 'p-1') => `GET /_routes/panel.patients.recall-conditions.index/${id}`;
const ACTION = (method, name, patient = 'p-1', id = 'c-1') =>
    `${method} /_routes/panel.patients.recall-conditions.${name}/${patient}${id ? `/${id}` : ''}`;

let wrappers = [];
async function mountCard(routes, props = {}) {
    serve({ [INDEX()]: { body: { data: payload() } }, ...routes });
    const wrapper = mount(PatientConditionsCard, { props: { patientId: 'p-1', ...props }, attachTo: document.body });
    wrappers.push(wrapper);
    await flushPromises();

    return wrapper;
}

const byTest = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`);

describe('Condições crônicas na gaveta do paciente', () => {
    beforeEach(() => {
        resetInertiaMock();
        globalThis.route = route;
    });

    afterEach(() => {
        wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    });

    it('mostra a condição: título, olho, situação em TEXTO, evidência em uma frase, início e o próximo retorno', async () => {
        const wrapper = await mountCard({});
        const card = byTest(wrapper, 'pc-condition-glaucoma');

        expect(byTest(wrapper, 'patient-conditions').attributes('aria-labelledby')).toBe('pc-title');
        expect(wrapper.find('#pc-title').text()).toBe('Condições crônicas');
        expect(card.text()).toContain('Glaucoma');
        expect(card.text()).toContain('Ambos os olhos (AO)');
        expect(byTest(card, 'pc-status').text()).toBe('Ativa');
        expect(card.text()).toContain('CID H40.1 em prontuário assinado (desde 01/10/2025)');
        expect(card.text()).toContain('Início do acompanhamento: 01/10/2025');
        expect(byTest(card, 'pc-next').text()).toContain('Consulta de retorno — vence em 01/02/2026');
        expect(byTest(card, 'pc-next').text()).toContain('(A vencer)');
        expect(calls.map((call) => call.key)).toEqual([INDEX()]);
    });

    it('os botões seguem o que o servidor permite: ativa → editar e dar alta; sugerida → confirmar, descartar e editar', async () => {
        const wrapper = await mountCard({}, {});
        const active = byTest(wrapper, 'pc-condition-glaucoma');
        expect(active.find('[data-testid="pc-edit"]').exists()).toBe(true);
        expect(active.find('[data-testid="pc-discharge"]').exists()).toBe(true);
        expect(active.find('[data-testid="pc-confirm"]').exists()).toBe(false);
        expect(active.find('[data-testid="pc-reject"]').exists()).toBe(false);

        wrappers.splice(0).forEach((w) => w.unmount());

        const suggested = await mountCard({
            [INDEX()]: {
                body: {
                    data: payload({
                        conditions: [
                            condition({
                                status: 'suggested',
                                status_label: 'Sugerida',
                                next: [],
                                can: { confirm: true, reject: true, discharge: false, edit: true },
                            }),
                        ],
                    }),
                },
            },
        });
        const card = byTest(suggested, 'pc-condition-glaucoma');

        expect(card.attributes('data-status')).toBe('suggested');
        expect(byTest(card, 'pc-hint').text()).toBe(
            'Sugerida pelo sistema: só gera retorno depois que você confirmar.',
        );
        expect(card.find('[data-testid="pc-next"]').exists()).toBe(false);
        expect(['pc-confirm', 'pc-reject', 'pc-edit'].every((id) => card.find(`[data-testid="${id}"]`).exists())).toBe(
            true,
        );
        expect(card.find('[data-testid="pc-discharge"]').exists()).toBe(false);
    });

    it('some se o servidor recusa (plano sem a feature, perfil sem permissão) ou se a rede falha', async () => {
        const refused = await mountCard({ [INDEX()]: { status: 403, body: { message: 'x' } } });
        expect(refused.find('[data-testid="patient-conditions"]').exists()).toBe(false);
        expect(refused.html()).toBe('<!--v-if-->');

        const broken = await mountCard({
            [INDEX()]: () => {
                throw new Error('rede');
            },
        });
        expect(broken.html()).toBe('<!--v-if-->'); // a falha é tratada: nada aparece e nada quebra
    });

    it('protocolo que a clínica não usa avisa que a condição não gera retorno (e não lista retornos)', async () => {
        const wrapper = await mountCard({
            [INDEX()]: { body: { data: payload({ conditions: [condition({ protocol_active: false, next: [] })] }) } },
        });
        const card = byTest(wrapper, 'pc-condition-glaucoma');

        expect(byTest(card, 'pc-inactive').text()).toBe(
            'O protocolo não está ativo na clínica: esta condição não gera retornos.',
        );
        expect(card.find('[data-testid="pc-next"]').exists()).toBe(false);
    });

    it('confirmar: POST na rota certa; a resposta troca o bloco e o aviso de sucesso aparece em status', async () => {
        const suggested = condition({
            status: 'suggested',
            status_label: 'Sugerida',
            next: [],
            can: { confirm: true, reject: true, discharge: false, edit: true },
        });
        const wrapper = await mountCard({
            [INDEX()]: { body: { data: payload({ conditions: [suggested] }) } },
            [ACTION('POST', 'confirm')]: { body: { message: 'Condição confirmada.', data: payload() } },
        });

        await byTest(wrapper, 'pc-confirm').trigger('click');
        await flushPromises();

        expect(calls.at(-1)).toEqual({ key: ACTION('POST', 'confirm'), body: null });
        expect(byTest(wrapper, 'pc-condition-glaucoma').attributes('data-status')).toBe('active');
        expect(byTest(wrapper, 'pc-message').attributes('role')).toBe('status');
        expect(byTest(wrapper, 'pc-message').text()).toBe('Condição confirmada.');
        expect(wrapper.find('[data-testid="pc-error"]').exists()).toBe(false);
    });

    it('descartar: POST em reject; a condição sai da lista de acompanhamento e vai para o histórico', async () => {
        const suggested = condition({
            status: 'suggested',
            status_label: 'Sugerida',
            next: [],
            can: { confirm: true, reject: true, discharge: false, edit: true },
        });
        const rejected = condition({
            status: 'rejected',
            status_label: 'Descartada',
            next: [],
            can: { confirm: false, reject: false, discharge: false, edit: false },
        });
        const wrapper = await mountCard({
            [INDEX()]: { body: { data: payload({ conditions: [suggested] }) } },
            [ACTION('POST', 'reject')]: {
                body: { message: 'Condição descartada.', data: payload({ conditions: [], history: [rejected] }) },
            },
        });

        await byTest(wrapper, 'pc-reject').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="pc-condition-glaucoma"]').exists()).toBe(false);
        expect(byTest(wrapper, 'pc-empty').exists()).toBe(true);
        expect(byTest(wrapper, 'pc-history').text()).toContain('Histórico (1)');
        expect(byTest(wrapper, 'pc-history').text()).toContain('Descartada em 05/10/2025');
    });

    it('editar: abre em linha com o olho e o início atuais, foca o 1º campo, envia PUT e devolve o foco ao botão', async () => {
        const wrapper = await mountCard({
            [ACTION('PUT', 'update')]: {
                body: {
                    message: 'Condição salva.',
                    data: payload({
                        conditions: [condition({ eye: 'left', eye_label: 'Olho esquerdo (OE)', started_on: null })],
                    }),
                },
            },
        });
        const trigger = byTest(wrapper, 'pc-edit');

        await trigger.trigger('click');
        await nextTick();
        await nextTick();

        const form = byTest(wrapper, 'pc-edit-form');
        expect(form.find('select').element.value).toBe('both');
        expect(form.find('input[type="date"]').element.value).toBe('2025-10-01');
        expect(form.find('input[type="date"]').attributes('max')).toBe('2026-01-15');
        expect(document.activeElement).toBe(form.find('select').element);
        expect(form.text()).toContain('Ao corrigir, a condição passa a ser sua');

        await form.find('select').setValue('left');
        await form.find('input[type="date"]').setValue('');
        await form.trigger('submit');
        await flushPromises();
        await nextTick();

        expect(calls.at(-1)).toEqual({ key: ACTION('PUT', 'update'), body: { eye: 'left', started_on: null } });
        expect(wrapper.find('[data-testid="pc-edit-form"]').exists()).toBe(false);
        expect(byTest(wrapper, 'pc-condition-glaucoma').text()).toContain('Olho esquerdo (OE)');
        expect(byTest(wrapper, 'pc-condition-glaucoma').text()).toContain('Início do acompanhamento não informado');
        expect(document.activeElement?.getAttribute('data-testid')).toBe('pc-edit');
    });

    it('dar alta: o motivo vai no corpo; recusa do servidor (422) aparece em alerta e o formulário continua aberto', async () => {
        const wrapper = await mountCard({
            [ACTION('POST', 'discharge')]: {
                status: 422,
                body: { message: 'Informe o motivo da alta.', errors: { reason: ['O motivo é obrigatório.'] } },
            },
        });

        await byTest(wrapper, 'pc-discharge').trigger('click');
        await nextTick();

        const form = byTest(wrapper, 'pc-discharge-form');
        expect(form.text()).toContain('Os retornos desta condição se encerram.');
        expect(form.text()).toContain('Obrigatório, ao menos 3 caracteres.');
        expect(document.activeElement).toBe(form.find('textarea').element);

        await form.find('textarea').setValue('ab');
        await form.trigger('submit');
        await flushPromises();

        expect(calls.at(-1)).toEqual({ key: ACTION('POST', 'discharge'), body: { reason: 'ab' } });
        expect(byTest(wrapper, 'pc-error').attributes('role')).toBe('alert');
        expect(byTest(wrapper, 'pc-error').text()).toBe('O motivo é obrigatório.');
        expect(wrapper.find('[data-testid="pc-discharge-form"]').exists()).toBe(true);
        expect(wrapper.find('textarea').element.value).toBe('ab');
    });

    it('dar alta com sucesso fecha o formulário e mostra a condição no histórico, com o motivo', async () => {
        const discharged = condition({
            status: 'discharged',
            status_label: 'Alta',
            next: [],
            discharge_reason: 'Controlado',
            can: { confirm: false, reject: false, discharge: false, edit: false },
        });
        const wrapper = await mountCard({
            [ACTION('POST', 'discharge')]: {
                body: { message: 'Alta registrada.', data: payload({ conditions: [], history: [discharged] }) },
            },
        });

        await byTest(wrapper, 'pc-discharge').trigger('click');
        await nextTick();
        await byTest(wrapper, 'pc-discharge-form').find('textarea').setValue('Controlado');
        await byTest(wrapper, 'pc-discharge-form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-testid="pc-discharge-form"]').exists()).toBe(false);
        expect(byTest(wrapper, 'pc-history').text()).toContain('Alta em 05/10/2025');
        expect(byTest(wrapper, 'pc-history').text()).toContain('Motivo: Controlado');
        expect(byTest(wrapper, 'pc-message').text()).toBe('Alta registrada.');
    });

    it('registrar à mão: só com protocolos ativos na clínica; envia protocolo, olho e início (vazio = null)', async () => {
        const wrapper = await mountCard({
            [ACTION('POST', 'store', 'p-1', null)]: {
                status: 201,
                body: { message: 'Condição registrada.', data: payload() },
            },
        });

        await byTest(wrapper, 'pc-add').trigger('click');
        await nextTick();

        const form = byTest(wrapper, 'pc-add-form');
        expect(form.find('#pc-protocol').element.value).toBe('glaucoma'); // o 1º protocolo já vem escolhido
        expect(form.findAll('#pc-protocol option').map((option) => option.text())).toEqual([
            'Glaucoma',
            'Degeneração macular',
        ]);
        expect(document.activeElement).toBe(form.find('#pc-protocol').element);

        await form.find('#pc-protocol').setValue('dmri');
        await form.find('#pc-eye').setValue('right');
        await form.trigger('submit');
        await flushPromises();

        expect(calls.at(-1)).toEqual({
            key: ACTION('POST', 'store', 'p-1', null),
            body: { protocol_key: 'dmri', eye: 'right', started_on: null },
        });
        expect(wrapper.find('[data-testid="pc-add-form"]').exists()).toBe(false);
        expect(byTest(wrapper, 'pc-message').text()).toBe('Condição registrada.');
        expect(document.activeElement?.getAttribute('data-testid')).toBe('pc-add');
    });

    it('sem protocolo ativo na clínica: não há botão de registrar, e o aviso manda ativar um (nada de lista vazia muda)', async () => {
        const wrapper = await mountCard({ [INDEX()]: { body: { data: payload({ conditions: [], protocols: [] }) } } });

        expect(wrapper.find('[data-testid="pc-add"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('A clínica ainda não ativou nenhum protocolo.');
        expect(byTest(wrapper, 'pc-empty').text()).toBe('Nenhuma condição registrada para este paciente.');
    });

    it('falha de rede ou resposta sem corpo num ato mostra a mensagem genérica em alerta, sem trocar o bloco', async () => {
        const wrapper = await mountCard({
            [ACTION('POST', 'discharge')]: () => {
                throw new Error('rede');
            },
        });

        await byTest(wrapper, 'pc-discharge').trigger('click');
        await nextTick();
        await byTest(wrapper, 'pc-discharge-form').find('textarea').setValue('Controlado');
        await byTest(wrapper, 'pc-discharge-form').trigger('submit');
        await flushPromises();

        expect(byTest(wrapper, 'pc-error').text()).toBe('Não foi possível salvar. Tente novamente.');
        expect(byTest(wrapper, 'pc-condition-glaucoma').exists()).toBe(true);
    });

    it('com o envio em andamento os botões ficam desabilitados e um 2º clique não envia de novo', async () => {
        let release;
        const wrapper = await mountCard({
            [ACTION('POST', 'discharge')]: () => ({}),
        });
        globalThis.fetch = vi.fn((url, init = {}) => {
            calls.push({ key: `${init.method ?? 'GET'} ${url}`, body: init.body ? JSON.parse(init.body) : null });

            return new Promise((resolve) => {
                release = () =>
                    resolve({
                        ok: true,
                        status: 200,
                        json: () => Promise.resolve({ message: 'Alta registrada.', data: payload({ conditions: [] }) }),
                    });
            });
        });

        await byTest(wrapper, 'pc-discharge').trigger('click');
        await nextTick();
        await byTest(wrapper, 'pc-discharge-form').find('textarea').setValue('Controlado');
        await byTest(wrapper, 'pc-discharge-form').trigger('submit');
        await nextTick();

        expect(byTest(wrapper, 'pc-discharge-submit').attributes('disabled')).toBeDefined();
        expect(byTest(wrapper, 'pc-edit').attributes('disabled')).toBeDefined();
        expect(byTest(wrapper, 'pc-discharge-submit').text()).toBe('Salvando…');

        await byTest(wrapper, 'pc-discharge-form').trigger('submit'); // 2º envio durante o 1º
        expect(calls.filter((call) => call.key === ACTION('POST', 'discharge'))).toHaveLength(1);

        release();
        await flushPromises();
        expect(wrapper.find('[data-testid="pc-condition-glaucoma"]').exists()).toBe(false);
    });

    it('resposta atrasada de OUTRO paciente nunca sobrescreve a tela (A pendente → troca para B → A chega por último)', async () => {
        const pending = {};
        calls = [];
        globalThis.fetch = vi.fn(
            (url) =>
                new Promise((resolve) => {
                    pending[url] = (body) => resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
                }),
        );

        const wrapper = mount(PatientConditionsCard, { props: { patientId: 'p-A' }, attachTo: document.body });
        wrappers.push(wrapper);
        await wrapper.setProps({ patientId: 'p-B' });

        pending['/_routes/panel.patients.recall-conditions.index/p-B']({
            data: payload({ conditions: [condition({ id: 'cb', protocol_key: 'dmri', title: 'DMRI do B' })] }),
        });
        await flushPromises();
        pending['/_routes/panel.patients.recall-conditions.index/p-A']({
            data: payload({ conditions: [condition({ id: 'ca', protocol_key: 'glaucoma', title: 'Glaucoma do A' })] }),
        });
        await flushPromises();

        expect(wrapper.text()).toContain('DMRI do B');
        expect(wrapper.text()).not.toContain('Glaucoma do A');
    });

    it('trocar de paciente fecha o formulário aberto e limpa os avisos do anterior', async () => {
        // O paciente seguinte tem uma condição com o MESMO id: o formulário aberto não pode reaparecer nela.
        const wrapper = await mountCard({
            [INDEX('p-2')]: { body: { data: payload() } },
        });

        await byTest(wrapper, 'pc-discharge').trigger('click');
        await nextTick();
        expect(wrapper.find('[data-testid="pc-discharge-form"]').exists()).toBe(true);

        await wrapper.setProps({ patientId: 'p-2' });
        await flushPromises();

        expect(wrapper.find('[data-testid="pc-condition-glaucoma"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="pc-discharge-form"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="pc-message"]').exists()).toBe(false);
    });

    it('resposta de um ATO do paciente anterior (chega depois de trocar de paciente) não troca o bloco nem avisa', async () => {
        let release;
        const wrapper = await mountCard({
            [INDEX('p-2')]: {
                body: {
                    data: payload({ conditions: [condition({ id: 'cb', protocol_key: 'dmri', title: 'DMRI do B' })] }),
                },
            },
        });
        const respond = globalThis.fetch;

        globalThis.fetch = vi.fn((url, init = {}) => {
            if (String(url).includes('discharge')) {
                return new Promise((resolve) => {
                    release = () =>
                        resolve({
                            ok: true,
                            status: 200,
                            json: () =>
                                Promise.resolve({
                                    message: 'Alta registrada.',
                                    data: payload({ conditions: [], history: [] }),
                                }),
                        });
                });
            }

            return respond(url, init);
        });

        await byTest(wrapper, 'pc-discharge').trigger('click');
        await nextTick();
        await byTest(wrapper, 'pc-discharge-form').find('textarea').setValue('Controlado');
        await byTest(wrapper, 'pc-discharge-form').trigger('submit');
        await nextTick();

        await wrapper.setProps({ patientId: 'p-2' });
        await flushPromises();
        release(); // a alta do paciente 1 termina DEPOIS de abrir o paciente 2
        await flushPromises();

        expect(wrapper.text()).toContain('DMRI do B');
        expect(wrapper.find('[data-testid="pc-message"]').exists()).toBe(false);
    });
});
