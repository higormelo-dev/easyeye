{{--
    Componente genérico de modal CRUD.

    Props:
      id        — id do modal (padrão: crudModal)
      title     — título exibido no header
      saveLabel — texto do botão salvar (padrão: Salvar)
      size      — tamanho do modal: 'sm', 'md', 'lg' (padrão), 'xl'

    Uso:
      <x-crud-modal id="scheduleModal" title="Agendamento">
          <x-slot:body>
              ... campos do formulário ...
          </x-slot:body>
      </x-crud-modal>
--}}
@props([
    'id'        => 'crudModal',
    'title'     => 'Formulário',
    'saveLabel' => 'Salvar',
    'size'      => 'lg',
])

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}Label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-{{ $size }}">
        <div class="modal-content">

            <div class="modal-header bg-info">
                <h5 class="modal-title text-white" id="{{ $id }}Label">{{ $title }}</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                {{ $body }}
            </div>

            <div class="modal-footer">
                <button type="button" class="btn waves-effect waves-light btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> {{ __('actions.cancel') }}
                </button>
                <button type="button"
                        class="btn waves-effect waves-light btn-info"
                        @click="save()"
                        :disabled="loading">
                    <span x-show="loading" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <i x-show="!loading" class="far fa-save me-1"></i>
                    {{ $saveLabel }}
                </button>
            </div>

        </div>
    </div>
</div>
