import { computed, ref, watch, mergeProps, withCtx, unref, createVNode, withModifiers, withDirectives, createTextVNode, vModelText, openBlock, createBlock, toDisplayString, createCommentVNode, Fragment, renderList, vModelSelect, vModelCheckbox, vShow, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrRenderAttr, ssrRenderClass, ssrInterpolate, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList } from "vue/server-renderer";
import { useForm } from "@inertiajs/vue3";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "PatientFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    patientId: { type: String, default: null },
    covenants: { type: Array, default: () => [] },
    skinTypes: { type: Array, default: () => [] },
    irisTypes: { type: Array, default: () => [] },
    genders: { type: Object, default: () => ({}) },
    maritalStatuses: { type: Object, default: () => ({}) },
    statesOfBrazil: { type: Object, default: () => ({}) }
  },
  emits: ["close"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const isEdit = computed(() => !!props.patientId);
    const title = computed(() => isEdit.value ? "Editar Paciente" : "Novo Paciente");
    const activeTab = ref("personal");
    const loading = ref(false);
    const form = useForm({
      // Clínico
      covenant_id: "",
      card_number: "",
      skin_id: "",
      iris_id: "",
      active: true,
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
      // Documento
      state_registry: "",
      state_registry_agency: "",
      state_registry_initial: "",
      state_registry_date: "",
      // Contato
      telephone: "",
      cellphone: "",
      whatsapp: false,
      // Endereço
      zipcode: "",
      address: "",
      number: "",
      complement: "",
      district: "",
      city: "",
      state: "",
      country: "Brasil"
    });
    function resetForm() {
      form.reset();
      form.clearErrors();
      activeTab.value = "personal";
    }
    async function loadEditData(id) {
      loading.value = true;
      try {
        const res = await fetch(route("panel.patients.editData", id));
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
      if (props.patientId) await loadEditData(props.patientId);
    });
    function submit() {
      if (isEdit.value) {
        form.put(route("panel.patients.update", props.patientId), {
          preserveScroll: true,
          onSuccess: () => emit("close")
        });
      } else {
        form.post(route("panel.patients.store"), {
          preserveScroll: true,
          onSuccess: () => emit("close")
        });
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
      () => Object.entries(props.statesOfBrazil).map(([v, l]) => ({ value: v, label: l }))
    );
    const tabHasErrors = computed(() => ({
      personal: Object.keys(form.errors).some(
        (k) => ["name", "nickname", "national_registry", "birth_date", "gender", "marital_status", "email", "mother_name", "father_name"].includes(k)
      ),
      clinical: Object.keys(form.errors).some(
        (k) => ["covenant_id", "card_number", "skin_id", "iris_id"].includes(k)
      ),
      contact: Object.keys(form.errors).some(
        (k) => ["telephone", "cellphone", "whatsapp"].includes(k)
      ),
      address: Object.keys(form.errors).some(
        (k) => ["zipcode", "address", "number", "complement", "district", "city", "state", "country"].includes(k)
      )
    }));
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 600,
        loading: loading.value,
        onClose: ($event) => emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<h5 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-user me-2 text-primary"${_scopeId}></i>${ssrInterpolate(title.value)}</h5>`);
          } else {
            return [
              createVNode("h5", { class: "mb-0 fw-semibold" }, [
                createVNode("i", { class: "ti ti-user me-2 text-primary" }),
                createTextVNode(toDisplayString(title.value), 1)
              ])
            ];
          }
        }),
        tabs: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<ul class="nav nav-tabs border-0"${_scopeId}><li class="nav-item"${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "personal", "text-danger": tabHasErrors.value.personal }, "nav-link"])}" type="button"${_scopeId}><i class="ti ti-user-circle me-1"${_scopeId}></i>Pessoal `);
            if (tabHasErrors.value.personal) {
              _push2(`<i class="ti ti-alert-circle text-danger ms-1"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</button></li><li class="nav-item"${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "clinical", "text-danger": tabHasErrors.value.clinical }, "nav-link"])}" type="button"${_scopeId}><i class="ti ti-stethoscope me-1"${_scopeId}></i>Clínico `);
            if (tabHasErrors.value.clinical) {
              _push2(`<i class="ti ti-alert-circle text-danger ms-1"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</button></li><li class="nav-item"${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "contact", "text-danger": tabHasErrors.value.contact }, "nav-link"])}" type="button"${_scopeId}><i class="ti ti-phone me-1"${_scopeId}></i>Contato `);
            if (tabHasErrors.value.contact) {
              _push2(`<i class="ti ti-alert-circle text-danger ms-1"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</button></li><li class="nav-item"${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "address", "text-danger": tabHasErrors.value.address }, "nav-link"])}" type="button"${_scopeId}><i class="ti ti-map-pin me-1"${_scopeId}></i>Endereço `);
            if (tabHasErrors.value.address) {
              _push2(`<i class="ti ti-alert-circle text-danger ms-1"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</button></li></ul>`);
          } else {
            return [
              createVNode("ul", { class: "nav nav-tabs border-0" }, [
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "personal", "text-danger": tabHasErrors.value.personal }],
                    type: "button",
                    onClick: ($event) => activeTab.value = "personal"
                  }, [
                    createVNode("i", { class: "ti ti-user-circle me-1" }),
                    createTextVNode("Pessoal "),
                    tabHasErrors.value.personal ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "clinical", "text-danger": tabHasErrors.value.clinical }],
                    type: "button",
                    onClick: ($event) => activeTab.value = "clinical"
                  }, [
                    createVNode("i", { class: "ti ti-stethoscope me-1" }),
                    createTextVNode("Clínico "),
                    tabHasErrors.value.clinical ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "contact", "text-danger": tabHasErrors.value.contact }],
                    type: "button",
                    onClick: ($event) => activeTab.value = "contact"
                  }, [
                    createVNode("i", { class: "ti ti-phone me-1" }),
                    createTextVNode("Contato "),
                    tabHasErrors.value.contact ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "address", "text-danger": tabHasErrors.value.address }],
                    type: "button",
                    onClick: ($event) => activeTab.value = "address"
                  }, [
                    createVNode("i", { class: "ti ti-map-pin me-1" }),
                    createTextVNode("Endereço "),
                    tabHasErrors.value.address ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ])
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
            _push2(` ${ssrInterpolate(isEdit.value ? "Salvar alterações" : "Cadastrar paciente")}</button>`);
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
                createTextVNode(" " + toDisplayString(isEdit.value ? "Salvar alterações" : "Cadastrar paciente"), 1)
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
            _push2(`</div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Apelido</label><input${ssrRenderAttr("value", unref(form).nickname)} type="text" class="form-control"${_scopeId}></div><div class="row g-3 mb-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Data de nascimento <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).birth_date)} type="date" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.birth_date }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.birth_date) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.birth_date)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Gênero <span class="text-danger"${_scopeId}>*</span></label><select class="${ssrRenderClass([{ "is-invalid": unref(form).errors.gender }, "form-select"])}"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).gender) ? ssrLooseContain(unref(form).gender, "") : ssrLooseEqual(unref(form).gender, "")) ? " selected" : ""}${_scopeId}>Selecione</option><!--[-->`);
            ssrRenderList(genderOptions.value, (o) => {
              _push2(`<option${ssrRenderAttr("value", o.value)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).gender) ? ssrLooseContain(unref(form).gender, o.value) : ssrLooseEqual(unref(form).gender, o.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(o.label)}</option>`);
            });
            _push2(`<!--]--></select>`);
            if (unref(form).errors.gender) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.gender)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div><div class="row g-3 mb-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Estado civil <span class="text-danger"${_scopeId}>*</span></label><select class="${ssrRenderClass([{ "is-invalid": unref(form).errors.marital_status }, "form-select"])}"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).marital_status) ? ssrLooseContain(unref(form).marital_status, "") : ssrLooseEqual(unref(form).marital_status, "")) ? " selected" : ""}${_scopeId}>Selecione</option><!--[-->`);
            ssrRenderList(maritalOptions.value, (o) => {
              _push2(`<option${ssrRenderAttr("value", o.value)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).marital_status) ? ssrLooseContain(unref(form).marital_status, o.value) : ssrLooseEqual(unref(form).marital_status, o.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(o.label)}</option>`);
            });
            _push2(`<!--]--></select>`);
            if (unref(form).errors.marital_status) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.marital_status)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>CPF <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).national_registry)} type="text" placeholder="000.000.000-00" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.national_registry }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.national_registry) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.national_registry)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>E-mail</label><input${ssrRenderAttr("value", unref(form).email)} type="email" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.email }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.email) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.email)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="row g-3 mb-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Nome da mãe</label><input${ssrRenderAttr("value", unref(form).mother_name)} type="text" class="form-control"${_scopeId}></div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Nome do pai</label><input${ssrRenderAttr("value", unref(form).father_name)} type="text" class="form-control"${_scopeId}></div></div>`);
            if (isEdit.value) {
              _push2(`<div class="mb-3"${_scopeId}><div class="form-check form-switch"${_scopeId}><input id="chkActive"${ssrIncludeBooleanAttr(Array.isArray(unref(form).active) ? ssrLooseContain(unref(form).active, null) : unref(form).active) ? " checked" : ""} class="form-check-input" type="checkbox"${_scopeId}><label class="form-check-label" for="chkActive"${_scopeId}>Paciente ativo</label></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div style="${ssrRenderStyle(activeTab.value === "clinical" ? null : { display: "none" })}"${_scopeId}><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Convênio</label><select class="${ssrRenderClass([{ "is-invalid": unref(form).errors.covenant_id }, "form-select"])}"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).covenant_id) ? ssrLooseContain(unref(form).covenant_id, "") : ssrLooseEqual(unref(form).covenant_id, "")) ? " selected" : ""}${_scopeId}>Selecione</option><!--[-->`);
            ssrRenderList(__props.covenants, (c) => {
              _push2(`<option${ssrRenderAttr("value", c.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).covenant_id) ? ssrLooseContain(unref(form).covenant_id, c.id) : ssrLooseEqual(unref(form).covenant_id, c.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(c.name)}</option>`);
            });
            _push2(`<!--]--></select>`);
            if (unref(form).errors.covenant_id) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.covenant_id)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Número da carteirinha</label><input${ssrRenderAttr("value", unref(form).card_number)} type="text" class="form-control"${_scopeId}></div><div class="row g-3 mb-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Tipo de pele</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).skin_id) ? ssrLooseContain(unref(form).skin_id, "") : ssrLooseEqual(unref(form).skin_id, "")) ? " selected" : ""}${_scopeId}>Não informado</option><!--[-->`);
            ssrRenderList(__props.skinTypes, (s) => {
              _push2(`<option${ssrRenderAttr("value", s.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).skin_id) ? ssrLooseContain(unref(form).skin_id, s.id) : ssrLooseEqual(unref(form).skin_id, s.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(s.name)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>Tipo de íris</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).iris_id) ? ssrLooseContain(unref(form).iris_id, "") : ssrLooseEqual(unref(form).iris_id, "")) ? " selected" : ""}${_scopeId}>Não informado</option><!--[-->`);
            ssrRenderList(__props.irisTypes, (i) => {
              _push2(`<option${ssrRenderAttr("value", i.id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).iris_id) ? ssrLooseContain(unref(form).iris_id, i.id) : ssrLooseEqual(unref(form).iris_id, i.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(i.name)}</option>`);
            });
            _push2(`<!--]--></select></div></div><div class="card border-0 bg-light p-3 mt-2"${_scopeId}><p class="text-muted small fw-medium mb-2"${_scopeId}>Registro estadual (opcional)</p><div class="row g-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label small"${_scopeId}>RG</label><input${ssrRenderAttr("value", unref(form).state_registry)} type="text" class="form-control form-control-sm"${_scopeId}></div><div class="col-6"${_scopeId}><label class="form-label small"${_scopeId}>Órgão emissor</label><input${ssrRenderAttr("value", unref(form).state_registry_agency)} type="text" class="form-control form-control-sm"${_scopeId}></div><div class="col-6"${_scopeId}><label class="form-label small"${_scopeId}>UF do RG</label><select class="form-select form-select-sm"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).state_registry_initial) ? ssrLooseContain(unref(form).state_registry_initial, "") : ssrLooseEqual(unref(form).state_registry_initial, "")) ? " selected" : ""}${_scopeId}>—</option><!--[-->`);
            ssrRenderList(stateOptions.value, (s) => {
              _push2(`<option${ssrRenderAttr("value", s.value)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).state_registry_initial) ? ssrLooseContain(unref(form).state_registry_initial, s.value) : ssrLooseEqual(unref(form).state_registry_initial, s.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(s.value)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="col-6"${_scopeId}><label class="form-label small"${_scopeId}>Data do RG</label><input${ssrRenderAttr("value", unref(form).state_registry_date)} type="date" class="form-control form-control-sm"${_scopeId}></div></div></div></div><div style="${ssrRenderStyle(activeTab.value === "contact" ? null : { display: "none" })}"${_scopeId}><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Celular <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", unref(form).cellphone)} type="text" placeholder="(00) 00000-0000" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.cellphone }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.cellphone) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.cellphone)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="mb-3"${_scopeId}><div class="form-check"${_scopeId}><input id="chkWhatsapp"${ssrIncludeBooleanAttr(Array.isArray(unref(form).whatsapp) ? ssrLooseContain(unref(form).whatsapp, null) : unref(form).whatsapp) ? " checked" : ""} class="form-check-input" type="checkbox"${_scopeId}><label class="form-check-label" for="chkWhatsapp"${_scopeId}><i class="fab fa-whatsapp text-success me-1"${_scopeId}></i>Este celular tem WhatsApp </label></div></div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Telefone fixo</label><input${ssrRenderAttr("value", unref(form).telephone)} type="text" class="form-control" placeholder="(00) 0000-0000"${_scopeId}></div></div><div style="${ssrRenderStyle(activeTab.value === "address" ? null : { display: "none" })}"${_scopeId}><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>CEP</label><div class="input-group"${_scopeId}><input${ssrRenderAttr("value", unref(form).zipcode)} type="text" class="form-control" placeholder="00000-000"${_scopeId}><button type="button" class="btn btn-outline-secondary"${_scopeId}><i class="ti ti-search"${_scopeId}></i></button></div></div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Logradouro</label><input${ssrRenderAttr("value", unref(form).address)} type="text" class="form-control"${_scopeId}></div><div class="row g-3 mb-3"${_scopeId}><div class="col-4"${_scopeId}><label class="form-label"${_scopeId}>Número</label><input${ssrRenderAttr("value", unref(form).number)} type="text" class="form-control"${_scopeId}></div><div class="col-8"${_scopeId}><label class="form-label"${_scopeId}>Complemento</label><input${ssrRenderAttr("value", unref(form).complement)} type="text" class="form-control"${_scopeId}></div></div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>Bairro</label><input${ssrRenderAttr("value", unref(form).district)} type="text" class="form-control"${_scopeId}></div><div class="row g-3 mb-3"${_scopeId}><div class="col-8"${_scopeId}><label class="form-label"${_scopeId}>Cidade</label><input${ssrRenderAttr("value", unref(form).city)} type="text" class="form-control"${_scopeId}></div><div class="col-4"${_scopeId}><label class="form-label"${_scopeId}>UF</label><select class="form-select"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).state) ? ssrLooseContain(unref(form).state, "") : ssrLooseEqual(unref(form).state, "")) ? " selected" : ""}${_scopeId}>—</option><!--[-->`);
            ssrRenderList(stateOptions.value, (s) => {
              _push2(`<option${ssrRenderAttr("value", s.value)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).state) ? ssrLooseContain(unref(form).state, s.value) : ssrLooseEqual(unref(form).state, s.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(s.value)}</option>`);
            });
            _push2(`<!--]--></select></div></div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>País</label><input${ssrRenderAttr("value", unref(form).country)} type="text" class="form-control"${_scopeId}></div></div></form>`);
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
                    createVNode("label", { class: "form-label" }, "Apelido"),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).nickname = $event,
                      type: "text",
                      class: "form-control"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).nickname]
                    ])
                  ]),
                  createVNode("div", { class: "row g-3 mb-3" }, [
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, [
                        createTextVNode("Data de nascimento "),
                        createVNode("span", { class: "text-danger" }, "*")
                      ]),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).birth_date = $event,
                        type: "date",
                        class: ["form-control", { "is-invalid": unref(form).errors.birth_date }]
                      }, null, 10, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).birth_date]
                      ]),
                      unref(form).errors.birth_date ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.birth_date), 1)) : createCommentVNode("", true)
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, [
                        createTextVNode("Gênero "),
                        createVNode("span", { class: "text-danger" }, "*")
                      ]),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => unref(form).gender = $event,
                        class: ["form-select", { "is-invalid": unref(form).errors.gender }]
                      }, [
                        createVNode("option", { value: "" }, "Selecione"),
                        (openBlock(true), createBlock(Fragment, null, renderList(genderOptions.value, (o) => {
                          return openBlock(), createBlock("option", {
                            key: o.value,
                            value: o.value
                          }, toDisplayString(o.label), 9, ["value"]);
                        }), 128))
                      ], 10, ["onUpdate:modelValue"]), [
                        [vModelSelect, unref(form).gender]
                      ]),
                      unref(form).errors.gender ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.gender), 1)) : createCommentVNode("", true)
                    ])
                  ]),
                  createVNode("div", { class: "row g-3 mb-3" }, [
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, [
                        createTextVNode("Estado civil "),
                        createVNode("span", { class: "text-danger" }, "*")
                      ]),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => unref(form).marital_status = $event,
                        class: ["form-select", { "is-invalid": unref(form).errors.marital_status }]
                      }, [
                        createVNode("option", { value: "" }, "Selecione"),
                        (openBlock(true), createBlock(Fragment, null, renderList(maritalOptions.value, (o) => {
                          return openBlock(), createBlock("option", {
                            key: o.value,
                            value: o.value
                          }, toDisplayString(o.label), 9, ["value"]);
                        }), 128))
                      ], 10, ["onUpdate:modelValue"]), [
                        [vModelSelect, unref(form).marital_status]
                      ]),
                      unref(form).errors.marital_status ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.marital_status), 1)) : createCommentVNode("", true)
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, [
                        createTextVNode("CPF "),
                        createVNode("span", { class: "text-danger" }, "*")
                      ]),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).national_registry = $event,
                        type: "text",
                        class: ["form-control", { "is-invalid": unref(form).errors.national_registry }],
                        placeholder: "000.000.000-00"
                      }, null, 10, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).national_registry]
                      ]),
                      unref(form).errors.national_registry ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.national_registry), 1)) : createCommentVNode("", true)
                    ])
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, "E-mail"),
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
                  createVNode("div", { class: "row g-3 mb-3" }, [
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, "Nome da mãe"),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).mother_name = $event,
                        type: "text",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).mother_name]
                      ])
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, "Nome do pai"),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).father_name = $event,
                        type: "text",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).father_name]
                      ])
                    ])
                  ]),
                  isEdit.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "mb-3"
                  }, [
                    createVNode("div", { class: "form-check form-switch" }, [
                      withDirectives(createVNode("input", {
                        id: "chkActive",
                        "onUpdate:modelValue": ($event) => unref(form).active = $event,
                        class: "form-check-input",
                        type: "checkbox"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelCheckbox, unref(form).active]
                      ]),
                      createVNode("label", {
                        class: "form-check-label",
                        for: "chkActive"
                      }, "Paciente ativo")
                    ])
                  ])) : createCommentVNode("", true)
                ], 512), [
                  [vShow, activeTab.value === "personal"]
                ]),
                withDirectives(createVNode("div", null, [
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, "Convênio"),
                    withDirectives(createVNode("select", {
                      "onUpdate:modelValue": ($event) => unref(form).covenant_id = $event,
                      class: ["form-select", { "is-invalid": unref(form).errors.covenant_id }]
                    }, [
                      createVNode("option", { value: "" }, "Selecione"),
                      (openBlock(true), createBlock(Fragment, null, renderList(__props.covenants, (c) => {
                        return openBlock(), createBlock("option", {
                          key: c.id,
                          value: c.id
                        }, toDisplayString(c.name), 9, ["value"]);
                      }), 128))
                    ], 10, ["onUpdate:modelValue"]), [
                      [vModelSelect, unref(form).covenant_id]
                    ]),
                    unref(form).errors.covenant_id ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "invalid-feedback"
                    }, toDisplayString(unref(form).errors.covenant_id), 1)) : createCommentVNode("", true)
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, "Número da carteirinha"),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).card_number = $event,
                      type: "text",
                      class: "form-control"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).card_number]
                    ])
                  ]),
                  createVNode("div", { class: "row g-3 mb-3" }, [
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, "Tipo de pele"),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => unref(form).skin_id = $event,
                        class: "form-select"
                      }, [
                        createVNode("option", { value: "" }, "Não informado"),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.skinTypes, (s) => {
                          return openBlock(), createBlock("option", {
                            key: s.id,
                            value: s.id
                          }, toDisplayString(s.name), 9, ["value"]);
                        }), 128))
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, unref(form).skin_id]
                      ])
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, "Tipo de íris"),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => unref(form).iris_id = $event,
                        class: "form-select"
                      }, [
                        createVNode("option", { value: "" }, "Não informado"),
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.irisTypes, (i) => {
                          return openBlock(), createBlock("option", {
                            key: i.id,
                            value: i.id
                          }, toDisplayString(i.name), 9, ["value"]);
                        }), 128))
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, unref(form).iris_id]
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "card border-0 bg-light p-3 mt-2" }, [
                    createVNode("p", { class: "text-muted small fw-medium mb-2" }, "Registro estadual (opcional)"),
                    createVNode("div", { class: "row g-3" }, [
                      createVNode("div", { class: "col-6" }, [
                        createVNode("label", { class: "form-label small" }, "RG"),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => unref(form).state_registry = $event,
                          type: "text",
                          class: "form-control form-control-sm"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, unref(form).state_registry]
                        ])
                      ]),
                      createVNode("div", { class: "col-6" }, [
                        createVNode("label", { class: "form-label small" }, "Órgão emissor"),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => unref(form).state_registry_agency = $event,
                          type: "text",
                          class: "form-control form-control-sm"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, unref(form).state_registry_agency]
                        ])
                      ]),
                      createVNode("div", { class: "col-6" }, [
                        createVNode("label", { class: "form-label small" }, "UF do RG"),
                        withDirectives(createVNode("select", {
                          "onUpdate:modelValue": ($event) => unref(form).state_registry_initial = $event,
                          class: "form-select form-select-sm"
                        }, [
                          createVNode("option", { value: "" }, "—"),
                          (openBlock(true), createBlock(Fragment, null, renderList(stateOptions.value, (s) => {
                            return openBlock(), createBlock("option", {
                              key: s.value,
                              value: s.value
                            }, toDisplayString(s.value), 9, ["value"]);
                          }), 128))
                        ], 8, ["onUpdate:modelValue"]), [
                          [vModelSelect, unref(form).state_registry_initial]
                        ])
                      ]),
                      createVNode("div", { class: "col-6" }, [
                        createVNode("label", { class: "form-label small" }, "Data do RG"),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => unref(form).state_registry_date = $event,
                          type: "date",
                          class: "form-control form-control-sm"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelText, unref(form).state_registry_date]
                        ])
                      ])
                    ])
                  ])
                ], 512), [
                  [vShow, activeTab.value === "clinical"]
                ]),
                withDirectives(createVNode("div", null, [
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, [
                      createTextVNode("Celular "),
                      createVNode("span", { class: "text-danger" }, "*")
                    ]),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).cellphone = $event,
                      type: "text",
                      class: ["form-control", { "is-invalid": unref(form).errors.cellphone }],
                      placeholder: "(00) 00000-0000"
                    }, null, 10, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).cellphone]
                    ]),
                    unref(form).errors.cellphone ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "invalid-feedback"
                    }, toDisplayString(unref(form).errors.cellphone), 1)) : createCommentVNode("", true)
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("div", { class: "form-check" }, [
                      withDirectives(createVNode("input", {
                        id: "chkWhatsapp",
                        "onUpdate:modelValue": ($event) => unref(form).whatsapp = $event,
                        class: "form-check-input",
                        type: "checkbox"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelCheckbox, unref(form).whatsapp]
                      ]),
                      createVNode("label", {
                        class: "form-check-label",
                        for: "chkWhatsapp"
                      }, [
                        createVNode("i", { class: "fab fa-whatsapp text-success me-1" }),
                        createTextVNode("Este celular tem WhatsApp ")
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, "Telefone fixo"),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).telephone = $event,
                      type: "text",
                      class: "form-control",
                      placeholder: "(00) 0000-0000"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).telephone]
                    ])
                  ])
                ], 512), [
                  [vShow, activeTab.value === "contact"]
                ]),
                withDirectives(createVNode("div", null, [
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
                            key: s.value,
                            value: s.value
                          }, toDisplayString(s.value), 9, ["value"]);
                        }), 128))
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, unref(form).state]
                      ])
                    ])
                  ]),
                  createVNode("div", { class: "mb-3" }, [
                    createVNode("label", { class: "form-label" }, "País"),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).country = $event,
                      type: "text",
                      class: "form-control"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).country]
                    ])
                  ])
                ], 512), [
                  [vShow, activeTab.value === "address"]
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Patients/PatientFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
