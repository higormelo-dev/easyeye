import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import NfseSettingsForm from '@/Pages/Panel/Manager/Nfse/NfseSettingsForm.vue';
import NfseCredentialsCard from '@/Pages/Panel/Manager/Nfse/NfseCredentialsCard.vue';
import NfseHookCard from '@/Pages/Panel/Manager/Nfse/NfseHookCard.vue';
import NfseMunicipalityCard from '@/Pages/Panel/Manager/Nfse/NfseMunicipalityCard.vue';
import NfseDocumentsTable from '@/Pages/Panel/Manager/Nfse/NfseDocumentsTable.vue';
import NfseInvoiceLinks from '@/Components/Billing/NfseInvoiceLinks.vue';
import NfseBlock from '@/Pages/Panel/Manager/Subscriptions/NfseBlock.vue';
import SubscriptionDetailDrawer from '@/Pages/Panel/Manager/Subscriptions/SubscriptionDetailDrawer.vue';
import NfseIndex from '@/Pages/Panel/Manager/Nfse/Index.vue';

vi.mock('@/composables/useLocaleFormat.js', () => ({
    useLocaleFormat: () => ({ money: (value) => `R$ ${value}`, dateTime: (value) => value }),
}));

const t = {
    title: 'Notas fiscais',
    standard: { municipal: 'Municipal', nacional: 'Nacional' },
    environment: { homologacao: 'Homologação', producao: 'Produção' },
    page: { documents: 'Notas fiscais', empty_documents: 'Nenhuma nota' },
    actions: { save: 'Salvar', cancel: 'Cancelar', close: 'Fechar' },
    settings: {
        title: 'Dados',
        provider: 'Prestador',
        cnpj: 'CNPJ',
        inscricao_municipal: 'Inscrição',
        codigo_municipio: 'IBGE',
        cnae: 'CNAE',
        item_lista_servico: 'Item',
        codigo_tributario_municipio: 'Tributário municipal',
        codigo_tributacao_nacional_iss: 'Tributário nacional',
        tributacao_iss: 'Tributação',
        aliquota_iss: 'Alíquota',
        iss_retido: 'Retido',
        natureza_operacao: 'Natureza',
        optante_simples_nacional: 'Simples',
        regime_especial_tributacao: 'Regime',
        serie_dps: 'Série DPS',
        proximo_numero_dps: 'Próximo DPS',
        standard: 'Padrão',
        environment: 'Ambiente',
        auto_issue: 'Automática',
        copy_email: 'Cópia',
        copy_email_hint: 'Recebe cópia',
        discriminacao_plano: 'Plano',
        discriminacao_ia: 'IA',
        discriminacao_hint: 'Placeholders',
        national_only_hint: 'Nacional',
        save: 'Salvar',
        tributacao_iss_option_1: 'Tributável',
        tributacao_iss_option_2: 'Imune',
        tributacao_iss_option_3: 'Exportação',
        tributacao_iss_option_4: 'Não incidência',
    },
    credentials: {
        configured: 'Configurada',
        not_configured: 'Não configurada',
        token: 'Token',
        master_token: 'Mestre',
        replace_token: 'Substituir',
        reason: 'Justificativa',
        reason_hint: 'Motivo',
        save: 'Salvar',
        test: 'Testar',
    },
    hook: {
        registered: 'Registrado',
        not_registered: 'Não registrado',
        reason: 'Motivo',
        reason_hint: 'Informe',
        register: 'Registrar',
        remove: 'Remover',
        id: 'ID',
        event: 'Evento',
        url: 'URL',
    },
    documents: {
        reference: 'Referência',
        entity: 'Clínica',
        number: 'Número',
        amount: 'Valor',
        status: 'Situação',
        download_pdf: 'PDF',
        download_xml: 'XML',
        mirror: 'Espelho',
        issue: 'Emitir',
        cancel: 'Cancelar',
        sync: 'Sincronizar',
        resend_email: 'Reenviar',
        mark_cancelled_externally: 'Cancelar externamente',
        cancel_validation: 'Justificativa inválida',
        reason_validation: 'Motivo inválido',
        justification: 'Justificativa',
        reason: 'Motivo',
        emails: 'E-mails',
        emails_hint: 'Separe',
        download_cancel_xml: 'XML cancelamento',
        cancel_required: 'Pendente',
    },
};

const settings = {
    prestador: { cnpj: '123', aliquota_iss: 0.05, serie_dps: 1, proximo_numero_dps: 3 },
    standard: 'nacional',
    environment: 'producao',
    auto_issue: true,
    copy_email: null,
    discriminacao: { plano: 'Plano {plano}', ia: 'IA {creditos}' },
};
let wrapper;
afterEach(() => {
    wrapper?.unmount();
    vi.unstubAllGlobals();
});

describe('NFS-e do Manager', () => {
    it('converte a aliquota de fracao para porcentagem e conserva o payload aninhado', async () => {
        wrapper = mount(NfseSettingsForm, { props: { settings, t } });
        expect(wrapper.get('#nfse-aliquota').element.value).toBe('5');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('save')[0][0]).toMatchObject({
            standard: 'nacional',
            prestador: { aliquota_iss: 0.05, serie_dps: 1, proximo_numero_dps: 3 },
        });
    });

    it('nunca preenche tokens existentes e envia somente a substituicao digitada', async () => {
        wrapper = mount(NfseCredentialsCard, {
            props: {
                credential: { environment: 'producao', configured: true, has_token: true, has_master_token: true },
                t,
            },
        });
        expect(wrapper.get('#nfse-token-producao').element.value).toBe('');
        expect(wrapper.get('#nfse-master-token-producao').element.value).toBe('');
        await wrapper.get('#nfse-token-producao').setValue('novo-token');
        await wrapper.get('#nfse-credentials-reason-producao').setValue('Substituição programada do token fiscal.');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('save')[0][0]).toMatchObject({ environment: 'producao', token: 'novo-token' });
        expect(wrapper.emitted('save')[0][0].master_token).toBeUndefined();
    });

    it('exige justificativa de ao menos 20 caracteres para credenciais e webhook', async () => {
        wrapper = mount(NfseCredentialsCard, {
            props: { credential: { environment: 'producao', configured: true }, t },
        });
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
        await wrapper.get('#nfse-credentials-reason-producao').setValue('Justificativa fiscal válida');
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined();
        wrapper.unmount();

        wrapper = mount(NfseHookCard, {
            props: {
                credential: { environment: 'producao', hook: { registered: false } },
                t: { ...t, page: { hook: 'Webhook' } },
            },
        });
        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
        await wrapper.get('#nfse-hook-reason-producao').setValue('Justificativa fiscal válida');
        expect(wrapper.get('button').attributes('disabled')).toBeUndefined();
    });

    it('mostra somente as acoes permitidas pela linha da nota', () => {
        wrapper = mount(NfseDocumentsTable, {
            props: {
                documents: [
                    {
                        id: 'nf-1',
                        ref: 'NF-1',
                        invoice_reference: 'F-1',
                        entity: { name: 'Clínica' },
                        amount: 10,
                        currency: 'BRL',
                        status: 'autorizado',
                        status_label: 'Autorizada',
                        status_badge: 'badge-soft-success',
                        actions: { sync: '/sync' },
                        urls: { pdf: '/pdf' },
                    },
                ],
                t,
            },
            global: { stubs: { CenteredModal: { template: '<div><slot /><slot name="footer" /></div>' } } },
        });
        expect(wrapper.text()).toContain('Sincronizar');
        expect(wrapper.text()).not.toContain('Cancelar externamente');
        expect(wrapper.get('.badge').classes()).toContain('badge-soft-success');
    });

    it('valida a justificativa de cancelamento antes de emitir e mantém o modal aberto', async () => {
        wrapper = mount(NfseDocumentsTable, {
            props: {
                documents: [
                    {
                        id: 'nf-2',
                        ref: 'NF-2',
                        entity: {},
                        amount: 10,
                        status_label: 'Autorizada',
                        status_badge: 'badge-soft-success',
                        actions: { cancel: '/cancel' },
                        urls: {},
                    },
                ],
                t,
            },
            global: {
                stubs: {
                    CenteredModal: {
                        props: ['open'],
                        template: '<div v-if="open"><slot /><slot name="footer" /></div>',
                    },
                },
            },
        });
        await wrapper.get('button.dropdown-toggle').trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Cancelar')
            .trigger('click');
        await wrapper.get('#nfse-action-reason').setValue('curta');
        await wrapper.get('#nfse-document-action-form').trigger('submit');
        expect(wrapper.emitted('action')).toBeUndefined();
        expect(wrapper.text()).toContain('Justificativa inválida');
        await wrapper.get('#nfse-action-reason').setValue('Justificativa de cancelamento válida.');
        await wrapper.get('#nfse-document-action-form').trigger('submit');
        expect(wrapper.emitted('action')[0][0].payload).toEqual({
            justification: 'Justificativa de cancelamento válida.',
        });
    });

    it('expõe os links de download da nota para a clínica sem rota Ziggy', () => {
        wrapper = mount(NfseInvoiceLinks, {
            props: {
                nfse: {
                    status: 'cancelado',
                    number: '123',
                    issued_at: '2026-10-18',
                    pdf_url: '/pdf',
                    xml_url: '/xml',
                    cancel_xml_url: '/cancel.xml',
                    cancelled: true,
                },
                t: {
                    title: 'NFS-e',
                    pdf: 'PDF',
                    xml: 'XML',
                    cancel_xml: 'XML cancelado',
                    cancelled: 'Cancelada',
                    download: 'Baixar',
                },
            },
        });
        expect(wrapper.get('[data-test="nfse-invoice-links"]').text()).toContain('123');
        expect(wrapper.get('a[href="/pdf"]').text()).toBe('PDF');
        expect(wrapper.get('a[href="/xml"]').text()).toBe('XML');
        expect(wrapper.get('a[href="/cancel.xml"]').text()).toBe('XML cancelado');
        expect(wrapper.get('a[href="/pdf"]').attributes('target')).toBe('_blank');
    });

    it('oculta os links da clínica enquanto a NFS-e está pendente', () => {
        wrapper = mount(NfseInvoiceLinks, {
            props: { nfse: { status: 'processando_autorizacao', pdf_url: '/pdf' }, t: {} },
        });
        expect(wrapper.find('[data-test="nfse-invoice-links"]').exists()).toBe(false);
    });

    it('exibe flags retornadas do município sem recarregar a página', () => {
        wrapper = mount(NfseMunicipalityCard, {
            props: {
                enabled: true,
                municipality: {
                    codigo_municipio: '3550308',
                    nome_municipio: 'São Paulo',
                    sigla_uf: 'SP',
                    provedor_nfse: 'Focus',
                    status_nfse: 'ativo',
                    nfse_habilitada: true,
                    requer_certificado_nfse: false,
                },
                t: {
                    page: { municipality: 'Município' },
                    municipality: {
                        configured: 'Configurado',
                        provider: 'Provedor',
                        status: 'Status',
                        nfse_habilitada: 'Habilitada',
                        requer_certificado_nfse: 'Certificado',
                        yes: 'Sim',
                        no: 'Não',
                        ibge_code: 'IBGE',
                        save: 'Salvar',
                        search: 'Buscar',
                        uf: 'UF',
                        search_hint: '',
                        refresh: 'Atualizar',
                    },
                },
            },
        });
        expect(wrapper.text()).toContain('Focus');
        expect(wrapper.text()).toContain('Habilitada');
        expect(wrapper.text()).toContain('Sim');
    });

    it('mostra o aviso de estorno parcial no bloco reutilizado do Manager', () => {
        wrapper = mount(NfseBlock, {
            props: {
                nfse: {
                    numero: '123',
                    status_label: 'Autorizada',
                    status_badge: 'badge-soft-success',
                    partial_refund_warning: true,
                    urls: { pdf: '/pdf', xml: '/xml' },
                },
                t: {
                    title: 'Nota fiscal',
                    number: 'Número',
                    status: 'Situação',
                    download_pdf: 'PDF',
                    download_xml: 'XML',
                    partial_refund_warning: 'Estorno parcial não cancela a nota.',
                },
                readOnly: true,
            },
        });

        expect(wrapper.get('[data-test="manager-nfse-block"]').text()).toContain('Estorno parcial não cancela a nota.');
        expect(wrapper.get('a[href="/pdf"]').text()).toBe('PDF');
    });

    it('exibe a mensagem da Focus sem converter objetos de erro em texto cru', () => {
        wrapper = mount(NfseBlock, {
            props: {
                nfse: {
                    numero: '123',
                    status_label: 'Erro',
                    status_badge: 'badge-soft-danger',
                    errors: [{ codigo: 'E123', mensagem: 'O município recusou a NFS-e.' }],
                    actions: {},
                    urls: {},
                },
                t: { title: 'Nota fiscal', number: 'Número', status: 'Situação' },
            },
            global: { stubs: { CenteredModal: true } },
        });

        expect(wrapper.text()).toContain('O município recusou a NFS-e.');
        expect(wrapper.text()).not.toContain('[object Object]');
    });

    it('recarrega as faturas depois de atualizar uma NFS-e', async () => {
        const fetch = vi.fn().mockResolvedValue({ json: async () => ({ data: [] }) });
        vi.stubGlobal('fetch', fetch);
        vi.stubGlobal(
            'route',
            vi.fn(() => '/manager/subscriptions/sub-1/invoices'),
        );
        wrapper = mount(SubscriptionDetailDrawer, {
            props: { open: false, subscriptionId: 'sub-1' },
            global: {
                stubs: {
                    OffcanvasPanel: { template: '<div><slot /></div>' },
                    BillingStateBadge: true,
                    SubscriptionHistory: true,
                    ReasonField: true,
                    PaymentRefundModal: true,
                    NfseBlock: true,
                    Link: true,
                },
            },
        });

        await wrapper.vm.refreshInvoicesAfterNfse();
        await wrapper.vm.refreshInvoicesAfterNfse();

        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('expõe as cinco ações permitidas pelo bloco de NFS-e', () => {
        wrapper = mount(NfseBlock, {
            props: {
                nfse: {
                    numero: '1',
                    status_label: 'Autorizada',
                    status_badge: 'badge-soft-success',
                    actions: {
                        issue: '/issue',
                        sync: '/sync',
                        resend_email: '/resend',
                        cancel: '/cancel',
                        mark_cancelled_externally: '/external',
                    },
                    urls: {},
                },
                t: {
                    title: 'NFS-e',
                    number: 'Número',
                    status: 'Situação',
                    issue: 'Emitir',
                    sync: 'Sincronizar',
                    resend_email: 'Reenviar',
                    cancel: 'Cancelar',
                    mark_cancelled: 'Externa',
                },
            },
            global: { stubs: { CenteredModal: true } },
        });
        expect(wrapper.text()).toContain('Emitir');
        expect(wrapper.text()).toContain('Sincronizar');
        expect(wrapper.text()).toContain('Reenviar');
        expect(wrapper.text()).toContain('Cancelar');
        expect(wrapper.text()).toContain('Externa');
    });

    it('mostra somente Emitir NFS-e para uma fatura paga ainda sem nota', () => {
        wrapper = mount(NfseBlock, {
            props: {
                nfse: { status: null, status_label: 'Sem nota', actions: { issue: '/issue' }, urls: { pdf: '/pdf' } },
                t: { issue: 'Emitir NFS-e' },
            },
            global: { stubs: { CenteredModal: true } },
        });

        expect(wrapper.get('[data-test="manager-nfse-block"]').text()).toBe('Emitir NFS-e');
        expect(wrapper.find('.badge').exists()).toBe(false);
        expect(wrapper.find('a').exists()).toBe(false);
    });

    it('mostra a ultima tentativa junto da emissao quando a fatura ainda esta sem nota', () => {
        wrapper = mount(NfseBlock, {
            props: {
                nfse: {
                    status: null,
                    actions: { issue: '/issue' },
                    last_attempt: {
                        numero: 'RPS-123',
                        status_label: 'Falha no envio',
                        status_badge: 'badge-soft-danger',
                        urls: { pdf: '/attempt.pdf', xml: '/attempt.xml' },
                        errors: [{ codigo: 'E123', mensagem: 'Município indisponível.' }],
                    },
                },
                t: { issue: 'Emitir NFS-e', number: 'Número', download_pdf: 'PDF', download_xml: 'XML' },
            },
            global: { stubs: { CenteredModal: true } },
        });

        expect(wrapper.text()).toContain('Emitir NFS-e');
        expect(wrapper.text()).toContain('Falha no envio');
        expect(wrapper.text()).toContain('RPS-123');
        expect(wrapper.text()).toContain('Município indisponível.');
        expect(wrapper.get('a[href="/attempt.pdf"]').attributes('target')).toBe('_blank');
        expect(wrapper.get('a[href="/attempt.xml"]').attributes('rel')).toBe('noopener');
    });

    it('exibe o erro de emitir ou sincronizar fora do modal', async () => {
        vi.stubGlobal('axios', {
            post: vi.fn().mockRejectedValue({ response: { data: { message: 'A Focus NFe está indisponível.' } } }),
        });
        wrapper = mount(NfseBlock, {
            props: {
                nfse: { status: 'send_failed', actions: { sync: '/sync' }, urls: {} },
                t: { sync: 'Sincronizar', title: 'NFS-e', number: 'Número', status: 'Situação' },
            },
            global: { stubs: { CenteredModal: true } },
        });

        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('A Focus NFe está indisponível.');
    });

    it('persiste o municipio no payload aninhado e atualiza o formulario de configuracoes', async () => {
        const axios = vi
            .fn()
            .mockResolvedValueOnce({
                data: { municipality: { codigo_municipio: '3550308', nome_municipio: 'São Paulo' } },
            })
            .mockResolvedValueOnce({ data: { message: 'Configuração fiscal salva.' } });
        vi.stubGlobal('axios', axios);
        vi.stubGlobal(
            'route',
            vi.fn((name) => name),
        );
        wrapper = mount(NfseIndex, {
            props: {
                settings,
                permissions: { can_settings: true },
                t: { title: 'NFS-e', breadcrumb: 'NFS-e', subtitle: '' },
            },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                    PageHeader: true,
                    NfseSettingsForm: {
                        props: ['settings'],
                        template:
                            '<output data-test="settings-municipality">{{ settings.prestador.codigo_municipio }}</output>',
                    },
                    NfseCredentialsCard: true,
                    NfseHookCard: true,
                    NfseMunicipalityCard: true,
                    NfseDocumentsTable: true,
                },
            },
        });

        await wrapper.vm.saveMunicipality('3550308');

        expect(axios).toHaveBeenNthCalledWith(
            2,
            expect.objectContaining({
                method: 'put',
                url: 'manager.nfse.settings.update',
                data: expect.objectContaining({ prestador: expect.objectContaining({ codigo_municipio: '3550308' }) }),
            }),
        );
        expect(wrapper.get('[data-test="settings-municipality"]').text()).toBe('3550308');
    });

    it('exige motivo de 20 a 255 caracteres para cancelar externamente nos dois blocos', async () => {
        const modal = {
            props: ['open'],
            template: '<div v-if="open"><slot /><slot name="footer" /></div>',
        };
        wrapper = mount(NfseDocumentsTable, {
            props: {
                documents: [
                    {
                        id: 'nf-3',
                        ref: 'NF-3',
                        entity: {},
                        amount: 10,
                        actions: { mark_cancelled_externally: '/external' },
                    },
                ],
                t: { ...t, documents: { ...t.documents, reason_validation: 'Motivo entre 20 e 255.' } },
            },
            global: { stubs: { CenteredModal: modal } },
        });
        await wrapper.get('button.dropdown-toggle').trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Cancelar externamente')
            .trigger('click');
        await wrapper.get('#nfse-action-reason').setValue('a'.repeat(19));
        await wrapper.get('#nfse-document-action-form').trigger('submit');
        expect(wrapper.emitted('action')).toBeUndefined();
        expect(wrapper.text()).toContain('Motivo entre 20 e 255.');
        await wrapper.get('#nfse-action-reason').setValue('a'.repeat(256));
        await wrapper.get('#nfse-document-action-form').trigger('submit');
        expect(wrapper.emitted('action')).toBeUndefined();

        wrapper.unmount();
        wrapper = mount(NfseBlock, {
            props: {
                nfse: { status: 'autorizado', actions: { mark_cancelled_externally: '/external' }, urls: {} },
                t: {
                    mark_cancelled: 'Cancelar externamente',
                    reason: 'Motivo',
                    reason_validation: 'Motivo entre 20 e 255.',
                    save: 'Salvar',
                    close: 'Fechar',
                },
            },
            global: { stubs: { CenteredModal: modal } },
        });
        await wrapper.get('button').trigger('click');
        await wrapper.get('#nfse-block-value').setValue('a'.repeat(256));
        await wrapper.get('#nfse-block-action').trigger('submit');
        expect(wrapper.text()).toContain('Motivo entre 20 e 255.');
    });
});
