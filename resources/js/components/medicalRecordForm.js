/**
 * medicalRecordForm — Alpine.js component for the 7-tab medical record form.
 *
 * Usage in Blade:
 *   x-data="medicalRecordForm({ isEdit: true, diabetic: false, ... })"
 */
import { validatePayload } from '../utils/formRulesValidator.js';
import { initDocModalEditor } from './docModalEditor.js';

export default ({
    isEdit = false,
    validationRulesUrl = '',
    diabetic = false,
    diabeticFamily = false,
    hypertensive = false,
    hypertensiveFamily = false,
    glaucomatous = false,
    glaucomatousFamily = false,
    showOthersHistory = false,
    tonometryPdfUrl = '',
    storeTonometryUrl = '',
    savedTonometryOd = null,
    savedTonometryOe = null,
    savedTonometryTime = null,
    doctorId = '',
    calcPresbyopiaUrl = '',
    dynamicSphericalRight = 0,
    dynamicSphericalLeft = 0,
    templatesUrl = '',
    templatePreviewUrl = '',
    storeDocUrl = '',
    storeFileUrl = '',
    quickActionUrlTemplate = '',
    examTemplateUrlTemplate = '',
    medicineSearchUrl = '',
    medicationFormatLineUrl = '',
    procedureSearchUrl = '',
    indicationSearchUrl = '',
    procedureFormatLineUrl = '',
    dayExtensionPreviewUrl = '',
    lensFormatUrl = '',
    i18n = {},
} = {}) => ({
    // ── i18n strings (injectadas via Blade @js()) ───────────────────────
    i18n,

    // ── Validação client-side (F9) ──────────────────────────────────────
    validationRulesUrl,
    validationRules: {},
    clientErrors: {},
    hasClientErrors: false,

    // ── Boolean switches ────────────────────────────────────────────────
    isEdit,
    diabetic,
    diabeticFamily,
    hypertensive,
    hypertensiveFamily,
    glaucomatous,
    glaucomatousFamily,
    showOthersHistory,

    // ── Tonometry print ─────────────────────────────────────────────────
    tonometryPdfUrl,
    storeTonometryUrl,
    doctorId,
    savedTonometryOd,
    savedTonometryOe,
    savedTonometryTime,
    tonometryPdfSrc: '',
    /**
     * Horário capturado no momento clínico da medição (HH:mm:ss).
     * Persistido no hidden tonometer_time via x-bind, garantindo paridade
     * com smart_oftal: o tempo reflete quando OE foi medido, não quando se imprime.
     */
    tonometryStampedTime: savedTonometryTime || '',

    /** Relógio ao vivo (HH:mm:ss) — atualizado a cada 1s. */
    liveTime: '',
    _liveTimeInterval: null,

    // ── Refraction state ────────────────────────────────────────────────
    dynamicSphericalRight,
    dynamicSphericalLeft,
    presbyopiaAddition: 0,
    staticSphericalRight: 0,
    staticSphericalLeft: 0,

    // ── Documentation state ─────────────────────────────────────────────
    docTemplates: [],
    docForm: {
        report_setting_content_id: '',
        title: '',
        content: '',
        exam_type: '',
        exam_subtype: '',
        exam_label: '',
    },
    docSaving: false,
    quickActionUrlTemplate,
    examTemplateUrlTemplate,
    quickActionBusy: false,

    // ── Receituário de medicamentos (F5) ─────────────────────────────────
    medicineSearchUrl,
    medicationFormatLineUrl,
    /** @type {Array<{id:string,name:string,presentation:?string,dosage:?string,frequency:?string,duration:?string,instructions:?string}>} */
    prescription: [],
    medicineLists: '',
    medSearchQuery: '',
    medSearchResults: [],
    medSearchOpen: false,
    medSearchLoading: false,
    maxMedicines: 5,

    // ── Solicitação de procedimentos (F6) ────────────────────────────────
    procedureSearchUrl,
    indicationSearchUrl,
    procedureFormatLineUrl,
    /** @type {Array<{id:string,name:string,code:?string,type:?string,type_label:?string}>} */
    procSelected: [],
    /** @type {Array<{id:string,description:string}>} */
    indSelected: [],
    procedureLists: '',
    procSearchQuery: '',
    procSearchResults: [],
    procSearchOpen: false,
    procSearchLoading: false,
    procTypeSelected: '',
    indSearchQuery: '',
    indSearchResults: [],
    indSearchOpen: false,
    indSearchLoading: false,
    /** Limite combinado (procedure + indication) — protege textarea de overflow visual. */
    maxProcSolicitations: 10,

    // ── Atestados (F7) ───────────────────────────────────────────────────
    dayExtensionPreviewUrl,
    attendanceForm: {
        content: '',
    },
    medicalForm: {
        days: 1,
        date: '',
        content: '',
        daysPreview: '',
    },

    // ── Receituário de catarata (F8) ─────────────────────────────────────
    /**
     * Estado do modal de catarata. `eye` é canônico (right/left/both) e
     * o backend mapeia para PT-BR via SurgerySchedulingDocService.
     */
    cataractForm: {
        eye: 'right',
        template: 'pre_operatorio',
        date_surgery: '',
        hour_surgery: '',
    },

    // ── Cálculo de Presbiopia — modal de observação de lentes ────────────
    presbyopiaObsForm: {
        content: '',
    },

    // ── File upload state ───────────────────────────────────────────────
    uploadedFiles: [],
    uploading: false,
    uploadProgress: 0,

    // ── PDF preview universal (substitui .print-documentation legado) ───
    pdfPreviewUrl: '',
    pdfPreviewTitle: '',

    // ── Clinical alerts (paciente / familiar — feedback fade) ───────────
    alertVisible: { diabetic: '', hypertensive: '', glaucomatous: '' },
    _alertTimers: { diabetic: null, hypertensive: null, glaucomatous: null },

    /**
     * Hook automático do Alpine: dispara quando o componente é montado.
     * Inicializa o relógio ao vivo (paridade smart_oftal `displayTime`).
     */
    init() {
        const tick = () => { this.liveTime = new Date().toTimeString().slice(0, 8); };
        tick();
        this._liveTimeInterval = setInterval(tick, 1000);

        // F9 — carregamento assíncrono. Validador degrada silenciosamente para
        // server-only se o fetch falhar, sem bloquear o form.
        this._fetchValidationRules();

        // TinyMCE no #docModal — load lazy, init/destroy por evento Bootstrap.
        initDocModalEditor(this);
    },


    async _fetchValidationRules() {
        if (!this.validationRulesUrl) return;
        try {
            const res = await fetch(this.validationRulesUrl, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });
            if (!res.ok) return;
            const data = await res.json();
            this.validationRules = data?.rules || {};
        } catch (e) {
            console.warn('[F9] failed to load validation rules:', e);
        }
    },

    /**
     * F9 — coleta valores diretamente do form e valida antes de submeter.
     * Cancela submit em caso de erro e exibe alerta acumulado.
     *
     * Estratégia: ler `form.elements` em vez de espelhar todo state Alpine —
     * funciona com inputs nativos, hidden + Alpine `x-model` indistintamente.
     */
    validateBeforeSubmit(event) {
        if (!this.validationRules || Object.keys(this.validationRules).length === 0) {
            return; // rules ainda não chegaram → confia no servidor.
        }

        const form    = event.target;
        const payload = {};

        // Espelha `prepareForValidation` do FormRequest: campos exibidos com
        // sufixo "º" (eixo refrativo) precisam ser limpos antes da validação
        // numérica client-side. Servidor já faz preg_replace equivalente.
        const AXIS_FIELDS = [
            'dynamic_axis_right', 'dynamic_axis_left',
            'static_axis_right',  'static_axis_left',
        ];

        for (const el of form.elements) {
            if (!el.name) continue;
            const name = el.name.replace('[]', '');
            if (el.type === 'checkbox' && !el.checked) continue;
            if (el.type === 'radio' && !el.checked)    continue;

            let value = el.value;
            if (AXIS_FIELDS.includes(name) && typeof value === 'string') {
                value = value.replace(/[^\d-]/g, '');
            }

            // Múltiplas ocorrências (ex: arrays) → último valor não captura nested.
            // Validação nested só roda no servidor (FormRequestRulesExporter ignora).
            payload[name] = value;
        }

        const labels = {
            doctor_id:        this.i18n.field_doctor        ?? 'Physician',
            main_complaint:   this.i18n.field_complaint     ?? 'Chief complaint',
            pachymetry_right: this.i18n.field_pachymetry_od ?? 'OD Pachymetry',
            pachymetry_left:  this.i18n.field_pachymetry_oe ?? 'OS Pachymetry',
            follow_up_days:   this.i18n.field_follow_up     ?? 'Follow-up days',
        };

        const result = validatePayload(payload, this.validationRules, labels);

        if (!result.valid) {
            event.preventDefault();
            this.clientErrors    = result.errors;
            this.hasClientErrors = true;

            // UX: rola até o alerta para o médico ver imediatamente o que falhou.
            this.$nextTick(() => {
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
            return false;
        }

        this.clientErrors    = {};
        this.hasClientErrors = false;
    },

    /** Limpa o intervalo quando o componente é desmontado (evita leak). */
    destroy() {
        if (this._liveTimeInterval) clearInterval(this._liveTimeInterval);
    },

    normalizeDocTemplates(payload) {
        if (Array.isArray(payload)) {
            return payload;
        }

        if (!payload || typeof payload !== 'object') {
            return [];
        }

        return Object.entries(payload).map(([id, group]) => ({
            report_setting_id: group.report_setting_id ?? id,
            report_setting_title: group.report_setting_title ?? group.title ?? '',
            contents: Array.isArray(group.contents) ? group.contents : [],
        }));
    },

    escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    // ── Tonometry PDF ───────────────────────────────────────────────────
    _tonometryModal() {
        return bootstrap.Modal.getOrCreateInstance(document.getElementById('tonometryModal'));
    },

    // ── PDF preview universal ───────────────────────────────────────────
    _pdfPreviewModal() {
        const el = document.getElementById('pdfPreviewModal');
        return el ? bootstrap.Modal.getOrCreateInstance(el) : null;
    },

    // ── Presbiopia — modal de observação ───────────────────────────────
    _presbyopiaObsModal() {
        const el = document.getElementById('presbyopiaObsModal');
        return el ? bootstrap.Modal.getOrCreateInstance(el) : null;
    },

    openPresbyopiaCalc() {
        // Pré-carrega o conteúdo atual do campo "Observação de lentes".
        const existing = document.querySelector('[name="observation_of_lenses"]');
        this.presbyopiaObsForm.content = existing?.value ?? '';
        this._presbyopiaObsModal()?.show();
    },

    confirmPresbyopiaCalc() {
        // Grava a observação no campo do formulário e dispara o cálculo.
        const obs = document.querySelector('[name="observation_of_lenses"]');
        if (obs) {
            obs.value = this.presbyopiaObsForm.content;
            obs.dispatchEvent(new Event('input', { bubbles: true }));
        }
        this._presbyopiaObsModal()?.hide();
        this.calcPresbyopia();
    },

    openPdfPreview(url, title = '') {
        if (!url) return;
        this.pdfPreviewUrl = url;
        this.pdfPreviewTitle = title;
        this._pdfPreviewModal()?.show();
    },

    closePdfPreview() {
        this._pdfPreviewModal()?.hide();
        this.pdfPreviewUrl = '';
        this.pdfPreviewTitle = '';
    },

    // ── Clinical alerts (paciente / familiar) ───────────────────────────
    flashAlert(group, kind) {
        if (!['diabetic', 'hypertensive', 'glaucomatous'].includes(group)) return;
        this.alertVisible[group] = kind; // 'self' ou 'family'
        if (this._alertTimers[group]) clearTimeout(this._alertTimers[group]);
        this._alertTimers[group] = setTimeout(() => { this.alertVisible[group] = ''; }, 1800);
    },

    // ── Lens auto-format (paridade smart_oftal patients.formatlense) ────
    async formatLens(kind, name) {
        if (!lensFormatUrl) return;
        const input = document.querySelector(`[name="${name}"]`);
        if (!input) return;
        const value = input.value;
        if (value === '' || value == null) return;

        try {
            const res = await fetch(lensFormatUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ kind, value: String(value) }),
            });
            if (!res.ok) return;
            const data = await res.json();
            if (typeof data.value === 'string') {
                input.value = data.value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        } catch (e) {
            console.error('Lens format error:', e);
        }
    },

    focusNextLensField(currentName) {
        const order = [
            'dynamic_spherical_right', 'dynamic_cylindrical_right', 'dynamic_axis_right',
            'dynamic_spherical_left', 'dynamic_cylindrical_left', 'dynamic_axis_left',
            'static_spherical_right', 'static_cylindrical_right', 'static_axis_right',
            'static_spherical_left', 'static_cylindrical_left', 'static_axis_left',
        ];
        const idx = order.indexOf(currentName);
        if (idx === -1 || idx + 1 >= order.length) return;
        const next = document.querySelector(`[name="${order[idx + 1]}"]`);
        if (next) {
            next.focus();
            if (typeof next.select === 'function') next.select();
        }
    },

    // ── Receituário de óculos: 4 modos (paridade smart_oftal templates 1..4) ─
    issueLensPrescription(mode) {
        if (!['dynamic', 'static', 'presbyopia_dynamic', 'presbyopia'].includes(mode)) return;
        return this.issueQuickAction('lens-prescription', { mode }, { preview: true });
    },

    appendDocumentationRow(doc, prepend = false) {
        const tbody = document.querySelector('#pmr-docs-tbody');
        if (!tbody) return;

        const emptyRow = tbody.querySelector('[data-empty]') ?? tbody.querySelector('td[colspan]')?.closest('tr');
        if (emptyRow) emptyRow.remove();

        const typeLabel = this.escapeHtml(doc.type_label);
        const title = this.escapeHtml(doc.title);
        const createdAt = this.escapeHtml(doc.created_at);
        const pdfUrl = doc.pdf_url ?? '#';

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><span class="badge bg-info-subtle text-info">${typeLabel}</span></td>
            <td>${title}</td>
            <td>${createdAt}</td>
            <td class="text-end">
                <a href="${pdfUrl}" target="_blank" class="btn btn-outline-secondary btn-sm" title="PDF">
                    <i class="fas fa-file-pdf"></i>
                </a>
            </td>`;

        if (prepend) {
            tbody.prepend(tr);
            return;
        }

        tbody.appendChild(tr);
    },

    /**
     * Captura o horário atual no formato HH:mm:ss e armazena em tonometryStampedTime.
     * Disparado automaticamente no blur do campo OE e manualmente pelo botão de relógio.
     *
     * @param {boolean} force  Se true, sobrescreve mesmo se já houver horário registrado.
     */
    stampTonometryTime(force = false) {
        if (!force && this.tonometryStampedTime) return;
        const odEl = document.querySelector('[name="tonometer_right"]');
        const oeEl = document.querySelector('[name="tonometer_left"]');
        // só carimba se as duas medidas existem (paridade: tonometer_left blur w/ both filled)
        if (!force && (!odEl?.value || !oeEl?.value)) return;
        this.tonometryStampedTime = new Date().toTimeString().slice(0, 8);
    },

    async printTonometry() {
        const od   = document.querySelector('[name="tonometer_right"]')?.value ?? '';
        const oe   = document.querySelector('[name="tonometer_left"]')?.value ?? '';

        // Bloqueia print sem médico — admin não tem doctorId auto-resolvido.
        // Médico logado tem doctorId vindo do backend (currentDoctor) → não bloqueia.
        const doctorIdVal = this.doctorId || document.querySelector('[name="doctor_id"]')?.value || '';
        if (!doctorIdVal) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: this.i18n.doctor_required_title     ?? 'Physician required',
                    text:  this.i18n.doctor_required_for_print ?? 'Please select the responsible physician before printing.',
                });
            } else {
                alert(this.i18n.doctor_required_for_print ?? 'Please select the responsible physician before printing.');
            }
            return;
        }

        // se ainda não houver carimbo (ex: usuário clicou print sem dar blur em OE), captura agora
        if (!this.tonometryStampedTime) this.stampTonometryTime(true);
        const time = (this.tonometryStampedTime || new Date().toTimeString().slice(0, 8)).slice(0, 5);

        // Edit mode: salva no histórico e abre PDF da documentação salva.
        if (this.storeTonometryUrl) {
            try {
                const res = await fetch(this.storeTonometryUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ od, oe, time, doctor_id: doctorIdVal }),
                });

                if (res.ok) {
                    const doc = await res.json();
                    this.appendDocumentationRow(doc, true);

                    this.tonometryPdfSrc = doc.pdf_url;
                    this._tonometryModal().show();
                    return;
                }
            } catch (e) {
                console.error('Tonometry save error:', e);
            }
        }

        // Create mode: abre PDF direto sem salvar histórico.
        const params = new URLSearchParams({ time, od, oe, doctor_id: doctorIdVal });
        this.tonometryPdfSrc = `${this.tonometryPdfUrl}?${params.toString()}`;
        this._tonometryModal().show();
    },

    closeTonometry() {
        this._tonometryModal().hide();
        this.tonometryPdfSrc = '';
    },

    // ── Presbyopia calculation ──────────────────────────────────────────
    async calcPresbyopia() {
        if (!calcPresbyopiaUrl) return;

        try {
            const res = await fetch(calcPresbyopiaUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    dynamic_spherical_right: this.dynamicSphericalRight,
                    dynamic_spherical_left: this.dynamicSphericalLeft,
                    addition: this.presbyopiaAddition,
                }),
            });

            if (!res.ok) return;

            const data = await res.json();
            this.staticSphericalRight = data.static_spherical_right ?? data.right ?? 0;
            this.staticSphericalLeft = data.static_spherical_left ?? data.left ?? 0;
        } catch (e) {
            console.error('Presbyopia calc error:', e);
        }
    },

    // ── Documentation: load templates and open modal ────────────────────
    async openNewDoc() {
        if (!templatesUrl) return;

        this.resetDocForm();

        try {
            const res = await fetch(templatesUrl, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });

            if (res.ok) {
                const payload = await res.json();
                this.docTemplates = this.normalizeDocTemplates(payload);
            }
        } catch (e) {
            console.error('Failed to load templates:', e);
        }

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('docModal'));
        modal.show();
    },

    // ── Documentation: open modal pre-selecting a specific type ─────────
    async openNewDocByType(type) {
        await this.openNewDoc();

        // Try to auto-select the first template of this type
        for (const group of this.docTemplates) {
            const tpl = (group.contents || []).find(c => c.type === type);
            if (tpl) {
                this.docForm.report_setting_content_id = tpl.id;
                await this.previewTemplate();
                break;
            }
        }
    },

    /**
     * Abre o modal de documentação no fluxo de **laudo de exame** (F4).
     * Diferente de openNewDoc (template livre), aqui o template é resolvido
     * server-side a partir do `ExamReportRegistry` e o conteúdo já vem
     * pré-populado com placeholders clínicos do prontuário.
     *
     * O save passa por quick-action `exam-report` (não pelo store padrão de doc),
     * preservando trilha de auditoria via MedicalRecordQuickActionService.
     */
    /**
     * Sequencia hide do hub → openExam → show do docModal. Evita race
     * entre transições do Bootstrap (DOM transicional causava NPE em
     * `parentNode` no init do TinyMCE) e remove focus pendente do
     * botão clicado (resolve warning `aria-hidden on focused descendant`).
     */
    async openExamFromHub(examType, subtype = null) {
        if (document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }

        const hubEl = document.getElementById('examHubModal');
        const hub   = hubEl ? bootstrap.Modal.getInstance(hubEl) : null;

        if (hub && hubEl.classList.contains('show')) {
            await new Promise((resolve) => {
                hubEl.addEventListener('hidden.bs.modal', resolve, { once: true });
                hub.hide();
            });
        }

        await this.openExam(examType, subtype);
    },

    async openExam(examType, subtype = null) {
        if (!examType || !this.examTemplateUrlTemplate) return;

        let url = this.examTemplateUrlTemplate.replace('__EXAM__', encodeURIComponent(examType));
        if (subtype) {
            const sep = url.includes('?') ? '&' : '?';
            url = `${url}${sep}subtype=${encodeURIComponent(subtype)}`;
        }

        try {
            const res = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });

            if (!res.ok) {
                let msg = 'Não foi possível carregar o template do exame.';
                let isMissingTemplate = false;
                try {
                    const err = await res.json();
                    msg = err.message ?? msg;
                    isMissingTemplate = res.status === 422 && /template não encontrado/i.test(msg);
                } catch (_) {}

                if (isMissingTemplate && typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Template não cadastrado',
                        html: `<p class="text-muted small mb-0">Este exame ainda não tem template cadastrado para a clínica ativa.</p>
                               <p class="text-muted small mb-0">Cadastre em <strong>Settings → Templates de Documentos</strong>.</p>
                               <hr><code class="small text-secondary">${msg}</code>`,
                    });
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Erro', text: msg });
                }
                return;
            }

            const data = await res.json();

            this.resetDocForm();
            this.docForm.exam_type    = data.exam_type;
            this.docForm.exam_subtype = data.subtype || '';
            this.docForm.exam_label   = data.label || '';
            this.docForm.title        = data.title || data.label || '';
            this.docForm.content      = data.html || '';

            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('docModal'));
            modal.show();

            // Defensivo: se modal já estava visível (re-open via hub), shown.bs.modal
            // não dispara → TinyMCE não re-inicia. Força sync manualmente.
            this.$nextTick(() => {
                const editor = this._tinymceEditor__docModal;
                if (editor && editor.getContent() !== (this.docForm.content || '')) {
                    this._tinymceSyncing = true;
                    editor.setContent(this.docForm.content || '');
                    this._tinymceSyncing = false;
                }
            });
        } catch (e) {
            console.error('Open exam template error:', e);
        }
    },

    resetDocForm() {
        // Mutar campos individualmente em vez de substituir o objeto inteiro:
        // Alpine `$watch('docForm.content', ...)` (usado por docModalEditor)
        // pode perder updates quando o objeto raiz é re-atribuído.
        this.docForm.report_setting_content_id = '';
        this.docForm.title                     = '';
        this.docForm.content                   = '';
        this.docForm.exam_type                 = '';
        this.docForm.exam_subtype              = '';
        this.docForm.exam_label                = '';
    },

    // ── Documentation: preview template content ─────────────────────────
    async previewTemplate() {
        if (!this.docForm.report_setting_content_id || !templatePreviewUrl) return;

        try {
            const res = await fetch(templatePreviewUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    report_setting_content_id: this.docForm.report_setting_content_id,
                }),
            });

            if (res.ok) {
                const data = await res.json();
                this.docForm.content = data.content ?? '';

                // Auto-fill title from the selected template label
                const tpl = this.docTemplates
                    .flatMap((g) => g.contents || [])
                    .find(c => c.id === this.docForm.report_setting_content_id);
                if (tpl && !this.docForm.title) {
                    this.docForm.title = tpl.label;
                }
            }
        } catch (e) {
            console.error('Template preview error:', e);
        }
    },

    /**
     * Persiste laudo de exame (F4) via quick-action `exam-report`.
     * Reusa o pipeline `MedicalRecordQuickActionService` → trilha de auditoria
     * + Auditable trait + replacement de `{{CONTEUDO_LIVRE}}`.
     */
    async saveExamReport() {
        const url = this.buildQuickActionUrl('exam-report');
        if (!url) return;

        this.docSaving = true;

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    exam_type: this.docForm.exam_type,
                    subtype:   this.docForm.exam_subtype || null,
                    content:   this.docForm.content,
                    title:     this.docForm.title,
                }),
            });

            if (!res.ok) {
                let msg = 'Erro ao emitir o laudo.';
                try { msg = (await res.json()).message ?? msg; } catch (_) {}
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Erro', text: msg });
                }
                return;
            }

            const doc = await res.json();
            this.appendDocumentationRow(doc, true);
            bootstrap.Modal.getInstance(document.getElementById('docModal'))?.hide();
            this.resetDocForm();

            if (doc.pdf_url) {
                this.openPdfPreview(doc.pdf_url, doc.title || '');
            }
        } catch (e) {
            console.error('Save exam report error:', e);
        } finally {
            this.docSaving = false;
        }
    },

    // ── Documentation: save ─────────────────────────────────────────────
    async saveDoc() {
        if (this.docSaving) return;
        // Branch F4: laudo de exame → quick-action/exam-report
        if (this.docForm.exam_type) {
            return this.saveExamReport();
        }
        if (!storeDocUrl) return;
        this.docSaving = true;

        try {
            const res = await fetch(storeDocUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(this.docForm),
            });

            if (!res.ok) {
                const err = await res.json();
                const msg = err.message ?? 'Erro ao salvar documentação.';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Erro', text: msg });
                }
                return;
            }

            const doc = await res.json();
            this.appendDocumentationRow(doc);

            // Close modal and reset
            bootstrap.Modal.getInstance(document.getElementById('docModal'))?.hide();
            this.resetDocForm();

            if (typeof $.toast === 'function') {
                $.toast({
                    heading: 'Sucesso',
                    text: 'Documentação salva com sucesso.',
                    position: 'top-right',
                    loaderBg: '#006A4E',
                    icon: 'success',
                    hideAfter: 3500,
                    stack: 6,
                });
            }
        } catch (e) {
            console.error('Save documentation error:', e);
        } finally {
            this.docSaving = false;
        }
    },

    // ── Quick actions (paridade legado) ─────────────────────────────────
    buildQuickActionUrl(action) {
        if (!this.quickActionUrlTemplate) return '';

        return this.quickActionUrlTemplate.replace('__ACTION__', action);
    },

    async issueQuickAction(action, payload = {}, { openPdf = true, preview = false } = {}) {
        const url = this.buildQuickActionUrl(action);
        if (!url || this.quickActionBusy) return;

        // Bloqueio de autoria: admin precisa ter selecionado médico antes.
        const doctorIdVal = this.doctorId || document.querySelector('[name="doctor_id"]')?.value || '';
        if (!doctorIdVal) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: this.i18n.doctor_required_title     ?? 'Physician required',
                    text:  this.i18n.doctor_required_for_issue ?? 'Please select the responsible physician before issuing a document.',
                });
            } else {
                alert(this.i18n.doctor_required_for_issue ?? 'Please select the responsible physician before issuing a document.');
            }
            return;
        }

        this.quickActionBusy = true;

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload),
            });

            if (!res.ok) {
                let message = 'Não foi possível emitir o documento.';

                try {
                    const err = await res.json();
                    message = err.message ?? message;
                } catch (_) {
                    // noop
                }

                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Erro', text: message });
                }

                return;
            }

            const doc = await res.json();
            this.appendDocumentationRow(doc, true);

            if (typeof $.toast === 'function') {
                $.toast({
                    heading: 'Sucesso',
                    text: 'Documento emitido com sucesso.',
                    position: 'top-right',
                    loaderBg: '#006A4E',
                    icon: 'success',
                    hideAfter: 3500,
                    stack: 6,
                });
            }

            if (preview && doc.pdf_url) {
                this.openPdfPreview(doc.pdf_url, doc.title || '');
            } else if (openPdf && doc.pdf_url) {
                window.open(doc.pdf_url, '_blank', 'noopener');
            }
        } catch (e) {
            console.error('Quick action error:', e);
        } finally {
            this.quickActionBusy = false;
        }
    },

    /**
     * F7 — Atestados editáveis com modal builder.
     *
     * Backwards-compat: chamadas legadas a `issueMedicalCertificate()` agora
     * abrem o modal builder em vez do prompt nativo.
     */
    issueMedicalCertificate() {
        return this.openMedicalCertificate();
    },

    openAttendanceCertificate() {
        this.attendanceForm = { content: '' };
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('attendanceCertificateModal'));
        modal.show();
    },

    openMedicalCertificate() {
        this.medicalForm = { days: 1, date: '', content: '', daysPreview: '' };
        this.refreshDayExtension();
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('medicalCertificateModal'));
        modal.show();
    },

    /**
     * Auto-formata data dd/mm/aaaa enquanto o usuário digita (compat com
     * `date_format:d/m/Y` do validator).
     */
    formatMedicalCertDate(event) {
        const digits = (event.target.value || '').replace(/\D/g, '').slice(0, 8);
        let formatted = digits;
        if (digits.length > 4) {
            formatted = `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`;
        } else if (digits.length > 2) {
            formatted = `${digits.slice(0, 2)}/${digits.slice(2)}`;
        }
        this.medicalForm.date = formatted;
    },

    /**
     * Consulta backend p/ obter "X (extenso) dias" e atualiza preview.
     * Backend usa NumberFormatter intl — mesma lib do PDF, evita divergência.
     */
    async refreshDayExtension() {
        const days = Number(this.medicalForm.days);
        if (!Number.isInteger(days) || days < 1 || days > 365) {
            this.medicalForm.daysPreview = '';
            return;
        }
        if (!this.dayExtensionPreviewUrl) return;

        try {
            const res = await fetch(this.dayExtensionPreviewUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ days }),
            });
            if (!res.ok) {
                this.medicalForm.daysPreview = '';
                return;
            }
            const data = await res.json();
            this.medicalForm.daysPreview = data.display || '';
        } catch (e) {
            console.error('Day extension preview error:', e);
            this.medicalForm.daysPreview = '';
        }
    },

    submitAttendanceCertificate() {
        const payload = {};
        const content = (this.attendanceForm.content || '').trim();
        if (content !== '') {
            payload.content = content;
        }

        bootstrap.Modal.getInstance(document.getElementById('attendanceCertificateModal'))?.hide();

        this.issueQuickAction('attendance-certificate', payload, { preview: true });
    },

    submitMedicalCertificate() {
        const days = Number(this.medicalForm.days);
        if (!Number.isInteger(days) || days < 1 || days > 365) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'warning', title: 'Atenção', text: 'Informe um número de dias entre 1 e 365.' });
            }
            return;
        }

        const payload = { days };

        const date = (this.medicalForm.date || '').trim();
        if (date !== '') {
            payload.date = date;
        }

        const content = (this.medicalForm.content || '').trim();
        if (content !== '') {
            payload.content = content;
        }

        bootstrap.Modal.getInstance(document.getElementById('medicalCertificateModal'))?.hide();

        this.issueQuickAction('medical-certificate', payload, { preview: true });
    },

    /**
     * F8 — Receituário de catarata via modal parametrizado.
     *
     * Backwards-compat: chamadas legadas a `issueCataractPrescription()`
     * agora abrem o modal builder em vez da série de prompts nativos.
     */
    issueCataractPrescription() {
        return this.openCataractPrescription();
    },

    openCataractPrescription() {
        // Reseta para defaults a cada abertura — médico tipicamente emite 1 receita por vez.
        this.cataractForm = {
            eye: 'right',
            template: 'pre_operatorio',
            date_surgery: '',
            hour_surgery: '',
        };
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('cataractPrescriptionModal'));
        modal.show();
    },

    /**
     * Auto-formata data dd/mm/aaaa enquanto o usuário digita.
     * Compatível com o validator server-side `date_format:d/m/Y`.
     */
    formatCataractDate(event) {
        const digits = (event.target.value || '').replace(/\D/g, '').slice(0, 8);
        let formatted = digits;
        if (digits.length > 4) {
            formatted = `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`;
        } else if (digits.length > 2) {
            formatted = `${digits.slice(0, 2)}/${digits.slice(2)}`;
        }
        this.cataractForm.date_surgery = formatted;
    },

    submitCataractPrescription() {
        if (!this.cataractForm.eye) return;

        // Hora vem de input type=time como HH:mm — payload bate com `date_format:H:i`.
        const payload = {
            eye:          this.cataractForm.eye,
            template:     this.cataractForm.template || 'pre_operatorio',
            date_surgery: (this.cataractForm.date_surgery || '').trim(),
            hour_surgery: (this.cataractForm.hour_surgery || '').trim(),
        };

        bootstrap.Modal.getInstance(document.getElementById('cataractPrescriptionModal'))?.hide();

        this.issueQuickAction('cataract-prescription', payload, { preview: true });
    },

    issueMedicalDeclaration() {
        // Declaração emitida em branco — placeholder {{CONTEUDO_LIVRE}}
        // resolvido como vazio pelo service. Médico preenche manuscrito.
        this.issueQuickAction('medical-declaration', { content: '' });
    },

    /**
     * F5 — Receituário de medicamentos com builder.
     *
     * Backwards-compat: chamadas legadas a `issueMedicationPrescription()`
     * agora abrem o modal builder em vez do prompt nativo.
     */
    issueMedicationPrescription() {
        return this.openMedicationPrescription();
    },

    openMedicationPrescription() {
        // não reseta state se já tinha receita aberta (UX: usuário pode
        // fechar acidentalmente e reabrir sem perder a lista).
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('medicationPrescriptionModal'));
        modal.show();
    },

    async searchMedicines() {
        const q = (this.medSearchQuery || '').trim();
        if (q.length < 2) {
            this.medSearchResults = [];
            this.medSearchOpen    = false;
            return;
        }
        if (!this.medicineSearchUrl) return;

        this.medSearchLoading = true;
        try {
            const url = `${this.medicineSearchUrl}?q=${encodeURIComponent(q)}`;
            const res = await fetch(url, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });
            if (!res.ok) {
                this.medSearchResults = [];
                this.medSearchOpen    = false;
                return;
            }
            this.medSearchResults = await res.json();
            this.medSearchOpen    = this.medSearchResults.length > 0;
        } catch (e) {
            console.error('Medicine search error:', e);
            this.medSearchResults = [];
            this.medSearchOpen    = false;
        } finally {
            this.medSearchLoading = false;
        }
    },

    async addMedicine(item) {
        if (!item || !item.id) return;

        // Limit
        if (this.prescription.length >= this.maxMedicines) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Limite atingido',
                    text: `Só é possível incluir ${this.maxMedicines} medicamentos por receita. Imprima esta antes de adicionar mais.`,
                });
            }
            return;
        }

        // Dedupe by id
        if (this.prescription.some(m => m.id === item.id)) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'info',
                    title: 'Atenção',
                    text: 'O medicamento já está na receita.',
                });
            }
            return;
        }

        // Append linha formatada via backend
        if (this.medicationFormatLineUrl) {
            try {
                const res = await fetch(this.medicationFormatLineUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ medicine_id: item.id }),
                });
                if (res.ok) {
                    const data = await res.json();
                    this.medicineLists += data.line ?? '';
                }
            } catch (e) {
                console.error('Format line error:', e);
            }
        }

        this.prescription.push(item);
        this.medSearchQuery   = '';
        this.medSearchResults = [];
        this.medSearchOpen    = false;
    },

    removeMedicine(idx) {
        if (idx < 0 || idx >= this.prescription.length) return;
        this.prescription.splice(idx, 1);
        // Não remove a linha do textarea (médico pode ter editado o texto).
        // UX: reset textarea só via botão "Limpar".
    },

    clearMedicines() {
        this.prescription   = [];
        this.medicineLists  = '';
        this.medSearchQuery = '';
        this.medSearchResults = [];
        this.medSearchOpen  = false;
    },

    submitMedicationPrescription() {
        const content = (this.medicineLists || '').trim();
        if (content === '') return;

        bootstrap.Modal.getInstance(document.getElementById('medicationPrescriptionModal'))?.hide();

        this.issueQuickAction('medication-prescription', { content }, { preview: true });
    },

    /**
     * F6 — Solicitação de procedimentos com builder.
     *
     * Backwards-compat: chamadas legadas a `issueProcedureRequest()` agora
     * abrem o modal builder em vez do prompt nativo.
     */
    issueProcedureRequest() {
        return this.openProcedureSolicitation();
    },

    openProcedureSolicitation() {
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('procedureSolicitationModal'));
        modal.show();
    },

    async searchProcedures() {
        const q = (this.procSearchQuery || '').trim();
        if (q.length < 2) {
            this.procSearchResults = [];
            this.procSearchOpen    = false;
            return;
        }
        if (!this.procedureSearchUrl) return;

        this.procSearchLoading = true;
        try {
            const res = await fetch(`${this.procedureSearchUrl}?q=${encodeURIComponent(q)}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });
            if (!res.ok) {
                this.procSearchResults = [];
                this.procSearchOpen    = false;
                return;
            }
            this.procSearchResults = await res.json();
            this.procSearchOpen    = this.procSearchResults.length > 0;
        } catch (e) {
            console.error('Procedure search error:', e);
            this.procSearchResults = [];
            this.procSearchOpen    = false;
        } finally {
            this.procSearchLoading = false;
        }
    },

    async searchIndications() {
        const q = (this.indSearchQuery || '').trim();
        if (q.length < 2) {
            this.indSearchResults = [];
            this.indSearchOpen    = false;
            return;
        }
        if (!this.indicationSearchUrl) return;

        this.indSearchLoading = true;
        try {
            const res = await fetch(`${this.indicationSearchUrl}?q=${encodeURIComponent(q)}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });
            if (!res.ok) {
                this.indSearchResults = [];
                this.indSearchOpen    = false;
                return;
            }
            this.indSearchResults = await res.json();
            this.indSearchOpen    = this.indSearchResults.length > 0;
        } catch (e) {
            console.error('Indication search error:', e);
            this.indSearchResults = [];
            this.indSearchOpen    = false;
        } finally {
            this.indSearchLoading = false;
        }
    },

    /**
     * Adiciona procedimento à lista + busca linha formatada do backend.
     * Tipo é capturado no momento do add (médico pode trocar entre adds).
     * Sem dedupe (mesmo procedimento pode ser solicitado com tipos diferentes).
     */
    async addProcedure(item) {
        if (this.procSelected.length + this.indSelected.length >= this.maxProcSolicitations) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'warning', title: 'Atenção', text: `Limite de ${this.maxProcSolicitations} itens atingido.` });
            }
            return;
        }

        const typeMap = { rotina: 'Rotina', urgencia: 'Urgência', controle: 'Controle', comparativo: 'Comparativo' };
        const type    = this.procTypeSelected || '';

        // Append visualmente já agora; formatação do backend chega async.
        const idx = this.procSelected.length;
        this.procSelected.push({
            id:         item.id,
            name:       item.name,
            code:       item.code,
            type:       type,
            type_label: type ? typeMap[type] : '',
        });
        this.procSearchQuery   = '';
        this.procSearchResults = [];
        this.procSearchOpen    = false;

        await this._appendSolicitationLine({
            kind: 'procedure',
            id:   item.id,
            type: type || null,
        }, () => this.procSelected.splice(idx, 1));
    },

    async addIndication(item) {
        if (this.procSelected.length + this.indSelected.length >= this.maxProcSolicitations) {
            return;
        }

        const idx = this.indSelected.length;
        this.indSelected.push({ id: item.id, description: item.description });
        this.indSearchQuery   = '';
        this.indSearchResults = [];
        this.indSearchOpen    = false;

        await this._appendSolicitationLine({
            kind: 'indication',
            id:   item.id,
        }, () => this.indSelected.splice(idx, 1));
    },

    async _appendSolicitationLine(payload, onError) {
        if (!this.procedureFormatLineUrl) return;

        try {
            const res = await fetch(this.procedureFormatLineUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(payload),
            });

            if (!res.ok) {
                onError?.();
                return;
            }

            const { line } = await res.json();
            if (!line) return;

            this.procedureLists = this.procedureLists
                ? `${this.procedureLists.trimEnd()}\n${line}\n`
                : `${line}\n`;
        } catch (e) {
            console.error('Format solicitation line error:', e);
            onError?.();
        }
    },

    removeProcedure(idx) {
        this.procSelected.splice(idx, 1);
    },

    removeIndication(idx) {
        this.indSelected.splice(idx, 1);
    },

    clearProcedureSolicitation() {
        this.procSelected      = [];
        this.indSelected       = [];
        this.procedureLists    = '';
        this.procSearchQuery   = '';
        this.indSearchQuery    = '';
        this.procSearchResults = [];
        this.indSearchResults  = [];
        this.procSearchOpen    = false;
        this.indSearchOpen     = false;
    },

    submitProcedureSolicitation() {
        const content = (this.procedureLists || '').trim();
        if (content === '') return;

        bootstrap.Modal.getInstance(document.getElementById('procedureSolicitationModal'))?.hide();

        this.issueQuickAction('procedure-request', { content }, { preview: true });
    },

    // ── Files: drag & drop ──────────────────────────────────────────────
    handleFileDrop(event) {
        event.currentTarget.classList.remove('border-primary');
        if (event.dataTransfer?.files?.length) {
            this.uploadFiles(event.dataTransfer.files);
        }
    },

    // ── Files: upload ───────────────────────────────────────────────────
    async uploadFiles(fileList) {
        if (this.uploading || !storeFileUrl || !fileList?.length) return;
        this.uploading = true;
        this.uploadProgress = 0;

        const formData = new FormData();
        Array.from(fileList).forEach(f => formData.append('files[]', f));

        try {
            const xhr = new XMLHttpRequest();

            const done = new Promise((resolve, reject) => {
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        this.uploadProgress = Math.round((e.loaded / e.total) * 100);
                    }
                });
                xhr.addEventListener('load', () => resolve(xhr));
                xhr.addEventListener('error', () => reject(new Error('Upload failed')));
            });

            xhr.open('POST', storeFileUrl);
            xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').content);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.send(formData);

            const result = await done;

            if (result.status >= 200 && result.status < 300) {
                const data = JSON.parse(result.responseText);
                (data.files || []).forEach(f => this.uploadedFiles.push(f));

                if (typeof $.toast === 'function') {
                    $.toast({
                        heading: 'Sucesso',
                        text: `${data.files?.length ?? 0} arquivo(s) enviado(s).`,
                        position: 'top-right',
                        loaderBg: '#006A4E',
                        icon: 'success',
                        hideAfter: 3500,
                        stack: 6,
                    });
                }
            } else {
                const err = JSON.parse(result.responseText);
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Erro', text: err.message ?? 'Erro no upload.' });
                }
            }
        } catch (e) {
            console.error('Upload error:', e);
        } finally {
            this.uploading = false;
            this.uploadProgress = 0;
        }
    },
});
