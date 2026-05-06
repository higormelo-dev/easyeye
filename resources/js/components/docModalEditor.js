/**
 * docModalEditor — wires TinyMCE into modal textareas of the medical record form.
 *
 * Generic factory: registra um editor por (modalId, textareaSelector, alpinePath).
 *
 * Otimizações de perceived performance:
 *   1. Script `tinymce.min.js` é pré-carregado em background no init do Alpine
 *      (~500KB) — quando o usuário abre o 1º modal, o script já está pronto.
 *   2. Editor PERSISTE entre opens (não destrói em `hidden.bs.modal`) — re-abrir
 *      o modal é instantâneo. `editor.remove()` só ocorre em page navigation
 *      (handled pelo browser).
 *   3. Skin/content CSS pré-fetched via `<link rel=preload>` no init.
 *
 * Lifecycle por instância:
 *   - `shown.bs.modal` (1ª vez) → tinymce.init() + bind handlers
 *   - `shown.bs.modal` (subsequentes) → no-op (editor já vivo)
 *   - editor `input/change/keyup/SetContent` → push HTML para Alpine
 *   - Alpine `$watch(path)` → reflete mudanças externas (template preview, reset)
 *
 * Self-hosted via `public/vendor/tinymce` (npm package). Sem CDN externa —
 * requisito LGPD do SaaS de saúde.
 *
 * IMPORTANT (security): output renderizado em PDFs/views — sanitizar HTML
 * server-side via HTMLPurifier profile `medical` antes de persistir.
 */

const TINYMCE_BASE = '/vendor/tinymce';
const TINYMCE_SRC  = `${TINYMCE_BASE}/tinymce.min.js`;
const LANG_URL     = '/vendor/tinymce-langs/langs7/pt_BR.js';
const SYNCING_FLAG = '_tinymceSyncing';

let _tinymceLoading = null;

function loadTinyMce() {
    if (window.tinymce) return Promise.resolve(window.tinymce);
    if (_tinymceLoading) return _tinymceLoading;

    _tinymceLoading = new Promise((resolve, reject) => {
        const script   = document.createElement('script');
        script.src     = TINYMCE_SRC;
        script.async   = true;
        script.onload  = () => resolve(window.tinymce);
        script.onerror = () => reject(new Error('Falha ao carregar TinyMCE'));
        document.head.appendChild(script);
    });

    return _tinymceLoading;
}

/**
 * Pré-carrega assets críticos do TinyMCE (skin CSS, language) via `<link rel=preload>`
 * para que o navegador inicie o download em paralelo com a renderização da página.
 * Reduz o time-to-interactive do 1º open de modal.
 */
function preloadTinyMceAssets() {
    const assets = [
        { href: `${TINYMCE_BASE}/skins/ui/oxide/skin.min.css`, as: 'style' },
        { href: `${TINYMCE_BASE}/skins/content/default/content.min.css`, as: 'style' },
        { href: LANG_URL, as: 'script' },
    ];

    for (const { href, as } of assets) {
        if (document.querySelector(`link[rel="preload"][href="${href}"]`)) continue;
        const link  = document.createElement('link');
        link.rel    = 'preload';
        link.href   = href;
        link.as     = as;
        if (as === 'script') link.crossOrigin = 'anonymous';
        document.head.appendChild(link);
    }
}

/**
 * Resolve nested path (e.g. "docForm.content") em ctx → returns getter/setter.
 */
function bindPath(ctx, path) {
    const parts = path.split('.');
    const last  = parts.pop();
    const get   = () => parts.reduce((acc, k) => acc?.[k], ctx)?.[last];
    const set   = (v) => {
        const parent = parts.reduce((acc, k) => acc?.[k], ctx);
        if (parent) parent[last] = v;
    };
    return { get, set };
}

function buildConfig(selector, alpineCtx, statePath, overrides = {}) {
    const { set } = bindPath(alpineCtx, statePath);

    return {
        // `selector` (string) é mais robusto que `target` (Element) — TinyMCE
        // resolve no momento do init, evitando NPE em parentNode quando timing
        // de Bootstrap modal colide com Alpine x-model rebind.
        selector,
        license_key: 'gpl',
        language: 'pt_BR',
        language_url: LANG_URL,
        height: 320,
        menubar: false,
        branding: false,
        promotion: false,
        plugins: 'lists link table autolink wordcount code searchreplace preview fullscreen',
        toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link table | searchreplace | code preview fullscreen',
        content_style: 'body{font-family:system-ui,-apple-system,sans-serif;font-size:13px;line-height:1.5;}',
        paste_data_images: false,
        invalid_elements: 'script,iframe,object,embed,form',
        ...overrides,
        setup(editor) {
            const push = () => {
                if (alpineCtx[SYNCING_FLAG]) return;
                set(editor.getContent());
            };
            editor.on('input change keyup undo redo SetContent', push);
            if (typeof overrides.setup === 'function') overrides.setup(editor);
        },
    };
}

/**
 * Registra um editor TinyMCE para um modal específico.
 *
 * @param {object}  alpineCtx
 * @param {object}  options
 * @param {string}  options.modalId         ID do modal Bootstrap (sem #).
 * @param {string}  options.selector        Selector CSS do textarea alvo.
 * @param {string}  options.statePath       Caminho dot-notation no Alpine.
 * @param {string} [options.editorFlag]     Flag interna do editor (default: derivado).
 * @param {object} [options.config]         Overrides para config TinyMCE.
 */
function registerEditor(alpineCtx, { modalId, selector, statePath, editorFlag, config = {} }) {
    const modalEl = document.getElementById(modalId);
    if (!modalEl) return;

    const flag = editorFlag || `_tinymceEditor__${modalId}`;
    const { get } = bindPath(alpineCtx, statePath);

    modalEl.addEventListener('shown.bs.modal', async () => {
        // Editor já vivo — apenas re-sincroniza conteúdo (caso state tenha mudado
        // enquanto modal estava fechado, ex: openExam carregou novo template).
        if (alpineCtx[flag]) {
            const editor = alpineCtx[flag];
            const val    = get() || '';
            if (editor.getContent() !== val) {
                alpineCtx[SYNCING_FLAG] = true;
                editor.setContent(val);
                alpineCtx[SYNCING_FLAG] = false;
            }
            return;
        }

        try {
            const tinymce = await loadTinyMce();

            // Defer 1 frame: shown.bs.modal dispara antes do paint final do
            // navegador. Iniciar TinyMCE imediatamente pode bater num textarea
            // ainda em transição (parentNode null em alguns browsers).
            await new Promise((resolve) => requestAnimationFrame(resolve));

            const target = document.querySelector(selector);
            if (!target || !target.isConnected) {
                console.warn(`[docModalEditor:${modalId}] textarea ${selector} not connected to DOM`);
                return;
            }

            const editors = await tinymce.init(buildConfig(selector, alpineCtx, statePath, config));
            const editor  = Array.isArray(editors) ? editors[0] : editors;
            if (!editor) return;

            alpineCtx[flag] = editor;
            editor.setContent(get() || '');
        } catch (e) {
            console.error(`[docModalEditor:${modalId}]`, e);
        }
    });

    // NOTA: editor NÃO é destruído em `hidden.bs.modal` — persiste para que
    // reabrir o modal seja instantâneo. Memória liberada apenas em navegação.

    // Reflete mudanças externas (previewTemplate, openExam, reset) sem loop
    // com push() do setup — flag SYNCING_FLAG bloqueia o handler do editor.
    alpineCtx.$watch(statePath, (val) => {
        const editor = alpineCtx[flag];
        if (!editor) return;
        if (editor.getContent() === (val || '')) return;
        alpineCtx[SYNCING_FLAG] = true;
        editor.setContent(val || '');
        alpineCtx[SYNCING_FLAG] = false;
    });
}

/**
 * Inicializa todos os editores TinyMCE dos modais do prontuário.
 * Chamado uma única vez no Alpine `init()` do componente.
 *
 * Pré-carrega script + assets imediatamente (background) para que a abertura
 * do 1º modal não pague o custo do download.
 */
export function initDocModalEditor(alpineCtx) {
    // 1. Pré-carrega assets em background (não bloqueia init do Alpine).
    preloadTinyMceAssets();

    // 2. Pré-fetch do script principal — quando user abrir o modal, já está
    //    parseado e na cache do browser.
    loadTinyMce().catch(() => { /* warning já no console */ });

    // 3. Registra handlers shown/watch para cada modal.
    registerEditor(alpineCtx, {
        modalId:   'docModal',
        selector:  '#doc-modal-content',
        statePath: 'docForm.content',
    });

    registerEditor(alpineCtx, {
        modalId:   'attendanceCertificateModal',
        selector:  '#attendance-cert-content',
        statePath: 'attendanceForm.content',
        config: {
            height: 220,
            toolbar: 'undo redo | bold italic underline | bullist numlist | searchreplace',
            plugins: 'lists autolink wordcount searchreplace',
        },
    });

    registerEditor(alpineCtx, {
        modalId:   'medicalCertificateModal',
        selector:  '#medical-cert-content',
        statePath: 'medicalForm.content',
        config: {
            height: 220,
            toolbar: 'undo redo | bold italic underline | bullist numlist | searchreplace',
            plugins: 'lists autolink wordcount searchreplace',
        },
    });
}
