import "jquery";
export function handleAjaxError(response) {
    let message = response.responseJSON?.message || 'Erro desconhecido';
    let errors = response.responseJSON?.errors;

    if (errors && Object.keys(errors).length) {
        $("#erro-default").addClass('show').show();
        $("#erro-msg-default").empty().append('<strong>Erro!</strong> Preencha corretamente os campos abaixo:<br>');

        $.each(errors, function (field, fieldErrors) {
            $.each(fieldErrors, function (index, error) {
                $("#erro-msg-default").append('- ' + error + '<br>');
            });
        });
    } else {
        showErrorToast(message);
    }
}

export function showSuccessToast(message) {
    $.toast({
        heading: 'Sucesso',
        text: message,
        position: 'top-right',
        loaderBg: '#006A4E',
        icon: 'success',
        hideAfter: 3500,
        stack: 6
    });
}

export function showErrorToast(message) {
    $.toast({
        heading: 'Erro!',
        text: message,
        position: 'top-right',
        loaderBg: '#EF0107',
        icon: 'error',
        hideAfter: 5000
    });
}

export function searchAddressByZipcode(value) {
    let zipcode = value.replace(/\D/g, '');

    if (zipcode !== "") {
        let zipcodeValidate = /^[0-9]{8}$/;

        if(zipcodeValidate.test(zipcode)) {
            $('input[name=address]').val("...");
            $('input[name=district]').val("...");
            $('input[name=city]').val("...");
            $('select[name=state]').val("");

            $.ajax({
                url: `https://viacep.com.br/ws/${zipcode}/json/`,
                type: 'GET',
                dataType: 'json',
                timeout: 10000, // 10 segundos de timeout
                beforeSend: function() {
                    // Opcional: mostrar loading
                    console.log('Buscando CEP:', zipcode);
                },
                success: function(data) {
                    if (!("erro" in data)) {
                        $('input[name=address]').val(data.logradouro);
                        $('input[name=district]').val(data.bairro);
                        $('input[name=city]').val(data.localidade);
                        $('select[name=state]').val(data.uf);
                        $('input[name=number]').focus();
                    } else {
                        clearAddressForm();
                    }
                },
                error: function(xhr, status, error) {
                    clearAddressForm();

                    let errorMessage = 'Erro ao buscar CEP.';

                    if (status === 'timeout') {
                        errorMessage = 'Timeout ao buscar CEP. Tente novamente.';
                    } else if (xhr.status === 0) {
                        errorMessage = 'Erro de conexão. Verifique sua internet.';
                    } else if (xhr.status >= 400 && xhr.status < 500) {
                        errorMessage = 'CEP inválido ou não encontrado.';
                    } else if (xhr.status >= 500) {
                        errorMessage = 'Erro no servidor de CEP. Tente novamente.';
                    }

                    alert(errorMessage);
                    console.error('Erro na consulta do CEP:', error);
                },
                complete: function() {
                    console.log('Consulta de CEP finalizada');
                }
            });
        } else {
            clearAddressForm();
            alert('Formato de CEP inválido.');
        }
    } else {
        clearAddressForm();
    }
}

function clearAddressForm() {
    $('input[name=address]').val("");
    $('input[name=district]').val("");
    $('input[name=city]').val("");
    $('select[name=state]').val("");
    $('input[name=number]').val("");
    $('input[name=complement]').val("");
}

export function getDataTableLanguage() {
    if (typeof window.dataTableTranslations !== 'undefined') {
        return window.dataTableTranslations;
    }

    // Fallback para português brasileiro
    return {
        "sEmptyTable": "Nenhum registro encontrado",
        "sProcessing": "Processando...",
        "sLengthMenu": "Mostrar _MENU_ registros",
        "sZeroRecords": "Não foram encontrados resultados",
        "sInfo": "Mostrando de _START_ até _END_ de _TOTAL_ registros",
        "sInfoEmpty": "Mostrando de 0 até 0 de 0 registros",
        "sInfoFiltered": "(filtrado de _MAX_ registros no total)",
        "sInfoPostFix": "",
        "sSearch": "Pesquisar:",
        "sUrl": "",
        "oPaginate": {
            "sFirst": "Primeiro",
            "sPrevious": "Anterior",
            "sNext": "Próximo",
            "sLast": "Último"
        },
        "oAria": {
            "sSortAscending": ": Ordenar colunas de forma ascendente",
            "sSortDescending": ": Ordenar colunas de forma descendente"
        }
    };
}
