import { describe, it, expect, vi, afterEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { renderIndex } from './eyeImagesTestHelpers.js';
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /><slot name="top-actions" /></div>' } }));
let wrapper;
afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});
describe('Eye Images: solicitante', () => {
    it('restaura filtro, envia texto aparado e remove ao limpar', async () => {
        const result = await renderIndex({ filters: { requesting_doctor: 'Carlos' } });
        wrapper = result.wrapper;
        expect(wrapper.vm.showFilters).toBe(true);
        const input = wrapper.get('[data-testid="exam-requester-filter"]');
        expect(input.attributes('maxlength')).toBe('80');
        await input.setValue('  Dra. Ana  ');
        await wrapper.vm.fetchPatients();
        expect(
            new URL(result.fetchMock.mock.calls.at(-1)[0], 'https://local').searchParams.get('requesting_doctor'),
        ).toBe('Dra. Ana');
        wrapper.vm.clearFilters();
        await flushPromises();
        await wrapper.vm.fetchPatients();
        expect(
            new URL(result.fetchMock.mock.calls.at(-1)[0], 'https://local').searchParams.has('requesting_doctor'),
        ).toBe(false);
    });
    it('digitação dispara a busca pelo watcher e espaços não enviam parâmetro', async () => {
        const result = await renderIndex({ filters: { requesting_doctor: 'Ana' } });
        wrapper = result.wrapper;
        vi.useFakeTimers();
        await wrapper.get('[data-testid="exam-requester-filter"]').setValue('Carlos');
        await vi.advanceTimersByTimeAsync(400);
        expect(
            new URL(result.fetchMock.mock.calls.at(-1)[0], 'https://local').searchParams.get('requesting_doctor'),
        ).toBe('Carlos');
        await wrapper.get('[data-testid="exam-requester-filter"]').setValue('   ');
        await vi.advanceTimersByTimeAsync(400);
        expect(
            new URL(result.fetchMock.mock.calls.at(-1)[0], 'https://local').searchParams.has('requesting_doctor'),
        ).toBe(false);
    });
});
