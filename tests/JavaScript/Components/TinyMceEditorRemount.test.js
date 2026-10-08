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

function realisticTinymce() {
    window.tinymce = {
        init: (cfg) => {
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
