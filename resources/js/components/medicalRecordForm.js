/**
 * medicalRecordForm — Alpine.js component for the 7-tab medical record form.
 *
 * Usage in Blade:
 *   x-data="medicalRecordForm({ isEdit: true, diabetic: false, ... })"
 */
export default ({
    isEdit = false,
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
} = {}) => ({
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
    },
    docSaving: false,
    quickActionUrlTemplate,
    quickActionBusy: false,

    // ── File upload state ───────────────────────────────────────────────
    uploadedFiles: [],
    uploading: false,
    uploadProgress: 0,

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

    async printTonometry() {
        const od   = document.querySelector('[name="tonometer_right"]')?.value ?? '';
        const oe   = document.querySelector('[name="tonometer_left"]')?.value ?? '';
        const time = new Date().toTimeString().slice(0, 5);

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
                    body: JSON.stringify({ od, oe, time }),
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
        const doctorIdVal = this.doctorId || document.querySelector('[name="doctor_id"]')?.value || '';
        const params = new URLSearchParams({ time, od, oe });
        if (doctorIdVal) params.set('doctor_id', doctorIdVal);
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

        this.docForm = { report_setting_content_id: '', title: '', content: '' };

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

    // ── Documentation: save ─────────────────────────────────────────────
    async saveDoc() {
        if (this.docSaving || !storeDocUrl) return;
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
            this.docForm = { report_setting_content_id: '', title: '', content: '' };

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

    async issueQuickAction(action, payload = {}, { openPdf = true } = {}) {
        const url = this.buildQuickActionUrl(action);
        if (!url || this.quickActionBusy) return;

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

            if (openPdf && doc.pdf_url) {
                window.open(doc.pdf_url, '_blank', 'noopener');
            }
        } catch (e) {
            console.error('Quick action error:', e);
        } finally {
            this.quickActionBusy = false;
        }
    },

    issueMedicalCertificate() {
        const rawDays = window.prompt('Dias de afastamento:', '1');
        if (rawDays === null) return;

        const days = Number(rawDays);
        if (!Number.isInteger(days) || days <= 0 || days > 365) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'warning', title: 'Atenção', text: 'Informe um número de dias entre 1 e 365.' });
            }

            return;
        }

        this.issueQuickAction('medical-certificate', { days });
    },

    issueCataractPrescription() {
        const eye = window.prompt('Olho a ser operado (ex: OD ou OE):', 'OD');
        if (eye === null || eye.trim() === '') return;

        const templateInput = window.prompt('Opção (1 = Pré, 2 = Pós, 3 = Instruções):', '1');
        if (templateInput === null) return;

        const template = ['1', '2', '3'].includes(templateInput.trim()) ? templateInput.trim() : '1';

        const dateSurgery = window.prompt('Data da cirurgia (dd/mm/aaaa):', '') ?? '';
        const hourSurgery = window.prompt('Hora da cirurgia (HH:mm):', '') ?? '';

        this.issueQuickAction('cataract-prescription', {
            eye: eye.trim(),
            template,
            date_surgery: dateSurgery.trim(),
            hour_surgery: hourSurgery.trim(),
        });
    },

    issueMedicalDeclaration() {
        const content = window.prompt('Digite o conteúdo da declaração médica:');
        if (content === null || content.trim() === '') return;

        this.issueQuickAction('medical-declaration', { content: content.trim() });
    },

    issueMedicationPrescription() {
        const content = window.prompt('Liste os medicamentos (um por linha):');
        if (content === null || content.trim() === '') return;

        this.issueQuickAction('medication-prescription', { content: content.trim() });
    },

    issueProcedureRequest() {
        const content = window.prompt('Digite os procedimentos/indicações solicitados:');
        if (content === null || content.trim() === '') return;

        this.issueQuickAction('procedure-request', { content: content.trim() });
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
