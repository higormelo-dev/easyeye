<div class="modal fade" id="modal_default">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h5 class="modal-title-default text-white"></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="erro-default" class="alert alert-danger" style="display:none">
                    <p id="erro-msg-default"></p>
                </div>
                <div id="retorno-default"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn waves-effect waves-light btn-outline-secondary"
                        data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> {{ __('actions.close') }}
                </button>
                <button id="btn-modal-default" type="button" class="btn waves-effect waves-light btn-info">
                    <i class="far fa-save me-1"></i> {{ __('actions.save') }}
                </button>
            </div>
        </div>
    </div>
</div>
