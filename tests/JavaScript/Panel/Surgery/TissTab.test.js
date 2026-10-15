import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () => (await import('./inertiaMock.js')).inertiaMock());

const { __router } = await import('@inertiajs/vue3');
const TissTab = (await import('@/Components/Surgery/Tabs/TissTab.vue')).default;

const modal = {
    template: '<div v-if="open"><slot name="header" /><slot /><slot name="footer" /></div>',
    props: ['open', 'size'],
};

const tiss = {
    title: 'Guias TISS do pedido',
    subtitle: 'sub',
    mock_notice: 'Transporte simulado',
    operator_label: 'Operadora',
    awaiting_hint: 'Envie primeiro o pedido para autorização',
    case_locked_hint: 'travado',
    inpatient_title: 'Solicitação de internação',
    opme_title: 'Anexo de OPME',
    not_generated: 'Guia ainda não gerada.',
    generate: 'Gerar guia',
    generate_inpatient_hint: 'monta a internação',
    generate_opme_hint: 'monta o anexo',
    number: 'Nº',
    operator_number: 'Nº na operadora',
    authorization_number: 'Autorização',
    suggested_date: 'Data sugerida',
    referenced_guide_number: 'Guia referenciada',
    professional_phone: 'Telefone',
    justification: 'Justificativa',
    specification: 'Especificação',
    observation: 'Observação',
    hospital: 'Hospital',
    diagnoses: 'CID',
    days: 'Diárias',
    save_fields: 'Salvar dados da guia',
    stale: 'O pedido mudou',
    refresh: 'Atualizar',
    items_title: 'Itens',
    item_description: 'Descrição',
    item_quantity: 'Qtd.',
    item_table: 'Tabela',
    item_code: 'Código TUSS',
    item_manufacturer_option: 'Opção',
    item_anvisa: 'ANVISA',
    item_ref: 'Ref.',
    item_operating_authorization: 'Autorização de funcionamento',
    validation_title: 'Pré-validação',
    validation_ok: 'Sem pendências',
    validation_errors: 'Pendências',
    validation_warnings: 'Avisos',
    make_xml: 'Gerar XML',
    download_xml: 'Baixar XML',
    download_pdf: 'PDF',
    xml_ready: 'XML validado',
    xml_invalid: 'XML recusado',
    xml_none: 'XML ainda não gerado',
    send: 'Enviar à operadora',
    resend: 'Reenviar (mesmo XML e mesma chave)',
    send_confirm: 'Enviar agora?',
    reopen: 'Reabrir',
    cancel: 'Cancelar guia',
    cancel_reason: 'Motivo',
    confirm: 'Confirmar',
    back: 'Voltar',
    locked_hint: 'Guia travada',
    doubt_title: 'Entrega em dúvida',
    doubt_text: 'A operadora pode ter recebido',
    failed_text: 'A operadora recusou',
    delivered_text: 'Entregue à operadora.',
    in_flight_text: 'Envio em andamento',
    response_title: 'Resposta da operadora',
    response_hint: 'hint',
    response_upload: 'Anexar',
    transmissions_title: 'Transmissões',
    attempt: 'Tentativa',
    protocol: 'Protocolo',
    events_title: 'Registros',
    no_transmissions: 'Nenhuma transmissão',
};

const urls = {
    store: '/store',
    guide: '/g/__GUIDE__',
    refresh: '/g/__GUIDE__/refresh',
    xml_make: '/g/__GUIDE__/xml',
    xml: '/g/__GUIDE__/xml',
    pdf: '/g/__GUIDE__/pdf',
    send: '/g/__GUIDE__/send',
    reopen: '/g/__GUIDE__/reopen',
    cancel: '/g/__GUIDE__/cancel',
    response: '/g/__GUIDE__/response',
};

function guide(over = {}) {
    return {
        id: 'g1',
        type: 'inpatient_request',
        number: 'GUI-202610-000001',
        operator_number: null,
        authorization_number: null,
        status: { value: 'xml_generated', label: 'XML pronto' },
        delivery: { state: 'none', label: 'Ainda não enviada' },
        locked: false,
        editable: true,
        stale: false,
        validation: { passes: true, errors: [], warnings: [] },
        fields: {
            suggested_date: '2026-11-03',
            referenced_guide_number: null,
            professional_phone: null,
            justification: null,
            specification: null,
            observation: null,
        },
        summary: { hospital: 'HOSPITAL X', hospital_code: 'H-1', diagnoses: ['H251'], days: 1 },
        overridden: [],
        items: [
            {
                id: 'i1',
                description: 'Facoemulsificação',
                quantity: 1,
                table_code: '22',
                tuss_code: '30306019',
                manufacturer_option: null,
                anvisa_registration: null,
            },
        ],
        xml: { id: 'x1', status: 'validated', errors: [], generated_at: '2026-10-15T10:00:00Z', validated: true },
        transmissions: [],
        events: [],
        ...over,
    };
}

function mountTab({
    inpatient = null,
    opme = null,
    awaiting = true,
    available = { inpatient: true, opme: true },
    canGenerate = true,
} = {}) {
    return mount(TissTab, {
        props: {
            section: {
                operator: { name: 'UNIMED', transport: 'mock' },
                case_awaiting: awaiting,
                available,
                can: { generate: canGenerate, act: true },
                guides: { inpatient, opme },
                table_codes: ['19', '22'],
                urls,
            },
            surgicalCase: { id: 'c1' },
            can: { manage: true },
            urls: {},
            t: { tiss },
        },
        global: { stubs: { CenteredModal: modal } },
    });
}

beforeEach(() => {
    __router.post.mockClear();
    __router.put.mockClear();
});

describe('TissTab', () => {
    it('sem guia gerada oferece "Gerar guia" por tipo e posta o tipo', async () => {
        const w = mountTab();
        expect(w.find('[data-testid="tiss-empty-inpatient"]').exists()).toBe(true);
        expect(w.find('[data-testid="tiss-empty-opme"]').exists()).toBe(true);

        await w.find('[data-testid="generate-opme"]').trigger('click');

        expect(__router.post).toHaveBeenCalledWith(
            '/store',
            { type: 'opme' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('esconde o card de OPME quando o pedido não tem OPME e avisa que o caso não está aguardando', () => {
        const w = mountTab({ awaiting: false, available: { inpatient: true, opme: false } });

        expect(w.find('[data-testid="tiss-empty-opme"]').exists()).toBe(false);
        expect(w.find('[data-testid="awaiting-hint"]').exists()).toBe(true);
        expect(w.find('[data-testid="mock-notice"]').text()).toContain('simulado');
    });

    it('guia pronta: envia só depois de confirmar, para a URL da guia', async () => {
        const w = mountTab({ inpatient: guide() });

        expect(w.find('[data-testid="send"]').text()).toBe('Enviar à operadora');
        expect(w.find('[data-testid="confirm-send"]').exists()).toBe(false);

        await w.find('[data-testid="send"]').trigger('click');
        expect(__router.post).not.toHaveBeenCalled();

        await w.find('[data-testid="confirm-send"]').trigger('click');
        expect(__router.post).toHaveBeenCalledWith('/g/g1/send', {}, expect.any(Object));
    });

    it('entrega em dúvida: banner, guia travada (campos desabilitados) e "reenviar" com o mesmo XML', () => {
        const w = mountTab({
            inpatient: guide({
                status: { value: 'xml_generated', label: 'XML pronto' },
                delivery: { state: 'doubt', label: 'Entrega em dúvida' },
                locked: true,
                editable: false,
                transmissions: [
                    {
                        id: 't1',
                        status: 'timeout',
                        label: 'Sem resposta',
                        attempt: 1,
                        requested_at: '2026-10-15T10:00:00Z',
                        responded_at: null,
                        protocol: null,
                        error: 'timeout',
                    },
                ],
            }),
            awaiting: false, // a retomada da dúvida vale mesmo com o caso fora de "aguardando"
        });

        expect(w.find('[data-testid="doubt-banner"]').text()).toContain('Entrega em dúvida');
        expect(w.find('[data-testid="send"]').text()).toContain('Reenviar');
        expect(w.find('[data-testid="awaiting-hint"]').exists()).toBe(false);
        expect(w.find('[data-testid="field-suggested-date"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-testid="item-code"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-testid="save-fields"]').exists()).toBe(false);
        expect(w.find('[data-testid="make-xml"]').exists()).toBe(false);
        expect(w.text()).not.toContain('Cancelar guia');
        expect(w.find('[data-testid="transmissions"]').text()).toContain('Sem resposta');
    });

    it('pendências da pré-validação aparecem e bloqueiam gerar XML e enviar', () => {
        const w = mountTab({
            inpatient: guide({
                status: { value: 'draft', label: 'Rascunho' },
                xml: null,
                validation: {
                    passes: false,
                    errors: [
                        {
                            code: 'HOSPITAL_CODE_REQUIRED',
                            field: 'hospital.operator_code',
                            message: 'Código do hospital não informado.',
                            suggestion: 'Preencha o código.',
                        },
                    ],
                    warnings: [],
                },
            }),
        });

        expect(w.find('[data-testid="validation"]').text()).toContain('Código do hospital não informado.');
        expect(w.find('[data-testid="make-xml"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-testid="send"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-testid="xml-state"]').text()).toContain('XML ainda não gerado');
    });

    it('salvar manda a data sugerida e os códigos digitados dos itens (PUT na guia)', async () => {
        const w = mountTab({ inpatient: guide() });

        await w.find('[data-testid="field-suggested-date"]').setValue('2026-11-10');
        await w.find('[data-testid="item-code"]').setValue('30306020');
        await w.find('[data-testid="save-fields"]').trigger('click');

        expect(__router.put).toHaveBeenCalledTimes(1);
        const [url, payload] = __router.put.mock.calls[0];
        expect(url).toBe('/g/g1');
        expect(payload.suggested_date).toBe('2026-11-10');
        expect(payload.items).toEqual([{ id: 'i1', tuss_code: '30306020', table_code: '22' }]);
    });

    it('guia desatualizada em relação ao pedido oferece atualizar', async () => {
        const w = mountTab({ inpatient: guide({ stale: true }) });

        await w.find('[data-testid="stale-banner"] button').trigger('click');

        expect(__router.post).toHaveBeenCalledWith('/g/g1/refresh', {}, expect.any(Object));
    });

    it('anexo de OPME mostra os campos do material e o telefone do solicitante', () => {
        const w = mountTab({
            opme: guide({
                id: 'g2',
                type: 'opme_attachment',
                items: [
                    {
                        id: 'i2',
                        description: 'LIO',
                        quantity: 1,
                        table_code: '19',
                        tuss_code: '',
                        manufacturer_option: '1',
                        anvisa_registration: '801',
                    },
                ],
                fields: {
                    suggested_date: null,
                    referenced_guide_number: 'GUI-1',
                    professional_phone: '11999990000',
                    justification: 'Catarata densa',
                    specification: 'LIO (ALCON)',
                    observation: null,
                },
            }),
        });

        expect(w.find('[data-testid="items-opme"] [data-testid="item-anvisa"]').element.value).toBe('801');
        expect(w.text()).toContain('Telefone');
        expect(w.find('[data-testid="field-suggested-date"]').exists()).toBe(false);
    });

    it('acessibilidade: todo cabeçalho de tabela tem scope e todo controle tem nome acessível', () => {
        const w = mountTab({
            inpatient: guide(),
            opme: guide({
                id: 'g2',
                type: 'opme_attachment',
                items: [
                    {
                        id: 'i2',
                        description: 'LIO',
                        quantity: 1,
                        table_code: '19',
                        tuss_code: '',
                        manufacturer_option: '1',
                        anvisa_registration: '801',
                    },
                ],
                fields: {
                    suggested_date: null,
                    referenced_guide_number: 'GUI-1',
                    professional_phone: '119',
                    justification: 'x',
                    specification: 'y',
                    observation: null,
                },
            }),
        });
        const root = w.element;

        const ths = [...root.querySelectorAll('th')];
        expect(ths.length).toBeGreaterThan(8);
        expect(ths.every((th) => th.getAttribute('scope') === 'col')).toBe(true);

        const controls = [...root.querySelectorAll('input, select, textarea')].filter(
            (el) => el.type !== 'file' || el.getAttribute('aria-label'),
        );
        const unnamed = controls.filter((el) => {
            const labelled = el.id && root.querySelector(`label[for="${el.id}"]`);

            return !(el.getAttribute('aria-label') || labelled);
        });
        expect(unnamed).toHaveLength(0);
    });
});
