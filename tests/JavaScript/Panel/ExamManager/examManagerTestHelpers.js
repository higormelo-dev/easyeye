import { shallowMount, flushPromises } from '@vue/test-utils';
import { vi } from 'vitest';
import Index from '@/Pages/Panel/ExamManager/Index.vue';

export const exam = (extra = {}) => ({
    id: 'exam-a',
    laterality: 1,
    exam_id: 'type-1',
    created_at: '2026-10-18T12:00:00Z',
    viewed_at: null,
    ...extra,
});
export async function renderIndex(overrides = {}) {
    const fetchMock = vi.fn(async () => ({
        ok: true,
        json: async () => ({ urls: {}, url: '', patients: [], meta: {} }),
    }));
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = shallowMount(Index, {
        attachTo: document.body,
        props: {
            entity: { id: 'entity-1' },
            patients: [],
            urls: {
                search: '/search',
                patient_urls: '/patient/__ID__/urls',
                image_url: '/exam/__ID__/url',
                exam_viewed_update: '/exam/__ID__/viewed',
            },
            ...overrides,
        },
        global: { stubs: { Teleport: true }, renderStubDefaultSlot: true },
    });
    await flushPromises();
    return { wrapper, fetchMock };
}
