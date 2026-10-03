import { describe, it, expect, vi, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import Index from '@/Pages/Panel/EyeImages/Index.vue';

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const exam = {
    id: 'exam-1',
    exam_id: 'type-1',
    active: true,
    laterality: 1,
    archive: 'original.jpg',
    created_at: '2026-02-12T12:32:25Z',
    exam_type: { name: 'Synthetic exam' },
};
const patient = { id: 'patient-1', code: 'PAC-1', person: { full_name: 'Synthetic patient' }, exams: [exam] };
const props = {
    entity: { id: 'entity-1' },
    patients: [],
    urls: { patient_urls: '/patient/__ID__/urls', image_url: '/exam/__ID__/url' },
};
const wrappers = [];

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

async function render(target) {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            json: async () => ({ urls: { 'exam-1': '/synthetic-original.jpg' }, url: '/synthetic-original.jpg' }),
        })),
    );
    const wrapper = shallowMount(Index, {
        props: { ...props, reviewTarget: target },
        global: { stubs: { Teleport: true }, renderStubDefaultSlot: true },
    });
    wrappers.push(wrapper);
    await flushPromises();
    return wrapper;
}

describe('receipt review handoff', () => {
    it('selects the server-scoped patient and opens the exact exam outside the current list', async () => {
        const wrapper = await render({ patient, exam_id: exam.id });
        expect(wrapper.find('.patient-item-active').text()).toContain('Synthetic patient');
        expect(wrapper.find('[data-testid="review-viewer"]').isVisible()).toBe(true);
        expect(wrapper.text()).toContain('Revisão clínica e completude: não informadas');
        expect(fetch).toHaveBeenCalledWith('/patient/patient-1/urls', expect.any(Object));
    });

    it('does not open a missing or mismatched target from query parameters alone', async () => {
        window.history.replaceState({}, '', '/panel/eye-images?patient_id=foreign&exam_id=foreign');
        const wrapper = await render({ patient, exam_id: 'different-exam' });
        expect(wrapper.find('.patient-item-active').exists()).toBe(false);
        expect(wrapper.find('[data-testid="review-viewer"]').element.style.display).toBe('none');
        expect(fetch).not.toHaveBeenCalled();
        window.history.replaceState({}, '', '/');
    });
});
