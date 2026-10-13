import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import EyeImageReportDraftsModal from '@/Pages/Panel/EyeImages/EyeImageReportDraftsModal.vue';

/**
 * Lista de rascunhos de laudo (Fase 5 — fluxo secretária-rascunha/médico-emite).
 * fetchDrafts() dispara de novo a cada troca de statusFilter (watch) e a
 * cada reabertura do modal (watch open) — cobre aqui só a corrida entre
 * chamadas concorrentes (achado via revisão Codex), não o CRUD completo do
 * componente.
 */
const urls = {
    index: '/_routes/eye-images.report-drafts.index',
    destroy: '/_routes/eye-images.report-drafts.destroy/__ID__',
};

function mountModal(propsOverride = {}) {
    return mount(EyeImageReportDraftsModal, {
        attachTo: document.body,
        props: { open: true, urls, t: {}, ...propsOverride },
    });
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('EyeImageReportDraftsModal — lista de rascunhos (Fase 5)', () => {
    it('[GAP] resposta de fetchDrafts() desatualizada (filtro trocado antes de chegar) nunca sobrescreve a lista do filtro atual', async () => {
        const resolvers = [];
        globalThis.window.axios = {
            get: vi.fn(() => new Promise((resolve) => resolvers.push(resolve))),
            delete: vi.fn(),
        };

        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true }); // dispara a 1ª chamada (filtro "", todos)
        await flushPromises();

        wrapper.vm.statusFilter = 'submitted'; // dispara a 2ª chamada (mais nova)
        await flushPromises();

        // Fora de ordem: a MAIS NOVA (submitted) responde primeiro.
        resolvers[1]({ data: { data: [{ id: 'd-2', status: 'submitted', status_label: 'Enviado' }] } });
        await flushPromises();
        resolvers[0]({ data: { data: [{ id: 'd-1', status: 'draft', status_label: 'Rascunho' }] } });
        await flushPromises();

        // A resposta antiga (filtro "") chegando por último nunca pode
        // sobrescrever a lista do filtro atual ("submitted") — mostraria
        // rascunhos que não combinam com o filtro selecionado no <select>
        // (achado via revisão Codex).
        expect(wrapper.vm.drafts).toHaveLength(1);
        expect(wrapper.vm.drafts[0].id).toBe('d-2');
        expect(wrapper.vm.loading).toBe(false);
        wrapper.unmount();
    });

    it('[GAP] erro de uma chamada já superada não aparece depois de outra ter tido sucesso, nem apaga a lista nova', async () => {
        const resolvers = [];
        const rejecters = [];
        globalThis.window.axios = {
            get: vi.fn(
                () =>
                    new Promise((resolve, reject) => {
                        resolvers.push(resolve);
                        rejecters.push(reject);
                    }),
            ),
            delete: vi.fn(),
        };

        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true }); // 1ª chamada, vai falhar, mas desatualizada
        await flushPromises();

        wrapper.vm.statusFilter = 'submitted'; // 2ª chamada, mais nova, tem sucesso
        await flushPromises();

        resolvers[1]({ data: { data: [{ id: 'd-2', status: 'submitted', status_label: 'Enviado' }] } });
        await flushPromises();
        rejecters[0](new Error('falha da chamada desatualizada'));
        await flushPromises();

        expect(wrapper.vm.drafts).toHaveLength(1);
        expect(wrapper.vm.drafts[0].id).toBe('d-2');
        expect(wrapper.vm.error).toBe('');
        wrapper.unmount();
    });

    it('[GAP] "Carregar mais" soma a próxima página na lista em vez de substituir (achado via revisão Codex)', async () => {
        globalThis.window.axios = {
            get: vi.fn((url, config) => {
                const page = config?.params?.page ?? 1;
                if (page === 1) {
                    return Promise.resolve({
                        data: {
                            data: Array.from({ length: 25 }, (_, i) => ({
                                id: `d-${i}`,
                                status: 'draft',
                                status_label: 'Rascunho',
                            })),
                            meta: { current_page: 1, has_more: true, total: 27, last_page: 2 },
                        },
                    });
                }
                return Promise.resolve({
                    data: {
                        data: [
                            { id: 'd-25', status: 'draft', status_label: 'Rascunho' },
                            { id: 'd-26', status: 'draft', status_label: 'Rascunho' },
                        ],
                        meta: { current_page: 2, has_more: false, total: 27, last_page: 2 },
                    },
                });
            }),
            delete: vi.fn(),
        };

        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(wrapper.vm.drafts).toHaveLength(25);
        expect(wrapper.vm.hasMore).toBe(true);

        wrapper.vm.loadMoreDrafts();
        await flushPromises();

        // A página 2 é SOMADA, não substitui a 1ª — perder os 25 já
        // carregados pra "ganhar" os 2 novos seria um retrocesso. Sem
        // paginação de verdade (só um limit() fixo), esses 2 rascunhos mais
        // antigos nunca ficavam alcançáveis de outro jeito (achado via
        // revisão Codex).
        expect(wrapper.vm.drafts).toHaveLength(27);
        expect(wrapper.vm.drafts[26].id).toBe('d-26');
        expect(wrapper.vm.hasMore).toBe(false);
        wrapper.unmount();
    });
});
