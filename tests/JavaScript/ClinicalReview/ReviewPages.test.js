import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock());
vi.mock('axios', () => ({ default: { put: vi.fn(), get: vi.fn() } }));

import axios from 'axios';
import { forms, page, resetInertiaMock, router } from './support/inertiaMock.js';
import { publicT } from './support/texts.js';
import { baseContent, exampleRows, ruleT } from '../Panel/Manager/RecallProtocols/support/fixtures.js';
import Show from '@/Pages/ClinicalReview/Show.vue';
import Done from '@/Pages/ClinicalReview/Done.vue';
import Unavailable from '@/Pages/ClinicalReview/Unavailable.vue';
import AccessCode from '@/Pages/ClinicalReview/AccessCode.vue';
import { resetFocusHeading } from '@/Support/clinicalReviewFocus.js';

const urls = {
    draft: '/revisao-clinica/rascunho',
    keepalive: '/revisao-clinica/sessao',
    submit: '/revisao-clinica',
    locale: '/revisao-clinica/idioma',
};

const decisions = [
    { value: 'approved', label: 'Aprovo', requires_comment: false },
    { value: 'changes_requested', label: 'Aprovo com ajustes', requires_comment: true },
    { value: 'rejected', label: 'Não aprovo', requires_comment: true },
];

const item = (overrides = {}) => ({
    id: 'item-1',
    key: 'glaucoma',
    title: 'Glaucoma',
    summary: 'Consulta e campimetria.',
    version: 1,
    short_hash: 'abcdef012345',
    available: true,
    content: baseContent(),
    parent: null,
    diff: [],
    examples: exampleRows(),
    ...overrides,
});

function showProps(overrides = {}) {
    return {
        reviewer: { name: 'DRA. ANA PAULA SOUZA' },
        invitation: { expires_at: '2026-10-28T23:59:00-03:00', sent_at: '2026-10-14T10:00:00-03:00' },
        items: [item(), item({ id: 'item-2', key: 'dmri', title: 'DMRI', summary: '' })],
        available: true,
        exampleDate: '2026-10-14',
        statement: {
            term_version_id: 'tv-1',
            version: '1.0',
            sha256: 'a'.repeat(64),
            text: 'DECLARAÇÃO DO REVISOR CLÍNICO\nDeclaro que...',
            is_translation: false,
        },
        draft: null,
        draftSavedAt: null,
        options: { ufs: ['SP', 'RJ', 'MG'], decisions },
        t: publicT,
        ruleT,
        urls,
        ...overrides,
    };
}

let wrapper;

function mountShow(overrides = {}) {
    wrapper = mount(Show, {
        props: showProps(overrides),
        attachTo: document.body,
        global: { stubs: { Teleport: true } },
    });

    return wrapper;
}

const byId = (testid) => wrapper.get(`[data-testid="${testid}"]`);

/** Preenche tudo o que o formulário exige (os dois itens aprovados). */
async function fillValid({ agree = true } = {}) {
    await byId('decision-glaucoma-approved').setValue();
    await byId('decision-dmri-approved').setValue();
    await byId('attestation-name').setValue('Dra. Helena Prado');
    await byId('attestation-email').setValue('helena@exemplo.test');
    await byId('attestation-crm-number').setValue('12.345');
    await byId('attestation-crm-uf').setValue('SP');
    await byId('attestation-rqe').setValue('6789');
    await byId('conflict-no').setValue();
    await byId('display-yes').setValue();
    if (agree) await byId('attestation-agreed').setValue(true);
}

beforeEach(() => {
    resetInertiaMock();
    resetFocusHeading();
    axios.put.mockReset();
    axios.get.mockReset();
    axios.get.mockResolvedValue({ status: 204 });
    axios.put.mockResolvedValue({ data: { saved_at: '2026-10-14T13:32:00-03:00' } });
    globalThis.route = vi.fn((name) => `/_routes/${name}`);
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('Show — a página', () => {
    it('mostra a saudação, o prazo, o aviso de privacidade, uma regra por versão e a declaração', () => {
        mountShow();

        expect(wrapper.get('h1').text()).toBe('Revisão clínica de protocolos de retorno');
        expect(wrapper.text()).toContain('Olá, DRA. ANA PAULA SOUZA.');
        expect(byId('valid-until').text()).toContain('28/10/2026');
        expect(byId('privacy-note').text()).toContain('Esta página não mostra dados de pacientes');

        expect(wrapper.findAll('[data-testid="recall-rule-card"]')).toHaveLength(2);
        expect(byId('review-item-glaucoma').text()).toContain('Versão 1');
        expect(byId('review-item-glaucoma').text()).toContain('Código abcdef012345');
        expect(byId('review-item-glaucoma').text()).toContain('Consulta e campimetria.');
        expect(byId('statement-text').text()).toContain('DECLARAÇÃO DO REVISOR CLÍNICO');
        expect(wrapper.text()).toContain('Código da declaração: aaaaaaaaaaaa');
    });

    it('mostra o que mudou e a origem quando a versão deriva de outra', () => {
        mountShow({
            items: [
                item({
                    parent: { version: 1, validated: true },
                    diff: [
                        {
                            section: 'task',
                            op: 'changed',
                            key: 'campimetria',
                            field: 'interval.default',
                            from: 12,
                            to: 9,
                        },
                    ],
                }),
            ],
        });

        expect(byId('item-parent').text()).toBe('Derivada da versão 1, que já foi validada.');
        expect(wrapper.find('[data-testid="diff-task-changed"]').exists()).toBe(true);
    });

    it('versão que saiu de revisão avisa e trava o envio', () => {
        mountShow({ available: false, items: [item({ available: false })] });

        expect(byId('set-unavailable').text()).toContain('saiu de revisão');
        expect(byId('item-unavailable').exists()).toBe(true);
        expect(byId('review-button').attributes('disabled')).toBeDefined();
        // Sem decisão possível numa versão indisponível.
        expect(wrapper.find('[data-testid="decision-glaucoma-approved"]').exists()).toBe(false);
    });

    it('todo campo tem rótulo associado (acessibilidade) e os campos de identificação começam vazios', () => {
        mountShow();

        for (const control of wrapper.findAll('input:not([type="radio"]):not([type="checkbox"]), select, textarea')) {
            const id = control.attributes('id');

            expect(id, `campo sem id: ${control.html().slice(0, 80)}`).toBeTruthy();
            expect(wrapper.find(`label[for="${id}"]`).exists(), `sem <label for="${id}">`).toBe(true);
        }

        expect(byId('attestation-name').element.value).toBe('');
        expect(byId('attestation-email').element.value).toBe('');
        expect(byId('attestation-crm-number').element.value).toBe('');
        expect(byId('attestation-rqe').element.value).toBe('');
        expect(byId('attestation-crm-uf').element.value).toBe('');
        // Só a especialidade vem sugerida.
        expect(wrapper.find('input[maxlength="80"]').element.value).toBe('Oftalmologia');
    });

    it('nenhuma pergunta vem respondida: sem decisão, sem conflito, sem autorização, sem concordância', () => {
        mountShow();

        expect(wrapper.findAll('input[type="radio"]:checked')).toHaveLength(0);
        expect(byId('attestation-agreed').element.checked).toBe(false);
    });

    it('traz o rascunho do servidor, menos a concordância (a declaração pode ter mudado)', () => {
        mountShow({
            draft: {
                decisions: {
                    'item-1': {
                        decision: 'changes_requested',
                        comment: 'Reduzir a campimetria.',
                        proposed_changes: '9 meses',
                    },
                },
                attestation: {
                    name: 'Dra. Helena',
                    crm_uf: 'RJ',
                    has_conflict: true,
                    conflict_description: 'Palestrante.',
                    agreed: true,
                },
            },
            draftSavedAt: '2026-10-14T13:00:00-03:00',
        });

        expect(byId('decision-glaucoma-changes_requested').element.checked).toBe(true);
        expect(byId('comment-glaucoma').element.value).toBe('Reduzir a campimetria.');
        expect(byId('proposed-glaucoma').element.value).toBe('9 meses');
        expect(byId('attestation-name').element.value).toBe('Dra. Helena');
        expect(byId('attestation-crm-uf').element.value).toBe('RJ');
        expect(byId('conflict-yes').element.checked).toBe(true);
        expect(byId('conflict-description').element.value).toBe('Palestrante.');
        expect(byId('attestation-agreed').element.checked).toBe(false);
        expect(byId('draft-status').text()).toContain('Rascunho salvo às');
    });

    it('a declaração traduzida avisa que o texto oficial é o em português', () => {
        mountShow({ statement: { ...showProps().statement, is_translation: true } });

        expect(byId('statement-translation').text()).toContain('O texto oficial que você assina é o em português');
    });
});

describe('Show — decisão e justificativa', () => {
    it('só aprovar dispensa justificativa; pedir ajuste ou não aprovar a exige e abre a alteração sugerida', async () => {
        mountShow();

        const label = () => wrapper.find('label[for^="rv-"][for$="decisions-item-1-comment"]').text();

        await byId('decision-glaucoma-approved').setValue();
        expect(label()).toBe('Comentário (opcional)');
        expect(byId('comment-glaucoma').attributes('aria-required')).toBe('false');
        expect(wrapper.find('[data-testid="proposed-glaucoma"]').exists()).toBe(false);

        await byId('decision-glaucoma-changes_requested').setValue();
        expect(label()).toBe('Justificativa');
        expect(byId('comment-glaucoma').attributes('aria-required')).toBe('true');
        expect(wrapper.find('[data-testid="proposed-glaucoma"]').exists()).toBe(true);

        await byId('decision-glaucoma-rejected').setValue();
        expect(label()).toBe('Justificativa');
    });

    it('conta os caracteres do comentário e respeita o limite', async () => {
        mountShow();

        await byId('comment-glaucoma').setValue('x'.repeat(25));

        expect(byId('review-item-glaucoma').text()).toContain('25/3000');
        expect(byId('comment-glaucoma').attributes('maxlength')).toBe('3000');
    });
});

describe('Show — revisar e enviar', () => {
    it('formulário vazio: não vai ao resumo, mostra a lista de erros (role=alert) e leva o foco a ela', async () => {
        mountShow();

        await wrapper.get('form').trigger('submit');
        await nextTick();

        const summary = byId('error-summary');

        expect(wrapper.find('[data-testid="review-confirm"]').exists()).toBe(false);
        expect(summary.attributes('role')).toBe('alert');
        expect(summary.text()).toContain('Faltam corrigir');
        expect(summary.text()).toContain('Glaucoma — Decisão: Escolha uma decisão.');
        expect(summary.text()).toContain('Nome completo: Informe o nome completo.');
        expect(summary.text()).toContain('É preciso concordar com a declaração para enviar.');
        expect(document.activeElement).toBe(summary.element);
        expect(router.post).not.toHaveBeenCalled();

        // Campo com erro: marcado e ligado à mensagem.
        expect(byId('attestation-name').attributes('aria-invalid')).toBe('true');
        expect(byId('attestation-name').attributes('aria-describedby')).toMatch(/-error$/);
    });

    it('cada erro da lista leva ao campo (foco), e o erro some quando o campo é corrigido', async () => {
        mountShow();
        await wrapper.get('form').trigger('submit');

        const link = wrapper.findAll('[data-testid="error-summary"] a').find((a) => a.text() === 'Nome completo');
        await link.trigger('click');

        expect(document.activeElement).toBe(byId('attestation-name').element);

        await byId('attestation-name').setValue('Dra. Helena Prado');

        expect(byId('attestation-name').attributes('aria-invalid')).toBe('false');
        expect(byId('error-summary').text()).not.toContain('Nome completo:');
    });

    it('justificativa curta e conflito sem descrição são apontados', async () => {
        mountShow();
        await fillValid();
        await byId('decision-glaucoma-rejected').setValue();
        await byId('comment-glaucoma').setValue('curto');
        await byId('conflict-yes').setValue();

        await wrapper.get('form').trigger('submit');

        const text = byId('error-summary').text();

        expect(text).toContain('Glaucoma — Justificativa: Explique a decisão (mínimo de 10 caracteres).');
        expect(text).toContain('Descreva o conflito de interesses');
    });

    it('formulário válido: mostra o resumo (decisões, identificação, declaração) e nada foi enviado ainda', async () => {
        mountShow();
        await fillValid();
        await byId('decision-glaucoma-changes_requested').setValue();
        await byId('comment-glaucoma').setValue('Reduzir a campimetria para 9 meses.');
        await byId('proposed-glaucoma').setValue('Campimetria a cada 9 meses.');

        await wrapper.get('form').trigger('submit');
        await nextTick();

        const summary = byId('review-summary');

        expect(wrapper.find('[data-testid="review-form"]').exists()).toBe(false);
        expect(summary.get('[data-testid="summary-glaucoma"]').text()).toContain('Aprovo com ajustes');
        expect(summary.get('[data-testid="summary-glaucoma"]').text()).toContain('Reduzir a campimetria para 9 meses.');
        expect(summary.get('[data-testid="summary-glaucoma"]').text()).toContain('Campimetria a cada 9 meses.');
        expect(summary.get('[data-testid="summary-dmri"]').text()).toContain('Aprovo');
        expect(summary.get('[data-testid="summary-identity"]').text()).toContain('Dra. Helena Prado');
        expect(summary.get('[data-testid="summary-identity"]').text()).toContain('12.345 / SP');
        expect(summary.text()).toContain('Envio único.');
        expect(document.activeElement.textContent).toContain('Confira antes de enviar');
        expect(router.post).not.toHaveBeenCalled();
    });

    it('"Voltar e editar" devolve ao formulário com tudo o que foi digitado', async () => {
        mountShow();
        await fillValid();
        await wrapper.get('form').trigger('submit');
        await nextTick();

        await byId('back-button').trigger('click');
        await nextTick();

        expect(byId('attestation-name').element.value).toBe('Dra. Helena Prado');
        expect(byId('decision-glaucoma-approved').element.checked).toBe(true);
        expect(byId('attestation-agreed').element.checked).toBe(true);
    });

    it('confirmar envia UM POST com as decisões, o atestado e a declaração que estava na tela', async () => {
        mountShow();
        await fillValid();
        await byId('decision-dmri-rejected').setValue();
        await byId('comment-dmri').setValue('Não concordo com os intervalos propostos.');
        await wrapper.get('form').trigger('submit');
        await nextTick();

        await byId('send-button').trigger('click');
        await flushPromises();

        expect(router.post).toHaveBeenCalledTimes(1);

        const [url, payload, options] = router.post.mock.calls[0];

        expect(url).toBe('/revisao-clinica');
        expect(payload.statement_term_version_id).toBe('tv-1');
        expect(payload.statement_sha256).toBe('a'.repeat(64));
        expect(payload.decisions['item-1'].decision).toBe('approved');
        expect(payload.decisions['item-2']).toEqual({
            decision: 'rejected',
            comment: 'Não concordo com os intervalos propostos.',
            proposed_changes: '',
        });
        expect(payload.attestation).toMatchObject({
            name: 'Dra. Helena Prado',
            email: 'helena@exemplo.test',
            crm_number: '12.345',
            crm_uf: 'SP',
            rqe: '6789',
            has_conflict: false,
            allow_identity_display: true,
            agreed: true,
        });
        expect(options).toMatchObject({ preserveState: true, preserveScroll: true });

        // Enquanto envia: botão travado (duplo clique não envia duas vezes).
        expect(byId('send-button').attributes('disabled')).toBeDefined();
        expect(byId('send-button').text()).toContain('Enviando');
        await byId('send-button').trigger('click');
        expect(router.post).toHaveBeenCalledTimes(1);

        options.onFinish();
        await nextTick();
        expect(byId('send-button').attributes('disabled')).toBeUndefined();
    });

    it('erro do servidor volta ao formulário, mostra a mensagem no campo certo e leva o foco à lista', async () => {
        mountShow();
        await fillValid();
        await wrapper.get('form').trigger('submit');
        await nextTick();
        await byId('send-button').trigger('click');
        await flushPromises();

        const { onError, onFinish } = router.post.mock.calls[0][2];

        onError({ 'attestation.crm_uf': 'A UF do CRM não é válida.' });
        onFinish();
        await nextTick();
        await nextTick();

        expect(wrapper.find('[data-testid="review-form"]').exists()).toBe(true);
        expect(byId('error-summary').text()).toContain('UF do CRM: A UF do CRM não é válida.');
        expect(byId('attestation-crm-uf').attributes('aria-invalid')).toBe('true');
        expect(document.activeElement).toBe(byId('error-summary').element);

        // Editar o campo limpa o erro do servidor.
        await byId('attestation-crm-uf').setValue('RJ');
        expect(byId('attestation-crm-uf').attributes('aria-invalid')).toBe('false');
    });

    it('aviso do servidor (conjunto ou declaração mudou) volta ao formulário com o aviso à vista', async () => {
        mountShow();
        await fillValid();
        await wrapper.get('form').trigger('submit');
        await nextTick();
        await byId('send-button').trigger('click');
        await flushPromises();

        const { onSuccess, onFinish } = router.post.mock.calls[0][2];

        page.props.flash = {
            error: 'A declaração foi atualizada. Leia o texto novo, marque que concorda e envie de novo.',
        };
        onSuccess();
        onFinish();
        await nextTick();
        await nextTick();

        expect(wrapper.find('[data-testid="review-form"]').exists()).toBe(true);
        expect(byId('flash-error').text()).toContain('A declaração foi atualizada');
        expect(byId('flash-error').attributes('role')).toBe('alert');
        // O que foi digitado continua lá.
        expect(byId('attestation-name').element.value).toBe('Dra. Helena Prado');
    });

    it('a declaração mudou com o formulário aberto: a concordância dada ao texto antigo some, o resto fica e o reenvio exige concordar de novo', async () => {
        mountShow();
        await fillValid();
        await wrapper.get('form').trigger('submit');
        await nextTick();
        await byId('send-button').trigger('click');
        await flushPromises();

        const { onSuccess, onFinish } = router.post.mock.calls[0][2];
        const newer = {
            ...showProps().statement,
            term_version_id: 'tv-2',
            sha256: 'b'.repeat(64),
            text: 'DECLARAÇÃO NOVA',
        };

        // O servidor recusou (statement_changed) e devolveu a declaração nova nas props.
        page.props.flash = {
            error: 'A declaração foi atualizada. Leia o texto novo, marque que concorda e envie de novo.',
        };
        await wrapper.setProps({ statement: newer });
        onSuccess();
        onFinish();
        await nextTick();
        await nextTick();

        expect(wrapper.find('[data-testid="review-form"]').exists()).toBe(true);
        expect(byId('attestation-agreed').element.checked).toBe(false);
        // As outras respostas continuam.
        expect(byId('attestation-name').element.value).toBe('Dra. Helena Prado');
        expect(byId('attestation-crm-number').element.value).toBe('12.345');

        // Sem concordar de novo, não chega ao resumo nem ao 2º POST.
        await wrapper.get('form').trigger('submit');
        await nextTick();

        expect(wrapper.find('[data-testid="review-form"]').exists()).toBe(true);
        expect(byId('error-summary').exists()).toBe(true);
        expect(router.post).toHaveBeenCalledTimes(1);

        // Concordando com o texto NOVO: o 2º POST leva a declaração nova e a concordância dada a ela.
        await byId('attestation-agreed').setValue(true);
        await wrapper.get('form').trigger('submit');
        await nextTick();
        await byId('send-button').trigger('click');
        await flushPromises();

        expect(router.post).toHaveBeenCalledTimes(2);

        const payload = router.post.mock.calls[1][1];

        expect(payload.statement_term_version_id).toBe('tv-2');
        expect(payload.statement_sha256).toBe('b'.repeat(64));
        expect(payload.attestation.agreed).toBe(true);
    });

    it('as props voltarem com a MESMA declaração não desmarca a concordância', async () => {
        mountShow();
        await fillValid();

        await wrapper.setProps({ statement: { ...showProps().statement } });
        await nextTick();

        expect(byId('attestation-agreed').element.checked).toBe(true);
    });

    it('antes do POST o que está na tela vai para o rascunho do servidor', async () => {
        mountShow();
        await fillValid();
        await wrapper.get('form').trigger('submit');
        await nextTick();

        await byId('send-button').trigger('click');
        await flushPromises();

        expect(axios.put).toHaveBeenCalledTimes(1);
        expect(axios.put.mock.calls[0][0]).toBe('/revisao-clinica/rascunho');
        expect(axios.put.mock.calls[0][1].attestation.name).toBe('Dra. Helena Prado');
    });
});

describe('Show — rascunho no servidor', () => {
    it('salva sozinho depois de uma pausa na digitação e mostra a hora', async () => {
        vi.useFakeTimers();
        mountShow();

        await byId('attestation-name').setValue('Dra. Helena');
        expect(axios.put).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1600);

        expect(axios.put).toHaveBeenCalledTimes(1);
        expect(axios.put.mock.calls[0][0]).toBe('/revisao-clinica/rascunho');
        expect(axios.put.mock.calls[0][1].attestation.name).toBe('Dra. Helena');
        expect(Object.keys(axios.put.mock.calls[0][1])).toEqual(['decisions', 'attestation']);
        expect(byId('draft-status').text()).toMatch(/Rascunho salvo às \d{2}:\d{2}\./);
    });

    it('digitar de novo reinicia a espera (uma gravação só, com o texto final)', async () => {
        vi.useFakeTimers();
        mountShow();

        await byId('attestation-name').setValue('Dra');
        await vi.advanceTimersByTimeAsync(1000);
        await byId('attestation-name').setValue('Dra. Helena');
        await vi.advanceTimersByTimeAsync(1000);
        expect(axios.put).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(700);

        expect(axios.put).toHaveBeenCalledTimes(1);
        expect(axios.put.mock.calls[0][1].attestation.name).toBe('Dra. Helena');
    });

    it('não grava de novo o que já está salvo', async () => {
        vi.useFakeTimers();
        mountShow();

        await byId('attestation-name').setValue('Dra. Helena');
        await vi.advanceTimersByTimeAsync(1600);
        await byId('save-draft').trigger('click');
        await flushPromises();

        expect(axios.put).toHaveBeenCalledTimes(1);
    });

    it('"Salvar rascunho agora" grava sem esperar', async () => {
        mountShow();

        await byId('attestation-name').setValue('Dra. Helena');
        await byId('save-draft').trigger('click');
        await flushPromises();

        expect(axios.put).toHaveBeenCalledTimes(1);
    });

    it('falha de rede ou limite: avisa que o texto continua na tela e tenta de novo', async () => {
        vi.useFakeTimers();
        axios.put.mockRejectedValueOnce({ response: { status: 429 } });
        mountShow();

        await byId('attestation-name').setValue('Dra. Helena');
        await vi.advanceTimersByTimeAsync(1600);

        expect(byId('draft-status').text()).toContain('O texto continua na tela');
        expect(wrapper.find('[data-testid="draft-ended"]').exists()).toBe(false);

        await vi.advanceTimersByTimeAsync(20500);

        expect(axios.put).toHaveBeenCalledTimes(2);
        expect(byId('draft-status').text()).toContain('Rascunho salvo');
    });

    it('link que deixou de valer (409) para de insistir, avisa e oferece a página do link', async () => {
        vi.useFakeTimers();
        axios.put.mockRejectedValue({
            response: { status: 409, data: { message: 'unavailable', redirect: '/revisao-clinica/indisponivel' } },
        });
        mountShow();

        await byId('attestation-name').setValue('Dra. Helena');
        await vi.advanceTimersByTimeAsync(1600);

        const ended = byId('draft-ended');

        expect(ended.attributes('role')).toBe('alert');
        expect(ended.text()).toContain('O link deixou de valer');
        expect(ended.get('a').attributes('href')).toBe('/revisao-clinica/indisponivel');

        // Mais digitação não gera mais tentativas.
        await byId('attestation-name').setValue('Dra. Helena Prado');
        await vi.advanceTimersByTimeAsync(60000);

        expect(axios.put).toHaveBeenCalledTimes(1);
        // O texto digitado continua na tela.
        expect(byId('attestation-name').element.value).toBe('Dra. Helena Prado');
    });

    it('NADA do que o Revisor digita vai para localStorage/sessionStorage', async () => {
        const local = vi.spyOn(Storage.prototype, 'setItem');
        mountShow();

        await fillValid();
        await byId('comment-glaucoma').setValue('Texto clínico do Revisor.');
        await byId('save-draft').trigger('click');
        await flushPromises();

        expect(local).not.toHaveBeenCalled();
        expect(JSON.stringify(Object.fromEntries(Object.entries(localStorage)))).not.toContain('Helena');
        expect(JSON.stringify(Object.fromEntries(Object.entries(sessionStorage)))).not.toContain('Helena');
    });

    it('avisa antes de fechar a aba só enquanto há texto ainda não salvo', async () => {
        mountShow();

        const fire = () => {
            const event = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(event);

            return event.defaultPrevented;
        };

        expect(fire()).toBe(false);

        await byId('attestation-name').setValue('Dra. Helena');
        expect(fire()).toBe(true);

        await byId('save-draft').trigger('click');
        await flushPromises();
        expect(fire()).toBe(false);
    });

    it('ao sair da página o aviso de fechar a aba deixa de existir', async () => {
        mountShow();
        await byId('attestation-name').setValue('Dra. Helena');

        wrapper.unmount();
        wrapper = null;

        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });
});

describe('Show — idioma e tema', () => {
    it('trocar o idioma usa o endereço da própria página, com a página de volta', async () => {
        mountShow();

        await byId('locale-en').trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post.mock.calls[0][0]).toBe('/revisao-clinica/idioma');
        expect(router.post.mock.calls[0][1]).toEqual({ locale: 'en', page: 'show' });
        expect(router.post.mock.calls[0][2]).toMatchObject({ preserveState: true });
        expect(byId('locale-pt_BR').attributes('aria-pressed')).toBe('true');
        expect(byId('locale-en').attributes('aria-pressed')).toBe('false');
    });

    it('o idioma que já está ativo não faz requisição', async () => {
        mountShow();

        await byId('locale-pt_BR').trigger('click');

        expect(router.post).not.toHaveBeenCalled();
    });

    it('o tema só grava a preferência de tema (e só ao clicar)', async () => {
        const set = vi.spyOn(Storage.prototype, 'setItem');
        mountShow();

        expect(set).not.toHaveBeenCalled();

        await byId('theme-toggle').trigger('click');

        expect(set).toHaveBeenCalledTimes(1);
        expect(set).toHaveBeenCalledWith('ee-guest-theme', 'dark');
        expect(document.documentElement.getAttribute('data-bs-theme')).toBe('dark');
        document.documentElement.setAttribute('data-bs-theme', 'light');
    });
});

describe('Done', () => {
    const doneProps = (overrides = {}) => ({
        reviewerName: 'DRA. ANA PAULA SOUZA',
        submission: {
            submitted_at: '2026-10-14T13:40:00-03:00',
            submission_hash: '1:' + 'abcd'.repeat(16),
            receipt_sent: true,
            statement: 'a'.repeat(64),
        },
        decisions: [
            { id: 'i-1', key: 'glaucoma', title: 'Glaucoma', version: 1, label: 'Aprovo' },
            { id: 'i-2', key: 'dmri', title: 'DMRI', version: 2, label: 'Aprovo com ajustes' },
        ],
        t: { layout: publicT.layout, done: publicT.done },
        urls: { locale: urls.locale },
        ...overrides,
    });

    it('mostra o recibo: data, código de verificação em grupos, decisões e o que acontece agora', async () => {
        wrapper = mount(Done, { props: doneProps(), attachTo: document.body, global: { stubs: { Teleport: true } } });
        await flushPromises();

        expect(wrapper.get('h1').text()).toBe('Parecer enviado');
        // Primeira carga do documento: o foco NÃO é roubado (o primeiro Tab cai no atalho "Ir para o conteúdo").
        expect(document.activeElement).toBe(document.body);
        expect(wrapper.text()).toContain('Obrigado, DRA. ANA PAULA SOUZA. Recebemos o seu parecer.');
        expect(byId('done-sent-at').text()).toContain('14/10/2026');
        expect(byId('done-code').text()).toMatch(/^1:(\S{4} ?)+$/);
        expect(byId('done-receipt').text()).toContain('Enviado para o e-mail que você declarou');
        expect(byId('done-decisions').text()).toContain('Glaucoma');
        expect(byId('done-decisions').text()).toContain('versão 2');
        expect(byId('done-decisions').text()).toContain('Aprovo com ajustes');
        expect(wrapper.text()).toContain('portal do Conselho Federal de Medicina');
    });

    it('recibo por e-mail que não saiu avisa que o código vale como recibo', () => {
        const props = doneProps();
        props.submission.receipt_sent = false;
        wrapper = mount(Done, { props, global: { stubs: { Teleport: true } } });

        expect(byId('done-receipt').text()).toContain('O código acima vale como recibo');
    });

    it('copia o código de verificação sem espaços de agrupamento', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        wrapper = mount(Done, { props: doneProps(), global: { stubs: { Teleport: true } } });

        await byId('copy-code').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('1:' + 'abcd'.repeat(16));
        expect(wrapper.text()).toContain('Copiado');
    });

    it('não tem formulário nem campo: é só o recibo', () => {
        wrapper = mount(Done, { props: doneProps(), global: { stubs: { Teleport: true } } });

        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.find('textarea').exists()).toBe(false);
    });
});

describe('Unavailable', () => {
    it('é genérica: lista as possibilidades juntas e não promete nada específico', async () => {
        wrapper = mount(Unavailable, {
            props: { t: { layout: publicT.layout, unavailable: publicT.unavailable }, urls: { locale: urls.locale } },
            attachTo: document.body,
            global: { stubs: { Teleport: true } },
        });
        await flushPromises();

        expect(wrapper.get('h1').text()).toBe('Este link não está disponível');
        expect(document.activeElement).toBe(document.body);
        expect(wrapper.findAll('li')).toHaveLength(3);
        expect(wrapper.text()).toContain('Procure o e-mail mais recente da EasyEye');
        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.find('input').exists()).toBe(false);
    });
});

describe('AccessCode', () => {
    const codeProps = () => ({
        reviewerName: 'DRA. ANA PAULA SOUZA',
        t: { layout: publicT.layout, code: publicT.code, show: { greeting: publicT.show.greeting } },
        urls: { verify: '/revisao-clinica/codigo', locale: urls.locale },
    });

    it('pede o código (campo de uso único, sem autocorreção) e só habilita com algo digitado', async () => {
        wrapper = mount(AccessCode, {
            props: codeProps(),
            attachTo: document.body,
            global: { stubs: { Teleport: true } },
        });

        const input = wrapper.get('#review-code-input');

        expect(wrapper.get('h1').text()).toBe('Código de acesso');
        expect(wrapper.text()).toContain('ele não vem no e-mail');
        expect(input.attributes('autocomplete')).toBe('one-time-code');
        expect(input.attributes('spellcheck')).toBe('false');
        expect(input.attributes('autocapitalize')).toBe('characters');
        expect(wrapper.find('label[for="review-code-input"]').exists()).toBe(true);
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();

        await input.setValue('ABCD 2345');
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined();
    });

    it('envia o código para o endereço do servidor', async () => {
        wrapper = mount(AccessCode, { props: codeProps(), global: { stubs: { Teleport: true } } });

        await wrapper.get('#review-code-input').setValue('ABCD 2345');
        await wrapper.get('form').trigger('submit');

        expect(forms).toHaveLength(1);
        expect(forms[0].post).toHaveBeenCalledTimes(1);
        expect(forms[0].lastRequest.url).toBe('/revisao-clinica/codigo');
        expect(forms[0].lastRequest.data).toEqual({ code: 'ABCD 2345' });
    });

    it('erro do servidor aparece como alerta ligado ao campo, e o campo é limpo para nova tentativa', async () => {
        wrapper = mount(AccessCode, {
            props: codeProps(),
            attachTo: document.body,
            global: { stubs: { Teleport: true } },
        });

        await wrapper.get('#review-code-input').setValue('ERRADO');
        await wrapper.get('form').trigger('submit');

        forms[0].setError('code', 'Código incorreto ou temporariamente bloqueado.');
        forms[0].lastRequest.options.onError();
        await nextTick();
        await nextTick();

        const alert = wrapper.get('#review-code-error');

        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toContain('Código incorreto ou temporariamente bloqueado.');
        expect(wrapper.get('#review-code-input').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('#review-code-input').attributes('aria-describedby')).toBe('review-code-error');
        expect(wrapper.get('#review-code-input').element.value).toBe('');
        expect(document.activeElement).toBe(wrapper.get('#review-code-input').element);
    });
});

describe('foco no título', () => {
    it('só nas trocas de página dentro do mesmo documento, nunca na primeira carga', async () => {
        const first = mount(Unavailable, {
            props: { t: { layout: publicT.layout, unavailable: publicT.unavailable }, urls: { locale: urls.locale } },
            attachTo: document.body,
            global: { stubs: { Teleport: true } },
        });
        await flushPromises();

        expect(document.activeElement).toBe(document.body);
        first.unmount();

        // A 2ª página montada no mesmo documento (Inertia: Show → Done): o botão que tinha o foco sumiu.
        wrapper = mount(Done, {
            props: {
                reviewerName: 'DRA. ANA',
                submission: {
                    submitted_at: '2026-10-14T13:40:00-03:00',
                    submission_hash: '1:' + 'ab'.repeat(32),
                    receipt_sent: true,
                },
                decisions: [],
                t: { layout: publicT.layout, done: publicT.done },
                urls: { locale: urls.locale },
            },
            attachTo: document.body,
            global: { stubs: { Teleport: true } },
        });
        await flushPromises();

        expect(document.activeElement).toBe(wrapper.get('h1').element);
    });
});

describe('Show — texto oficial da declaração', () => {
    it('em português não há bloco extra; na tradução o texto oficial fica à vista, recolhido', () => {
        mountShow();
        expect(wrapper.find('[data-testid="statement-official"]').exists()).toBe(false);
        wrapper.unmount();

        mountShow({
            statement: {
                ...showProps().statement,
                is_translation: true,
                text: 'CLINICAL REVIEWER STATEMENT',
                official_text: 'DECLARAÇÃO DO REVISOR CLÍNICO — texto oficial',
            },
        });

        const official = byId('statement-official');

        expect(official.element.tagName).toBe('DETAILS');
        expect(official.attributes('open')).toBeUndefined();
        expect(official.text()).toContain('Ver o texto oficial, em português');
        expect(official.text()).toContain('DECLARAÇÃO DO REVISOR CLÍNICO — texto oficial');
        expect(byId('statement-text').text()).toContain('CLINICAL REVIEWER STATEMENT');
    });
});

describe('Show — sinal de vida da sessão', () => {
    it('renova a sessão a cada 10 minutos enquanto a página está aberta', async () => {
        vi.useFakeTimers();
        mountShow();

        await vi.advanceTimersByTimeAsync(9 * 60 * 1000);
        expect(axios.get).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(2 * 60 * 1000);
        expect(axios.get).toHaveBeenCalledTimes(1);
        expect(axios.get.mock.calls[0][0]).toBe('/revisao-clinica/sessao');
        expect(wrapper.find('[data-testid="draft-ended"]').exists()).toBe(false);

        await vi.advanceTimersByTimeAsync(10 * 60 * 1000);
        expect(axios.get).toHaveBeenCalledTimes(2);
    });

    it('sessão que já acabou: avisa ENQUANTO o texto ainda está na tela e para de insistir', async () => {
        vi.useFakeTimers();
        axios.get.mockRejectedValue({
            response: { status: 409, data: { message: 'unavailable', redirect: '/revisao-clinica/indisponivel' } },
        });
        mountShow();
        await byId('attestation-name').setValue('Dra. Helena Prado');

        await vi.advanceTimersByTimeAsync(11 * 60 * 1000);

        const ended = byId('draft-ended');

        expect(ended.attributes('role')).toBe('alert');
        expect(ended.get('a').attributes('href')).toBe('/revisao-clinica/indisponivel');
        expect(byId('attestation-name').element.value).toBe('Dra. Helena Prado');

        await vi.advanceTimersByTimeAsync(30 * 60 * 1000);
        expect(axios.get).toHaveBeenCalledTimes(1);
    });

    it('ao voltar para a aba confere na hora se a sessão ainda vale', async () => {
        mountShow();

        Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
        await flushPromises();

        expect(axios.get).toHaveBeenCalledTimes(1);
    });

    it('ao sair da página o sinal de vida para', async () => {
        vi.useFakeTimers();
        mountShow();
        wrapper.unmount();
        wrapper = null;

        await vi.advanceTimersByTimeAsync(30 * 60 * 1000);

        expect(axios.get).not.toHaveBeenCalled();
    });
});

describe('ReviewLayout — idioma do documento', () => {
    it('o <html lang> acompanha o idioma da página, também quando ele troca sem recarregar', async () => {
        document.documentElement.lang = 'pt-BR';
        mountShow();
        expect(document.documentElement.lang).toBe('pt-BR');

        page.props.locale = 'en';
        await nextTick();
        expect(document.documentElement.lang).toBe('en');

        page.props.locale = 'pt_BR';
        await nextTick();
        expect(document.documentElement.lang).toBe('pt-BR');
    });
});

describe('ReviewLayout — nome do app', () => {
    it('o nome vem do <html data-app> ao montar (no servidor, onde não há documento, vale o padrão)', async () => {
        document.documentElement.dataset.app = 'Clínica Vista';

        try {
            mountShow();
            await nextTick();

            expect(wrapper.get('header').text()).toContain('Clínica Vista');
            expect(wrapper.get('footer').text()).toContain('Clínica Vista');
        } finally {
            delete document.documentElement.dataset.app;
        }
    });

    it('sem data-app vale EasyEye', async () => {
        delete document.documentElement.dataset.app;

        mountShow();
        await nextTick();

        expect(wrapper.get('header').text()).toContain('EasyEye');
        expect(wrapper.get('footer').text()).toContain('EasyEye');
    });
});
