{{--
    Modal universal de preview de PDF — substitui o `.print-documentation{patient.id}`
    do smart_oftal. Renderiza um <iframe> dentro de um modal Bootstrap 5 e expõe
    estado via Alpine no escopo do `medicalRecordForm`.

    Uso (após emitir um documento que retorne `pdf_url`):
        this.openPdfPreview(pdfUrl, 'Receituário de Lentes — Dinâmica');

    Fechamento limpa o src do iframe para liberar memória do wkhtmltopdf renderer.
--}}
<div class="modal fade" id="pdfPreviewModal" tabindex="-1"
     @keydown.escape.window="closePdfPreview()">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="height:90vh;">
        <div class="modal-content" style="height:100%;">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0">
                    <i class="fas fa-file-pdf me-2" style="color:#e91e8c;"></i>
                    <span x-text="pdfPreviewTitle || '{{ __('actions.medical_records.print_preview') }}'"></span>
                </h6>
                <div class="ms-auto d-flex gap-2 align-items-center">
                    <a x-show="pdfPreviewUrl" x-cloak
                       :href="pdfPreviewUrl" target="_blank"
                       class="btn btn-outline-secondary btn-sm"
                       title="{{ __('actions.medical_records.open_in_new_tab') }}">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                    <button type="button" class="btn-close" @click="closePdfPreview()"></button>
                </div>
            </div>
            <div class="modal-body p-0" style="flex:1;overflow:hidden;">
                <iframe x-bind:src="pdfPreviewUrl"
                        style="width:100%;height:100%;border:none;display:block;"
                        title="{{ __('actions.medical_records.print_preview') }}"></iframe>
            </div>
        </div>
    </div>
</div>
