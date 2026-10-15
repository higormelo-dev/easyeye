import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock());
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div class="layout"><h1>{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/Components/Panel/ConfirmationWithReasonModal.vue', () => ({
    default: { props: ['open'], template: '<div v-if="open" data-testid="reason-modal" />' },
}));

import { forms, page, resetInertiaMock } from './support/inertiaMock.js';
import { managerT } from './support/texts.js';
import ReviewOpinionModal from '@/Components/ClinicalReview/ReviewOpinionModal.vue';
import Index from '@/Pages/Panel/Manager/ClinicalReviews/Index.vue';

const route = vi.fn(
    (name, params) =>
        `/_routes/${name}${params === undefined ? '' : `/${Array.isArray(params) ? params.join('/') : params}`}`,
);
const global = {
    stubs: { Teleport: true, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } },
    mocks: { route },
};

const attestation = (overrides = {}) => ({
    name: 'Dra. Helena Prado',
    email: 'helena@exemplo.test',
    crm_number: '12345',
    crm_uf: 'SP',
    rqe: '6789',
    specialty: 'Oftalmologia',
    conflict_of_interest: false,
    conflict_description: null,
    allow_identity_display: true,
    signed_at: '2026-10-14T13:40:00Z',
    ip: '203.0.113.7',
    user_agent: 'Mozilla/5.0 (teste)',
    submission_hash: '1:' + 'ab'.repeat(32),
    statement_sha256: 'a'.repeat(64),
    receipt_sent: true,
    identity_matches: true,
    identity_mismatch: [],
    registered: {
        name: 'Dra. Helena Prado',
        email: 'helena@exemplo.test',
        crm_number: '12345',
        crm_uf: 'SP',
        rqe: '6789',
    },
    ...overrides,
});

const invitation = (overrides = {}) => ({
    id: 'inv-1',
    reviewer: { id: 'r-1', name: 'Dra. Helena Prado', crm_label: 'CRM 12345/SP' },
    status: 'submitted',
    status_label: 'Parecer recebido',
    can_accept: true,
    attestation: attestation(),
    acceptance: null,
    items: [
        {
            id: 'it-1',
            version_id: 'v-1',
            protocol_id: 'p-1',
            key: 'glaucoma',
            title: 'Glaucoma',
            version: 1,
            short_hash: 'abcdef012345',
            decision: 'changes_requested',
            decision_label: 'Aprovo com ajustes',
            comment: 'Reduzir a campimetria <b>para 9 meses</b>.',
            proposed_changes: 'Campimetria a cada 9 meses.',
        },
        {
            id: 'it-2',
            version_id: 'v-2',
            protocol_id: 'p-2',
            key: 'dmri',
            title: 'DMRI',
            version: 1,
            short_hash: '123456abcdef',
            decision: 'approved',
            decision_label: 'Aprovo',
            comment: null,
            proposed_changes: null,
        },
    ],
    ...overrides,
});

let wrapper;

const mountModal = (props = {}) => {
    wrapper = mount(ReviewOpinionModal, {
        props: { open: true, invitation: invitation(), t: managerT, ...props },
        attachTo: document.body,
        global,
    });

    return wrapper;
};

const byId = (testid) => wrapper.get(`[data-testid="${testid}"]`);

beforeEach(() => {
    resetInertiaMock();
    globalThis.route = route;
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('ReviewOpinionModal — o que o Revisor declarou', () => {
    it('mostra o declarado, a prova do envio e o cadastro lado a lado', () => {
        mountModal();

        const text = byId('opinion-identity').text();

        expect(document.body.textContent).toContain('Conferir o parecer');
        expect(text).toContain('Dra. Helena Prado');
        expect(text).toContain('12345 / SP');
        expect(text).toContain('6789');
        expect(text).toContain('Nenhum');
        expect(text).toContain('Autorizado');
        expect(text).toContain('203.0.113.7');
        expect(text).toContain('Mozilla/5.0 (teste)');
        expect(text).toContain('Cadastro na EasyEye');
        expect(wrapper.find('[data-testid="mismatch-banner"]').exists()).toBe(false);
    });

    it('divergência com o cadastro: aviso com os campos e o que conferir no CFM', () => {
        mountModal({
            invitation: invitation({
                attestation: attestation({
                    crm_number: '777',
                    rqe: '12',
                    identity_matches: false,
                    identity_mismatch: ['crm_number', 'rqe'],
                }),
            }),
        });

        const banner = byId('mismatch-banner');

        expect(banner.attributes('role')).toBe('alert');
        expect(banner.text()).toContain('CRM, RQE');
        expect(banner.text()).toContain('se confere o DECLARADO');
    });

    it('conflito de interesses declarado aparece com a descrição', () => {
        mountModal({
            invitation: invitation({
                attestation: attestation({
                    conflict_of_interest: true,
                    conflict_description: 'Palestrante de um fabricante.',
                }),
            }),
        });

        expect(byId('opinion-identity').text()).toContain('Declarado');
        expect(byId('opinion-identity').text()).toContain('Palestrante de um fabricante.');
    });

    it('as decisões trazem a justificativa como TEXTO (HTML digitado não vira marcação) e o link da versão', () => {
        mountModal();

        const item = byId('opinion-glaucoma');

        expect(item.text()).toContain('Aprovo com ajustes');
        expect(item.text()).toContain('Reduzir a campimetria <b>para 9 meses</b>.');
        expect(item.find('b').exists()).toBe(false);
        expect(item.text()).toContain('Campimetria a cada 9 meses.');
        expect(item.get('a').attributes('href')).toBe('/_routes/manager.recall-protocols.versions.show/p-1/v-1');
        expect(byId('opinion-dmri').text()).not.toContain('Justificativa');
    });
});

describe('ReviewOpinionModal — o aceite', () => {
    it('Aceitar envia a decisão e o que foi conferido, e a nota; fecha quando dá certo', async () => {
        mountModal();

        await byId('crm-checked').setValue(true);
        await byId('rqe-checked').setValue(true);
        await byId('acceptance-note').setValue('Conferido no portal.');
        await byId('accept').trigger('click');

        const form = forms[0];

        expect(form.post).toHaveBeenCalledTimes(1);
        expect(form.lastRequest.url).toBe('/_routes/manager.clinical-reviews.acceptance/inv-1');
        expect(form.lastRequest.data).toEqual({
            decision: 'accepted',
            crm_checked: true,
            rqe_checked: true,
            note: 'Conferido no portal.',
        });

        form.lastRequest.options.onSuccess();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('Recusar envia decision=refused (com as conferências como estão)', async () => {
        mountModal();

        await byId('acceptance-note').setValue('O CRM não consta no portal.');
        await byId('refuse').trigger('click');

        expect(forms[0].lastRequest.data).toEqual({
            decision: 'refused',
            crm_checked: false,
            rqe_checked: false,
            note: 'O CRM não consta no portal.',
        });
    });

    it('erro do servidor aparece no campo (conferência, nota) e o modal continua aberto', async () => {
        mountModal();

        await byId('accept').trigger('click');
        forms[0].setError('crm_checked', 'Aceitar exige conferir o CRM/UF e o RQE no portal do CFM.');
        forms[0].setError('note', 'Explique o motivo (ao menos 10 caracteres).');
        await nextTick();

        expect(byId('error-checks').attributes('role')).toBe('alert');
        expect(byId('error-checks').text()).toContain('Aceitar exige conferir');
        expect(byId('error-note').text()).toContain('Explique o motivo');
        expect(byId('acceptance-note').classes()).toContain('is-invalid');
    });

    it('aviso de negócio (já conferido por outra pessoa) mantém o modal aberto', async () => {
        mountModal();

        await byId('accept').trigger('click');
        page.props.flash = { error: 'Este parecer não está mais aguardando conferência. Atualize a página.' };
        forms[0].lastRequest.options.onSuccess();

        expect(wrapper.emitted('close')).toBeUndefined();
    });

    it('os botões travam enquanto envia (sem duplo envio)', async () => {
        mountModal();
        forms[0].processing = true;
        await nextTick();

        expect(byId('accept').attributes('disabled')).toBeDefined();
        expect(byId('refuse').attributes('disabled')).toBeDefined();

        await byId('accept').trigger('click');
        expect(forms[0].post).not.toHaveBeenCalled();
    });

    it('as duas conferências têm rótulo e a nota explica quando é obrigatória', () => {
        mountModal();

        expect(wrapper.find('label[for="ac-crm"]').exists()).toBe(true);
        expect(wrapper.find('label[for="ac-rqe"]').exists()).toBe(true);
        expect(wrapper.find('label[for="ac-note"]').exists()).toBe(true);
        expect(byId('acceptance-form').text()).toContain('Obrigatória ao recusar');
        expect(byId('acceptance-form').text()).toContain('Sem as duas conferências não há aceite');
    });
});

describe('ReviewOpinionModal — já conferido (somente leitura)', () => {
    const accepted = () =>
        invitation({
            status: 'accepted',
            can_accept: false,
            acceptance: {
                decision: 'accepted',
                decision_label: 'Identidade conferida',
                accepted_by: 'GESTOR ADMIN',
                method: 'cfm_portal',
                crm_checked: true,
                rqe_checked: true,
                checked_at: '2026-10-15T09:00:00Z',
                note: 'Conferido no portal.',
            },
        });

    it('mostra o resultado (quem, quando, o que foi conferido, a nota) e nenhum formulário nem botão de aceite', () => {
        mountModal({ invitation: accepted() });

        const result = byId('opinion-result');

        expect(document.body.textContent).toContain('Parecer do Revisor');
        expect(result.text()).toContain('Identidade conferida');
        expect(result.text()).toContain('GESTOR ADMIN');
        expect(result.text()).toContain('15/10/2026');
        expect(result.text()).toContain('Portal do CFM');
        expect(result.text()).toContain('CRM/UF conferidos');
        expect(result.text()).toContain('RQE conferido');
        expect(result.text()).toContain('Conferido no portal.');
        expect(wrapper.find('[data-testid="acceptance-form"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="accept"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="refuse"]').exists()).toBe(false);
    });

    it('aceite recusado mostra o que NÃO foi conferido', () => {
        const refused = accepted();
        refused.acceptance = {
            ...refused.acceptance,
            decision: 'refused',
            decision_label: 'Identidade não conferida',
            rqe_checked: false,
            note: 'RQE não consta.',
        };
        mountModal({ invitation: refused });

        expect(byId('opinion-result').text()).toContain('Identidade não conferida');
        expect(byId('opinion-result').text()).toContain('RQE: não conferido');
    });
});

describe('Manager → Revisões clínicas — abrir o parecer', () => {
    const indexProps = (rows) => ({
        reviewers: [],
        invitations: rows,
        reviewableVersions: [],
        issuedAccessCode: null,
        options: { ufs: ['SP'], expiry_days: [14], default_expiry: 14 },
        t: managerT,
    });

    const mountIndex = (rows) => {
        wrapper = mount(Index, { props: indexProps(rows), attachTo: document.body, global });

        return wrapper;
    };

    it('convite respondido: botão "Conferir" abre o modal; depois do aceite o botão vira "Ver parecer"', async () => {
        mountIndex([invitation()]);

        expect(byId('opinion-inv-1').text()).toBe('Conferir');
        expect(wrapper.find('[data-testid="opinion-modal"]').exists()).toBe(false);

        await byId('opinion-inv-1').trigger('click');
        expect(byId('opinion-modal').text()).toContain('Decisões do Revisor');

        // O servidor devolveu a linha com o aceite (a página recarregou): o modal mostra o resultado.
        await wrapper.setProps({
            invitations: [
                invitation({
                    status: 'accepted',
                    can_accept: false,
                    acceptance: {
                        decision: 'accepted',
                        decision_label: 'Identidade conferida',
                        accepted_by: 'GESTOR',
                        method: 'cfm_portal',
                        crm_checked: true,
                        rqe_checked: true,
                        checked_at: '2026-10-15T09:00:00Z',
                        note: null,
                    },
                }),
            ],
        });

        expect(byId('opinion-result').exists()).toBe(true);
        expect(byId('opinion-inv-1').text()).toBe('Ver parecer');
    });

    it('convite sem atestado (pendente) não tem o botão', () => {
        mountIndex([invitation({ attestation: null, can_accept: false, status: 'pending' })]);

        expect(wrapper.find('[data-testid="opinion-inv-1"]').exists()).toBe(false);
    });
});
