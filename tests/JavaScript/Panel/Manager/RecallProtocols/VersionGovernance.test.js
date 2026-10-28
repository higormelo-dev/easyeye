import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock());
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div class="layout"><h1>{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/composables/useUnsavedChangesGuard.js', () => ({
    useUnsavedChangesGuard: () => ({ bypass: (fn) => fn() }),
}));
// O modal de motivo é testado à parte; aqui importa o contrato (tamanho mínimo, texto, confirmar com motivo).
vi.mock('@/Components/Panel/ConfirmationWithReasonModal.vue', () => ({
    default: {
        props: ['open', 'title', 'message', 'confirmLabel', 'minLength', 'error'],
        emits: ['close', 'confirm'],
        template:
            '<div v-if="open" data-testid="reason-modal" :data-min="minLength"><h6>{{ title }}</h6><p>{{ message }}</p><small v-if="error" data-testid="reason-error">{{ error }}</small><button data-testid="reason-confirm" @click="$emit(\'confirm\', \'Motivo escrito com mais de vinte caracteres.\')">{{ confirmLabel }}</button></div>',
    },
}));

import { page, router, resetInertiaMock } from './support/inertiaMock.js';
import Version from '@/Pages/Panel/Manager/RecallProtocols/Version.vue';
import { baseContent, exampleRows, options, ruleT } from './support/fixtures.js';
import { recallT } from '../../../ClinicalReview/support/texts.js';

const route = vi.fn(
    (name, params) =>
        `/_routes/${name}${params === undefined ? '' : `/${Array.isArray(params) ? params.join('/') : params}`}`,
);

const global = {
    stubs: { Teleport: true, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } },
    mocks: { route },
};

const summary = (overrides = {}) => ({
    id: 'v-1',
    version: 1,
    state: 'validated',
    state_label: 'Validada',
    short_hash: 'abcdef012345',
    content_hash: '1:' + 'abcdef012345'.padEnd(64, '0'),
    frozen_at: '2026-10-13T12:00:00Z',
    validated_at: '2026-10-14T09:00:00Z',
    released_at: null,
    suspended_at: null,
    retired_at: null,
    review_due_at: null,
    created_at: '2026-10-13T12:00:00Z',
    ...overrides,
});

const noActions = { release: false, suspend: false, resume: false, retire: false, withdraw: false };

function props({ version = {}, actions = {}, marks = {}, ...rest } = {}) {
    return {
        protocol: { id: 'p-1', key: 'glaucoma', title: 'Glaucoma' },
        version: {
            ...summary(),
            editable: false,
            lock_version: 3,
            integrity_ok: true,
            content_error: false,
            parent: null,
            actions: { ...noActions, ...actions },
            marks: {
                frozen_by: 'GESTOR',
                released_by: null,
                suspended_by: null,
                retired_by: null,
                review_due_at: null,
                review_due_status: null,
                ...marks,
            },
            ...version,
        },
        content: baseContent(),
        canonical: '{"a":1}',
        diff: [],
        examples: exampleRows(),
        exampleDate: '2026-01-15',
        validation: { invitations: [] },
        currentReleased: null,
        events: [],
        options,
        t: recallT,
        ruleT,
        ...rest,
    };
}

let wrapper;
const mountVersion = (overrides) => {
    wrapper = mount(Version, { props: props(overrides), global, attachTo: document.body });

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

describe('Version — botões de governança por estado', () => {
    it.each([
        [
            'em revisão',
            { state: 'in_review', state_label: 'Em revisão' },
            { withdraw: true, retire: true },
            ['send-to-review', 'withdraw', 'retire'],
        ],
        ['validada', {}, { release: true, withdraw: true, retire: true }, ['release', 'withdraw', 'retire']],
        [
            'liberada',
            { state: 'released', state_label: 'Liberada' },
            { suspend: true, retire: true },
            ['suspend', 'retire'],
        ],
        [
            'suspensa',
            { state: 'suspended', state_label: 'Suspensa' },
            { resume: true, retire: true },
            ['resume', 'retire'],
        ],
        ['aposentada', { state: 'retired', state_label: 'Aposentada' }, {}, []],
    ])('%s', (_name, version, actions, expected) => {
        mountVersion({ version, actions });

        const present = ['send-to-review', 'release', 'resume', 'suspend', 'withdraw', 'retire'].filter((id) =>
            wrapper.find(`[data-testid="${id}"]`).exists(),
        );

        expect(present).toEqual(expected);
    });

    it('o rascunho não tem governança (só salvar e congelar)', () => {
        mountVersion({ version: { state: 'draft', state_label: 'Rascunho', editable: true } });

        for (const id of ['release', 'resume', 'suspend', 'withdraw', 'retire', 'send-to-review']) {
            expect(wrapper.find(`[data-testid="${id}"]`).exists()).toBe(false);
        }
        expect(wrapper.find('[data-testid="freeze"]').exists()).toBe(true);
    });
});

describe('Version — liberar e retomar (confirmação simples)', () => {
    it('liberar: o modal explica, mostra que nenhuma outra está liberada e só envia ao confirmar', async () => {
        mountVersion({ actions: { release: true } });

        await byId('release').trigger('click');

        expect(document.body.textContent).toContain('Liberar esta versão?');
        expect(byId('release-note').text()).toBe('Nenhuma outra versão está liberada.');
        expect(router.post).not.toHaveBeenCalled();

        await byId('release-confirm').trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post.mock.calls[0][0]).toBe('/_routes/manager.recall-protocols.versions.release/p-1/v-1');
        expect(router.post.mock.calls[0][1]).toEqual({});
        expect(router.post.mock.calls[0][2]).toMatchObject({ preserveScroll: true });
    });

    it('liberar com outra versão no ar: avisa qual passa a "Substituída"', async () => {
        mountVersion({ actions: { release: true }, currentReleased: { version: 3 } });

        await byId('release').trigger('click');

        expect(byId('release-note').text()).toContain('A versão 3, hoje liberada, passa a "Substituída"');
    });

    it('depois de enviar, o botão trava (sem liberar duas vezes)', async () => {
        mountVersion({ actions: { release: true } });

        await byId('release').trigger('click');
        await byId('release-confirm').trigger('click');
        await nextTick();

        expect(byId('release-confirm').attributes('disabled')).toBeDefined();

        await byId('release-confirm').trigger('click');
        expect(router.post).toHaveBeenCalledTimes(1);

        router.post.mock.calls[0][2].onFinish();
        await nextTick();
        expect(wrapper.find('[data-testid="release-confirm"]').exists()).toBe(false);
    });

    it('retomar pede confirmação e envia sem corpo', async () => {
        mountVersion({ version: { state: 'suspended', state_label: 'Suspensa' }, actions: { resume: true } });

        await byId('resume').trigger('click');
        expect(document.body.textContent).toContain('Retomar esta versão?');

        await byId('resume-confirm').trigger('click');

        expect(router.post.mock.calls[0][0]).toBe('/_routes/manager.recall-protocols.versions.resume/p-1/v-1');
        expect(router.post.mock.calls[0][1]).toEqual({});
    });

    it('cancelar fecha sem enviar nada', async () => {
        mountVersion({ actions: { release: true } });

        await byId('release').trigger('click');
        await wrapper.find('.ee-modal__footer .btn-light').trigger('click');

        expect(router.post).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="release-confirm"]').exists()).toBe(false);
    });
});

describe('Version — suspender, aposentar e retirar (exigem o motivo)', () => {
    it.each([
        ['suspend', 'suspend', '20', 'Suspender esta versão?', 'sai de uso NA HORA'],
        ['retire', 'retire', '20', 'Aposentar esta versão?', 'definitivo'],
        ['withdraw', 'withdraw', '10', 'Retirar esta versão da revisão?', 'antes de liberá-la'],
    ])('%s: modal com o mínimo de caracteres e o motivo vai no POST', async (testid, action, min, title, body) => {
        mountVersion({ actions: { [action]: true } });

        await byId(testid).trigger('click');

        expect(byId('reason-modal').attributes('data-min')).toBe(min);
        expect(byId('reason-modal').text()).toContain(title);
        expect(byId('reason-modal').text()).toContain(body);

        await byId('reason-confirm').trigger('click');

        expect(router.post.mock.calls[0][0]).toBe(`/_routes/manager.recall-protocols.versions.${action}/p-1/v-1`);
        expect(router.post.mock.calls[0][1]).toEqual({ reason: 'Motivo escrito com mais de vinte caracteres.' });
    });

    it('só o modal da ação escolhida abre', async () => {
        mountVersion({ actions: { suspend: true, retire: true } });

        await byId('suspend').trigger('click');

        expect(wrapper.findAll('[data-testid="reason-modal"]')).toHaveLength(1);
        expect(byId('reason-modal').text()).toContain('Suspender esta versão?');
    });

    it('erro de motivo do servidor aparece no modal', async () => {
        page.props.errors = { reason: 'Explique o motivo (ao menos 20 caracteres).' };
        mountVersion({ actions: { suspend: true } });

        await byId('suspend').trigger('click');

        expect(byId('reason-error').text()).toContain('Explique o motivo');
    });
});

describe('Version — avisos por estado', () => {
    it('em revisão: orienta convidar o Revisor e leva às Revisões clínicas', () => {
        mountVersion({ version: { state: 'in_review', state_label: 'Em revisão' } });

        expect(byId('banner-in-review').text()).toContain('Convide um Revisor Clínico');
        expect(byId('banner-in-review').get('a').attributes('href')).toBe('/_routes/manager.clinical-reviews.index');
    });

    it('validada: diz o que falta (liberar)', () => {
        mountVersion();

        expect(byId('banner-validated').text()).toContain('Falta liberar para as clínicas');
    });

    it('suspensa: alerta com o motivo, quem, quando e o estado de antes', () => {
        mountVersion({
            version: { state: 'suspended', state_label: 'Suspensa', suspended_at: '2026-10-15T10:00:00Z' },
            marks: {
                suspended_reason: 'Intervalo da campimetria sob revisão.',
                suspended_by: 'GESTOR',
                suspended_from_label: 'Liberada',
            },
        });

        const banner = byId('banner-suspended');

        expect(banner.attributes('role')).toBe('alert');
        expect(banner.text()).toContain('SUSPENSA');
        expect(banner.text()).toContain('Motivo: Intervalo da campimetria sob revisão.');
        expect(banner.text()).toContain('por GESTOR em 15/10/2026');
        expect(banner.text()).toContain('Antes da suspensão: Liberada.');
    });

    it('aposentada, retirada e recusada têm o seu aviso', () => {
        mountVersion({
            version: { state: 'retired', state_label: 'Aposentada', retired_at: '2026-10-15T10:00:00Z' },
            marks: { retired_reason: 'Fora de uso.', retired_by: 'GESTOR' },
        });
        expect(byId('banner-retired').text()).toContain('APOSENTADA');
        expect(byId('banner-retired').text()).toContain('Motivo: Fora de uso.');
        wrapper.unmount();

        mountVersion({ version: { state: 'withdrawn', state_label: 'Retirada' } });
        expect(byId('banner-withdrawn').text()).toContain('retirada da revisão');
        wrapper.unmount();

        mountVersion({ version: { state: 'declined', state_label: 'Recusada' } });
        expect(byId('banner-declined').text()).toContain('Abra uma nova versão');
    });

    it('revalidação perto de vencer ou vencida avisa com a data', () => {
        mountVersion({
            version: { state: 'released', state_label: 'Liberada' },
            marks: { review_due_at: '2026-12-01', review_due_status: 'soon' },
        });
        expect(byId('banner-review-due').text()).toContain('vence em 01/12/2026');
        wrapper.unmount();

        mountVersion({
            version: { state: 'released', state_label: 'Liberada' },
            marks: { review_due_at: '2026-09-01', review_due_status: 'overdue' },
        });
        expect(byId('banner-review-due').text()).toContain('venceu em 01/09/2026');
        wrapper.unmount();

        mountVersion({
            version: { state: 'released', state_label: 'Liberada' },
            marks: { review_due_at: '2028-09-01', review_due_status: 'ok' },
        });
        expect(wrapper.find('[data-testid="banner-review-due"]').exists()).toBe(false);
    });
});

describe('Version — aba Validação (quem validou, quando e qual hash)', () => {
    const invitation = (overrides = {}) => ({
        id: 'inv-1',
        reviewer: 'Dra. Helena Prado',
        status: 'accepted',
        status_label: 'Aceito',
        sent_at: '2026-10-14T10:00:00Z',
        submitted_at: '2026-10-14T13:40:00Z',
        decision: 'approved',
        decision_label: 'Aprovo',
        comment: 'Concordo com os intervalos <i>propostos</i>.',
        proposed_changes: null,
        hash_matches: true,
        is_validating: true,
        attestation: {
            name: 'Dra. Helena Prado',
            crm_number: '12345',
            crm_uf: 'SP',
            rqe: '6789',
            conflict_of_interest: false,
            allow_identity_display: true,
            signed_at: '2026-10-14T13:40:00Z',
            submission_hash: '1:' + 'ab'.repeat(32),
        },
        acceptance: {
            decision: 'accepted',
            decision_label: 'Identidade conferida',
            accepted_by: 'GESTOR ADMIN',
            checked_at: '2026-10-15T09:00:00Z',
            crm_checked: true,
            rqe_checked: true,
            note: 'Conferido no portal.',
        },
        ...overrides,
    });

    const openTab = async () => {
        await byId('tab-validation').trigger('click');
    };

    it('sem convite: avisa e leva às Revisões clínicas', async () => {
        mountVersion();
        await openTab();

        expect(byId('validation-empty').text()).toContain('Nenhum convite de revisão cita esta versão');
        expect(byId('validation-empty').get('a').attributes('href')).toBe('/_routes/manager.clinical-reviews.index');
    });

    it('mostra a decisão, o declarado, o código do envio e o aceite do CFM — e o selo de quem VALIDA a versão', async () => {
        mountVersion({ validation: { invitations: [invitation()] } });
        await openTab();

        const card = byId('validation-inv-1');

        expect(card.text()).toContain('Dra. Helena Prado');
        expect(card.text()).toContain('Aceito');
        expect(card.get('[data-testid="validating-badge"]').text()).toBe('Valida esta versão');
        expect(card.text()).toContain('Aprovo');
        // Texto do Revisor como TEXTO.
        expect(card.text()).toContain('Concordo com os intervalos <i>propostos</i>.');
        expect(card.find('i').exists()).toBe(false);
        expect(card.text()).toContain('12345/SP');
        expect(card.text()).toContain('6789');
        expect(card.text()).toContain('Nenhum');
        expect(card.text()).toContain('Autorizada');
        expect(card.text()).toContain('14/10/2026');
        expect(card.text()).toContain('1:' + 'ab'.repeat(32));
        expect(byId('acceptance-inv-1').text()).toContain('Identidade conferida');
        expect(byId('acceptance-inv-1').text()).toContain('Conferido por GESTOR ADMIN em 15/10/2026');
        expect(byId('acceptance-inv-1').text()).toContain('CRM/UF e RQE conferidos');
        expect(byId('acceptance-inv-1').text()).toContain('Nota: Conferido no portal.');
    });

    it('parecer dado sobre OUTRO conteúdo (hash diferente) é sinalizado', async () => {
        mountVersion({ validation: { invitations: [invitation({ hash_matches: false, is_validating: false })] } });
        await openTab();

        expect(byId('hash-differs').attributes('role')).toBe('alert');
        expect(wrapper.find('[data-testid="validating-badge"]').exists()).toBe(false);
    });

    it('convite sem aceite ainda mostra que falta a conferência', async () => {
        mountVersion({
            validation: {
                invitations: [
                    invitation({
                        acceptance: null,
                        is_validating: false,
                        status: 'submitted',
                        status_label: 'Parecer recebido',
                    }),
                ],
            },
        });
        await openTab();

        expect(byId('acceptance-inv-1').text()).toBe('Ainda sem conferência no CFM.');
    });

    it('convite ainda sem resposta: "ainda sem decisão" e sem declarado', async () => {
        mountVersion({
            validation: {
                invitations: [
                    invitation({
                        decision: null,
                        decision_label: null,
                        comment: null,
                        attestation: null,
                        acceptance: null,
                        is_validating: false,
                        status: 'pending',
                        status_label: 'Aguardando parecer',
                    }),
                ],
            },
        });
        await openTab();

        expect(byId('validation-inv-1').text()).toContain('ainda sem decisão');
        expect(byId('validation-inv-1').text()).not.toContain('Declarou');
    });
});

describe('Version — marcos com quem agiu', () => {
    it('a aba Integridade mostra "por Fulano" nos marcos', async () => {
        mountVersion({
            version: {
                state: 'released',
                state_label: 'Liberada',
                released_at: '2026-10-15T10:00:00Z',
                review_due_at: '2028-10-15',
            },
            marks: {
                frozen_by: 'GESTOR A',
                released_by: 'GESTOR B',
                review_due_at: '2028-10-15',
                review_due_status: 'ok',
            },
        });
        await byId('tab-integrity').trigger('click');

        const text = wrapper.get('#version-panel-integrity').text();

        expect(text).toContain('Congelada em');
        expect(text).toContain('GESTOR A');
        expect(text).toContain('Liberada em');
        expect(text).toContain('GESTOR B');
        expect(text).toContain('Revalidar até');
    });
});
