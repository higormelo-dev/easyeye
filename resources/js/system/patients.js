import { handleAjaxError, showSuccessToast, showErrorToast } from './auxiliary_functions.js';

$(function () {
    let record_id;

    const trans = window.translations;
    const entityName = trans.actions.patient;

    // Obtém a instância do DataTable criada pelo Laravel DataTables
    let patientsDataTable = window.LaravelDataTables?.['patients_datatable'];

    // Após cada redraw do DataTable: tooltips + ajuste de colunas.
    // columns.adjust() aqui garante que a tabela já está visível e com dados
    // renderizados — resolve o problema de colunas bagunçadas ao voltar da view cards.
    $('#patients_datatable').on('draw.dt', function () {
        $('[data-bs-toggle="tooltip"]').tooltip();
        patientsDataTable = patientsDataTable ?? window.LaravelDataTables?.['patients_datatable'];
        patientsDataTable?.columns.adjust();
    });

    /**
     * Recarrega a view ativa (tabela ou cards).
     * Usado após criar, editar, excluir ou alterar status.
     */
    function reloadCurrentView() {
        const component = window.patientViewComponent;
        if (component && component.view === 'cards') {
            component.fetchCards(component.meta.current_page);
        } else {
            patientsDataTable?.ajax.reload();
        }
    }

    window.reloadCurrentView = reloadCurrentView;
    window.addEventListener('patient-saved', () => reloadCurrentView());

    // ----------------------------------------------------------------
    // Handlers via document delegation — funcionam na tabela E nos cards
    // ----------------------------------------------------------------

    // Visualizar
    $(document).on('click', '.btn-show', function () {
        record_id = $(this).data('id');
        $('.modal-title-default').empty().append(trans.messages.view.replace(':name', entityName));
        $('#btn-modal-default').css('display', 'none');
        $('.modal-dialog').removeClass('modal-md modal-lg modal-xl').addClass('modal-lg');
        $("#erro-default").removeClass('show').css('display', 'none');
        $.ajax({
            url: `patients/${record_id}`,
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function (data) {
                $('#retorno-default').empty().append(data);
                $('#modal_default').modal('show');
            },
            error: handleAjaxError
        });
    });

    // Editar — delega ao Alpine crudForm via evento nativo
    $(document).on('click', '.btn-edit', function () {
        const id = $(this).attr('data-id');
        window.dispatchEvent(new CustomEvent('edit-patient', { detail: { id } }));
    });

    // Ativar / Inativar
    $(document).on('click', '.btn-active', function () {
        record_id = $(this).attr('data-id');
        $.ajax({
            url: `patients/${record_id}`,
            type: 'put',
            dataType: 'json',
            data: {
                'type_method': 1,
                'active': parseInt($(this).attr('data-situation'), 10)
            },
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function (response) {
                showSuccessToast(response.message);
                reloadCurrentView();
            },
            error: handleAjaxError
        });
    });

    // Deletar
    $(document).on('click', '.btn-trash', function () {
        record_id = $(this).data('id');
        Swal.fire({
            title: trans.messages.delete_confirm_title,
            text: trans.messages.delete_confirm_text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: trans.messages.confirm_yes,
            cancelButtonText: trans.messages.confirm_no
        }).then((result) => {
            if (result.value) {
                $.ajax({
                    method: 'delete',
                    url: `patients/${record_id}`,
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (response) {
                        showSuccessToast(response.message);
                        reloadCurrentView();
                    },
                    error: function (data) {
                        showErrorToast(data.responseJSON?.message);
                    }
                });
            }
        });
    });

    // Restaurar
    $(document).on('click', '.btn-restore', function () {
        record_id = $(this).data('id');
        Swal.fire({
            title: trans.messages.restore_confirm_title,
            text: trans.messages.restore_confirm_text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: trans.messages.confirm_yes,
            cancelButtonText: trans.messages.confirm_no
        }).then((result) => {
            if (result.value) {
                $.ajax({
                    method: 'get',
                    url: `patients/${record_id}/restore`,
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (response) {
                        showSuccessToast(response.message);
                        reloadCurrentView();
                    },
                    error: function (data) {
                        showErrorToast(data.responseJSON?.message);
                    }
                });
            }
        });
    });

});
