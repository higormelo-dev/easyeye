import { computed, ref, watch, mergeProps, withCtx, unref, createVNode, withModifiers, withDirectives, createTextVNode, vModelText, openBlock, createBlock, toDisplayString, createCommentVNode, Fragment, renderList, vModelSelect, vModelCheckbox, vShow, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrRenderAttr, ssrRenderClass, ssrInterpolate, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList } from "vue/server-renderer";
import { useForm } from "@inertiajs/vue3";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "DoctorFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    doctorId: { type: String, default: null },
    genders: { type: Object, default: () => ({}) },
    maritalStatuses: { type: Object, default: () => ({}) },
    statesOfBrazil: { type: Object, default: () => ({}) }
  },
  emits: ["close"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const isEdit = computed(() => !!props.doctorId);
    const title = computed(() => isEdit.value ? "Editar Médico" : "Novo Médico");
    const loading = ref(false);
    const activeTab = ref("personal");
    const form = useForm({
      // Pessoal
      name: "",
      nickname: "",
      national_registry: "",
      birth_date: "",
      gender: "",
      marital_status: "",
      email: "",
      mother_name: "",
      father_name: "",
      // Médico
      record: "",
      record_specialty: "",
      color: "#3699ff",
      observation: "",
      partner: false,
      active: true,
      // Contato + Endereço
      telephone: "",
      cellphone: "",
      whatsapp: false,
      zipcode: "",
      address: "",
      number: "",
      complement: "",
      district: "",
      city: "",
      state: "",
      // Acesso (somente criação)
      password: "",
      password_confirmation: ""
    });
    function resetForm() {
      form.reset();
      form.clearErrors();
      form.color = "#3699ff";
      activeTab.value = "personal";
    }
    async function loadEditData(id) {
      loading.value = true;
      try {
        const res = await fetch(route("panel.doctors.editData", id));
        const json = await res.json();
        const d = json.data;
        Object.keys(form).forEach((key) => {
          if (key in d) form[key] = d[key] ?? form[key];
        });
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, async (val) => {
      if (!val) return;
      resetForm();
      if (props.doctorId) await loadEditData(props.doctorId);
    });
    function submit() {
      const opts = { preserveScroll: true, onSuccess: () => emit("close") };
      if (isEdit.value) {
        form.put(route("panel.doctors.update", props.doctorId), opts);
      } else {
        form.post(route("panel.doctors.store"), opts);
      }
    }
    async function lookupCep() {
      const cep = form.zipcode.replace(/\D/g, "");
      if (cep.length !== 8) return;
      try {
        const res = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
        const d = await res.json();
        if (!d.erro) {
          form.address = d.logradouro ?? form.address;
          form.district = d.bairro ?? form.district;
          form.city = d.localidade ?? form.city;
          form.state = d.uf ?? form.state;
        }
      } catch {
      }
    }
    const genderOptions = computed(
      () => Object.entries(props.genders).map(([v, l]) => ({ value: Number(v), label: l }))
    );
    const maritalOptions = computed(
      () => Object.entries(props.maritalStatuses).map(([v, l]) => ({ value: Number(v), label: l }))
    );
    const stateOptions = computed(
      () => Object.entries(props.statesOfBrazil).map(([v]) => v)
    );
    const tabErrors = computed(() => ({
      personal: ["name", "nickname", "national_registry", "birth_date", "gender", "marital_status", "email", "mother_name", "father_name"].some((k) => k in form.errors),
      doctor: ["record", "record_specialty", "color", "observation"].some((k) => k in form.errors),
      contact: ["telephone", "cellphone", "whatsapp", "zipcode", "address", "number", "complement", "district", "city", "state"].some((k) => k in form.errors),
      auth: ["password", "password_confirmation"].some((k) => k in form.errors)
    }));
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 580,
        loading: loading.value,
        onClose: ($event) => emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<h5 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-stethoscope me-2 text-primary"${_scopeId}></i>${ssrInterpolate(title.value)}</h5>`);
          } else {
            return [
              createVNode("h5", { class: "mb-0 fw-semibold" }, [
                createVNode("i", { class: "ti ti-stethoscope me-2 text-primary" }),
                createTextVNode(toDisplayString(title.value), 1)
              ])
            ];
          }
        }),
        tabs: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<ul class="nav nav-tabs border-0"${_scopeId}><li class="nav-item"${_scopeId}><button type="button" class="${ssrRenderClass([{ active: activeTab.value === "personal", "text-danger": tabErrors.value.personal }, "nav-link"])}"${_scopeId}><i class="ti ti-user me-1"${_scopeId}></i>Pessoal `);
            if (tabErrors.value.personal) {
              _push2(`<i class="ti ti-alert-circle text-danger ms-1"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</button></li><li class="nav-item"${_scopeId}><button type="button" class="${ssrRenderClass([{ active: activeTab.value === "doctor", "text-danger": tabErrors.value.doctor }, "nav-link"])}"${_scopeId}><i class="ti ti-stethoscope me-1"${_scopeId}></i>Médico `);
            if (tabErrors.value.doctor) {
              _push2(`<i class="ti ti-alert-circle text-danger ms-1"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</button></li><li class="nav-item"${_scopeId}><button type="button" class="${ssrRenderClass([{ active: activeTab.value === "contact", "text-danger": tabErrors.value.contact }, "nav-link"])}"${_scopeId}><i class="ti ti-phone me-1"${_scopeId}></i>Contato `);
            if (tabErrors.value.contact) {
              _push2(`<i class="ti ti-alert-circle text-danger ms-1"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</button></li>`);
            if (!isEdit.value) {
              _push2(`<li class="nav-item"${_scopeId}><button type="button" class="${ssrRenderClass([{ active: activeTab.value === "auth", "text-danger": tabErrors.value.auth }, "nav-link"])}"${_scopeId}><i class="ti ti-lock me-1"${_scopeId}></i>Acesso `);
              if (tabErrors.value.auth) {
                _push2(`<i class="ti ti-alert-circle text-danger ms-1"${_scopeId}></i>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</button></li>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</ul>`);
          } else {
            return [
              createVNode("ul", { class: "nav nav-tabs border-0" }, [
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    type: "button",
                    class: ["nav-link", { active: activeTab.value === "personal", "text-danger": tabErrors.value.personal }],
                    onClick: ($event) => activeTab.value = "personal"
                  }, [
                    createVNode("i", { class: "ti ti-user me-1" }),
                    createTextVNode("Pessoal "),
                    tabErrors.value.personal ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    type: "button",
                    class: ["nav-link", { active: activeTab.value === "doctor", "text-danger": tabErrors.value.doctor }],
                    onClick: ($event) => activeTab.value = "doctor"
                  }, [
                    createVNode("i", { class: "ti ti-stethoscope me-1" }),
                    createTextVNode("Médico "),
                    tabErrors.value.doctor ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    type: "button",
                    class: ["nav-link", { active: activeTab.value === "contact", "text-danger": tabErrors.value.contact }],
                    onClick: ($event) => activeTab.value = "contact"
                  }, [
                    createVNode("i", { class: "ti ti-phone me-1" }),
                    createTextVNode("Contato "),
                    tabErrors.value.contact ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ]),
                !isEdit.value ? (openBlock(), createBlock("li", {
                  key: 0,
                  class: "nav-item"
                }, [
                  createVNode("button", {
                    type: "button",
                    class: ["nav-link", { active: activeTab.value === "auth", "text-danger": tabErrors.value.auth }],
                    onClick: ($event) => activeTab.value = "auth"
                  }, [
                    createVNode("i", { class: "ti ti-lock me-1" }),
                    createTextVNode("Acesso "),
                    tabErrors.value.auth ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ])) : createCommentVNode("", true)
              ])
            ];
          }
        }),
        footer: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<button type="button" class="btn btn-light"${_scopeId}>Cancelar</button><button type="button" class="btn btn-primary px-4"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""}${_scopeId}>`);
            if (unref(form).processing) {
              _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` ${ssrInterpolate(isEdit.value ? "Salvar alterações" : "Cadastrar médico")}</button>`);
          } else {
            return [
              createVNode("button", {
                type: "button",
                class: "btn btn-light",
                onClick: ($event) => emit("close")
              }, "Cancelar", 8, ["onClick"]),
              createVNode("button", {
                type: "button",
                class: "btn btn-primary px-4",
                disabled: unref(form).processing,
                onClick: submit
              }, [
                unref(form).processing ? (openBlock(), createBlock("span", {
                  key: 0,
                  class: "spinner-border spinner-border-sm me-1"
                })) : createCommentVNode("", true),
                createTextVNode(" " + toDisplayString(isEdit.value ? "Salvar alterações" : "Cadastrar médico"), 1)
              ], 8, ["disabled"])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<form${_scopeId}><div style="${ssrRenderStyle(activeTab.value === "personal" ? null : { display: "none" })}"${_scopeId}><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Nome completo <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).name)} type="text" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.name }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.name) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.name)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Apelido <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).nickname)} type="text" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.nickname }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.nickname) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.nickname)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="row g-3 mb-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Data de nascimento</label><input${ssrRenderAttr("value", unref(form).birth_date)} type="date" class="form-control"${_scopeId}></div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Gênero</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).gender) ? ssrLooseContain(unref(form).gender, "") : ssrLooseEqual(unref(form).gender, "")) ? " selected" : ""}${_scopeId}>Selecione</option><!--[-->`);
            ssrRenderList(genderOptions.value, (o) => {
              _push2(`<option${ssrRenderAttr("value", o.value)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).gender) ? ssrLooseContain(unref(form).gender, o.value) : ssrLooseEqual(unref(form).gender, o.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(o.label)}</option>`);
            });
            _push2(`<!--]--></select></div></div><div class="row g-3 mb-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>CPF <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).national_registry)} type="text" placeholder="00000000000" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.national_registry }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.national_registry) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.national_registry)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Estado civil</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).marital_status) ? ssrLooseContain(unref(form).marital_status, "") : ssrLooseEqual(unref(form).marital_status, "")) ? " selected" : ""}${_scopeId}>Selecione</option><!--[-->`);
            ssrRenderList(maritalOptions.value, (o) => {
              _push2(`<option${ssrRenderAttr("value", o.value)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).marital_status) ? ssrLooseContain(unref(form).marital_status, o.value) : ssrLooseEqual(unref(form).marital_status, o.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(o.label)}</option>`);
            });
            _push2(`<!--]--></select></div></div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>E-mail <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).email)} type="email" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.email }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.email) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.email)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (isEdit.value) {
              _push2(`<div class="mb-3"${_scopeId}><div class="form-check form-switch"${_scopeId}><input id="drActive"${ssrIncludeBooleanAttr(Array.isArray(unref(form).active) ? ssrLooseContain(unref(form).active, null) : unref(form).active) ? " checked" : ""} class="form-check-input" type="checkbox"${_scopeId}><label class="form-check-label" for="drActive"${_scopeId}>Médico ativo</label></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div style="${ssrRenderStyle(activeTab.value === "doctor" ? null : { display: "none" })}"${_scopeId}><div class="row g-3 mb-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>CRM <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).record)} type="text" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.record }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.record) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.record)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Especialidade <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).record_specialty)} type="text" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.record_specialty }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.record_specialty) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.record_specialty)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}> Cor na agenda <span class="text-danger"${_scopeId}>*</span><span class="ms-2 rounded-circle d-inline-block border" style="${ssrRenderStyle({ background: unref(form).color, width: "16px", height: "16px", verticalAlign: "middle" })}"${_scopeId}></span></label><input${ssrRenderAttr("value", unref(form).color)} type="color" style="${ssrRenderStyle({ "height": "38px" })}" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.color }, "form-control form-control-color"])}"${_scopeId}>`);
            if (unref(form).errors.color) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.color)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Observações</label><textarea class="form-control" rows="3" placeholder="Informações adicionais sobre o médico..."${_scopeId}>${ssrInterpolate(unref(form).observation)}</textarea></div><div class="form-check mb-3"${_scopeId}><input id="drPartner"${ssrIncludeBooleanAttr(Array.isArray(unref(form).partner) ? ssrLooseContain(unref(form).partner, null) : unref(form).partner) ? " checked" : ""} class="form-check-input" type="checkbox"${_scopeId}><label class="form-check-label" for="drPartner"${_scopeId}>Médico parceiro</label></div></div><div style="${ssrRenderStyle(activeTab.value === "contact" ? null : { display: "none" })}"${_scopeId}><div class="row g-3 mb-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Celular</label><input${ssrRenderAttr("value", unref(form).cellphone)} type="text" class="form-control" placeholder="(00) 00000-0000"${_scopeId}></div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Telefone</label><input${ssrRenderAttr("value", unref(form).telephone)} type="text" class="form-control" placeholder="(00) 0000-0000"${_scopeId}></div></div><div class="form-check mb-3"${_scopeId}><input id="drWhatsapp"${ssrIncludeBooleanAttr(Array.isArray(unref(form).whatsapp) ? ssrLooseContain(unref(form).whatsapp, null) : unref(form).whatsapp) ? " checked" : ""} class="form-check-input" type="checkbox"${_scopeId}><label class="form-check-label" for="drWhatsapp"${_scopeId}><i class="fab fa-whatsapp text-success me-1"${_scopeId}></i>Celular tem WhatsApp </label></div><hr class="my-3"${_scopeId}><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>CEP</label><div class="input-group"${_scopeId}><input${ssrRenderAttr("value", unref(form).zipcode)} type="text" class="form-control" placeholder="00000-000"${_scopeId}><button type="button" class="btn btn-outline-secondary"${_scopeId}><i class="ti ti-search"${_scopeId}></i></button></div></div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Logradouro</label><input${ssrRenderAttr("value", unref(form).address)} type="text" class="form-control"${_scopeId}></div><div class="row g-3 mb-3"${_scopeId}><div class="col-4"${_scopeId}><label class="form-label"${_scopeId}>Número</label><input${ssrRenderAttr("value", unref(form).number)} type="text" class="form-control"${_scopeId}></div><div class="col-8"${_scopeId}><label class="form-label"${_scopeId}>Complemento</label><input${ssrRenderAttr("value", unref(form).complement)} type="text" class="form-control"${_scopeId}></div></div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Bairro</label><input${ssrRenderAttr("value", unref(form).district)} type="text" class="form-control"${_scopeId}></div><div class="row g-3 mb-3"${_scopeId}><div class="col-8"${_scopeId}><label class="form-label"${_scopeId}>Cidade</label><input${ssrRenderAttr("value", unref(form).city)} type="text" class="form-control"${_scopeId}></div><div class="col-4"${_scopeId}><label class="form-label"${_scopeId}>UF</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).state) ? ssrLooseContain(unref(form).state, "") : ssrLooseEqual(unref(form).state, "")) ? " selected" : ""}${_scopeId}>—</option><!--[-->`);
            ssrRenderList(stateOptions.value, (s) => {
              _push2(`<option${ssrRenderAttr("value", s)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).state) ? ssrLooseContain(unref(form).state, s) : ssrLooseEqual(unref(form).state, s)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(s)}</option>`);
            });
            _push2(`<!--]--></select></div></div></div><div style="${ssrRenderStyle(activeTab.value === "auth" ? null : { display: "none" })}"${_scopeId}><div class="alert alert-info small mb-3 py-2"${_scopeId}><i class="ti ti-info-circle me-1"${_scopeId}></i> O médico receberá estas credenciais para acessar o sistema. </div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Senha <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).password)} type="password" autocomplete="new-password" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.password }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.password) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.password)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="form-text"${_scopeId}>Mínimo 8 caracteres com letras maiúsculas, minúsculas, números e símbolos.</div></div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Confirmar senha <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).password_confirmation)} type="password" autocomplete="new-password" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.password_confirmation }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.password_confirmation) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.password_confirmation)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div></form>`);
          } else {
            return [
              createVNode("form", {
                onSubmit: withModifiers(submit, ["prevent"])
              }, [
                withDirectives(createVNode("div", null, [
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, [
                      createTextVNode("Nome completo "),
                      createVNode("span", { class: "text-danger" }, "*")
                    ]),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).name = $event,
                      type: "text",
                      class: ["form-control", { "is-invalid": unref(form).errors.name }]
                    }, null, 10, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).name]
                    ]),
                    unref(form).errors.name ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "invalid-feedback"
                    }, toDisplayString(unref(form).errors.name), 1)) : createCommentVNode("", true)
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, [
                      createTextVNode("Apelido "),
                      createVNode("span", { class: "text-danger" }, "*")
                    ]),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).nickname = $event,
                      type: "text",
                      class: ["form-control", { "is-invalid": unref(form).errors.nickname }]
                    }, null, 10, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).nickname]
                    ]),
                    unref(form).errors.nickname ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "invalid-feedback"
                    }, toDisplayString(unref(form).errors.nickname), 1)) : createCommentVNode("", true)
                  ]),
                  createVNode("div", { class: "row g-3 mb-3" }, [
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, "Data de nascimento"),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).birth_date = $event,
                        type: "date",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).birth_date]
                      ])
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, "Gênero"),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => unref(form).gender = $event,
                        class: "form-select"
                      }, [
                        createVNode("option", { value: "" }, "Selecione"),
                        (openBlock(true), createBlock(Fragment, null, renderList(genderOptions.value, (o) => {
                          return openBlock(), createBlock("option", {
                            key: o.value,
                            value: o.value
                          }, toDisplayString(o.label), 9, ["value"]);
                        }), 128))
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, unref(form).gender]
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "row g-3 mb-3" }, [
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, [
                        createTextVNode("CPF "),
                        createVNode("span", { class: "text-danger" }, "*")
                      ]),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).national_registry = $event,
                        type: "text",
                        class: ["form-control", { "is-invalid": unref(form).errors.national_registry }],
                        placeholder: "00000000000"
                      }, null, 10, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).national_registry]
                      ]),
                      unref(form).errors.national_registry ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.national_registry), 1)) : createCommentVNode("", true)
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, "Estado civil"),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => unref(form).marital_status = $event,
                        class: "form-select"
                      }, [
                        createVNode("option", { value: "" }, "Selecione"),
                        (openBlock(true), createBlock(Fragment, null, renderList(maritalOptions.value, (o) => {
                          return openBlock(), createBlock("option", {
                            key: o.value,
                            value: o.value
                          }, toDisplayString(o.label), 9, ["value"]);
                        }), 128))
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, unref(form).marital_status]
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, [
                      createTextVNode("E-mail "),
                      createVNode("span", { class: "text-danger" }, "*")
                    ]),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).email = $event,
                      type: "email",
                      class: ["form-control", { "is-invalid": unref(form).errors.email }]
                    }, null, 10, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).email]
                    ]),
                    unref(form).errors.email ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "invalid-feedback"
                    }, toDisplayString(unref(form).errors.email), 1)) : createCommentVNode("", true)
                  ]),
                  isEdit.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "mb-3"
                  }, [
                    createVNode("div", { class: "form-check form-switch" }, [
                      withDirectives(createVNode("input", {
                        id: "drActive",
                        "onUpdate:modelValue": ($event) => unref(form).active = $event,
                        class: "form-check-input",
                        type: "checkbox"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelCheckbox, unref(form).active]
                      ]),
                      createVNode("label", {
                        class: "form-check-label",
                        for: "drActive"
                      }, "Médico ativo")
                    ])
                  ])) : createCommentVNode("", true)
                ], 512), [
                  [vShow, activeTab.value === "personal"]
                ]),
                withDirectives(createVNode("div", null, [
                  createVNode("div", { class: "row g-3 mb-3" }, [
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, [
                        createTextVNode("CRM "),
                        createVNode("span", { class: "text-danger" }, "*")
                      ]),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).record = $event,
                        type: "text",
                        class: ["form-control", { "is-invalid": unref(form).errors.record }]
                      }, null, 10, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).record]
                      ]),
                      unref(form).errors.record ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.record), 1)) : createCommentVNode("", true)
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, [
                        createTextVNode("Especialidade "),
                        createVNode("span", { class: "text-danger" }, "*")
                      ]),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).record_specialty = $event,
                        type: "text",
                        class: ["form-control", { "is-invalid": unref(form).errors.record_specialty }]
                      }, null, 10, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).record_specialty]
                      ]),
                      unref(form).errors.record_specialty ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.record_specialty), 1)) : createCommentVNode("", true)
                    ])
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, [
                      createTextVNode(" Cor na agenda "),
                      createVNode("span", { class: "text-danger" }, "*"),
                      createVNode("span", {
                        class: "ms-2 rounded-circle d-inline-block border",
                        style: { background: unref(form).color, width: "16px", height: "16px", verticalAlign: "middle" }
                      }, null, 4)
                    ]),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).color = $event,
                      type: "color",
                      class: ["form-control form-control-color", { "is-invalid": unref(form).errors.color }],
                      style: { "height": "38px" }
                    }, null, 10, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).color]
                    ]),
                    unref(form).errors.color ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "invalid-feedback"
                    }, toDisplayString(unref(form).errors.color), 1)) : createCommentVNode("", true)
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, "Observações"),
                    withDirectives(createVNode("textarea", {
                      "onUpdate:modelValue": ($event) => unref(form).observation = $event,
                      class: "form-control",
                      rows: "3",
                      placeholder: "Informações adicionais sobre o médico..."
                    }, "                    ", 8, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).observation]
                    ])
                  ]),
                  createVNode("div", { class: "form-check mb-3" }, [
                    withDirectives(createVNode("input", {
                      id: "drPartner",
                      "onUpdate:modelValue": ($event) => unref(form).partner = $event,
                      class: "form-check-input",
                      type: "checkbox"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelCheckbox, unref(form).partner]
                    ]),
                    createVNode("label", {
                      class: "form-check-label",
                      for: "drPartner"
                    }, "Médico parceiro")
                  ])
                ], 512), [
                  [vShow, activeTab.value === "doctor"]
                ]),
                withDirectives(createVNode("div", null, [
                  createVNode("div", { class: "row g-3 mb-3" }, [
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, "Celular"),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).cellphone = $event,
                        type: "text",
                        class: "form-control",
                        placeholder: "(00) 00000-0000"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).cellphone]
                      ])
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, "Telefone"),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).telephone = $event,
                        type: "text",
                        class: "form-control",
                        placeholder: "(00) 0000-0000"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).telephone]
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "form-check mb-3" }, [
                    withDirectives(createVNode("input", {
                      id: "drWhatsapp",
                      "onUpdate:modelValue": ($event) => unref(form).whatsapp = $event,
                      class: "form-check-input",
                      type: "checkbox"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelCheckbox, unref(form).whatsapp]
                    ]),
                    createVNode("label", {
                      class: "form-check-label",
                      for: "drWhatsapp"
                    }, [
                      createVNode("i", { class: "fab fa-whatsapp text-success me-1" }),
                      createTextVNode("Celular tem WhatsApp ")
                    ])
                  ]),
                  createVNode("hr", { class: "my-3" }),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, "CEP"),
                    createVNode("div", { class: "input-group" }, [
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).zipcode = $event,
                        type: "text",
                        class: "form-control",
                        placeholder: "00000-000",
                        onBlur: lookupCep
                      }, null, 40, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).zipcode]
                      ]),
                      createVNode("button", {
                        type: "button",
                        class: "btn btn-outline-secondary",
                        onClick: lookupCep
                      }, [
                        createVNode("i", { class: "ti ti-search" })
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, "Logradouro"),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).address = $event,
                      type: "text",
                      class: "form-control"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).address]
                    ])
                  ]),
                  createVNode("div", { class: "row g-3 mb-3" }, [
                    createVNode("div", { class: "col-4" }, [
                      createVNode("label", { class: "form-label" }, "Número"),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).number = $event,
                        type: "text",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).number]
                      ])
                    ]),
                    createVNode("div", { class: "col-8" }, [
                      createVNode("label", { class: "form-label" }, "Complemento"),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).complement = $event,
                        type: "text",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).complement]
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, "Bairro"),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).district = $event,
                      type: "text",
                      class: "form-control"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).district]
                    ])
                  ]),
                  createVNode("div", { class: "row g-3 mb-3" }, [
                    createVNode("div", { class: "col-8" }, [
                      createVNode("label", { class: "form-label" }, "Cidade"),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).city = $event,
                        type: "text",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).city]
                      ])
                    ]),
                    createVNode("div", { class: "col-4" }, [
                      createVNode("label", { class: "form-label" }, "UF"),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => unref(form).state = $event,
                        class: "form-select"
                      }, [
                        createVNode("option", { value: "" }, "—"),
                        (openBlock(true), createBlock(Fragment, null, renderList(stateOptions.value, (s) => {
                          return openBlock(), createBlock("option", {
                            key: s,
                            value: s
                          }, toDisplayString(s), 9, ["value"]);
                        }), 128))
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, unref(form).state]
                      ])
                    ])
                  ])
                ], 512), [
                  [vShow, activeTab.value === "contact"]
                ]),
                withDirectives(createVNode("div", null, [
                  createVNode("div", { class: "alert alert-info small mb-3 py-2" }, [
                    createVNode("i", { class: "ti ti-info-circle me-1" }),
                    createTextVNode(" O médico receberá estas credenciais para acessar o sistema. ")
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, [
                      createTextVNode("Senha "),
                      createVNode("span", { class: "text-danger" }, "*")
                    ]),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).password = $event,
                      type: "password",
                      class: ["form-control", { "is-invalid": unref(form).errors.password }],
                      autocomplete: "new-password"
                    }, null, 10, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).password]
                    ]),
                    unref(form).errors.password ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "invalid-feedback"
                    }, toDisplayString(unref(form).errors.password), 1)) : createCommentVNode("", true),
                    createVNode("div", { class: "form-text" }, "Mínimo 8 caracteres com letras maiúsculas, minúsculas, números e símbolos.")
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, [
                      createTextVNode("Confirmar senha "),
                      createVNode("span", { class: "text-danger" }, "*")
                    ]),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).password_confirmation = $event,
                      type: "password",
                      class: ["form-control", { "is-invalid": unref(form).errors.password_confirmation }],
                      autocomplete: "new-password"
                    }, null, 10, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).password_confirmation]
                    ]),
                    unref(form).errors.password_confirmation ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "invalid-feedback"
                    }, toDisplayString(unref(form).errors.password_confirmation), 1)) : createCommentVNode("", true)
                  ])
                ], 512), [
                  [vShow, activeTab.value === "auth"]
                ])
              ], 32)
            ];
          }
        }),
        _: 1
      }, _parent));
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Doctors/DoctorFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
