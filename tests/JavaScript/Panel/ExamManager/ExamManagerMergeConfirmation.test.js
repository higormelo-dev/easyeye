import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, shallowMount } from '@vue/test-utils';
import Index from '@/Pages/Panel/ExamManager/Index.vue';

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /><slot name="top-actions" /></div>' } }));

const wrappers = [];

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

async function render(t) {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({ json: async () => ({ urls: {}, url: '' }) })),
    );

    const wrapper = shallowMount(Index, {
        props: {
            entity: { id: 'entity-1' },
            patients: [],
            t,
            urls: { patient_urls: '/patients/__ID__/urls', image_url: '/exams/__ID__/url' },
        },
        global: { stubs: { Teleport: true }, renderStubDefaultSlot: true },
    });
    wrappers.push(wrapper);
    await flushPromises();

    return wrapper;
}

describe('ExamManager — confirmação de mesclar exames', () => {
    it('interpola a quantidade na tradução de merge_confirm_text', async () => {
        const fire = vi.fn().mockResolvedValue({ isConfirmed: false });
        vi.stubGlobal('Swal', { fire });
        const wrapper = await render({
            merge_confirm_text: 'As :count imagens escolhidas serão unidas.',
        });

        await wrapper.vm.confirmMerge(3);

        expect(fire).toHaveBeenCalledWith(expect.objectContaining({ text: 'As 3 imagens escolhidas serão unidas.' }));
    });
});
