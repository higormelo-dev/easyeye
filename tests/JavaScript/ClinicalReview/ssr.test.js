// @vitest-environment node
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';

vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock());
vi.mock('axios', () => ({ default: { put: vi.fn(), get: vi.fn() } }));

import { page, resetInertiaMock } from './support/inertiaMock.js';
import { publicT } from './support/texts.js';
import { baseContent, exampleRows, ruleT } from '../Panel/Manager/RecallProtocols/support/fixtures.js';
import ReviewLayout from '@/Layouts/ReviewLayout.vue';
import Show from '@/Pages/ClinicalReview/Show.vue';
import Done from '@/Pages/ClinicalReview/Done.vue';
import Unavailable from '@/Pages/ClinicalReview/Unavailable.vue';
import AccessCode from '@/Pages/ClinicalReview/AccessCode.vue';

/**
 * SSR (Inertia): a página PÚBLICA do Revisor é pré-renderizada no Node (`resources/js/ssr.js`, `renderToString`), onde
 * NÃO existe `document`, `window` nem `localStorage`. O layout lia o `<html data-app>` no setup e escrevia o
 * `<html lang>` num observador `immediate` (que no servidor roda uma vez): `ReferenceError: document is not defined`,
 * e toda carga inicial caía no render do navegador — ou falhava, com `INERTIA_SSR_THROW_ON_ERROR`.
 *
 * Este arquivo roda em ambiente `node` de propósito (o setup global só liga o DOM quando ele existe): se qualquer
 * página ou componente do Revisor tocar o navegador ANTES de montar, aqui vira erro.
 */
const urls = {
    draft: '/revisao-clinica/rascunho',
    keepalive: '/revisao-clinica/sessao',
    submit: '/revisao-clinica',
    locale: '/revisao-clinica/idioma',
    verify: '/revisao-clinica/codigo',
};

const decisions = [
    { value: 'approved', label: 'Aprovo', requires_comment: false },
    { value: 'changes_requested', label: 'Aprovo com ajustes', requires_comment: true },
    { value: 'rejected', label: 'Não aprovo', requires_comment: true },
];

const show = () => ({
    reviewer: { name: 'DRA. ANA PAULA SOUZA' },
    invitation: { expires_at: '2026-10-28T23:59:00-03:00', sent_at: '2026-10-14T10:00:00-03:00' },
    items: [
        {
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
        },
    ],
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
});

const render = (component, props) => renderToString(createSSRApp({ render: () => h(component, props) }));

afterEach(() => resetInertiaMock());

describe('SSR — a página pública do Revisor renderiza no servidor, sem navegador', () => {
    it('o ambiente é mesmo de servidor: não há document, window nem localStorage', () => {
        expect(typeof document).toBe('undefined');
        expect(typeof window).toBe('undefined');
        expect(typeof localStorage).toBe('undefined');
    });

    it.each([
        ['Show (o formulário)', Show, show, 'Revisão clínica de protocolos de retorno'],
        [
            'Done (o recibo)',
            Done,
            () => ({
                reviewerName: 'DRA. ANA',
                submission: { submitted_at: '2026-10-14T13:32:00-03:00', submission_hash: 'a'.repeat(64) },
                decisions: [],
                t: publicT,
                urls,
            }),
            'data-testid="review-done"',
        ],
        ['Unavailable (link ruim)', Unavailable, () => ({ t: publicT, urls }), 'data-testid="review-unavailable"'],
        [
            'AccessCode (o PIN)',
            AccessCode,
            () => ({ reviewerName: 'DRA. ANA', t: publicT, urls }),
            'data-testid="review-code"',
        ],
    ])('%s: renderiza com a moldura (marca, idioma, tema) e sem erro', async (_name, component, props, marker) => {
        const html = await render(component, props());

        expect(html).toContain(marker);
        expect(html).toContain('data-testid="review-layout"');
        // Idioma e tema: os botões da moldura existem no HTML do servidor (a hidratação só liga o comportamento).
        expect(html).toContain('data-testid="locale-pt_BR"');
        expect(html).toContain('data-testid="theme-toggle"');
    });

    it('a moldura sozinha: nome padrão do app e o ano no rodapé (o <html data-app> só existe no navegador)', async () => {
        const html = await render(ReviewLayout, { t: publicT, current: 'show', localeUrl: urls.locale });

        expect(html).toContain('EasyEye');
        expect(html).toContain(`© ${new Date().getFullYear()} EasyEye`);
    });

    it('o idioma da página é lido sem escrever no documento (o observador immediate roda no servidor)', async () => {
        page.props.locale = 'en';

        await expect(render(ReviewLayout, { t: publicT, current: 'show', localeUrl: urls.locale })).resolves.toContain(
            'data-testid="review-layout"',
        );
    });
});
