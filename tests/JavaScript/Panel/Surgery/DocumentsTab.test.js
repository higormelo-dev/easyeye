import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import DocumentsTab from '@/Components/Surgery/Tabs/DocumentsTab.vue';

/**
 * Aba "Documentos" do caso cirúrgico (M3a): lista os documentos congelados de
 * qualquer etapa, a situação da assinatura do paciente e as ações permitidas.
 */
const t = {
    documents: {
        variables: { data_cirurgia: 'Data e hora da cirurgia' },
        ui: {
            title: 'Documentos do caso',
            empty: 'Nenhum documento emitido ainda.',
            pending_title: 'Aguardando a assinatura do paciente',
            pending_text: 'Anexe a cópia digitalizada assinada de: :list.',
            outdated_title: 'Termo desatualizado',
            outdated_text: 'O termo não cobre mais o pedido (mudou: :fields).',
            outdated: 'Desatualizado — reemitir',
            issue_consent: 'Emitir termo de consentimento',
            issue_pre_op_guidance: 'Emitir orientações pré-operatórias',
            manage_templates: 'Gerenciar modelos',
            signed: 'Cópia assinada anexada',
            unsigned: 'Falta a cópia assinada',
            signature_optional: 'Assinatura opcional',
            current: 'Vigente',
            superseded: 'Substituído',
            open_pdf: 'Abrir PDF',
            reissue: 'Reemitir',
            attach_copy: 'Anexar cópia assinada',
            replace_copy: 'Trocar cópia',
            view_copy: 'Ver cópia assinada',
            copy_hint: 'PDF, JPG ou PNG, até :max MB.',
            show_history: 'Mostrar documentos substituídos (:count)',
            hide_history: 'Ocultar documentos substituídos',
            upload_failed: 'Não foi possível anexar a cópia.',
            issued_by: 'por :name',
            version: 'modelo v:version',
        },
    },
};

const doc = (over = {}) => ({
    id: 'd1',
    type: 'consent',
    type_label: 'Termo de consentimento (TCLE)',
    title: 'TCLE — Catarata',
    issued_at: '2026-10-15T10:00:00-03:00',
    issuer: 'Secretária',
    doctor: null,
    template_version: 2,
    current: true,
    requires_signature: true,
    has_signed_copy: false,
    signed_copy: null,
    can_reissue: true,
    content: '<p>texto</p>',
    urls: { pdf: '/pdf/d1', signed_copy: '/copy/d1', signed_copy_store: '/copy-store/d1' },
    ...over,
});

const section = (over = {}) => ({
    documents: [doc()],
    pending_signature: [{ id: 'd1', title: 'TCLE — Catarata', type_label: 'TCLE' }],
    can_issue: true,
    can_attach: true,
    issuable: [
        { type: 'consent', label: 'Termo de consentimento (TCLE)', templates: [] },
        { type: 'pre_op_guidance', label: 'Orientações pré-operatórias', templates: [] },
    ],
    urls: { preview: '/preview', store: '/store', templates: '/settings/templates' },
    signed_copy_mimes: ['application/pdf'],
    signed_copy_max_mb: 10,
    ...over,
});

function mountTab(sectionOverride = {}) {
    return mount(DocumentsTab, {
        attachTo: document.body,
        props: { section: section(sectionOverride), surgicalCase: { id: 'c1' }, can: {}, urls: {}, options: {}, t },
        global: { stubs: { SurgeryDocumentIssueModal: true } },
    });
}

beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('DocumentsTab', () => {
    it('lista o documento com a situação da assinatura e alerta o que falta assinar', () => {
        const wrapper = mountTab();

        expect(wrapper.findAll('[data-testid="doc-row"]')).toHaveLength(1);
        expect(wrapper.find('[data-testid="doc-signature-unsigned"]').text()).toBe('Falta a cópia assinada');
        expect(wrapper.find('[data-testid="doc-pending-alert"]').attributes('role')).toBe('alert');
        expect(wrapper.find('[data-testid="doc-pending-alert"]').text()).toContain('TCLE — Catarata');
        expect(wrapper.find('[data-testid="doc-pdf"]').attributes('href')).toBe('/pdf/d1');
        expect(wrapper.find('[data-testid="doc-attach"]').text()).toBe('Anexar cópia assinada');
    });

    it('com a cópia anexada mostra "assinada", oferece ver e trocar e some o alerta', () => {
        const wrapper = mountTab({
            documents: [
                doc({
                    has_signed_copy: true,
                    signed_copy: { name: 'termo.pdf', uploaded_at: '2026-10-15T11:00:00-03:00' },
                }),
            ],
            pending_signature: [],
        });

        expect(wrapper.find('[data-testid="doc-signature-signed"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="doc-pending-alert"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="doc-view-copy"]').attributes('href')).toBe('/copy/d1');
        expect(wrapper.find('[data-testid="doc-attach"]').text()).toBe('Trocar cópia');
    });

    it('orientação e documentos sem exigência de assinatura mostram "assinatura opcional"', () => {
        const wrapper = mountTab({
            documents: [doc({ type: 'pre_op_guidance', requires_signature: false })],
            pending_signature: [],
        });

        expect(wrapper.find('[data-testid="doc-signature-optional"]').text()).toBe('Assinatura opcional');
    });

    it('termo assinado que já não cobre o pedido aparece como desatualizado e a reemissão parte de uma prévia nova', async () => {
        const wrapper = mountTab({
            documents: [
                doc({
                    has_signed_copy: true,
                    signed_copy: { name: 'termo.pdf', uploaded_at: '2026-10-15T11:00:00-03:00' },
                    outdated_fields: ['eye'],
                }),
            ],
            outdated_consent: { id: 'd1', title: 'TCLE — Catarata', fields: 'olho' },
            pending_signature: [],
        });

        // A cópia assinada existe, mas o selo é de desatualizado — nunca "assinado".
        expect(wrapper.find('[data-testid="doc-signature-signed"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="doc-signature-outdated"]').text()).toBe('Desatualizado — reemitir');
        expect(wrapper.find('[data-testid="doc-outdated-alert"]').attributes('role')).toBe('alert');
        expect(wrapper.find('[data-testid="doc-outdated-alert"]').text()).toContain('mudou: olho');

        // O texto emitido fala do pedido velho: a reemissão começa de uma prévia nova (sem reemitir o texto antigo).
        await wrapper.find('[data-testid="doc-reissue"]').trigger('click');
        const modal = wrapper.findComponent({ name: 'SurgeryDocumentIssueModal' });
        expect(modal.props('open')).toBe(true);
        expect(modal.props('reissue')).toBeNull();
        expect(modal.props('issuable').type).toBe('consent');
    });

    it('termo em dia, mesmo com cópia assinada, não mostra o aviso de desatualizado', () => {
        const wrapper = mountTab({
            documents: [
                doc({
                    has_signed_copy: true,
                    signed_copy: { name: 'a.pdf', uploaded_at: '2026-10-15T11:00:00-03:00' },
                    outdated_fields: [],
                }),
            ],
            outdated_consent: null,
            pending_signature: [],
        });

        expect(wrapper.find('[data-testid="doc-outdated-alert"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="doc-signature-signed"]').exists()).toBe(true);
    });

    it('documentos substituídos ficam escondidos até pedir o histórico', async () => {
        const wrapper = mountTab({ documents: [doc(), doc({ id: 'd0', current: false, can_reissue: false })] });

        expect(wrapper.findAll('[data-testid="doc-row"]')).toHaveLength(1);
        await wrapper.find('[data-testid="doc-history-toggle"]').trigger('click');
        expect(wrapper.findAll('[data-testid="doc-row"]')).toHaveLength(2);
        expect(wrapper.find('[data-current="0"]').text()).toContain('Substituído');
    });

    it('sem permissão de emitir não há botões de emissão nem de reemissão', () => {
        const wrapper = mountTab({ can_issue: false, issuable: [] });

        expect(wrapper.find('[data-testid="doc-issue-consent"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="doc-reissue"]').exists()).toBe(false);
    });

    it('mostra o link para gerenciar modelos só quando o servidor o entrega', () => {
        expect(mountTab().find('[data-testid="doc-manage-templates"]').attributes('href')).toBe('/settings/templates');
        expect(
            mountTab({ urls: { preview: '/p', store: '/s', templates: null } })
                .find('[data-testid="doc-manage-templates"]')
                .exists(),
        ).toBe(false);
    });

    it('lista vazia mostra a mensagem', () => {
        const wrapper = mountTab({ documents: [], pending_signature: [] });

        expect(wrapper.find('[data-testid="doc-empty"]').text()).toBe('Nenhum documento emitido ainda.');
    });

    it('arquivo acima do limite é barrado no navegador, sem enviar', async () => {
        const wrapper = mountTab();
        await wrapper.find('[data-testid="doc-attach"]').trigger('click');

        const input = wrapper.find('[data-testid="doc-file-input"]');
        const big = new File(['x'], 'grande.pdf', { type: 'application/pdf' });
        Object.defineProperty(big, 'size', { value: 11 * 1024 * 1024 });
        Object.defineProperty(input.element, 'files', { value: [big], configurable: true });
        await input.trigger('change');

        expect(router.post).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="doc-upload-error"]').text()).toContain('até 10 MB');
    });

    it('envia a cópia como multipart e recarrega a aba', async () => {
        const wrapper = mountTab();
        await wrapper.find('[data-testid="doc-attach"]').trigger('click');

        const input = wrapper.find('[data-testid="doc-file-input"]');
        const file = new File(['%PDF'], 'termo.pdf', { type: 'application/pdf' });
        Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
        await input.trigger('change');

        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, data, options] = router.post.mock.calls[0];
        expect(url).toBe('/copy-store/d1');
        expect(data.file).toBe(file);
        expect(options.forceFormData).toBe(true);

        options.onSuccess();
        await flushPromises();
        expect(wrapper.emitted('reload')).toHaveLength(1);
    });
});
