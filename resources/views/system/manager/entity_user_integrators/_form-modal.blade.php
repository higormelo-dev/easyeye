<x-crud-modal id="userIntegratorModal" title="Usuário Integrador">
    <x-slot:body>
        <div class="row g-3">

            <div class="col-12">
                <label class="form-label">Nome <span class="text-danger">*</span></label>
                <input type="text" class="form-control" :class="{ 'is-invalid': hasError('name') }"
                       x-model="form.name" maxlength="255" autocomplete="off">
                <div class="invalid-feedback" x-text="firstError('name')"></div>
            </div>

            <div class="col-12">
                <label class="form-label">E-mail <span class="text-danger">*</span></label>
                <input type="email" class="form-control" :class="{ 'is-invalid': hasError('email') }"
                       x-model="form.email" maxlength="255" autocomplete="off">
                <div class="invalid-feedback" x-text="firstError('email')"></div>
            </div>

            <div class="col-12">
                <label class="form-label">
                    Senha <span class="text-danger" x-show="!editing">*</span>
                </label>
                <input type="password" class="form-control" :class="{ 'is-invalid': hasError('password') }"
                       x-model="form.password" maxlength="255" autocomplete="new-password">
                <div class="invalid-feedback" x-text="firstError('password')"></div>
                <small class="text-muted" x-show="editing">Deixe em branco para não alterar</small>
            </div>

            <div class="col-md-4" x-show="editing">
                <label class="form-label">{{ __('forms.active') }}</label>
                <select class="form-select" x-model="form.active">
                    <option :value="true">{{ __('forms.yes') }}</option>
                    <option :value="false">{{ __('forms.no') }}</option>
                </select>
            </div>

        </div>
    </x-slot:body>
</x-crud-modal>
