<x-crud-modal id="entityModal" title="Empresa">
    <x-slot:body>
        {{-- Abas de navegação --}}
        <ul class="nav nav-tabs mb-3" id="entityModalTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-dados-btn"
                        data-bs-toggle="tab" data-bs-target="#tab-dados"
                        type="button" role="tab">
                    <i class="fa fa-building me-1"></i> Dados da Empresa
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-endereco-btn"
                        data-bs-toggle="tab" data-bs-target="#tab-endereco"
                        type="button" role="tab">
                    <i class="fa fa-map-marker me-1"></i> Endereço
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-config-btn"
                        data-bs-toggle="tab" data-bs-target="#tab-config"
                        type="button" role="tab">
                    <i class="fa fa-cog me-1"></i> Configurações
                </button>
            </li>
        </ul>

        <div class="tab-content">

            {{-- ── Aba: Dados da Empresa ──────────────────────────────── --}}
            <div class="tab-pane fade show active" id="tab-dados" role="tabpanel">
                <div class="row g-3">

                    <div class="col-md-8">
                        <label class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" :class="{ 'is-invalid': hasError('name') }"
                               x-model="form.name" maxlength="150" autocomplete="off">
                        <div class="invalid-feedback" x-text="firstError('name')"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Subdomínio</label>
                        <input type="text" class="form-control" :class="{ 'is-invalid': hasError('subdomain') }"
                               x-model="form.subdomain" maxlength="100" autocomplete="off" placeholder="minha-empresa">
                        <div class="invalid-feedback" x-text="firstError('subdomain')"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">E-mail</label>
                        <input type="email" class="form-control" :class="{ 'is-invalid': hasError('email') }"
                               x-model="form.email" maxlength="150" autocomplete="off">
                        <div class="invalid-feedback" x-text="firstError('email')"></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Telefone</label>
                        <input type="text" class="form-control"
                               x-model="form.telephone" maxlength="20" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Celular</label>
                        <input type="text" class="form-control"
                               x-model="form.cellphone" maxlength="20" autocomplete="off">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">CNPJ / CPF</label>
                        <input type="text" class="form-control" :class="{ 'is-invalid': hasError('national_registration') }"
                               x-model="form.national_registration" maxlength="20" autocomplete="off">
                        <div class="invalid-feedback" x-text="firstError('national_registration')"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Insc. Estadual</label>
                        <input type="text" class="form-control"
                               x-model="form.state_registration" maxlength="30" autocomplete="off">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Insc. Municipal</label>
                        <input type="text" class="form-control"
                               x-model="form.municipal_registration" maxlength="30" autocomplete="off">
                    </div>

                    <div class="col-12">
                        <label class="form-label">Site</label>
                        <input type="text" class="form-control" :class="{ 'is-invalid': hasError('website') }"
                               x-model="form.website" maxlength="150" autocomplete="off" placeholder="https://">
                        <div class="invalid-feedback" x-text="firstError('website')"></div>
                    </div>

                </div>
            </div>

            {{-- ── Aba: Endereço ───────────────────────────────────────── --}}
            <div class="tab-pane fade" id="tab-endereco" role="tabpanel">
                <div class="row g-3">

                    <div class="col-md-3">
                        <label class="form-label">CEP</label>
                        <input type="text" class="form-control" x-model="form.zipcode" maxlength="10" autocomplete="off">
                    </div>

                    <div class="col-md-7">
                        <label class="form-label">Logradouro</label>
                        <input type="text" class="form-control" x-model="form.address" maxlength="200" autocomplete="off">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Número</label>
                        <input type="text" class="form-control" x-model="form.number" maxlength="10" autocomplete="off">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Complemento</label>
                        <input type="text" class="form-control" x-model="form.complement" maxlength="100" autocomplete="off">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Bairro</label>
                        <input type="text" class="form-control" x-model="form.district" maxlength="100" autocomplete="off">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Cidade</label>
                        <input type="text" class="form-control" x-model="form.city" maxlength="100" autocomplete="off">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">UF</label>
                        <input type="text" class="form-control" x-model="form.state" maxlength="2" autocomplete="off">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">País</label>
                        <input type="text" class="form-control" x-model="form.country" maxlength="50" autocomplete="off">
                    </div>

                </div>
            </div>

            {{-- ── Aba: Configurações ──────────────────────────────────── --}}
            <div class="tab-pane fade" id="tab-config" role="tabpanel">
                <div class="row g-3">

                    <div class="col-md-4">
                        <label class="form-label">Intervalo de Consulta</label>
                        <select class="form-select" :class="{ 'is-invalid': hasError('schedule_interval') }"
                                x-model.number="form.schedule_interval">
                            <option :value="15">15 minutos</option>
                            <option :value="20">20 minutos</option>
                            <option :value="30">30 minutos</option>
                        </select>
                        <div class="invalid-feedback" x-text="firstError('schedule_interval')"></div>
                    </div>

                    <div class="col-md-4" x-show="editing">
                        <label class="form-label">{{ __('forms.active') }}</label>
                        <select class="form-select" x-model="form.active">
                            <option :value="true">{{ __('forms.yes') }}</option>
                            <option :value="false">{{ __('forms.no') }}</option>
                        </select>
                    </div>

                </div>
            </div>

        </div>{{-- /tab-content --}}
    </x-slot:body>
</x-crud-modal>
