import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock());
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div class="layout"><h1>{{ title }}</h1><slot /></div>' },
}));
// O modal de confirmação com motivo é um componente próprio (testado à parte): aqui só importa o contrato.
vi.mock('@/Components/Panel/ConfirmationWithReasonModal.vue', () => ({
    default: {
        props: ['open', 'title', 'message', 'confirmLabel', 'minLength', 'saving'],
        emits: ['close', 'confirm'],
        template:
            '<div v-if="open" data-testid="reason-modal" :data-min="minLength"><p>{{ message }}</p><button data-testid="reason-confirm" @click="$emit(\'confirm\', \'Revisor trocado pelo conselho.\')">{{ confirmLabel }}</button></div>',
    },
}));

import { forms, page, resetInertiaMock, router } from './support/inertiaMock.js';
import { managerT } from './support/texts.js';
import Index from '@/Pages/Panel/Manager/ClinicalReviews/Index.vue';
import ReviewerFormModal from '@/Components/ClinicalReview/ReviewerFormModal.vue';
import ReviewInvitationFormModal from '@/Components/ClinicalReview/ReviewInvitationFormModal.vue';

/** Ziggy: params posicionais viram segmentos. */
const route = vi.fn(
    (name, params) =>
        `/_routes/${name}${params === undefined ? '' : `/${Array.isArray(params) ? params.join('/') : params}`}`,
);

const global = { stubs: { Teleport: true }, mocks: { route } };

const reviewers = [
    {
        id: 'r-1',
        name: 'Dra. Helena Prado',
        email: 'helena@exemplo.test',
        crm_number: '12345',
        crm_uf: 'SP',
        crm_label: 'CRM 12345/SP',
        rqe: '6789',
        specialty: 'Oftalmologia',
        phone: '11912345678',
        notes: 'Indicada pelo conselho.',
        active: true,
        invitations_count: 2,
    },
    {
        id: 'r-2',
        name: 'Dr. Paulo Lima',
        email: 'paulo@exemplo.test',
        crm_number: '999',
        crm_uf: 'RJ',
        crm_label: 'CRM 999/RJ',
        rqe: '111',
        specialty: 'Oftalmologia',
        phone: null,
        notes: null,
        active: false,
        invitations_count: 0,
    },
];

const invitation = (overrides = {}) => ({
    id: 'inv-1',
    reviewer: { id: 'r-1', name: 'Dra. Helena Prado', crm_label: 'CRM 12345/SP' },
    status: 'pending',
    status_label: 'Aguardando parecer',
    is_expired: false,
    created_at: '2026-10-14T10:00:00Z',
    sent_at: '2026-10-14T10:01:00Z',
    expires_at: '2026-10-28T23:59:00Z',
    last_opened_at: null,
    open_count: 0,
    submitted_at: null,
    pin_required: false,
    last_send_error: null,
    revoked_reason: null,
    can_resend: true,
    can_revoke: true,
    items: [
        {
            id: 'it-1',
            version_id: 'v-1',
            key: 'glaucoma',
            title: 'Glaucoma',
            version: 1,
            short_hash: 'abcdef012345',
            decision: null,
            decision_label: null,
        },
    ],
    ...overrides,
});

const versions = [
    {
        id: 'v-1',
        protocol_key: 'glaucoma',
        protocol_title: 'Glaucoma',
        version: 1,
        short_hash: 'abcdef012345',
        frozen_at: '2026-10-14T09:00:00Z',
    },
    {
        id: 'v-2',
        protocol_key: 'dmri',
        protocol_title: 'DMRI',
        version: 1,
        short_hash: '123456abcdef',
        frozen_at: '2026-10-14T09:05:00Z',
    },
];

const options = { ufs: ['SP', 'RJ', 'MG'], expiry_days: [7, 14, 21, 30], default_expiry: 14 };

let wrapper;

function mountIndex(props = {}) {
    wrapper = mount(Index, {
        props: {
            reviewers,
            invitations: [invitation()],
            reviewableVersions: versions,
            issuedAccessCode: null,
            options,
            t: managerT,
            ...props,
        },
        attachTo: document.body,
        global,
    });

    return wrapper;
}

const byId = (testid) => wrapper.get(`[data-testid="${testid}"]`);

beforeEach(() => {
    resetInertiaMock();
    globalThis.route = route;
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('Manager → Revisões clínicas — a tela', () => {
    it('título, aviso e duas abas com papéis de acessibilidade; só a de convites aparece de início', () => {
        mountIndex();

        expect(wrapper.get('h1').text()).toBe('Revisões clínicas');
        expect(wrapper.text()).toContain('O Revisor Clínico recebe um link pessoal');
        expect(wrapper.findAll('[role="tab"]')).toHaveLength(2);
        expect(byId('tab-invitations').attributes('aria-selected')).toBe('true');
        expect(byId('tab-reviewers').attributes('aria-selected')).toBe('false');
        expect(byId('tab-reviewers').attributes('tabindex')).toBe('-1');
        expect(wrapper.get('#reviews-panel-invitations').isVisible()).toBe(true);
        expect(wrapper.get('#reviews-panel-reviewers').isVisible()).toBe(false);
    });

    it('troca de aba por clique e por setas do teclado', async () => {
        mountIndex();

        await byId('tab-reviewers').trigger('click');
        expect(wrapper.get('#reviews-panel-reviewers').isVisible()).toBe(true);
        expect(wrapper.get('#reviews-panel-invitations').isVisible()).toBe(false);

        await byId('tab-reviewers').trigger('keydown', { key: 'ArrowLeft' });
        expect(byId('tab-invitations').attributes('aria-selected')).toBe('true');
        expect(document.activeElement).toBe(byId('tab-invitations').element);

        await byId('tab-invitations').trigger('keydown', { key: 'ArrowRight' });
        expect(byId('tab-reviewers').attributes('aria-selected')).toBe('true');
    });

    it('convite pendente: Revisor, CRM, versões, situação, envio, prazo, e as duas ações', () => {
        mountIndex({
            invitations: [invitation({ pin_required: true, open_count: 3, last_opened_at: '2026-10-15T08:00:00Z' })],
        });

        const row = byId('invitation-inv-1');

        expect(row.text()).toContain('Dra. Helena Prado');
        expect(row.text()).toContain('CRM 12345/SP');
        expect(row.text()).toContain('Com código de acesso');
        expect(row.text()).toContain('Glaucoma');
        expect(row.text()).toContain('v1');
        expect(row.text()).toContain('Aguardando parecer');
        expect(row.text()).toContain('14/10/2026');
        expect(row.text()).toContain('28/10/2026');
        expect(row.text()).toContain('aberto 3 vezes');
        expect(byId('resend-inv-1').exists()).toBe(true);
        expect(byId('revoke-inv-1').exists()).toBe(true);
    });

    it('e-mail que não saiu: "Não enviado" com o aviso de falha — não finge que enviou', () => {
        mountIndex({ invitations: [invitation({ sent_at: null, last_send_error: 'TransportException: SMTP' })] });

        const row = byId('invitation-inv-1');

        expect(row.text()).toContain('Não enviado');
        expect(row.text()).toContain('O e-mail não saiu');
        // O erro técnico (host, credencial) nunca vai para a tela.
        expect(row.text()).not.toContain('TransportException');
    });

    it('nunca aberto, vencido e revogado (com o motivo)', () => {
        mountIndex({
            invitations: [
                invitation({ id: 'a', is_expired: true, can_resend: false }),
                invitation({
                    id: 'b',
                    status: 'revoked',
                    status_label: 'Revogado',
                    revoked_reason: 'Revisor trocado.',
                    can_resend: false,
                    can_revoke: false,
                }),
            ],
        });

        expect(byId('invitation-a').text()).toContain('Prazo vencido');
        expect(byId('invitation-a').text()).toContain('Nunca aberto');
        expect(wrapper.find('[data-testid="resend-a"]').exists()).toBe(false);
        expect(byId('revoke-a').exists()).toBe(true);
        expect(byId('invitation-b').text()).toContain('Revogado');
        expect(byId('invitation-b').text()).toContain('Revisor trocado.');
        expect(wrapper.find('[data-testid="resend-b"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="revoke-b"]').exists()).toBe(false);
    });

    it('parecer recebido mostra a decisão de cada versão (texto + cor) e fecha as ações', () => {
        mountIndex({
            invitations: [
                invitation({
                    status: 'submitted',
                    status_label: 'Parecer recebido',
                    can_resend: false,
                    can_revoke: false,
                    items: [
                        {
                            id: 'it-1',
                            version_id: 'v-1',
                            key: 'glaucoma',
                            title: 'Glaucoma',
                            version: 1,
                            short_hash: 'a',
                            decision: 'approved',
                            decision_label: 'Aprovo',
                        },
                        {
                            id: 'it-2',
                            version_id: 'v-2',
                            key: 'dmri',
                            title: 'DMRI',
                            version: 1,
                            short_hash: 'b',
                            decision: 'changes_requested',
                            decision_label: 'Aprovo com ajustes',
                        },
                    ],
                }),
            ],
        });

        const row = byId('invitation-inv-1');

        expect(row.text()).toContain('Parecer recebido');
        expect(row.text()).toContain('Aprovo com ajustes');
        expect(row.findAll('.badge.text-bg-success')).toHaveLength(1);
        expect(row.findAll('.badge.text-bg-warning')).toHaveLength(1);
        expect(wrapper.find('[data-testid="resend-inv-1"]').exists()).toBe(false);
    });

    it('lista vazia e sem versão congelada avisam o que fazer', () => {
        mountIndex({ invitations: [], reviewableVersions: [] });

        expect(byId('invitations-empty').text()).toContain('Nenhum convite ainda');
        expect(byId('no-versions').text()).toContain('Congele um rascunho');
    });

    it('novo convite fica desabilitado sem Revisor ativo', () => {
        mountIndex({ reviewers: [reviewers[1]] });

        expect(byId('new-invitation').attributes('disabled')).toBeDefined();
    });

    it('aba de Revisores: CRM, RQE, e-mail, quantidade de convites e situação', async () => {
        mountIndex();
        await byId('tab-reviewers').trigger('click');

        const active = byId('reviewer-r-1');
        const inactive = byId('reviewer-r-2');

        expect(active.text()).toContain('12345/SP');
        expect(active.text()).toContain('6789');
        expect(active.text()).toContain('helena@exemplo.test');
        expect(active.text()).toContain('Ativo');
        expect(inactive.text()).toContain('Inativo');
        expect(byId('toggle-r-1').text()).toBe('Desativar');
        expect(byId('toggle-r-2').text()).toBe('Reativar');
    });
});

describe('Manager → Revisões clínicas — ações', () => {
    it('reenviar pede confirmação, avisa que o link anterior morre e só então envia', async () => {
        mountIndex();

        await byId('resend-inv-1').trigger('click');

        expect(document.body.textContent).toContain('Reenviar o convite?');
        expect(document.body.textContent).toContain('o link anterior deixa de funcionar na hora');
        expect(router.post).not.toHaveBeenCalled();

        await byId('resend-confirm').trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post.mock.calls[0][0]).toBe('/_routes/manager.clinical-reviews.resend/inv-1');
        expect(router.post.mock.calls[0][2]).toMatchObject({ preserveScroll: true });
    });

    it('revogar exige o motivo (mínimo de 10) e o envia junto', async () => {
        mountIndex();

        await byId('revoke-inv-1').trigger('click');

        expect(byId('reason-modal').attributes('data-min')).toBe('10');
        expect(router.post).not.toHaveBeenCalled();

        await byId('reason-confirm').trigger('click');

        expect(router.post.mock.calls[0][0]).toBe('/_routes/manager.clinical-reviews.revoke/inv-1');
        expect(router.post.mock.calls[0][1]).toEqual({ reason: 'Revisor trocado pelo conselho.' });
    });

    it('desativar o Revisor avisa que os convites pendentes serão revogados', async () => {
        mountIndex();
        await byId('tab-reviewers').trigger('click');

        await byId('toggle-r-1').trigger('click');

        expect(document.body.textContent).toContain('Os convites pendentes dele serão revogados');

        await byId('toggle-confirm').trigger('click');

        expect(router.post.mock.calls[0][0]).toBe('/_routes/manager.clinical-reviewers.active/r-1');
        expect(router.post.mock.calls[0][1]).toEqual({ active: false });
    });

    it('reativar o Revisor manda active=true', async () => {
        mountIndex();
        await byId('tab-reviewers').trigger('click');

        await byId('toggle-r-2').trigger('click');
        await byId('toggle-confirm').trigger('click');

        expect(router.post.mock.calls[0][1]).toEqual({ active: true });
    });

    it('código de acesso gerado aparece UMA vez, agrupado, e fecha com "Entendi"', async () => {
        mountIndex({ issuedAccessCode: 'ABCD2345' });
        await nextTick();

        expect(byId('issued-pin').text()).toBe('ABCD 2345');
        expect(document.body.textContent).toContain('este código não será mostrado de novo');

        await byId('issued-pin-close').trigger('click');

        expect(wrapper.find('[data-testid="issued-pin"]').exists()).toBe(false);
    });

    it('sem código gerado, o modal do código nem existe', () => {
        mountIndex();

        expect(wrapper.find('[data-testid="issued-pin"]').exists()).toBe(false);
    });

    it('o código gerado depois de um envio novo abre o modal de novo', async () => {
        mountIndex();

        await wrapper.setProps({ issuedAccessCode: 'WXYZ6789' });
        await nextTick();

        expect(byId('issued-pin').text()).toBe('WXYZ 6789');
    });
});

describe('ReviewerFormModal', () => {
    const mountModal = (props = {}) => {
        wrapper = mount(ReviewerFormModal, {
            props: { open: true, reviewer: null, ufs: options.ufs, t: managerT, ...props },
            attachTo: document.body,
            global,
        });

        return wrapper;
    };

    it('novo Revisor: formulário vazio, envia por POST e fecha quando dá certo', async () => {
        mountModal();

        expect(document.body.textContent).toContain('Novo Revisor Clínico');

        const form = forms[0];
        form.name = 'Dra. Helena Prado';
        form.email = 'helena@exemplo.test';
        form.crm_number = '12.345';
        form.crm_uf = 'SP';
        form.rqe = '6789';

        await wrapper.get('form').trigger('submit');

        expect(form.post).toHaveBeenCalledTimes(1);
        expect(form.lastRequest.url).toBe('/_routes/manager.clinical-reviewers.store');
        expect(form.lastRequest.data).toMatchObject({
            name: 'Dra. Helena Prado',
            crm_number: '12.345',
            crm_uf: 'SP',
            specialty: 'Oftalmologia',
        });

        form.lastRequest.options.onSuccess();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('editar: preenche com o cadastro e envia por PUT para o Revisor certo', async () => {
        mountModal({ reviewer: reviewers[0] });
        await nextTick();

        const form = forms[0];

        expect(document.body.textContent).toContain('Editar Revisor');
        expect(form.name).toBe('Dra. Helena Prado');
        expect(form.crm_uf).toBe('SP');
        expect(form.notes).toBe('Indicada pelo conselho.');

        await wrapper.get('form').trigger('submit');

        expect(form.put).toHaveBeenCalledTimes(1);
        expect(form.lastRequest.url).toBe('/_routes/manager.clinical-reviewers.update/r-1');
    });

    it('erros do servidor aparecem junto de cada campo (CRM repetido, e-mail repetido)', async () => {
        mountModal();
        forms[0].setError('crm_number', 'Já existe um Revisor com este CRM nesta UF.');
        forms[0].setError('email', 'Já existe um Revisor com este e-mail.');
        await nextTick();

        expect(document.body.textContent).toContain('Já existe um Revisor com este CRM nesta UF.');
        expect(document.body.textContent).toContain('Já existe um Revisor com este e-mail.');
        expect(document.querySelector('#rf-crm').classList.contains('is-invalid')).toBe(true);
    });

    it('todo campo tem rótulo associado', async () => {
        mountModal();
        await nextTick();

        for (const control of document.querySelectorAll(
            '#reviewer-form input, #reviewer-form select, #reviewer-form textarea',
        )) {
            expect(
                document.querySelector(`label[for="${control.id}"]`),
                `sem label para #${control.id}`,
            ).not.toBeNull();
        }
    });
});

describe('ReviewInvitationFormModal', () => {
    const mountModal = (props = {}) => {
        wrapper = mount(ReviewInvitationFormModal, {
            props: {
                open: true,
                reviewers,
                versions,
                expiryDays: options.expiry_days,
                defaultExpiry: 14,
                t: managerT,
                ...props,
            },
            attachTo: document.body,
            global,
        });

        return wrapper;
    };

    it('só Revisores ativos são oferecidos, e o botão só habilita com Revisor e ao menos uma versão', async () => {
        mountModal();
        await nextTick();

        const select = document.querySelector('#if-reviewer');

        expect(Array.from(select.options).map((option) => option.textContent.trim())).toEqual([
            'Selecione',
            'Dra. Helena Prado — CRM 12345/SP',
        ]);
        expect(byId('invitation-send').attributes('disabled')).toBeDefined();

        forms[0].reviewer_id = 'r-1';
        await nextTick();
        expect(byId('invitation-send').attributes('disabled')).toBeDefined();

        forms[0].version_ids = ['v-1'];
        await nextTick();
        expect(byId('invitation-send').attributes('disabled')).toBeUndefined();
    });

    it('envia os campos que o servidor espera (PIN gerado → generate_access_code, sem access_code)', async () => {
        mountModal();
        const form = forms[0];

        form.reviewer_id = 'r-1';
        form.version_ids = ['v-1', 'v-2'];
        form.expires_in_days = 7;
        form.pin_mode = 'generate';
        await nextTick();
        await wrapper.get('form').trigger('submit');

        expect(form.post).toHaveBeenCalledTimes(1);
        expect(form.lastRequest.url).toBe('/_routes/manager.clinical-reviews.store');
        expect(form.lastRequest.data).toEqual({
            reviewer_id: 'r-1',
            version_ids: ['v-1', 'v-2'],
            expires_in_days: 7,
            generate_access_code: true,
            access_code: null,
        });
    });

    it('PIN digitado vai em access_code; sem PIN, nada', async () => {
        mountModal();
        const form = forms[0];

        form.reviewer_id = 'r-1';
        form.version_ids = ['v-1'];
        form.pin_mode = 'custom';
        form.access_code = 'abc 234 xyz';
        await nextTick();
        await wrapper.get('form').trigger('submit');

        expect(form.lastRequest.data).toMatchObject({ generate_access_code: false, access_code: 'abc 234 xyz' });

        form.pin_mode = 'none';
        form.access_code = 'sobrou';
        await nextTick();
        await wrapper.get('form').trigger('submit');

        expect(form.lastRequest.data).toMatchObject({ generate_access_code: false, access_code: null });
    });

    it('PIN digitado vazio não habilita o envio', async () => {
        mountModal();
        const form = forms[0];

        form.reviewer_id = 'r-1';
        form.version_ids = ['v-1'];
        form.pin_mode = 'custom';
        await nextTick();

        expect(byId('invitation-send').attributes('disabled')).toBeDefined();
    });

    it('fecha quando dá certo, mas continua aberto se o servidor recusou por regra de negócio (aviso de erro)', async () => {
        mountModal();
        const form = forms[0];

        form.reviewer_id = 'r-1';
        form.version_ids = ['v-1'];
        await nextTick();
        await wrapper.get('form').trigger('submit');

        page.props.flash = { error: 'Já existe um convite pendente deste Revisor para o mesmo conjunto de versões.' };
        form.lastRequest.options.onSuccess();
        expect(wrapper.emitted('close')).toBeUndefined();

        page.props.flash = { success: 'Convite enviado.' };
        form.lastRequest.options.onSuccess();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('sem versão congelada avisa e não deixa enviar', async () => {
        mountModal({ versions: [] });
        await nextTick();

        expect(byId('no-versions').text()).toContain('Congele um rascunho');
        expect(byId('invitation-send').attributes('disabled')).toBeDefined();
    });

    it('abrir de novo limpa o formulário', async () => {
        mountModal({ open: false });
        const form = forms[0];

        form.reviewer_id = 'r-1';
        form.version_ids = ['v-1'];
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(form.reviewer_id).toBe('');
        expect(form.version_ids).toEqual([]);
        expect(form.expires_in_days).toBe(14);
    });
});
