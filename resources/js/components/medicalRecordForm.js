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

    // ── File upload state ───────────────────────────────────────────────
    uploadedFiles: [],
    uploading: false,
    uploadProgress: 0,

    // ── Tonometry PDF ───────────────────────────────────────────────────
    _tonometryModal() {
        return bootstrap.Modal.getOrCreateInstance(document.getElementById('tonometryModal'));
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

                    // Adiciona ao histórico de documentações sem recarregar
                    const tbody = document.querySelector('#pmr-docs-tbody');
                    if (tbody) {
                        const emptyRow = tbody.querySelector('[data-empty]');
                        if (emptyRow) emptyRow.remove();

                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td><span class="badge bg-info-subtle text-info">${doc.type_label}</span></td>
                            <td>${doc.title}</td>
                            <td>${doc.created_at}</td>
                            <td class="text-end">
                                <a href="${doc.pdf_url}" target="_blank" class="btn btn-outline-secondary btn-sm" title="PDF">
                                    <i class="fas fa-file-pdf"></i>
                                </a>
                            </td>`;
                        tbody.prepend(tr);
                    }

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
            this.staticSphericalRight = data.static_spherical_right ?? 0;
            this.staticSphericalLeft = data.static_spherical_left ?? 0;
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
                this.docTemplates = await res.json();
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
                    .flatMap(g => g.contents)
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

            // Append new row to the documentation table
            const tbody = document.querySelector('#tab-docs table tbody');
            if (tbody) {
                const emptyRow = tbody.querySelector('td[colspan]')?.closest('tr');
                if (emptyRow) emptyRow.remove();

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><span class="badge bg-info-subtle text-info">${doc.type_label}</span></td>
                    <td>${doc.title ?? ''}</td>
                    <td>${doc.created_at}</td>
                    <td class="text-end">
                        <a href="${doc.pdf_url}" target="_blank" class="btn btn-outline-secondary btn-sm" title="PDF">
                            <i class="fas fa-file-pdf"></i>
                        </a>
                    </td>`;
                tbody.appendChild(tr);
            }

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
