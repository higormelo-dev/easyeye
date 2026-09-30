import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { defineComponent, h } from 'vue';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { useDashboardPolling } from '@/composables/useDashboardPolling.js';

/**
 * Atualização automática do Dashboard: "atualizado às" só avança quando os
 * dados chegam; em falha continua mostrando a última atualização boa.
 */

function mountPolling() {
    let api;
    const Host = defineComponent({
        setup() {
            api = useDashboardPolling(['stats'], 30_000);

            return () => h('div');
        },
    });
    const wrapper = mount(Host);

    return { api, wrapper };
}

const lastReloadOptions = () => vi.mocked(router.reload).mock.calls.at(-1)[0];

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-29T10:00:00'));
    vi.mocked(router.reload).mockClear();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('useDashboardPolling', () => {
    it('sucesso: atualiza o horário e libera nova atualização', () => {
        const { api, wrapper } = mountPolling();
        const before = api.lastUpdated.value.getTime();

        vi.setSystemTime(new Date('2026-09-29T10:00:30'));
        api.refresh();
        expect(api.isRefreshing.value).toBe(true);
        expect(lastReloadOptions()).toMatchObject({ only: ['stats'], preserveScroll: true });

        lastReloadOptions().onSuccess();
        lastReloadOptions().onFinish();

        expect(api.lastUpdated.value.getTime()).toBeGreaterThan(before);
        expect(api.isRefreshing.value).toBe(false);
        wrapper.unmount();
    });

    it('falha (rede, 500): mantém o horário da última atualização boa', () => {
        const { api, wrapper } = mountPolling();
        const before = api.lastUpdated.value.getTime();

        vi.setSystemTime(new Date('2026-09-29T10:00:30'));
        api.refresh();
        lastReloadOptions().onFinish(); // sem onSuccess

        expect(api.lastUpdated.value.getTime()).toBe(before);
        expect(api.isRefreshing.value).toBe(false);
        wrapper.unmount();
    });

    it('não dispara duas atualizações ao mesmo tempo', () => {
        const { api, wrapper } = mountPolling();

        api.refresh();
        api.refresh();

        expect(router.reload).toHaveBeenCalledOnce();
        wrapper.unmount();
    });
});
