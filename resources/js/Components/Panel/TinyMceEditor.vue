<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';

/**
 * TinyMceEditor — Wrapper Vue 3 para TinyMCE 8.
 *
 * Carrega o TinyMCE via <script> global (lazy, uma vez por sessão).
 * Inicializa quando montado e destrói quando desmontado, evitando leaks.
 * Suporta v-model + disabled + height customizável.
 *
 * Uso:
 *   <TinyMceEditor v-model="content" :height="300" />
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    height:     { type: [Number, String], default: 320 },
    placeholder:{ type: String, default: '' },
    disabled:   { type: Boolean, default: false },
    /** Toolbar customizada — se vazio usa default clínico (bold/lista/link/undo). */
    toolbar:    { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const textareaRef = ref(null);
let editorInstance = null;
let syncing = false;
let usingFallback = false;

/**
 * Carrega o script TinyMCE global apenas uma vez (cache no window).
 * Mesma estratégia do legado `docModalEditor.js`: usa o vendor já copiado
 * pelo script `tinymce:install` (post-install hook do package.json).
 */
function ensureTinyMceLoaded() {
    return new Promise((resolve, reject) => {
        if (window.tinymce) return resolve(window.tinymce);
        if (window.__tinymceLoading) {
            window.__tinymceLoading.then(resolve, reject);
            return;
        }
        const promise = new Promise((res, rej) => {
            const s = document.createElement('script');
            s.src = '/vendor/tinymce/tinymce.min.js';
            s.referrerPolicy = 'origin';
            s.onload = () => res(window.tinymce);
            s.onerror = () => rej(new Error('Falha ao carregar TinyMCE'));
            document.head.appendChild(s);
        });
        window.__tinymceLoading = promise;
        promise.then(resolve, reject);
    });
}

async function initEditor() {
    const tinymce = await ensureTinyMceLoaded();
    if (!textareaRef.value) return;

    const defaultToolbar =
        'undo redo | blocks | bold italic underline | bullist numlist | ' +
        'alignleft aligncenter alignright | link | removeformat | code';

    const editors = await tinymce.init({
        target: textareaRef.value,
        height: typeof props.height === 'number' ? props.height : Number(props.height) || 320,
        menubar: false,
        statusbar: false,
        branding: false,
        promotion: false,
        license_key: 'gpl',
        base_url: '/vendor/tinymce',
        suffix: '.min',
        language: 'pt_BR',
        language_url: '/vendor/tinymce-langs/langs7/pt_BR.js',
        placeholder: props.placeholder,
        plugins: ['lists', 'link', 'code', 'autolink'],
        toolbar: props.toolbar || defaultToolbar,
        content_style:
            'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;' +
            'font-size:.9rem;line-height:1.5;color:#1f2937;padding:.6rem;}',
        skin: 'oxide',
        content_css: 'default',
        setup: (editor) => {
            editor.on('init', () => {
                editor.setContent(props.modelValue ?? '');
                if (props.disabled) editor.mode.set('readonly');
            });
            editor.on('input change keyup undo redo', () => {
                if (syncing) return;
                const html = editor.getContent();
                emit('update:modelValue', html);
            });
        },
    });
    editorInstance = Array.isArray(editors) ? editors[0] : editors;
}

/**
 * Fallback: se o TinyMCE não carregar (vendor/CDN indisponível ou ambiente de
 * teste), mantém o <textarea> nativo editável e sincroniza o v-model na mão.
 * Evita unhandled rejection e degrada com elegância — o laudo nunca fica preso.
 */
function bindFallbackTextarea() {
    const el = textareaRef.value;
    if (!el) return;
    usingFallback = true;
    el.value = props.modelValue ?? '';
    el.classList.add('form-control');
    el.disabled = props.disabled;
    el.style.minHeight = (typeof props.height === 'number' ? props.height : Number(props.height) || 320) + 'px';
    el.addEventListener('input', () => emit('update:modelValue', el.value));
}

onMounted(async () => {
    await nextTick();
    try {
        await initEditor();
    } catch (e) {
        // eslint-disable-next-line no-console
        console.warn('[TinyMceEditor] fallback para textarea nativo:', e?.message ?? e);
        bindFallbackTextarea();
    }
});

onBeforeUnmount(() => {
    if (editorInstance) {
        try { editorInstance.remove(); } catch (e) { /* noop */ }
        editorInstance = null;
    }
});

// Sync externo → editor (quando parent muda o v-model)
watch(() => props.modelValue, (v) => {
    if (usingFallback) {
        if (textareaRef.value && textareaRef.value.value !== (v ?? '')) {
            textareaRef.value.value = v ?? '';
        }
        return;
    }
    if (!editorInstance) return;
    const current = editorInstance.getContent();
    if (current === (v ?? '')) return;
    syncing = true;
    editorInstance.setContent(v ?? '');
    syncing = false;
});

// Toggle readonly quando disabled muda
watch(() => props.disabled, (d) => {
    if (usingFallback) {
        if (textareaRef.value) textareaRef.value.disabled = d;
        return;
    }
    if (!editorInstance) return;
    editorInstance.mode.set(d ? 'readonly' : 'design');
});
</script>

<template>
    <textarea ref="textareaRef"></textarea>
</template>
