<x-crud-modal id="patientModal" title="{{ __('actions.patient') }}">
    <x-slot:body>
        {{-- Resumo de validação no padrão do modal de usuários --}}
        <div class="alert alert-danger"
             x-show="Object.keys(errors).length > 0"
             x-cloak>
            <strong>Erro!</strong> Preencha corretamente os campos abaixo:
            <ul class="mb-0 mt-2 ps-3">
                <template x-for="(messages, field) in errors" :key="field">
                    <template x-for="(message, index) in messages" :key="`${field}-${index}`">
                        <li x-text="message"></li>
                    </template>
                </template>
            </ul>
        </div>

        {{-- Abas de navegação --}}
        <ul class="nav nav-tabs mb-3" id="patientModalTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-pessoal-btn"
                        data-bs-toggle="tab" data-bs-target="#tab-pessoal"
                        type="button" role="tab">
                    <i class="fa fa-user me-1"></i> {{ __('forms.tab_personal') }}
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-contato-btn"
                        data-bs-toggle="tab" data-bs-target="#tab-contato"
                        type="button" role="tab">
                    <i class="fa fa-phone me-1"></i> {{ __('forms.tab_contact') }}
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-clinico-btn"
                        data-bs-toggle="tab" data-bs-target="#tab-clinico"
                        type="button" role="tab">
                    <i class="fa fa-stethoscope me-1"></i> {{ __('forms.tab_clinical') }}
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-endereco-btn"
                        data-bs-toggle="tab" data-bs-target="#tab-endereco"
                        type="button" role="tab">
                    <i class="fa fa-map-marker me-1"></i> {{ __('forms.tab_address') }}
                </button>
            </li>
        </ul>

        <div class="tab-content">

            {{-- ── Aba: Dados Pessoais ─────────────────────────────────── --}}
            <div class="tab-pane fade show active" id="tab-pessoal" role="tabpanel">
                <div class="row g-3">

                    <div class="col-12">
                        <label class="form-label">{{ __('forms.full_name') }} <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" :class="{ 'is-invalid': hasError('name') }"
                               x-model="form.name" maxlength="100" autocomplete="off">
                        <div class="invalid-feedback" x-text="firstError('name')"></div>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">{{ __('forms.nickname') }}</label>
                        <input type="text" class="form-control" x-model="form.nickname" maxlength="100" autocomplete="off">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('forms.national_registry') }} <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" :class="{ 'is-invalid': hasError('national_registry') }"
                               x-model="form.national_registry" x-mask-br="'cpf'" maxlength="14" autocomplete="off">
                        <div class="invalid-feedback" x-text="firstError('national_registry')"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('forms.birth_date') }} <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" :class="{ 'is-invalid': hasError('birth_date') }"
                               x-model="form.birth_date" min="1900-01-01" :max="new Date().toISOString().split('T')[0]">
                        <div class="invalid-feedback" x-text="firstError('birth_date')"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('forms.gender') }} <span class="text-danger">*</span></label>
                        <select class="form-select" :class="{ 'is-invalid': hasError('gender') }" x-model="form.gender">
                            <option value="">{{ __('forms.select') }}...</option>
                            @foreach($genders as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback" x-text="firstError('gender')"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('forms.marital_status') }} <span class="text-danger">*</span></label>
                        <select class="form-select" :class="{ 'is-invalid': hasError('marital_status') }" x-model="form.marital_status">
                            <option value="">{{ __('forms.select') }}...</option>
                            @foreach($maritalStatuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback" x-text="firstError('marital_status')"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">{{ __('forms.email') }} <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" :class="{ 'is-invalid': hasError('email') }"
                               x-model="form.email" maxlength="100" autocomplete="off">
                        <div class="invalid-feedback" x-text="firstError('email')"></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">{{ __('forms.mother_name') }}</label>
                        <input type="text" class="form-control" x-model="form.mother_name" maxlength="100" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">{{ __('forms.father_name') }}</label>
                        <input type="text" class="form-control" x-model="form.father_name" maxlength="100" autocomplete="off">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">{{ __('forms.state_registry') }}</label>
                        <input type="text" class="form-control" x-model="form.state_registry" maxlength="100" autocomplete="off">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">{{ __('forms.state_registry_agency') }}</label>
                        <input type="text" class="form-control" x-model="form.state_registry_agency" maxlength="100" autocomplete="off">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">{{ __('forms.state_registry_initial') }}</label>
                        <select class="form-select" x-model="form.state_registry_initial">
                            <option value="">UF</option>
                            @foreach($statesOfBrazil as $uf => $label)
                                <option value="{{ $uf }}">{{ $uf }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">{{ __('forms.state_registry_date') }}</label>
                        <input type="date" class="form-control" x-model="form.state_registry_date"
                               min="1900-01-01" :max="new Date().toISOString().split('T')[0]">
                    </div>

                </div>
            </div>

            {{-- ── Aba: Contato ────────────────────────────────────────── --}}
            <div class="tab-pane fade" id="tab-contato" role="tabpanel">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">{{ __('forms.telephone') }}</label>
                        <input type="text" class="form-control" x-model="form.telephone" x-mask-br="'tel'" maxlength="14" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">{{ __('forms.cellphone') }} <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" :class="{ 'is-invalid': hasError('cellphone') }"
                               x-model="form.cellphone" x-mask-br="'cel'" maxlength="15" autocomplete="off">
                        <div class="invalid-feedback" x-text="firstError('cellphone')"></div>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="whatsappCheck" x-model="form.whatsapp">
                            <label class="form-check-label" for="whatsappCheck">
                                <i class="fab fa-whatsapp text-success"></i> {{ __('forms.is_whatsapp') }}
                            </label>
                        </div>
                    </div>

                </div>
            </div>

            {{-- ── Aba: Clínico ─────────────────────────────────────────── --}}
            <div class="tab-pane fade" id="tab-clinico" role="tabpanel">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">{{ __('forms.covenant_id') }} <span class="text-danger">*</span></label>
                        <select class="form-select" :class="{ 'is-invalid': hasError('covenant_id') }" x-model="form.covenant_id">
                            <option value="">{{ __('forms.select') }}...</option>
                            @foreach($covenants as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback" x-text="firstError('covenant_id')"></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">{{ __('forms.card_number') }}</label>
                        <input type="text" class="form-control" x-model="form.card_number" maxlength="100" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">{{ __('forms.skin_id') }}</label>
                        <select class="form-select" x-model="form.skin_id">
                            <option value="">{{ __('forms.select') }}...</option>
                            @foreach($skinTypes as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">{{ __('forms.iris_id') }}</label>
                        <select class="form-select" x-model="form.iris_id">
                            <option value="">{{ __('forms.select') }}...</option>
                            @foreach($irisTypes as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4" x-show="editing">
                        <label class="form-label">{{ __('forms.active') }} <span class="text-danger">*</span></label>
                        <select class="form-select" x-model="form.active">
                            <option :value="true">{{ __('forms.yes') }}</option>
                            <option :value="false">{{ __('forms.no') }}</option>
                        </select>
                    </div>

                </div>
            </div>

            {{-- ── Aba: Endereço ───────────────────────────────────────── --}}
            <div class="tab-pane fade" id="tab-endereco" role="tabpanel">
                <div class="row g-3">

                    <div class="col-md-4">
                        <label class="form-label">{{ __('forms.zipcode') }}</label>
                        <input type="text" class="form-control" x-model="form.zipcode" x-mask-br="'cep'" maxlength="9" autocomplete="off">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">{{ __('forms.address') }}</label>
                        <input type="text" class="form-control" x-model="form.address" autocomplete="off">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">{{ __('forms.number') }}</label>
                        <input type="text" class="form-control" x-model="form.number" autocomplete="off">
                    </div>

                    <div class="col-12">
                        <label class="form-label">{{ __('forms.complement') }}</label>
                        <input type="text" class="form-control" x-model="form.complement" autocomplete="off">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('forms.district') }}</label>
                        <input type="text" class="form-control" x-model="form.district" autocomplete="off">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('forms.city') }}</label>
                        <input type="text" class="form-control" x-model="form.city" autocomplete="off">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('forms.state') }}</label>
                        <select class="form-select" x-model="form.state">
                            <option value="">UF</option>
                            @foreach($statesOfBrazil as $uf => $label)
                                <option value="{{ $uf }}">{{ $uf }}</option>
                            @endforeach
                        </select>
                    </div>

                </div>
            </div>

        </div>{{-- /tab-content --}}
    </x-slot:body>
</x-crud-modal>
