<div class="modal fade"
     id="waitingListModal"
     tabindex="-1"
     aria-labelledby="waitingListModalLabel"
     aria-hidden="true"
     x-data="{
         form: {
             doctor_id: '', patient_id: '', full_name: '',
             telephone: '', cellphone: '', cellphone_whatsapp: false,
             covenant_id: '', visit_id: '', notes: '',
             preferred_date_from: '', preferred_date_until: ''
         },
         errors: {},
         saving: false,
         hasError(f) { return !! this.errors[f]; },
         firstError(f) { return this.errors[f]?.[0] ?? ''; },
         reset() {
             this.form = {
                 doctor_id: '', patient_id: '', full_name: '',
                 telephone: '', cellphone: '', cellphone_whatsapp: false,
                 covenant_id: '', visit_id: '', notes: '',
                 preferred_date_from: '', preferred_date_until: ''
             };
             this.errors = {};
         },
         async save() {
             this.saving = true;
             this.errors = {};
             try {
                 const res  = await fetch(@js(route('panel.waiting-list.store')), {
                     method: 'POST',
                     headers: {
                         'Content-Type':     'application/json',
                         'Accept':           'application/json',
                         'X-CSRF-TOKEN':     document.querySelector('meta[name=csrf-token]').content,
                         'X-Requested-With': 'XMLHttpRequest',
                     },
                     body: JSON.stringify(this.form),
                 });
                 const data = await res.json();
                 if (! res.ok) { this.errors = data.errors ?? {}; return; }
                 bootstrap.Modal.getInstance(document.getElementById('waitingListModal'))?.hide();
                 window.dispatchEvent(new CustomEvent('waiting-list-saved', { detail: data.data }));
                 if (window.showSuccessToast) showSuccessToast(data.message);
             } catch { if (window.showErrorToast) showErrorToast('Erro de conexão.'); }
             finally  { this.saving = false; }
         }
     }"
     x-on:show.bs.modal="reset()"
     x-on:hidden.bs.modal="reset()">

    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">

            {{-- Header --}}
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-semibold fs-6" id="waitingListModalLabel">
                    <i class="fas fa-hourglass-half me-2"></i> Lista de Espera
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body p-4">
                <div class="row g-3">

                    {{-- Médico --}}
                    <div class="col-md-6">
                        <label class="form-label">Médico <span class="text-danger">*</span></label>
                        <select class="form-select"
                                :class="{ 'is-invalid': hasError('doctor_id') }"
                                x-model="form.doctor_id">
                            <option value="">Selecione...</option>
                            @foreach($doctors as $doctor)
                                <option value="{{ $doctor->id }}">{{ $doctor->user_name }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback" x-text="firstError('doctor_id')"></div>
                    </div>

                    {{-- Período preferido --}}
                    <div class="col-md-6">
                        <label class="form-label">Período preferido
                            <small class="text-muted fw-normal">— opcional</small>
                        </label>
                        <div class="input-group input-group-sm">
                            <input type="date"
                                   class="form-control"
                                   :class="{ 'is-invalid': hasError('preferred_date_from') }"
                                   x-model="form.preferred_date_from"
                                   placeholder="De">
                            <span class="input-group-text">até</span>
                            <input type="date"
                                   class="form-control"
                                   :class="{ 'is-invalid': hasError('preferred_date_until') }"
                                   x-model="form.preferred_date_until"
                                   placeholder="Até">
                        </div>
                        <div class="invalid-feedback d-block"
                             x-show="hasError('preferred_date_from') || hasError('preferred_date_until')"
                             x-text="firstError('preferred_date_from') || firstError('preferred_date_until')">
                        </div>
                    </div>

                    {{-- Paciente (busca + cadastro rápido) --}}
                    <div class="col-12"
                         x-data="patientSearch(
                             @js(route('panel.patients.search')),
                             @js(route('panel.patients.quick'))
                         )"
                         x-init="$nextTick(() => document.getElementById('waitingListModal').addEventListener('hidden.bs.modal', () => reset()))">

                        <label class="form-label">Paciente</label>

                        <template x-if="selectedPatient">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-info text-dark fs-6 fw-normal px-3 py-2"
                                      x-text="selectedPatient.full_name + ' (' + selectedPatient.code + ')'"></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" @click="clear()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </template>

                        <div x-show="!selectedPatient" class="position-relative">
                            <input type="text"
                                   class="form-control"
                                   x-model="query"
                                   @input.debounce.300ms="search()"
                                   @blur="closeResults()"
                                   placeholder="Buscar por nome, CPF ou celular…">
                            <span x-show="searching" x-cloak
                                  class="position-absolute top-50 end-0 translate-middle-y pe-3">
                                <span class="spinner-border spinner-border-sm text-secondary"></span>
                            </span>
                            <ul x-show="showResults && results.length" x-cloak
                                class="list-group position-absolute w-100 shadow-sm"
                                style="z-index:1055;max-height:220px;overflow-y:auto">
                                <template x-for="p in results" :key="p.id">
                                    <li class="list-group-item list-group-item-action"
                                        style="cursor:pointer"
                                        @mousedown.prevent="select(p)">
                                        <span x-text="p.full_name"></span>
                                        <small class="text-muted ms-2" x-text="p.code"></small>
                                    </li>
                                </template>
                                <li class="list-group-item list-group-item-action text-primary"
                                    style="cursor:pointer"
                                    @mousedown.prevent="prefillCreate()">
                                    <i class="fas fa-plus-circle me-1"></i>
                                    Cadastrar "<span x-text="query"></span>"
                                </li>
                            </ul>
                            <ul x-show="showResults && !results.length && query.length >= 2 && !searching" x-cloak
                                class="list-group position-absolute w-100 shadow-sm"
                                style="z-index:1055">
                                <li class="list-group-item list-group-item-action text-primary"
                                    style="cursor:pointer"
                                    @mousedown.prevent="prefillCreate()">
                                    <i class="fas fa-plus-circle me-1"></i>
                                    Cadastrar "<span x-text="query"></span>"
                                </li>
                            </ul>
                        </div>

                        {{-- Cadastro rápido --}}
                        <div x-show="showCreate" x-cloak class="card card-body bg-light mt-2 p-3">
                            <p class="mb-2 fw-semibold small">Cadastro rápido de paciente</p>
                            <div class="row g-2">
                                <div class="col-md-7">
                                    <input type="text" class="form-control form-control-sm"
                                           x-model="newPatient.name" placeholder="Nome completo *">
                                </div>
                                <div class="col-md-5">
                                    <input type="text" class="form-control form-control-sm"
                                           x-model="newPatient.cellphone" x-mask-br="'cel'" maxlength="15"
                                           placeholder="Celular *">
                                </div>
                            </div>
                            <div class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-sm btn-primary"
                                        :disabled="creating" @click="createAndLink()">
                                    <span x-show="creating" class="spinner-border spinner-border-sm me-1"></span>
                                    Salvar e vincular
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        @click="showCreate = false">Cancelar</button>
                            </div>
                        </div>

                    </div>

                    {{-- Nome completo --}}
                    <div class="col-md-12">
                        <label class="form-label">Nome completo <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control"
                               :class="{ 'is-invalid': hasError('full_name') }"
                               x-model="form.full_name"
                               placeholder="Nome do paciente">
                        <div class="invalid-feedback" x-text="firstError('full_name')"></div>
                    </div>

                    {{-- Convênio --}}
                    <div class="col-md-6">
                        <label class="form-label">Convênio</label>
                        <select class="form-select" x-model="form.covenant_id">
                            <option value="">Nenhum</option>
                            @foreach($covenants as $covenant)
                                <option value="{{ $covenant->id }}">{{ $covenant->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Tipo de consulta --}}
                    <div class="col-md-6">
                        <label class="form-label">Tipo de consulta</label>
                        <select class="form-select" x-model="form.visit_id">
                            <option value="">Nenhum</option>
                            @foreach($visitTypes as $type)
                                <option value="{{ $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Telefone --}}
                    <div class="col-md-6">
                        <label class="form-label">Telefone</label>
                        <input type="text" class="form-control"
                               x-model="form.telephone" x-mask-br="'tel'" maxlength="14"
                               placeholder="(00) 0000-0000">
                    </div>

                    {{-- Celular --}}
                    <div class="col-md-6">
                        <label class="form-label">Celular</label>
                        <input type="text" class="form-control"
                               x-model="form.cellphone" x-mask-br="'cel'" maxlength="15"
                               placeholder="(00) 00000-0000">
                    </div>

                    {{-- WhatsApp --}}
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox"
                                   id="wlWhatsapp" x-model="form.cellphone_whatsapp">
                            <label class="form-check-label" for="wlWhatsapp">
                                <i class="fab fa-whatsapp text-success"></i> Celular é WhatsApp
                            </label>
                        </div>
                    </div>

                    {{-- Observações --}}
                    <div class="col-12">
                        <label class="form-label">Observações</label>
                        <textarea class="form-control" x-model="form.notes" rows="2"
                                  placeholder="Motivo da consulta, urgência..."></textarea>
                    </div>

                </div>
            </div>

            <div class="modal-footer border-top">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning px-4" @click="save()" :disabled="saving">
                    <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>
                    <i class="fas fa-hourglass-half me-1"></i> Adicionar à Lista
                </button>
            </div>

        </div>
    </div>
</div>
