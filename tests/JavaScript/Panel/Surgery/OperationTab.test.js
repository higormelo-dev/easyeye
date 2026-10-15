import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import OperationTab from '@/Components/Surgery/Tabs/OperationTab.vue';
import PerformPanel from '@/Components/Surgery/Operation/PerformPanel.vue';
import ImplantFormModal from '@/Components/Surgery/Operation/ImplantFormModal.vue';
import ImplantsPanel from '@/Components/Surgery/Operation/ImplantsPanel.vue';
import DescriptionPanel from '@/Components/Surgery/Operation/DescriptionPanel.vue';
import { callApi, compact, errorMessages, useOperationText } from '@/Components/Surgery/Operation/useOperation.js';

/**
 * Aba "Cirurgia" (M4): condições para registrar a cirurgia, implantes
 * (rastreabilidade) e descrição cirúrgica. O servidor decide o que cada perfil
 * pode (`section.state`); a tela reflete, chama a API por fetch (JSON) e mostra
 * o motivo da recusa sem perder o que foi digitado.
 */

const t = {
    operation: {
        ui: {
            cancel: 'Cancelar',
            actions: 'Ações',
            perform: {
                title: 'Registrar a cirurgia',
                done: 'Cirurgia realizada',
                requirements: 'Condições',
                ok: 'Atendida',
                pending: 'Pendente',
                min_chars: 'mínimo de :n caracteres',
                performed_at: 'Data e hora da cirurgia',
                performed_at_hint: 'Em branco = agora.',
                complications: 'Intercorrências',
                no_implant_reason: 'Sem implante',
                team: 'Equipe',
                team_confirmed: 'Confirmo a equipe.',
                button: 'Registrar cirurgia realizada',
                not_ready: 'Confirme a equipe.',
                only_surgeon: 'Só o cirurgião registra.',
                override: {
                    authorization: 'Justificativa: autorização',
                    implant: 'Justificativa: implante',
                    time_out: 'Justificativa: Pausa',
                },
            },
            implant: {
                title: 'Implantes',
                add: 'Registrar implante',
                empty: 'Nenhum implante vigente registrado.',
                form_title: 'Registrar implante',
                source: 'Origem',
                eye: 'Olho',
                choose: 'Selecione',
                product: 'Produto',
                change_product: 'Trocar produto',
                search_placeholder: 'Buscar',
                searching: 'Buscando…',
                no_results: 'Nenhum produto',
                lot: 'Lote',
                no_lots: 'Nenhum lote com saldo',
                expires: 'Validade',
                no_expiry: 'sem validade',
                balance: 'Saldo',
                fefo: 'Vence primeiro',
                kind: 'Tipo',
                product_name: 'Nome do produto',
                manufacturer: 'Fabricante',
                lot_number: 'Número do lote',
                expiry: 'Validade',
                model_reference: 'Modelo',
                anvisa: 'ANVISA',
                serial: 'Série',
                power: 'Potência',
                quantity: 'Quantidade',
                implanted_at: 'Data do implante',
                implanted_at_hint: 'Em branco = agora.',
                save: 'Registrar',
                labels: 'Etiquetas',
                label_count: 'Etiquetas por folha',
                card: 'Cartão',
                card_all: 'Cartão com todos',
                record_issue: 'Emitir registro',
                record_reissue: 'Reemitir registro',
                record_open: 'Registro',
                void: 'Anular',
                void_title: 'Anular implante',
                void_reason: 'Motivo',
                void_return: 'Volta ao estoque',
                void_return_hint: 'dica',
                void_confirm: 'Anular implante',
                voided_title: 'Anulados (:n)',
                void_action: { returned: 'voltou ao estoque', lost: 'perda', none: 'sem movimento' },
            },
            description: {
                title: 'Descrição cirúrgica',
                draft: 'Rascunho',
                finalized: 'Finalizada',
                pdf: 'PDF',
                none: 'Sem descrição.',
                only_surgeon: 'Só o cirurgião.',
                start: 'Início',
                end: 'Fim',
                anesthesia: 'Anestesia',
                choose: 'Selecione',
                findings: 'Achados',
                technique: 'Técnica',
                complications: 'Intercorrências',
                no_complications: 'Sem intercorrências',
                no_complications_text: 'Sem intercorrências.',
                save: 'Salvar rascunho',
                saved: 'Rascunho salvo.',
                finalize: 'Finalizar descrição',
                finalize_after_performed: 'Só depois de realizada.',
                finalized_at: 'Finalizada em :date',
                addenda: 'Adendos',
                addenda_none: 'Nenhum adendo.',
                addendum_new: 'Novo adendo',
                addendum_reason: 'Motivo',
                addendum_text: 'Texto',
                addendum_add: 'Registrar adendo',
            },
        },
    },
};

const urls = {
    products: '/p',
    implant_store: '/implants',
    implant_void: '/implants/__ID__/void',
    implant_record: '/implants/__ID__/record',
    implant_record_pdf: '/implants/__ID__/record/pdf',
    implant_labels: '/implants/__ID__/labels',
    card: '/card',
    description_save: '/description',
    description_finalize: '/description/finalize',
    description_addendum: '/description/addenda',
    description_pdf: '/description/pdf',
    perform: '/perform',
};

const baseSection = (over = {}) => ({
    performed: false,
    operation: null,
    requirements: [
        { key: 'authorization', ok: true, message: 'Autorização válida.', override: null },
        { key: 'implant', ok: true, message: 'Implante registrado.', override: null },
    ],
    implants: [],
    iol_plans: [{ eye: 'od', eye_label: 'OD', model_label: 'LIO X', power: '21.50' }],
    description: null,
    team: [{ role_label: 'Cirurgião', name: 'DR. A', registry: '123' }],
    state: {
        can_perform: true,
        can_register: true,
        can_describe: true,
        can_finalize: false,
        can_addendum: false,
        can_print: true,
        case_revision: 7,
    },
    options: {
        eyes: [{ value: 'od', label: 'OD' }],
        anesthesias: [{ value: 'topical', label: 'Tópica' }],
        sources: [
            { value: 'clinic_stock', label: 'Estoque da clínica' },
            { value: 'external', label: 'De fora' },
        ],
        kinds: [
            { value: 'iol', label: 'LIO' },
            { value: 'other', label: 'Outro' },
        ],
        label_max: 30,
        override_min: 10,
    },
    urls,
    ...over,
});

const CenteredModalStub = {
    props: ['open'],
    template: '<div v-if="open"><slot name="header" /><slot /><slot name="footer" /></div>',
};
const mountOpts = { attachTo: document.body, global: { stubs: { CenteredModal: CenteredModalStub } } };

function jsonResponse(status, data) {
    return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(data) });
}

let wrapper;

beforeEach(() => {
    globalThis.fetch = vi.fn(() => jsonResponse(200, {}));
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('useOperation (apoio)', () => {
    it('compact tira vazios e nulos, mas mantém false e 0', () => {
        expect(compact({ a: '', b: null, c: undefined, d: false, e: 0, f: 'x' })).toEqual({ d: false, e: 0, f: 'x' });
    });

    it('errorMessages junta a validação (422) ou usa a mensagem da regra (409)', () => {
        expect(errorMessages({ data: { errors: { a: ['um'], b: ['dois'] } } })).toEqual(['um', 'dois']);
        expect(errorMessages({ data: { message: 'Estado mudou' } })).toEqual(['Estado mudou']);
        expect(errorMessages({ data: {} })).toEqual([]);
    });

    it('tr resolve caminho pontilhado, troca :param e devolve o caminho se faltar', () => {
        let out;
        const C = {
            template: '<i />',
            setup() {
                out = useOperationText(() => t).tr;
            },
        };
        mount(C);
        expect(out('perform.min_chars', { n: 10 })).toBe('mínimo de 10 caracteres');
        expect(out('nao.existe')).toBe('nao.existe');
    });

    it('callApi manda JSON com CSRF e devolve status e corpo; falha de rede vira ok=false', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse(409, { message: 'Já registrada' }));
        const result = await callApi('/x', { body: { a: 1 } });

        expect(result).toEqual({ ok: false, status: 409, data: { message: 'Já registrada' } });
        const [, init] = globalThis.fetch.mock.calls[0];
        expect(init.headers.Accept).toBe('application/json');
        expect(init.headers['X-CSRF-TOKEN']).toBe('test-csrf-token');
        expect(JSON.parse(init.body)).toEqual({ a: 1 });

        globalThis.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        expect((await callApi('/x')).ok).toBe(false);
    });
});

describe('PerformPanel', () => {
    const mountPerform = (section = baseSection()) =>
        mount(PerformPanel, { props: { section, surgicalCase: { revision: 7 }, t }, ...mountOpts });

    it('lista as condições e só habilita o botão com a equipe confirmada', async () => {
        wrapper = mountPerform();

        expect(wrapper.get('[data-testid="op-req-authorization"]').attributes('data-ok')).toBe('1');
        const button = wrapper.get('[data-testid="op-perform-button"]');
        expect(button.attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="op-team-confirmed"]').setValue(true);
        expect(button.attributes('disabled')).toBeUndefined();
    });

    it('condição pendente com exceção pede a justificativa (mínimo) antes de liberar', async () => {
        wrapper = mountPerform(
            baseSection({
                requirements: [
                    {
                        key: 'authorization',
                        ok: false,
                        message: 'Autorização vencida.',
                        override: 'authorization_override_reason',
                    },
                ],
            }),
        );

        await wrapper.get('[data-testid="op-team-confirmed"]').setValue(true);
        const button = wrapper.get('[data-testid="op-perform-button"]');
        expect(button.attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="op-ovr-authorization"]').setValue('curto');
        expect(button.attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="op-ovr-authorization"]').setValue('Operadora confirmou a renovação');
        expect(button.attributes('disabled')).toBeUndefined();
    });

    it('condição pendente SEM exceção (ex.: pedido negado) nunca libera o botão', async () => {
        wrapper = mountPerform(
            baseSection({
                requirements: [{ key: 'authorization', ok: false, message: 'Sem autorização.', override: null }],
            }),
        );
        await wrapper.get('[data-testid="op-team-confirmed"]').setValue(true);

        expect(wrapper.get('[data-testid="op-perform-button"]').attributes('disabled')).toBeDefined();
    });

    it('envia o corpo certo (revisão do caso, justificativa) e pede reload no sucesso', async () => {
        wrapper = mountPerform(
            baseSection({
                requirements: [
                    { key: 'implant', ok: false, message: 'Falta o implante.', override: 'no_implant_reason' },
                ],
            }),
        );

        await wrapper.get('[data-testid="op-team-confirmed"]').setValue(true);
        await wrapper.get('[data-testid="op-ovr-implant"]').setValue('LIO adiada para segundo tempo');
        await wrapper.get('[data-testid="op-perform-button"]').trigger('click');
        await flushPromises();

        const [url, init] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/perform');
        expect(JSON.parse(init.body)).toEqual({
            team_confirmed: true,
            no_implant_reason: 'LIO adiada para segundo tempo',
            expected_revision: 7,
        });
        expect(wrapper.emitted('reload')).toHaveLength(1);
    });

    it('mostra o motivo da recusa do servidor e não pede reload', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse(409, { message: 'Feche a Pausa Cirúrgica.' }));
        wrapper = mountPerform();

        await wrapper.get('[data-testid="op-team-confirmed"]').setValue(true);
        await wrapper.get('[data-testid="op-perform-button"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="op-perform-errors"]').text()).toContain('Feche a Pausa Cirúrgica.');
        expect(wrapper.emitted('reload')).toBeUndefined();
    });

    it('sem permissão de cirurgião não mostra o formulário', () => {
        wrapper = mountPerform(baseSection({ state: { ...baseSection().state, can_perform: false } }));

        expect(wrapper.find('[data-testid="op-perform-button"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Só o cirurgião registra.');
    });

    it('depois de realizada mostra o ato e as justificativas gravadas', () => {
        wrapper = mountPerform(
            baseSection({
                performed: true,
                operation: {
                    performed_at: '2026-10-15T09:00:00-03:00',
                    performer: 'DR. A',
                    complications: 'Nenhuma',
                    no_implant_reason: null,
                    overrides: { authorization: 'Renovação por telefone' },
                },
            }),
        );

        expect(wrapper.find('[data-testid="op-performed"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Renovação por telefone');
        expect(wrapper.text()).toContain('Justificativa: autorização');
        expect(wrapper.find('[data-testid="op-perform-button"]').exists()).toBe(false);
    });
});

describe('ImplantFormModal', () => {
    const product = {
        id: 'p1',
        name: 'LIO Monofocal',
        manufacturer: 'ACME',
        sku: 'MF-1',
        kind: 'iol',
        requires_serial: true,
        requires_lot: true,
        qty_on_hand: 4,
        suggested_anvisa: '80123',
        lots: [
            { id: 'l1', lot_number: 'CEDO', expiry_date: '2026-12-01', qty_on_hand: 2, suggested: true },
            { id: 'l2', lot_number: 'TARDE', expiry_date: '2027-06-01', qty_on_hand: 2, suggested: false },
        ],
    };

    async function openForm(section = baseSection()) {
        wrapper = mount(ImplantFormModal, { props: { open: false, section, t }, ...mountOpts });
        await wrapper.setProps({ open: true });
        await flushPromises();
    }

    it('busca o produto, pré-seleciona o lote que vence primeiro (FEFO) e preenche ANVISA/potência do plano', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse(200, [product]));
        vi.useFakeTimers();
        await openForm();

        await wrapper.get('[data-testid="op-imp-search"]').setValue('lio');
        vi.advanceTimersByTime(400);
        vi.useRealTimers();
        await flushPromises();

        expect(globalThis.fetch.mock.calls[0][0]).toBe('/p?q=lio');
        await wrapper.get('[data-testid="op-imp-results"] button').trigger('click');

        expect(wrapper.get('[data-testid="op-imp-product"]').text()).toContain('LIO Monofocal');
        expect(wrapper.get('[data-testid="op-lot-l1"]').element.checked).toBe(true);
        expect(wrapper.get('[data-testid="op-imp-anvisa"]').element.value).toBe('80123');
        // Olho único (OD) já selecionado: potência vem da LIO planejada.
        expect(wrapper.get('[data-testid="op-imp-power"]').element.value).toBe('21.50');
        expect(wrapper.get('[data-testid="op-imp-eye"]').element.value).toBe('od');
    });

    it('a escolha do lote é livre (não obriga o sugerido)', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse(200, [product]));
        vi.useFakeTimers();
        await openForm();
        await wrapper.get('[data-testid="op-imp-search"]').setValue('lio');
        vi.advanceTimersByTime(400);
        vi.useRealTimers();
        await flushPromises();
        await wrapper.get('[data-testid="op-imp-results"] button').trigger('click');

        await wrapper.get('[data-testid="op-lot-l2"]').setValue(true);
        await wrapper.get('[data-testid="op-imp-serial"]').setValue('SN-1');

        globalThis.fetch = vi.fn(() => jsonResponse(201, { implant_id: 'i1' }));
        await wrapper.get('[data-testid="op-implant-save"]').trigger('click');
        await flushPromises();

        const [url, init] = globalThis.fetch.mock.calls[0];
        const body = JSON.parse(init.body);
        expect(url).toBe('/implants');
        expect(body).toMatchObject({
            source: 'clinic_stock',
            eye: 'od',
            entity_product_id: 'p1',
            stock_lot_id: 'l2',
            serial_number: 'SN-1',
            anvisa_registration: '80123',
            quantity: 1,
        });
        // Campos do consignado não vão no corpo do estoque da clínica.
        expect(body).not.toHaveProperty('lot_number');
        expect(body).not.toHaveProperty('product_name');
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });

    it('produto sem lote com saldo avisa e a recusa do servidor aparece sem fechar', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse(200, [{ ...product, lots: [] }]));
        vi.useFakeTimers();
        await openForm();
        await wrapper.get('[data-testid="op-imp-search"]').setValue('lio');
        vi.advanceTimersByTime(400);
        vi.useRealTimers();
        await flushPromises();
        await wrapper.get('[data-testid="op-imp-results"] button').trigger('click');

        expect(wrapper.find('[data-testid="op-imp-no-lots"]').exists()).toBe(true);

        globalThis.fetch = vi.fn(() =>
            jsonResponse(422, {
                message: 'Lote vencido',
                errors: { stock_lot_id: ['O lote L1 venceu em 01/10/2026.'] },
            }),
        );
        await wrapper.get('[data-testid="op-implant-save"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="op-implant-errors"]').text()).toContain('O lote L1 venceu');
        expect(wrapper.emitted('saved')).toBeUndefined();
    });

    it('origem "de fora" mostra os campos do consignado e manda só eles (sem produto de estoque)', async () => {
        await openForm();

        await wrapper.get('[data-testid="op-source-external"]').trigger('click');
        await wrapper.get('[data-testid="op-imp-name"]').setValue('LIO Consignada');
        await wrapper.get('[data-testid="op-imp-serial"]').setValue('EXT-1');
        await wrapper.get('[data-testid="op-imp-anvisa"]').setValue('80111');

        expect(wrapper.find('[data-testid="op-imp-search"]').exists()).toBe(false);

        globalThis.fetch = vi.fn(() => jsonResponse(201, { implant_id: 'i2' }));
        await wrapper.get('[data-testid="op-implant-save"]').trigger('click');
        await flushPromises();

        const body = JSON.parse(globalThis.fetch.mock.calls[0][1].body);
        expect(body).toMatchObject({
            source: 'external',
            product_name: 'LIO Consignada',
            serial_number: 'EXT-1',
            kind: 'iol',
        });
        expect(body).not.toHaveProperty('entity_product_id');
        expect(body).not.toHaveProperty('stock_lot_id');
    });
});

describe('ImplantFormModal — plano e acessibilidade (revisão)', () => {
    const iol = {
        id: 'p1',
        name: 'LIO',
        manufacturer: 'ACME',
        sku: 'MF',
        kind: 'iol',
        requires_serial: true,
        requires_lot: true,
        qty_on_hand: 4,
        suggested_anvisa: '80123',
        lots: [{ id: 'l1', lot_number: 'A', expiry_date: '2027-01-01', qty_on_hand: 2, suggested: true }],
    };

    async function pickProduct() {
        globalThis.fetch = vi.fn(() => jsonResponse(200, [iol]));
        vi.useFakeTimers();
        wrapper = mount(ImplantFormModal, { props: { open: false, section: baseSection(), t }, ...mountOpts });
        await wrapper.setProps({ open: true });
        await flushPromises();
        await wrapper.get('[data-testid="op-imp-search"]').setValue('lio');
        vi.advanceTimersByTime(400);
        vi.useRealTimers();
        await flushPromises();
    }

    it('a lista de resultados é lista simples de botões (sem role listbox/option)', async () => {
        await pickProduct();

        expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
        expect(wrapper.find('[role="option"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="op-imp-results"] li button')).toHaveLength(1);
    });

    it('potência diferente da planejada pede a justificativa e a envia; igual, não mostra o campo', async () => {
        await pickProduct();
        await wrapper.get('[data-testid="op-imp-results"] button').trigger('click');

        expect(wrapper.get('[data-testid="op-imp-power"]').element.value).toBe('21.50');
        expect(wrapper.find('[data-testid="op-imp-deviation"]').exists()).toBe(false);

        await wrapper.get('[data-testid="op-imp-power"]').setValue('12');
        await wrapper.get('[data-testid="op-imp-serial"]').setValue('SN-9');
        expect(wrapper.find('[data-testid="op-imp-deviation"]').exists()).toBe(true);

        await wrapper.get('[data-testid="op-imp-deviation-reason"]').setValue('Biometria refeita no dia');
        globalThis.fetch = vi.fn(() => jsonResponse(201, { implant_id: 'i1' }));
        await wrapper.get('[data-testid="op-implant-save"]').trigger('click');
        await flushPromises();

        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toMatchObject({
            power: 12,
            plan_deviation_reason: 'Biometria refeita no dia',
        });
    });
});

describe('ImplantsPanel', () => {
    const implant = (over = {}) => ({
        id: 'i1',
        status: 'active',
        kind: 'iol',
        kind_label: 'LIO',
        source: 'clinic_stock',
        source_label: 'Estoque da clínica',
        eye: 'od',
        eye_label: 'OD',
        product_name: 'LIO Monofocal',
        manufacturer: 'ACME',
        model_reference: 'MF-1',
        anvisa_registration: '80123',
        power: '21.50',
        lot_number: 'L-1',
        serial_number: 'SN-1',
        expiry_date: '2027-01-01',
        quantity: 1,
        implanted_at: '2026-10-15T09:00:00-03:00',
        record: null,
        ...over,
    });

    it('lista vigentes e separa os anulados', () => {
        wrapper = mount(ImplantsPanel, {
            props: {
                section: baseSection({
                    implants: [
                        implant(),
                        implant({
                            id: 'i2',
                            status: 'voided',
                            serial_number: 'SN-2',
                            voided_at: '2026-10-15T10:00:00-03:00',
                            void_reason: 'Erro',
                            void_stock_action: 'returned',
                        }),
                    ],
                }),
                t,
            },
            ...mountOpts,
        });

        expect(wrapper.findAll('[data-testid="op-implant-row"]')).toHaveLength(1);
        expect(wrapper.get('[data-testid="op-implant-serial"]').text()).toBe('SN-1');
        expect(wrapper.get('[data-testid="op-implant-voided"]').text()).toContain('voltou ao estoque');
    });

    it('sem permissão de registro não há botão de adicionar, emitir nem anular', () => {
        wrapper = mount(ImplantsPanel, {
            props: {
                section: baseSection({
                    implants: [implant()],
                    state: { ...baseSection().state, can_register: false, can_print: false },
                }),
                t,
            },
            ...mountOpts,
        });

        expect(wrapper.find('[data-testid="op-implant-add"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="op-record-issue"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="op-implant-void"]').exists()).toBe(false);
    });

    it('anular pede o motivo, manda o destino da unidade e pede reload', async () => {
        wrapper = mount(ImplantsPanel, { props: { section: baseSection({ implants: [implant()] }), t }, ...mountOpts });

        await wrapper.get('[data-testid="op-implant-void"]').trigger('click');
        expect(wrapper.get('[data-testid="op-void-confirm"]').attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="op-void-reason"]').setValue('Lente caiu');
        await wrapper.get('[data-testid="op-void-return"]').setValue(true);
        await wrapper.get('[data-testid="op-void-confirm"]').trigger('click');
        await flushPromises();

        const [url, init] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/implants/i1/void');
        expect(JSON.parse(init.body)).toEqual({ reason: 'Lente caiu', return_to_stock: true });
        expect(wrapper.emitted('reload')).toHaveLength(1);
    });

    it('anulação de unidade consignada não oferece "volta ao estoque"', async () => {
        wrapper = mount(ImplantsPanel, {
            props: { section: baseSection({ implants: [implant({ source: 'external' })] }), t },
            ...mountOpts,
        });

        await wrapper.get('[data-testid="op-implant-void"]').trigger('click');

        expect(wrapper.find('[data-testid="op-void-return"]').exists()).toBe(false);
    });

    it('emitir o registro chama a API e reabre a lista; erro do servidor aparece', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        wrapper = mount(ImplantsPanel, { props: { section: baseSection({ implants: [implant()] }), t }, ...mountOpts });

        await wrapper.get('[data-testid="op-record-issue"]').trigger('click');
        await flushPromises();

        expect(globalThis.fetch.mock.calls[0][0]).toBe('/implants/i1/record');
        expect(open).toHaveBeenCalledWith('/implants/i1/record/pdf', '_blank', 'noopener');
        expect(wrapper.emitted('reload')).toHaveLength(1);

        globalThis.fetch = vi.fn(() => jsonResponse(409, { message: 'Só implante vigente.' }));
        await wrapper.get('[data-testid="op-record-issue"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Só implante vigente.');
        open.mockRestore();
    });
});

describe('DescriptionPanel', () => {
    const draft = (over = {}) => ({
        status: 'draft',
        finalized: false,
        revision: 2,
        performed_start_at: '2026-10-15T08:10',
        performed_end_at: '2026-10-15T08:40',
        anesthesia_used: 'topical',
        findings: 'Catarata',
        technique_performed: 'Facoemulsificação',
        no_complications: true,
        complications: null,
        problems: [],
        addenda: [],
        ...over,
    });

    it('salva o rascunho com a revisão vista e mostra a confirmação', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse(200, { revision: 3 }));
        wrapper = mount(DescriptionPanel, {
            props: { section: baseSection({ description: draft() }), t },
            ...mountOpts,
        });

        await wrapper.get('[data-testid="op-d-findings"]').setValue('Catarata nuclear 3+');
        await wrapper.get('[data-testid="op-description-save"]').trigger('submit');
        await flushPromises();

        const [url, init] = globalThis.fetch.mock.calls[0];
        const body = JSON.parse(init.body);
        expect(url).toBe('/description');
        expect(init.method).toBe('PUT');
        expect(body).toMatchObject({
            findings: 'Catarata nuclear 3+',
            expected_revision: 2,
            anesthesia_used: 'topical',
            no_complications: true,
        });
        expect(wrapper.text()).toContain('Rascunho salvo.');
        expect(wrapper.emitted('reload')).toHaveLength(1);
    });

    it('finalizar salva antes e usa a revisão devolvida pelo servidor', async () => {
        const calls = [];
        globalThis.fetch = vi.fn((url, init) => {
            calls.push([url, JSON.parse(init.body)]);
            return jsonResponse(200, url === '/description' ? { revision: 5 } : { hash: 'abc' });
        });
        wrapper = mount(DescriptionPanel, {
            props: {
                section: baseSection({ description: draft(), state: { ...baseSection().state, can_finalize: true } }),
                t,
            },
            ...mountOpts,
        });

        await wrapper.get('[data-testid="op-description-finalize"]').trigger('click');
        await flushPromises();

        expect(calls.map(([u]) => u)).toEqual(['/description', '/description/finalize']);
        expect(calls[1][1]).toEqual({ expected_revision: 5 });
        expect(wrapper.emitted('reload')).toHaveLength(1);
    });

    it('finalização recusada depois do salvamento em silêncio: o próximo salvar usa a revisão devolvida, não a do prop', async () => {
        const calls = [];
        let revision = 2;
        globalThis.fetch = vi.fn((url, init) => {
            calls.push([url, init.method, JSON.parse(init.body)]);

            if (url === '/description/finalize') {
                return jsonResponse(422, { errors: { findings: ['Descreva os achados.'] } });
            }

            revision += 1; // cada gravação do servidor sobe a revisão

            return jsonResponse(200, { revision });
        });
        wrapper = mount(DescriptionPanel, {
            props: {
                section: baseSection({ description: draft(), state: { ...baseSection().state, can_finalize: true } }),
                t,
            },
            ...mountOpts,
        });

        // Finalizar grava antes (revisão 2 → 3) e é recusado; nada recarrega a tela.
        await wrapper.get('[data-testid="op-description-finalize"]').trigger('click');
        await flushPromises();

        expect(calls.map(([u]) => u)).toEqual(['/description', '/description/finalize']);
        expect(calls[1][2]).toEqual({ expected_revision: 3 });
        expect(wrapper.emitted('reload')).toBeUndefined();

        // O médico corrige e grava de novo: precisa ir com a revisão 3 (a do prop continua 2 e daria 409).
        await wrapper.get('[data-testid="op-d-findings"]').setValue('Catarata nuclear 3+');
        await wrapper.get('[data-testid="op-description-save"]').trigger('submit');
        await flushPromises();

        expect(calls[2][0]).toBe('/description');
        expect(calls[2][2]).toMatchObject({ findings: 'Catarata nuclear 3+', expected_revision: 3 });

        // E a seguinte, com a 4.
        await wrapper.get('[data-testid="op-description-save"]').trigger('submit');
        await flushPromises();

        expect(calls[3][2]).toMatchObject({ expected_revision: 4 });
    });

    it('recarregar a tela com a revisão nova do servidor passa a valer para o próximo salvar', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse(200, { revision: 10 }));
        wrapper = mount(DescriptionPanel, {
            props: { section: baseSection({ description: draft() }), t },
            ...mountOpts,
        });

        await wrapper.setProps({ section: baseSection({ description: draft({ revision: 9 }) }) });
        await wrapper.get('[data-testid="op-description-save"]').trigger('submit');
        await flushPromises();

        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toMatchObject({ expected_revision: 9 });
    });

    it('mostra o que falta para finalizar e o erro do servidor', async () => {
        globalThis.fetch = vi.fn(() => jsonResponse(422, { errors: { findings: ['Descreva os achados.'] } }));
        wrapper = mount(DescriptionPanel, {
            props: { section: baseSection({ description: draft({ problems: ['Descreva a técnica realizada.'] }) }), t },
            ...mountOpts,
        });

        expect(wrapper.get('[data-testid="op-description-problems"]').text()).toContain(
            'Descreva a técnica realizada.',
        );

        await wrapper.get('[data-testid="op-description-save"]').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-testid="op-description-errors"]').text()).toContain('Descreva os achados.');
        expect(wrapper.emitted('reload')).toBeUndefined();
    });

    it('finalizada é só leitura (sem editor) e oferece o adendo ao cirurgião', async () => {
        wrapper = mount(DescriptionPanel, {
            props: {
                section: baseSection({
                    description: draft({
                        finalized: true,
                        status: 'finalized',
                        finalized_at: '2026-10-15T09:00:00-03:00',
                        content_hash: 'a'.repeat(64),
                        addenda: [
                            {
                                id: 'a1',
                                text: 'Complemento',
                                reason: 'Esquecimento',
                                doctor: 'DR. A',
                                registry: '123',
                                created_at: '2026-10-15T10:00:00-03:00',
                            },
                        ],
                    }),
                    state: { ...baseSection().state, can_describe: false, can_addendum: true },
                }),
                t,
            },
            ...mountOpts,
        });

        expect(wrapper.find('[data-testid="op-description-final"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="op-d-findings"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="op-addendum"]')).toHaveLength(1);

        await wrapper.get('[data-testid="op-addendum-reason"]').setValue('Revisão');
        await wrapper.get('[data-testid="op-addendum-text"]').setValue('Texto do adendo com detalhes');
        await wrapper.get('[data-testid="op-addendum-submit"]').trigger('submit');
        await flushPromises();

        expect(globalThis.fetch.mock.calls[0][0]).toBe('/description/addenda');
        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toEqual({
            text: 'Texto do adendo com detalhes',
            reason: 'Revisão',
        });
        expect(wrapper.emitted('reload')).toHaveLength(1);
    });

    it('sem permissão de escrita e sem descrição: só o aviso', () => {
        wrapper = mount(DescriptionPanel, {
            props: {
                section: baseSection({ description: null, state: { ...baseSection().state, can_describe: false } }),
                t,
            },
            ...mountOpts,
        });

        expect(wrapper.text()).toContain('Sem descrição.');
        expect(wrapper.find('form').exists()).toBe(false);
    });
});

describe('OperationTab', () => {
    it('compõe os três painéis e repassa o reload', async () => {
        wrapper = mount(OperationTab, {
            props: { section: baseSection(), surgicalCase: { revision: 7 }, can: {}, urls: {}, options: {}, t },
            ...mountOpts,
        });

        expect(wrapper.find('[data-testid="op-perform"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="op-implants"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="op-description"]').exists()).toBe(true);

        await wrapper.findComponent(PerformPanel).vm.$emit('reload');
        expect(wrapper.emitted('reload')).toHaveLength(1);
    });
});
