import { describe, it, expect, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, h, ref, nextTick } from 'vue';
import TinyMceEditor from '@/Components/Panel/TinyMceEditor.vue';

/**
 * TinyMCE de verdade esconde o <textarea> e põe o editor como IRMÃO dele.
 * Trocar o editor por :key (seletor de modelos das Documentações) fazia o
 * Vue usar esse irmão — já removido pelo TinyMCE no unmount — como âncora:
 * "insertBefore ... not a child" e a tela parava de atualizar. O stub
 * abaixo imita o TinyMCE real (container irmão, removido no remove()).
 */
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

describe('TinyMceEditor — troca por :key', () => {
    it('remonta o editor sem quebrar o DOM do Vue', async () => {
        realisticTinymce();
        const key = ref('a');
        const Host = defineComponent({
            setup: () => () =>
                h('div', { class: 'host' }, [
                    h('label', 'Conteúdo'),
                    h(TinyMceEditor, { key: key.value, modelValue: '' }),
                ]),
        });
        const errors = [];
        const w = mount(Host, { attachTo: document.body, global: { config: { errorHandler: (e) => errors.push(e) } } });
        await flushPromises();
        expect(w.findAll('.tox-tinymce')).toHaveLength(1);
        // Causa: o editor do TinyMCE precisa ficar DENTRO da raiz do
        // componente (a âncora do Vue nunca é um nó que o TinyMCE remove).
        const root = w.findComponent(TinyMceEditor).element;
        expect(root.contains(w.find('.tox-tinymce').element)).toBe(true);

        key.value = 'b';
        await nextTick();
        await flushPromises();
        key.value = 'c';
        await nextTick();
        await flushPromises();

        expect(errors).toEqual([]);
        expect(w.findAll('.tox-tinymce')).toHaveLength(1);
        w.unmount();
    });
});

describe('TinyMceEditor — idioma (EE-14)', () => {
    /**
     * Sem locale pt_BR (aqui: mock global de usePage() sem a prop `locale`,
     * ver tests/JavaScript/setup.js) o TinyMCE não deve receber language_url
     * apontando pra um arquivo que não existe em disco (só existe pt_BR.js
     * em public/vendor/tinymce-langs/langs7/) — cai no inglês nativo dele.
     */
    it('sem locale pt_BR, não configura language/language_url (cai no inglês nativo)', async () => {
        let receivedConfig;
        realisticTinymce((cfg) => (receivedConfig = cfg));

        const w = mount(TinyMceEditor, { attachTo: document.body, props: { modelValue: '' } });
        await flushPromises();

        expect(receivedConfig.language).toBeUndefined();
        expect(receivedConfig.language_url).toBeUndefined();
        w.unmount();
    });
});
