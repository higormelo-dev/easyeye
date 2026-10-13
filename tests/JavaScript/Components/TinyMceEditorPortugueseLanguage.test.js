import { describe, it, expect, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import TinyMceEditor from '@/Components/Panel/TinyMceEditor.vue';

/**
 * EE-14: com locale pt_BR (prop Inertia `locale`, ver HandleInertiaRequests),
 * o editor configura o pacote de idioma real que existe em
 * public/vendor/tinymce-langs/langs7/pt_BR.js. Arquivo separado do resto do
 * TinyMceEditorRemount.test.js porque precisa de um usePage() próprio
 * (vi.mock é por arquivo) — ver lá o caso sem pt_BR (cai no inglês nativo).
 */
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR' } }),
}));

const original = window.tinymce;

afterEach(() => {
    window.tinymce = original;
});

function realisticTinymce(onInit) {
    window.tinymce = {
        init: (cfg) => {
            onInit?.(cfg);
            const container = document.createElement('div');
            container.className = 'tox-tinymce';
            cfg.target.style.display = 'none';
            cfg.target.parentNode.insertBefore(container, cfg.target.nextSibling);
            const editor = {
                getContent: () => '',
                setContent: () => {},
                mode: { set: () => {} },
                on: () => {},
                remove: () => {
                    container.remove();
                    cfg.target.style.display = '';
                },
            };
            cfg.setup?.(editor);
            return Promise.resolve([editor]);
        },
    };
}

describe('TinyMceEditor — idioma pt_BR (EE-14)', () => {
    it('com locale pt_BR, configura language e language_url do pacote real', async () => {
        let receivedConfig;
        realisticTinymce((cfg) => (receivedConfig = cfg));

        const w = mount(TinyMceEditor, { attachTo: document.body, props: { modelValue: '' } });
        await flushPromises();

        expect(receivedConfig.language).toBe('pt_BR');
        expect(receivedConfig.language_url).toBe('/vendor/tinymce-langs/langs7/pt_BR.js');
        w.unmount();
    });
});
