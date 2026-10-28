import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import DocumentDateField from '@/Components/Panel/Documents/DocumentDateField.vue';
import CertificateModal from '@/Components/Panel/Documents/CertificateModal.vue';
import PersonalTemplatesBar from '@/Components/Panel/Documents/PersonalTemplatesBar.vue';
import ProcedureRequestModal from '@/Components/Panel/Documents/ProcedureRequestModal.vue';
import ExamKitEditor from '@/Components/Panel/Documents/ExamKitEditor.vue';
import { documentDatePayload, requestSummary, dateInRange } from '@/Support/clinicalDocuments.js';

/*
 * Fase 2 / Bloco B — componentes dos documentos do atendimento: data
 * alternativa (faixa), atestados (CID só com autorização, horários, AV do
 * prontuário), "Salvar como modelo", pedido de exames com kits e editor de
 * kit. O servidor revalida tudo; aqui, o que a tela manda e mostra.
 */
enableAutoUnmount(afterEach);

const DATE = { today: '2026-10-07', min: '2026-09-07', max: '2026-11-06' };
const STUBS = { teleport: true, TinyMceEditor: true };

function jsonResponse(status, body) {
    return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });
}

describe('utilitários', () => {
    it('data igual a hoje ou vazia não vai como data alternativa', () => {
        expect(documentDatePayload('', DATE.today)).toEqual({});
        expect(documentDatePayload('2026-10-07', DATE.today)).toEqual({});
        expect(documentDatePayload('2026-09-20', DATE.today)).toEqual({ document_date: '2026-09-20' });
        expect(dateInRange('2026-09-06', DATE)).toBe(false);
        expect(dateInRange('2026-11-06', DATE)).toBe(true);
    });

    it('resumo do pedido só com os exames marcados, com o código TUSS', () => {
        expect(
            requestSummary([
                { name: 'Campimetria', code: '40103137', checked: true },
                { name: 'Gonioscopia', code: '41301242', checked: false },
                { name: 'Aberrometria', code: null, checked: true },
            ]),
        ).toBe('- Campimetria (TUSS 40103137)\n- Aberrometria');
    });
});

describe('DocumentDateField', () => {
    it('limita a faixa no input, mostra a data impressa e avisa fora da faixa', async () => {
        const w = mount(DocumentDateField, { props: { modelValue: '', config: DATE } });
        const input = w.find('[data-testid="document-date-input"]');
        expect(input.attributes('min')).toBe('2026-09-07');
        expect(input.attributes('max')).toBe('2026-11-06');
        expect(w.text()).toContain('Permitido de 07/09/2026 a 06/11/2026');

        await w.setProps({ modelValue: '2026-09-20' });
        expect(w.text()).toContain('Data impressa: 20/09/2026');
        expect(w.find('[data-testid="document-date-today"]').exists()).toBe(true);

        await w.setProps({ modelValue: '2026-12-25' });
        expect(w.find('[role="alert"]').text()).toContain('entre 07/09/2026 e 06/11/2026');
        expect(input.attributes('aria-invalid')).toBe('true');
    });

    it('"Hoje" limpa a data alternativa', async () => {
        const w = mount(DocumentDateField, { props: { modelValue: '2026-09-20', config: DATE } });
        await w.find('[data-testid="document-date-today"]').trigger('click');
        expect(w.emitted('update:modelValue').at(-1)).toEqual(['']);
    });
});

describe('CertificateModal', () => {
    const mountCert = (props) =>
        mount(CertificateModal, {
            props: { open: false, dateConfig: DATE, ...props },
            global: { stubs: { ...STUBS, Cid10Picker: true } },
        });

    it('comparecimento: horário pré-preenchido (chegada → agora), dilatação com horas e data alternativa', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 9, 7, 9, 45));
        const w = mountCert({ kind: 'attendance', arrivedAt: '08:10' });
        await w.setProps({ open: true });
        vi.useRealTimers();

        expect(w.find('[data-testid="cert-time-from"]').element.value).toBe('08:10');
        expect(w.find('[data-testid="cert-time-to"]').element.value).toBe('09:45');

        await w.find('[data-testid="cert-dilation"]').setValue(true);
        await w.find('[data-testid="cert-dilation-hours"]').setValue(6);
        await w.find('[data-testid="document-date-input"]').setValue('2026-10-01');
        await w.find('form').trigger('submit');

        expect(w.emitted('submit')[0][0]).toEqual({
            time_from: '08:10',
            time_to: '09:45',
            dilation: true,
            dilation_hours: 6,
            document_date: '2026-10-01',
        });
    });

    it('sem chegada válida (ausente ou depois de agora): os dois horários em branco e a emissão segue', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 9, 7, 9, 45));
        for (const arrivedAt of [null, '', '09:45', '10:30']) {
            const w = mountCert({ kind: 'attendance', arrivedAt });
            await w.setProps({ open: true });
            expect(w.find('[data-testid="cert-time-from"]').element.value).toBe('');
            expect(w.find('[data-testid="cert-time-to"]').element.value).toBe('');
            expect(w.find('[role="alert"]').exists()).toBe(false);
            await w.find('form').trigger('submit');
            expect(w.emitted('submit')[0][0]).toEqual({});
        }
        vi.useRealTimers();
    });

    it('escolher período limpa os horários; digitar horário limpa o período', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 9, 7, 9, 45));
        const w = mountCert({ kind: 'attendance', arrivedAt: '08:10' });
        await w.setProps({ open: true });
        vi.useRealTimers();

        await w.find('[data-testid="cert-period"]').setValue('morning');
        expect(w.find('[data-testid="cert-time-from"]').element.value).toBe('');
        expect(w.find('[data-testid="cert-time-to"]').element.value).toBe('');
        await w.find('form').trigger('submit');
        expect(w.emitted('submit')[0][0]).toEqual({ period: 'morning' });

        await w.find('[data-testid="cert-time-from"]').setValue('10:00');
        expect(w.find('[data-testid="cert-period"]').element.value).toBe('');
    });

    it('horário final antes do inicial bloqueia o envio', async () => {
        const w = mountCert({ kind: 'companion' });
        await w.setProps({ open: true });
        await w.find('[data-testid="cert-companion-name"]').setValue('João Souza');
        await w.find('[data-testid="cert-time-from"]').setValue('10:00');
        await w.find('[data-testid="cert-time-to"]').setValue('09:00');
        await w.find('form').trigger('submit');

        expect(w.emitted('submit')).toBeUndefined();
        expect(w.text()).toContain('O horário final deve ser depois do inicial.');
    });

    it('acompanhante: nome obrigatório; período quando não há horário', async () => {
        const w = mountCert({ kind: 'companion' });
        await w.setProps({ open: true });
        await w.find('[data-testid="cert-time-from"]').setValue('');
        await w.find('[data-testid="cert-time-to"]').setValue('');
        await w.find('form').trigger('submit');
        expect(w.emitted('submit')).toBeUndefined();

        await w.find('[data-testid="cert-companion-name"]').setValue('Ana Lima');
        await w.find('select').setValue('afternoon');
        await w.find('form').trigger('submit');
        expect(w.emitted('submit')[0][0]).toEqual({ companion_name: 'Ana Lima', period: 'afternoon' });
    });

    it('atestado médico: CID só segue com a autorização do paciente marcada', async () => {
        const w = mountCert({ kind: 'medical', cid10SearchUrl: '/cid' });
        await w.setProps({ open: true });
        // O seletor de CID (stub) devolve o código escolhido.
        await w
            .findComponent({ name: 'Cid10Picker' })
            .vm.$emit('update:modelValue', [{ code: 'H10.0', description: 'X' }]);
        await flushPromises();
        await w.find('form').trigger('submit');

        expect(w.emitted('submit')).toBeUndefined();
        expect(w.find('[role="alert"]').text()).toContain('confirme que o paciente autorizou');

        await w.find('[data-testid="cert-cid-authorized"]').setValue(true);
        await w.find('form').trigger('submit');
        expect(w.emitted('submit')[0][0]).toMatchObject({ days: 1, cid: 'H10.0', cid_authorized: true });
    });

    it('aptidão visual: mostra a AV do prontuário; sem AV ou com AV não salva não emite', async () => {
        const acuity = { without: { right: '20/40', left: '20/50' }, with: { right: '20/20', left: '' } };
        const w = mountCert({ kind: 'visual', acuity, acuityUnsaved: true });
        await w.setProps({ open: true });

        expect(w.find('[data-testid="cert-acuity"]').text()).toContain('20/40');
        expect(w.find('[data-testid="cert-acuity-unsaved"]').exists()).toBe(true);
        expect(w.find('[data-testid="certificate-emit"]').attributes('disabled')).toBeDefined();

        await w.setProps({ acuityUnsaved: false });
        await w.find('[data-testid="cert-conclusion-fit_with_correction"]').setValue(true);
        await w.find('[data-testid="cert-purpose"]').setValue('sport');
        await w.find('form').trigger('submit');
        expect(w.emitted('submit')[0][0]).toEqual({ conclusion: 'fit_with_correction', purpose: 'sport' });

        await w.setProps({ acuity: { without: {}, with: {} } });
        expect(w.text()).toContain('Registre a acuidade visual');
    });

    it('erros do servidor aparecem no campo', async () => {
        const w = mountCert({ kind: 'companion', serverErrors: { companion_name: ['O nome é obrigatório.'] } });
        await w.setProps({ open: true });
        expect(w.text()).toContain('O nome é obrigatório.');
    });
});

describe('PersonalTemplatesBar', () => {
    let calls;
    beforeEach(() => {
        calls = [];
        globalThis.fetch = vi.fn((url, init = {}) => {
            calls.push({ url, method: init.method ?? 'GET', body: init.body ? JSON.parse(init.body) : null });
            if ((init.method ?? 'GET') === 'GET')
                return jsonResponse(200, {
                    // body_format:'text' — como o servidor sempre devolve (serialize()); o
                    // modal (format=text) só lista isso mesmo, então nunca dá mismatch nele.
                    data: [
                        {
                            id: 't1',
                            name: 'Olho seco',
                            body: 'Lubrificante',
                            body_format: 'text',
                            payload: { items: [{ name: 'X' }] },
                        },
                    ],
                });
            if (init.method === 'POST')
                return jsonResponse(201, { data: { id: 't2', name: JSON.parse(init.body).name } });
            return jsonResponse(200, { message: 'ok' });
        });
    });

    const mountBar = (props = {}) =>
        mount(PersonalTemplatesBar, {
            props: {
                kind: 'prescription',
                url: '/tpl',
                itemUrlTemplate: '/tpl/__ID__',
                build: () => ({ body: 'Colírio 4x/dia', payload: { items: [{ name: 'Colírio' }] } }),
                ...props,
            },
        });

    it('lista só os modelos do tipo e aplica ao escolher', async () => {
        const w = mountBar();
        await flushPromises();
        expect(calls[0].url).toBe('/tpl?kind=prescription');

        await w.find('[data-testid="personal-templates-select"]').setValue('t1');
        expect(w.emitted('update:modelValue').at(-1)).toEqual(['t1']);
        expect(w.emitted('apply')[0][0].name).toBe('Olho seco');
        // Mudar o v-model por fora (ex.: modelo recém-salvo) não reaplica.
        await w.setProps({ modelValue: 't1' });
        expect(w.emitted('apply')).toHaveLength(1);
    });

    it('"Salvar como modelo" manda nome, tipo, corpo e itens', async () => {
        const w = mountBar();
        await flushPromises();
        await w.find('[data-testid="personal-templates-save-as"]').trigger('click');
        await w.find('[data-testid="personal-templates-name"]').setValue('Conjuntivite');
        await w.find('[data-testid="personal-templates-confirm"]').trigger('click');
        await flushPromises();

        expect(calls.find((c) => c.method === 'POST').body).toEqual({
            kind: 'prescription',
            name: 'Conjuntivite',
            body: 'Colírio 4x/dia',
            payload: { items: [{ name: 'Colírio' }] },
        });
        expect(w.emitted('changed')).toBeTruthy();
    });

    it('modal (format=text) lista só os modelos que entende; o contexto do prontuário vai ao salvar e atualizar', async () => {
        const w = mountBar({ format: 'text', context: { medical_record_id: 'mr1', document_date: '2026-10-01' } });
        await flushPromises();
        expect(calls[0].url).toBe('/tpl?kind=prescription&format=text');

        await w.find('[data-testid="personal-templates-save-as"]').trigger('click');
        await w.find('[data-testid="personal-templates-name"]').setValue('Conjuntivite');
        await w.find('[data-testid="personal-templates-confirm"]').trigger('click');
        await flushPromises();
        expect(calls.find((c) => c.method === 'POST').body).toMatchObject({
            medical_record_id: 'mr1',
            document_date: '2026-10-01',
        });

        await w.setProps({ modelValue: 't1' });
        await w.find('[data-testid="personal-templates-update"]').trigger('click');
        await flushPromises();
        expect(calls.find((c) => c.method === 'PUT').body).toMatchObject({ medical_record_id: 'mr1' });
    });

    it('"Atualizar modelo atual" só funciona quando o formato do modelo bate com o da tela (2ª revisão Codex, P2-7)', async () => {
        // t1 (HTML, do editor) e t2 (texto, de um modal) na mesma lista — o
        // editor (format=null) lista os dois formatos (doc do componente).
        globalThis.fetch = vi.fn((url, init = {}) => {
            calls.push({ url, method: init.method ?? 'GET', body: init.body ? JSON.parse(init.body) : null });
            if ((init.method ?? 'GET') === 'GET')
                return jsonResponse(200, {
                    data: [
                        { id: 't1', name: 'Laudo padrão', body: '<p>x</p>', body_format: 'html', payload: {} },
                        {
                            id: 't2',
                            name: 'Receita do modal',
                            body: 'Colírio',
                            body_format: 'text',
                            payload: { items: [{ name: 'X' }] },
                        },
                    ],
                });
            return jsonResponse(200, { message: 'ok' });
        });

        const w = mountBar({ format: null, modelValue: 't2' });
        await flushPromises();

        // t2 é "text" (salvo por um modal) — o editor (format=null) monta HTML: bloqueado.
        const button = w.find('[data-testid="personal-templates-update"]');
        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('title')).toBeTruthy();
        await button.trigger('click');
        await flushPromises();
        expect(calls.some((c) => c.method === 'PUT')).toBe(false);

        // t1 é "html" — igual ao que o editor produz: atualiza normalmente (sem regressão).
        await w.setProps({ modelValue: 't1' });
        await flushPromises();
        expect(w.find('[data-testid="personal-templates-update"]').attributes('disabled')).toBeUndefined();
        await w.find('[data-testid="personal-templates-update"]').trigger('click');
        await flushPromises();
        expect(calls.find((c) => c.method === 'PUT').url).toBe('/tpl/t1');
    });

    it('editor (sem tipo/formato) lista todos', async () => {
        mountBar({ kind: null });
        await flushPromises();
        expect(calls[0].url).toBe('/tpl');
    });

    it('excluir pede confirmação e só então chama o servidor', async () => {
        const w = mountBar({ modelValue: 't1' });
        await flushPromises();
        await w.find('[data-testid="personal-templates-delete"]').trigger('click');
        expect(calls.some((c) => c.method === 'DELETE')).toBe(false);
        await w.find('[data-testid="personal-templates-delete-confirm"]').trigger('click');
        await flushPromises();
        expect(calls.find((c) => c.method === 'DELETE').url).toBe('/tpl/t1');
    });
});

describe('ProcedureRequestModal', () => {
    const KITS = {
        covenant: { id: 'c1', name: 'UNIMED' },
        kits: [
            {
                id: 'k1',
                name: 'Glaucoma',
                origin: 'clinic',
                items: [
                    { procedure_id: 'p1', code: '40103137', name: 'CAMPIMETRIA', in_catalog: true },
                    { procedure_id: null, code: '41501144', name: 'OCT de nervo', in_catalog: false },
                ],
            },
            {
                id: 'k2',
                name: 'Retina diabética',
                origin: 'global',
                items: [{ procedure_id: 'p1', code: '40103137', name: 'CAMPIMETRIA', in_catalog: true }],
            },
        ],
    };

    beforeEach(() => {
        globalThis.fetch = vi.fn((url) => {
            if (url === '/kits') return jsonResponse(200, KITS);
            if (url.startsWith('/tpl')) return jsonResponse(200, { data: [] });
            return jsonResponse(200, []);
        });
    });

    async function openModal(props = {}) {
        const w = mount(ProcedureRequestModal, {
            props: {
                open: false,
                kitsUrl: '/kits',
                templatesUrl: '/tpl',
                templateItemUrl: '/tpl/__ID__',
                dateConfig: DATE,
                initialCids: [{ code: 'H40.1', description: 'Glaucoma' }],
                ...props,
            },
            global: { stubs: { ...STUBS, Cid10Picker: true } },
        });
        await w.setProps({ open: true });
        await flushPromises();
        return w;
    }

    it('lista só os modelos de pedido em texto + itens (o HTML do editor não emite)', async () => {
        await openModal();
        expect(globalThis.fetch.mock.calls.map(([url]) => url)).toContain('/tpl?kind=procedure&format=text');
    });

    it('modelo de pedido SEM itens (só corpo) entra como observação — "Emitir" funciona (14ª revisão Codex, P2)', async () => {
        // API aceita modelo de pedido SÓ com corpo (sem payload.items) — sem
        // isto, aplicar um desses limpava o pedido e "Emitir" não emitia
        // nada, apesar do aviso dizer que o modelo foi aplicado.
        globalThis.fetch = vi.fn((url) => {
            if (url === '/kits') return jsonResponse(200, KITS);
            if (url.startsWith('/tpl')) {
                return jsonResponse(200, {
                    data: [
                        {
                            id: 'tp1',
                            name: 'OCT + campimetria (texto)',
                            body: 'OCT de nervo óptico e campimetria conforme evolução.',
                            payload: { items: [] },
                        },
                    ],
                });
            }
            return jsonResponse(200, []);
        });
        const w = await openModal();

        await w.find('[data-testid="request-kit-select"]').setValue('tpl:tp1');
        await flushPromises();

        expect(w.find('[data-testid="request-text-only-body"]').element.value).toBe(
            'OCT de nervo óptico e campimetria conforme evolução.',
        );
        expect(w.find('[data-testid="request-emit"]').element.disabled).toBe(false);

        await w.find('[data-testid="request-type"]').setValue('rotina');
        await w.find('[data-testid="request-emit"]').trigger('click');

        expect(w.emitted('submit')[0][0]).toEqual({
            items: [],
            type: 'rotina',
            cids: ['H40.1'],
            content: 'OCT de nervo óptico e campimetria conforme evolução.',
            template_id: 'tp1',
        });
    });

    it('modelo SEM itens com corpo E observação salvos separados: nenhum some, nenhum duplica (17ª revisão Codex, P2)', async () => {
        // body e payload.notes reaproveitavam o mesmo campo da tela — aplicar
        // o modelo perdia a observação salva (ou duplicava o corpo nela).
        globalThis.fetch = vi.fn((url) => {
            if (url === '/kits') return jsonResponse(200, KITS);
            if (url.startsWith('/tpl')) {
                return jsonResponse(200, {
                    data: [
                        {
                            id: 'tp1',
                            name: 'OCT + campimetria (texto)',
                            body: 'OCT de nervo óptico e campimetria conforme evolução.',
                            payload: { items: [], notes: 'Ambos os olhos' },
                        },
                    ],
                });
            }
            return jsonResponse(200, []);
        });
        const w = await openModal();

        await w.find('[data-testid="request-kit-select"]').setValue('tpl:tp1');
        await flushPromises();

        expect(w.find('[data-testid="request-text-only-body"]').element.value).toBe(
            'OCT de nervo óptico e campimetria conforme evolução.',
        );
        expect(w.find('[placeholder="Ex.: realizar em ambos os olhos"]').element.value).toBe('Ambos os olhos');

        await w.find('[data-testid="request-emit"]').trigger('click');

        expect(w.emitted('submit')[0][0]).toMatchObject({
            content: 'OCT de nervo óptico e campimetria conforme evolução.',
            notes: 'Ambos os olhos',
        });
    });

    it('kits do convênio do atendimento; item sem cadastro avisa; mesmo exame não repete; desmarcar sai do pedido', async () => {
        const w = await openModal();
        expect(w.text()).toContain('Kits para o convênio UNIMED');

        await w.find('[data-testid="request-kit-select"]').setValue('kit:k1');
        // Volta ao "Escolher kit…" (kits somam; dá para escolher outro).
        expect(w.find('[data-testid="request-kit-select"]').element.value).toBe('');
        await w.find('[data-testid="request-kit-select"]').setValue('kit:k2');

        const items = w.findAll('[data-testid="request-items"] li');
        expect(items).toHaveLength(2);
        expect(w.findAll('[data-testid="request-item-warning"]')).toHaveLength(1);

        await items[1].find('input[type="checkbox"]').setValue(false);
        await w.find('[data-testid="request-type"]').setValue('rotina');
        await w.find('[data-testid="request-emit"]').trigger('click');

        expect(w.emitted('submit')[0][0]).toEqual({
            items: [{ procedure_id: 'p1', kit_id: 'k1' }],
            kit_ids: ['k1', 'k2'],
            type: 'rotina',
            cids: ['H40.1'],
        });
    });

    it('teto de exames: além do limite não adiciona', async () => {
        const w = await openModal({ limits: { items: 1, indications: 5, cids: 4 } });
        await w.find('[data-testid="request-kit-select"]').setValue('kit:k1');
        expect(w.findAll('[data-testid="request-items"] li')).toHaveLength(1);
        expect(w.text()).toContain('Limite de 1 exames por pedido.');
    });

    it('kit maior que a capacidade restante: só entra o que cabe e o aviso diz que ficou parcial (2ª revisão Codex)', async () => {
        // k1 tem 2 exames (CAMPIMETRIA + OCT de nervo); com teto 1, só o
        // primeiro cabe — o aviso não pode dizer "adicionado" como se os 2
        // tivessem entrado (antes, "OCT de nervo" era descartado em silêncio).
        const w = await openModal({ limits: { items: 1, indications: 5, cids: 4 } });
        await w.find('[data-testid="request-kit-select"]').setValue('kit:k1');

        expect(w.findAll('[data-testid="request-items"] li')).toHaveLength(1);
        expect(w.text()).not.toContain('Kit "Glaucoma" adicionado ao pedido.');
        expect(w.text()).toContain('adicionado só em parte');
        expect(w.text()).toContain('OCT de nervo');
    });

    it('exame avulso em texto livre vai com o nome (sem procedimento)', async () => {
        const w = await openModal();
        await w.find('[data-testid="request-search"]').setValue('Pupilometria');
        await w.find('[data-testid="request-search"]').trigger('keydown', { key: 'Enter' });
        await w.find('[data-testid="request-emit"]').trigger('click');
        expect(w.emitted('submit')[0][0].items).toEqual([{ name: 'Pupilometria' }]);
    });

    it('rascunho: há pedido não emitido até marcar como emitido', async () => {
        const w = await openModal();
        expect(w.vm.hasDraft()).toBe(false);
        await w.find('[data-testid="request-kit-select"]').setValue('kit:k1');
        expect(w.vm.hasDraft()).toBe(true);
        w.vm.markIssued();
        expect(w.vm.hasDraft()).toBe(false);
    });

    it('rascunho: pedido em TEXTO LIVRE (sem item) também conta, inclusive editar depois de emitido (18ª revisão Codex, P2)', async () => {
        const w = await openModal();
        expect(w.vm.hasDraft()).toBe(false);

        await w.find('[data-testid="request-text-only-body"]').setValue('Avaliação de rotina.');
        expect(w.vm.hasDraft()).toBe(true);
        w.vm.markIssued();
        expect(w.vm.hasDraft()).toBe(false);

        // Editou o texto JÁ EMITIDO — ainda é rascunho (não persistiu sozinho).
        await w.find('[data-testid="request-text-only-body"]').setValue('Avaliação de rotina, olho direito.');
        expect(w.vm.hasDraft()).toBe(true);
    });

    it('rascunho: mudar só o CID ou só a data depois de emitido também conta (2ª revisão Codex)', async () => {
        const w = await openModal({ cid10SearchUrl: '/cid' });
        await w.find('[data-testid="request-kit-select"]').setValue('kit:k1');
        w.vm.markIssued();
        expect(w.vm.hasDraft()).toBe(false);

        // CID (vem do diagnóstico do prontuário, mas o médico pode ajustar).
        await w
            .findComponent({ name: 'Cid10Picker' })
            .vm.$emit('update:modelValue', [{ code: 'H25.1', description: 'Catarata' }]);
        await flushPromises();
        expect(w.vm.hasDraft()).toBe(true);
        w.vm.markIssued();
        expect(w.vm.hasDraft()).toBe(false);

        // Data do documento.
        await w.find('[data-testid="document-date-input"]').setValue('2026-10-20');
        await flushPromises();
        expect(w.vm.hasDraft()).toBe(true);
    });

    it('kit com exame já no pedido mas DESMARCADO respeita o teto ao reativar (3ª revisão Codex, P2)', async () => {
        // k1 tem 2 exames; com teto 2 os dois cabem.
        const w = await openModal({ limits: { items: 2, indications: 5, cids: 4 } });
        await w.find('[data-testid="request-kit-select"]').setValue('kit:k1');
        expect(w.findAll('[data-testid="request-items"] li')).toHaveLength(2);

        // Desmarca "OCT de nervo" na mão — fica no pedido, só sai da contagem.
        await w.findAll('[data-testid="request-items"] li')[1].find('input[type="checkbox"]').setValue(false);

        // Ocupa a vaga com outro exame: teto 2/2 de novo, agora com "OCT de
        // nervo" desmarcado (não removido) e sua caixinha travada pela tela.
        await w.find('[data-testid="request-search"]').setValue('Pupilometria');
        await w.find('[data-testid="request-search"]').trigger('keydown', { key: 'Enter' });

        const rows = w.findAll('[data-testid="request-items"] li');
        expect(rows).toHaveLength(3);
        const octCheckbox = rows[1].find('input[type="checkbox"]');
        expect(octCheckbox.element.checked).toBe(false);
        expect(octCheckbox.element.disabled).toBe(true);

        // Reaplicar o kit tenta reativar "OCT de nervo" por outra porta (o
        // seletor de kit, não a caixinha travada) — tem que respeitar o
        // mesmo teto, não furar pra 3 marcados.
        await w.find('[data-testid="request-kit-select"]').setValue('kit:k1');

        const checkboxes = w.findAll('[data-testid="request-items"] input[type="checkbox"]');
        expect(checkboxes.filter((c) => c.element.checked)).toHaveLength(2);
        expect(w.text()).toContain('adicionado só em parte');
        expect(w.text()).toContain('OCT de nervo');
    });

    it('teto de KITS por pedido: kit novo além do limite não entra; reaplicar o mesmo kit não conta de novo (3ª revisão Codex, P2)', async () => {
        const w = await openModal({ limits: { items: 20, indications: 5, cids: 4, kits: 1 } });

        await w.find('[data-testid="request-kit-select"]').setValue('kit:k1');
        expect(w.findAll('[data-testid="request-items"] li')).toHaveLength(2);

        // 2º kit: já no teto de 1 — nem entra, nenhum exame novo.
        await w.find('[data-testid="request-kit-select"]').setValue('kit:k2');
        expect(w.findAll('[data-testid="request-items"] li')).toHaveLength(2);
        expect(w.text()).toContain('Limite de 1 kits por pedido.');

        // Reaplicar k1 (já contado) não é bloqueado pelo mesmo teto.
        await w.find('[data-testid="request-kit-select"]').setValue('kit:k1');
        expect(w.text()).not.toContain('Limite de 1 kits por pedido.');
    });

    it('remover todos os exames de um kit tira o kit_id do pedido — não fica fantasma no teto de kits (3ª revisão Codex, P2)', async () => {
        const w = await openModal();
        await w.find('[data-testid="request-kit-select"]').setValue('kit:k1');
        expect(w.findAll('[data-testid="request-items"] li')).toHaveLength(2);

        // Exame avulso, fora do kit — sobra pra emissão continuar possível
        // depois que o kit inteiro for removido.
        await w.find('[data-testid="request-search"]').setValue('Pupilometria');
        await w.find('[data-testid="request-search"]').trigger('keydown', { key: 'Enter' });
        expect(w.findAll('[data-testid="request-items"] li')).toHaveLength(3);

        // Remove os 2 exames do kit um a um (botão "remover", não desmarcar).
        await w.findAll('[data-testid="request-items"] li')[0].find('button').trigger('click');
        await w.findAll('[data-testid="request-items"] li')[0].find('button').trigger('click');
        expect(w.findAll('[data-testid="request-items"] li')).toHaveLength(1);

        await w.find('[data-testid="request-type"]').setValue('rotina');
        await w.find('[data-testid="request-emit"]').trigger('click');
        expect(w.emitted('submit')[0][0].kit_ids).toBeUndefined();
    });
});

describe('ExamKitEditor', () => {
    it('salva nome, exames (ordem/código) e convênios; sem convênio = todos', async () => {
        const w = mount(ExamKitEditor, {
            props: { open: false, withCovenants: true, covenants: [{ id: 'c1', name: 'UNIMED' }] },
            global: { stubs: { ...STUBS, SearchSelect: true } },
        });
        await w.setProps({ open: true });
        await w.find('[data-testid="exam-kit-name"]').setValue('Uveíte');
        await w.find('[data-testid="exam-kit-item-name"]').setValue('Retinografia');
        await w.find('[data-testid="exam-kit-item-code"]').setValue('41301315');
        await w.find('[data-testid="exam-kit-add-item"]').trigger('click');
        await w.findAll('[data-testid="exam-kit-item-name"]')[1].setValue('Angiofluoresceinografia');
        await w.find('form').trigger('submit');

        expect(w.emitted('save')[0][0]).toEqual({
            name: 'Uveíte',
            description: null,
            active: true,
            items: [
                { name: 'Retinografia', tuss_code: '41301315' },
                { name: 'Angiofluoresceinografia', tuss_code: null },
            ],
            covenant_ids: [],
        });
    });

    it('editar código ou nome de item vinculado ao catálogo solta o vínculo (2ª revisão, P2-D)', async () => {
        const kit = {
            id: 'k',
            name: 'Retina',
            active: true,
            items: [
                {
                    name: 'MAPEAMENTO DE RETINA',
                    tuss_code: '41301250',
                    procedure_id: 'p1',
                    procedure_name: 'MAPEAMENTO DE RETINA',
                },
                { name: 'GONIOSCOPIA', tuss_code: '41301242', procedure_id: 'p2', procedure_name: 'GONIOSCOPIA' },
                { name: 'CAMPIMETRIA', tuss_code: '40103137', procedure_id: 'p3', procedure_name: 'CAMPIMETRIA' },
            ],
        };
        const w = mount(ExamKitEditor, {
            props: { open: false, kit },
            global: { stubs: { ...STUBS, SearchSelect: true } },
        });
        await w.setProps({ open: true });

        await w.findAll('[data-testid="exam-kit-item-code"]')[0].setValue('41501144');
        await w.findAll('[data-testid="exam-kit-item-name"]')[1].setValue('Gonioscopia com lente de 4 espelhos');
        await w.find('form').trigger('submit');

        expect(w.emitted('save')[0][0].items).toEqual([
            { name: 'MAPEAMENTO DE RETINA', tuss_code: '41501144' },
            { name: 'Gonioscopia com lente de 4 espelhos', tuss_code: '41301242' },
            { name: 'CAMPIMETRIA', tuss_code: '40103137', procedure_id: 'p3' },
        ]);
        expect(w.findAll('.text-success .ti-link')).toHaveLength(1);
    });

    it('kit do manager não tem convênios', async () => {
        const w = mount(ExamKitEditor, {
            props: {
                open: false,
                kit: {
                    id: 'k',
                    name: 'Glaucoma',
                    active: true,
                    items: [{ name: 'Campimetria', tuss_code: '40103137' }],
                },
            },
            global: { stubs: { ...STUBS, SearchSelect: true } },
        });
        await w.setProps({ open: true });
        await w.find('form').trigger('submit');
        expect(w.emitted('save')[0][0]).not.toHaveProperty('covenant_ids');
    });
});
