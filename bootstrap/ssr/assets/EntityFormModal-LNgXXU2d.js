import { computed, ref, watch, mergeProps, withCtx, unref, createVNode, withModifiers, withDirectives, toDisplayString, vModelText, openBlock, createBlock, createCommentVNode, vShow, vModelSelect, createTextVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual } from "vue/server-renderer";
import { useForm, router } from "@inertiajs/vue3";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import { _ as _sfc_main$1 } from "./ConfirmationWithReasonModal-CmfO7qbN.js";
import { u as useConfirmationWithReason } from "./useConfirmationWithReason-DDlQOe6J.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "EntityFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    entityId: { type: String, default: null },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const isEdit = computed(() => !!props.entityId);
    const title = computed(() => isEdit.value ? props.t.form_title_edit : props.t.form_title_create);
    const loading = ref(false);
    const activeTab = ref("dados");
    const form = useForm({
      name: "",
      subdomain: "",
      email: "",
      telephone: "",
      cellphone: "",
      national_registration: "",
      state_registration: "",
      municipal_registration: "",
      website: "",
      zipcode: "",
      address: "",
      number: "",
      complement: "",
      district: "",
      city: "",
      state: "",
      country: "Brasil",
      schedule_interval: 15,
      active: true
    });
    const twoFactor = ref({
      requires: false,
      enabled_at: null,
      enabled_by: null
    });
    const { state: reasonModal, open: openReasonModal, close: closeReasonModal, handle: handleReasonConfirm } = useConfirmationWithReason();
    async function loadTwoFactorState(id) {
      try {
        const res = await fetch(route("manager.entities.show", id), {
          headers: { Accept: "application/json" }
        });
        const json = await res.json();
        twoFactor.value = {
          requires: !!json.data.requires_two_factor,
          enabled_at: json.data.two_factor_enabled_at,
          enabled_by: json.data.two_factor_enabled_by
        };
      } catch {
      }
    }
    function toggleTwoFactor() {
      const enabling = !twoFactor.value.requires;
      openReasonModal({
        title: enabling ? props.t.form_2fa_toggle_enable ?? "Ativar 2FA obrigatório" : props.t.form_2fa_toggle_disable ?? "Desativar 2FA obrigatório",
        message: enabling ? props.t.form_2fa_modal_enable ?? "" : props.t.form_2fa_modal_disable ?? "",
        confirmVariant: enabling ? "primary" : "danger",
        async onConfirm(reason) {
          var _a;
          const res = await fetch(route("manager.entities.two-factor.toggle", props.entityId), {
            method: "PATCH",
            headers: {
              "Content-Type": "application/json",
              "Accept": "application/json",
              "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
            },
            body: JSON.stringify({ enabled: enabling, reason })
          });
          const json = await res.json();
          if (res.ok) {
            twoFactor.value = {
              requires: !!json.data.requires_two_factor,
              enabled_at: json.data.two_factor_enabled_at,
              enabled_by: json.data.two_factor_enabled_by ?? null
            };
            if (window.showSuccessToast) window.showSuccessToast(json.message);
            router.reload({ only: ["entities"] });
          } else if (window.showErrorToast) {
            window.showErrorToast(json.message ?? "Erro");
          }
        }
      });
    }
    function resetForm() {
      form.reset();
      form.clearErrors();
      form.country = "Brasil";
      form.schedule_interval = 15;
      activeTab.value = "dados";
    }
    async function loadEditData(id) {
      loading.value = true;
      try {
        const res = await fetch(route("manager.entities.edit-data", id));
        const json = await res.json();
        Object.keys(form).forEach((k) => {
          if (k in json.data && json.data[k] !== null) form[k] = json.data[k];
        });
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, async (val) => {
      if (val) {
        resetForm();
        if (props.entityId) {
          await loadEditData(props.entityId);
          await loadTwoFactorState(props.entityId);
        }
      }
    });
    function submit() {
      const opts = { preserveScroll: true, onSuccess: () => emit("close") };
      isEdit.value ? form.put(route("manager.entities.update", props.entityId), opts) : form.post(route("manager.entities.store"), opts);
    }
    async function lookupCep() {
      const cep = form.zipcode.replace(/\D/g, "");
      if (cep.length !== 8) return;
      try {
        const d = await fetch(`https://viacep.com.br/ws/${cep}/json/`).then((r) => r.json());
        if (!d.erro) {
          form.address = d.logradouro ?? form.address;
          form.district = d.bairro ?? form.district;
          form.city = d.localidade ?? form.city;
          form.state = d.uf ?? form.state;
        }
      } catch {
      }
    }
    const tabErrors = computed(() => ({
      dados: ["name", "subdomain", "email", "telephone", "cellphone", "national_registration", "state_registration", "municipal_registration", "website"].some((k) => k in form.errors),
      endereco: ["zipcode", "address", "number", "complement", "district", "city", "state", "country"].some((k) => k in form.errors),
      config: ["schedule_interval", "active"].some((k) => k in form.errors)
    }));
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 580,
        loading: loading.value,
        "loading-label": __props.t.loading,
        onClose: ($event) => _ctx.$emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<h5 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-building me-2 text-primary"${_scopeId}></i>${ssrInterpolate(title.value)}</h5>`);
          } else {
            return [
              createVNode("h5", { class: "mb-0 fw-semibold" }, [
                createVNode("i", { class: "ti ti-building me-2 text-primary" }),
                createTextVNode(toDisplayString(title.value), 1)
              ])
            ];
          }
        }),
        tabs: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<ul class="nav nav-tabs border-0"${_scopeId}><li class="nav-item"${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "dados", "text-danger": tabErrors.value.dados }, "nav-link"])}"${_scopeId}><i class="ti ti-building me-1"${_scopeId}></i> ${ssrInterpolate(__props.t.tab_data)} `);
            if (tabErrors.value.dados) {
              _push2(`<i class="ti ti-alert-circle text-danger ms-1 fs-12"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</button></li><li class="nav-item"${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "endereco", "text-danger": tabErrors.value.endereco }, "nav-link"])}"${_scopeId}><i class="ti ti-map-pin me-1"${_scopeId}></i> ${ssrInterpolate(__props.t.tab_address)} `);
            if (tabErrors.value.endereco) {
              _push2(`<i class="ti ti-alert-circle text-danger ms-1 fs-12"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</button></li><li class="nav-item"${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "config", "text-danger": tabErrors.value.config }, "nav-link"])}"${_scopeId}><i class="ti ti-settings me-1"${_scopeId}></i> ${ssrInterpolate(__props.t.tab_config)} `);
            if (tabErrors.value.config) {
              _push2(`<i class="ti ti-alert-circle text-danger ms-1 fs-12"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</button></li>`);
            if (isEdit.value) {
              _push2(`<li class="nav-item"${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "security" }, "nav-link"])}"${_scopeId}><i class="ti ti-shield-lock me-1"${_scopeId}></i> ${ssrInterpolate(__props.t.form_section_security ?? "Segurança")} `);
              if (twoFactor.value.requires) {
                _push2(`<span class="badge badge-soft-success rounded text-success border border-success fs-11 ms-1"${_scopeId}> 2FA </span>`);
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
                    class: ["nav-link", { active: activeTab.value === "dados", "text-danger": tabErrors.value.dados }],
                    onClick: ($event) => activeTab.value = "dados"
                  }, [
                    createVNode("i", { class: "ti ti-building me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.tab_data) + " ", 1),
                    tabErrors.value.dados ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1 fs-12"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "endereco", "text-danger": tabErrors.value.endereco }],
                    onClick: ($event) => activeTab.value = "endereco"
                  }, [
                    createVNode("i", { class: "ti ti-map-pin me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.tab_address) + " ", 1),
                    tabErrors.value.endereco ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1 fs-12"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "config", "text-danger": tabErrors.value.config }],
                    onClick: ($event) => activeTab.value = "config"
                  }, [
                    createVNode("i", { class: "ti ti-settings me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.tab_config) + " ", 1),
                    tabErrors.value.config ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1 fs-12"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ]),
                isEdit.value ? (openBlock(), createBlock("li", {
                  key: 0,
                  class: "nav-item"
                }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "security" }],
                    onClick: ($event) => activeTab.value = "security"
                  }, [
                    createVNode("i", { class: "ti ti-shield-lock me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.form_section_security ?? "Segurança") + " ", 1),
                    twoFactor.value.requires ? (openBlock(), createBlock("span", {
                      key: 0,
                      class: "badge badge-soft-success rounded text-success border border-success fs-11 ms-1"
                    }, " 2FA ")) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ])) : createCommentVNode("", true)
              ])
            ];
          }
        }),
        footer: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<button type="button" class="btn btn-light"${_scopeId}>${ssrInterpolate(__props.t.btn_cancel)}</button><button type="button" class="btn btn-primary"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""}${_scopeId}>`);
            if (unref(form).processing) {
              _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` ${ssrInterpolate(isEdit.value ? __props.t.btn_save_changes : __props.t.btn_create_company)}</button>`);
          } else {
            return [
              createVNode("button", {
                type: "button",
                class: "btn btn-light",
                onClick: ($event) => _ctx.$emit("close")
              }, toDisplayString(__props.t.btn_cancel), 9, ["onClick"]),
              createVNode("button", {
                type: "button",
                class: "btn btn-primary",
                disabled: unref(form).processing,
                onClick: submit
              }, [
                unref(form).processing ? (openBlock(), createBlock("span", {
                  key: 0,
                  class: "spinner-border spinner-border-sm me-1"
                })) : createCommentVNode("", true),
                createTextVNode(" " + toDisplayString(isEdit.value ? __props.t.btn_save_changes : __props.t.btn_create_company), 1)
              ], 8, ["disabled"])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b, _c, _d;
          if (_push2) {
            _push2(`<form${_scopeId}><div style="${ssrRenderStyle(activeTab.value === "dados" ? null : { display: "none" })}"${_scopeId}><div class="row g-3"${_scopeId}><div class="col-8"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_name_required)}</label><input${ssrRenderAttr("value", unref(form).name)} type="text" maxlength="150" autocomplete="off" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.name }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.name) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.name)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_subdomain)}</label><input${ssrRenderAttr("value", unref(form).subdomain)} type="text" maxlength="100"${ssrRenderAttr("placeholder", __props.t.field_subdomain_placeholder)} class="${ssrRenderClass([{ "is-invalid": unref(form).errors.subdomain }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.subdomain) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.subdomain)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-12"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_email)}</label><input${ssrRenderAttr("value", unref(form).email)} type="email" maxlength="150" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.email }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.email) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.email)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_telephone)}</label><input${ssrRenderAttr("value", unref(form).telephone)} type="text" maxlength="20" class="form-control"${_scopeId}></div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_cellphone)}</label><input${ssrRenderAttr("value", unref(form).cellphone)} type="text" maxlength="20" class="form-control"${_scopeId}></div><div class="col-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_national_registration)}</label><input${ssrRenderAttr("value", unref(form).national_registration)} type="text" maxlength="20" class="form-control"${_scopeId}></div><div class="col-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_state_registration)}</label><input${ssrRenderAttr("value", unref(form).state_registration)} type="text" maxlength="30" class="form-control"${_scopeId}></div><div class="col-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_municipal_registration)}</label><input${ssrRenderAttr("value", unref(form).municipal_registration)} type="text" maxlength="30" class="form-control"${_scopeId}></div><div class="col-12"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_website)}</label><input${ssrRenderAttr("value", unref(form).website)} type="text" maxlength="150"${ssrRenderAttr("placeholder", __props.t.field_website_placeholder)} class="${ssrRenderClass([{ "is-invalid": unref(form).errors.website }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.website) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.website)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div></div><div style="${ssrRenderStyle(activeTab.value === "endereco" ? null : { display: "none" })}"${_scopeId}><div class="row g-3"${_scopeId}><div class="col-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_zipcode)}</label><div class="input-group"${_scopeId}><input${ssrRenderAttr("value", unref(form).zipcode)} type="text" maxlength="10" class="form-control"${ssrRenderAttr("placeholder", __props.t.field_zipcode_placeholder)}${_scopeId}><button type="button" class="btn btn-outline-secondary"${ssrRenderAttr("title", __props.t.btn_lookup_cep)}${_scopeId}><i class="ti ti-search"${_scopeId}></i></button></div></div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_address)}</label><input${ssrRenderAttr("value", unref(form).address)} type="text" maxlength="200" class="form-control"${_scopeId}></div><div class="col-2"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_number)}</label><input${ssrRenderAttr("value", unref(form).number)} type="text" maxlength="10" class="form-control"${_scopeId}></div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_complement)}</label><input${ssrRenderAttr("value", unref(form).complement)} type="text" maxlength="100" class="form-control"${_scopeId}></div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_district)}</label><input${ssrRenderAttr("value", unref(form).district)} type="text" maxlength="100" class="form-control"${_scopeId}></div><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_city_required)}</label><input${ssrRenderAttr("value", unref(form).city)} type="text" maxlength="100" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.city }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.city) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.city)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-2"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_state_required)}</label><input${ssrRenderAttr("value", unref(form).state)} type="text" maxlength="2" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.state }, "form-control text-uppercase"])}"${_scopeId}>`);
            if (unref(form).errors.state) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.state)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_country_required)}</label><input${ssrRenderAttr("value", unref(form).country)} type="text" maxlength="50" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.country }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.country) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.country)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div></div><div style="${ssrRenderStyle(activeTab.value === "config" ? null : { display: "none" })}"${_scopeId}><div class="row g-3"${_scopeId}><div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_schedule_interval)}</label><select class="form-select"${_scopeId}><option${ssrRenderAttr("value", 15)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).schedule_interval) ? ssrLooseContain(unref(form).schedule_interval, 15) : ssrLooseEqual(unref(form).schedule_interval, 15)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.interval_15)}</option><option${ssrRenderAttr("value", 20)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).schedule_interval) ? ssrLooseContain(unref(form).schedule_interval, 20) : ssrLooseEqual(unref(form).schedule_interval, 20)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.interval_20)}</option><option${ssrRenderAttr("value", 30)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).schedule_interval) ? ssrLooseContain(unref(form).schedule_interval, 30) : ssrLooseEqual(unref(form).schedule_interval, 30)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.interval_30)}</option></select></div>`);
            if (isEdit.value) {
              _push2(`<div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_status)}</label><select class="form-select"${_scopeId}><option${ssrRenderAttr("value", true)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).active) ? ssrLooseContain(unref(form).active, true) : ssrLooseEqual(unref(form).active, true)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.status_option_active)}</option><option${ssrRenderAttr("value", false)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).active) ? ssrLooseContain(unref(form).active, false) : ssrLooseEqual(unref(form).active, false)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.status_option_inactive)}</option></select></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (!isEdit.value) {
              _push2(`<div class="alert alert-info small mt-3 py-2"${_scopeId}><i class="ti ti-info-circle me-1"${_scopeId}></i> ${ssrInterpolate(__props.t.config_info_after_create)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (isEdit.value) {
              _push2(`<div style="${ssrRenderStyle(activeTab.value === "security" ? null : { display: "none" })}"${_scopeId}><div class="card border-0 bg-light"${_scopeId}><div class="card-body"${_scopeId}><div class="d-flex align-items-start gap-3 mb-3"${_scopeId}><div class="flex-shrink-0"${_scopeId}><i class="${ssrRenderClass([twoFactor.value.requires ? "text-success" : "text-muted", "ti ti-shield-lock-filled fs-1"])}"${_scopeId}></i></div><div class="flex-grow-1"${_scopeId}><h6 class="fw-semibold mb-1"${_scopeId}>${ssrInterpolate(__props.t.form_2fa_label ?? "Exigir 2FA para todos os usuários")} `);
              if (twoFactor.value.requires) {
                _push2(`<span class="badge badge-soft-success rounded text-success border border-success ms-1"${_scopeId}> Ativo </span>`);
              } else {
                _push2(`<span class="badge badge-soft-secondary rounded ms-1"${_scopeId}> Inativo </span>`);
              }
              _push2(`</h6><p class="text-muted small mb-2"${_scopeId}>${ssrInterpolate(__props.t.form_2fa_hint)}</p>`);
              if (twoFactor.value.requires && twoFactor.value.enabled_at) {
                _push2(`<p class="small text-muted mb-0"${_scopeId}><i class="ti ti-history me-1"${_scopeId}></i> ${ssrInterpolate((_b = (_a = __props.t.form_2fa_enabled_at ?? "Ativado em :date por :user") == null ? void 0 : _a.replace(":date", twoFactor.value.enabled_at)) == null ? void 0 : _b.replace(":user", twoFactor.value.enabled_by ?? "—"))}</p>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div><button type="button" class="${ssrRenderClass(`btn btn-sm ${twoFactor.value.requires ? "btn-outline-danger" : "btn-primary"}`)}"${_scopeId}><i class="${ssrRenderClass(`ti me-1 ${twoFactor.value.requires ? "ti-shield-off" : "ti-shield-check"}`)}"${_scopeId}></i> ${ssrInterpolate(twoFactor.value.requires ? __props.t.form_2fa_toggle_disable : __props.t.form_2fa_toggle_enable)}</button></div></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</form>`);
            _push2(ssrRenderComponent(_sfc_main$1, {
              open: unref(reasonModal).open,
              title: unref(reasonModal).title,
              message: unref(reasonModal).message,
              "confirm-variant": unref(reasonModal).confirmVariant,
              saving: unref(reasonModal).saving,
              onClose: unref(closeReasonModal),
              onConfirm: unref(handleReasonConfirm)
            }, null, _parent2, _scopeId));
          } else {
            return [
              createVNode("form", {
                onSubmit: withModifiers(submit, ["prevent"])
              }, [
                withDirectives(createVNode("div", null, [
                  createVNode("div", { class: "row g-3" }, [
                    createVNode("div", { class: "col-8" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_name_required), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).name = $event,
                        type: "text",
                        maxlength: "150",
                        class: ["form-control", { "is-invalid": unref(form).errors.name }],
                        autocomplete: "off"
                      }, null, 10, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).name]
                      ]),
                      unref(form).errors.name ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.name), 1)) : createCommentVNode("", true)
                    ]),
                    createVNode("div", { class: "col-4" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_subdomain), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).subdomain = $event,
                        type: "text",
                        maxlength: "100",
                        class: ["form-control", { "is-invalid": unref(form).errors.subdomain }],
                        placeholder: __props.t.field_subdomain_placeholder
                      }, null, 10, ["onUpdate:modelValue", "placeholder"]), [
                        [vModelText, unref(form).subdomain]
                      ]),
                      unref(form).errors.subdomain ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.subdomain), 1)) : createCommentVNode("", true)
                    ]),
                    createVNode("div", { class: "col-12" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_email), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).email = $event,
                        type: "email",
                        maxlength: "150",
                        class: ["form-control", { "is-invalid": unref(form).errors.email }]
                      }, null, 10, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).email]
                      ]),
                      unref(form).errors.email ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.email), 1)) : createCommentVNode("", true)
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_telephone), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).telephone = $event,
                        type: "text",
                        maxlength: "20",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).telephone]
                      ])
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_cellphone), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).cellphone = $event,
                        type: "text",
                        maxlength: "20",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).cellphone]
                      ])
                    ]),
                    createVNode("div", { class: "col-4" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_national_registration), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).national_registration = $event,
                        type: "text",
                        maxlength: "20",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).national_registration]
                      ])
                    ]),
                    createVNode("div", { class: "col-4" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_state_registration), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).state_registration = $event,
                        type: "text",
                        maxlength: "30",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).state_registration]
                      ])
                    ]),
                    createVNode("div", { class: "col-4" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_municipal_registration), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).municipal_registration = $event,
                        type: "text",
                        maxlength: "30",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).municipal_registration]
                      ])
                    ]),
                    createVNode("div", { class: "col-12" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_website), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).website = $event,
                        type: "text",
                        maxlength: "150",
                        class: ["form-control", { "is-invalid": unref(form).errors.website }],
                        placeholder: __props.t.field_website_placeholder
                      }, null, 10, ["onUpdate:modelValue", "placeholder"]), [
                        [vModelText, unref(form).website]
                      ]),
                      unref(form).errors.website ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.website), 1)) : createCommentVNode("", true)
                    ])
                  ])
                ], 512), [
                  [vShow, activeTab.value === "dados"]
                ]),
                withDirectives(createVNode("div", null, [
                  createVNode("div", { class: "row g-3" }, [
                    createVNode("div", { class: "col-4" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_zipcode), 1),
                      createVNode("div", { class: "input-group" }, [
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => unref(form).zipcode = $event,
                          type: "text",
                          maxlength: "10",
                          class: "form-control",
                          placeholder: __props.t.field_zipcode_placeholder,
                          onBlur: lookupCep
                        }, null, 40, ["onUpdate:modelValue", "placeholder"]), [
                          [vModelText, unref(form).zipcode]
                        ]),
                        createVNode("button", {
                          type: "button",
                          class: "btn btn-outline-secondary",
                          title: __props.t.btn_lookup_cep,
                          onClick: lookupCep
                        }, [
                          createVNode("i", { class: "ti ti-search" })
                        ], 8, ["title"])
                      ])
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_address), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).address = $event,
                        type: "text",
                        maxlength: "200",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).address]
                      ])
                    ]),
                    createVNode("div", { class: "col-2" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_number), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).number = $event,
                        type: "text",
                        maxlength: "10",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).number]
                      ])
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_complement), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).complement = $event,
                        type: "text",
                        maxlength: "100",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).complement]
                      ])
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_district), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).district = $event,
                        type: "text",
                        maxlength: "100",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).district]
                      ])
                    ]),
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_city_required), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).city = $event,
                        type: "text",
                        maxlength: "100",
                        class: ["form-control", { "is-invalid": unref(form).errors.city }]
                      }, null, 10, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).city]
                      ]),
                      unref(form).errors.city ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.city), 1)) : createCommentVNode("", true)
                    ]),
                    createVNode("div", { class: "col-2" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_state_required), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).state = $event,
                        type: "text",
                        maxlength: "2",
                        class: ["form-control text-uppercase", { "is-invalid": unref(form).errors.state }]
                      }, null, 10, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).state]
                      ]),
                      unref(form).errors.state ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.state), 1)) : createCommentVNode("", true)
                    ]),
                    createVNode("div", { class: "col-4" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_country_required), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).country = $event,
                        type: "text",
                        maxlength: "50",
                        class: ["form-control", { "is-invalid": unref(form).errors.country }]
                      }, null, 10, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).country]
                      ]),
                      unref(form).errors.country ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.country), 1)) : createCommentVNode("", true)
                    ])
                  ])
                ], 512), [
                  [vShow, activeTab.value === "endereco"]
                ]),
                withDirectives(createVNode("div", null, [
                  createVNode("div", { class: "row g-3" }, [
                    createVNode("div", { class: "col-6" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_schedule_interval), 1),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => unref(form).schedule_interval = $event,
                        class: "form-select"
                      }, [
                        createVNode("option", { value: 15 }, toDisplayString(__props.t.interval_15), 1),
                        createVNode("option", { value: 20 }, toDisplayString(__props.t.interval_20), 1),
                        createVNode("option", { value: 30 }, toDisplayString(__props.t.interval_30), 1)
                      ], 8, ["onUpdate:modelValue"]), [
                        [
                          vModelSelect,
                          unref(form).schedule_interval,
                          void 0,
                          { number: true }
                        ]
                      ])
                    ]),
                    isEdit.value ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "col-6"
                    }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_status), 1),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => unref(form).active = $event,
                        class: "form-select"
                      }, [
                        createVNode("option", { value: true }, toDisplayString(__props.t.status_option_active), 1),
                        createVNode("option", { value: false }, toDisplayString(__props.t.status_option_inactive), 1)
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, unref(form).active]
                      ])
                    ])) : createCommentVNode("", true)
                  ]),
                  !isEdit.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "alert alert-info small mt-3 py-2"
                  }, [
                    createVNode("i", { class: "ti ti-info-circle me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.config_info_after_create), 1)
                  ])) : createCommentVNode("", true)
                ], 512), [
                  [vShow, activeTab.value === "config"]
                ]),
                isEdit.value ? withDirectives((openBlock(), createBlock("div", { key: 0 }, [
                  createVNode("div", { class: "card border-0 bg-light" }, [
                    createVNode("div", { class: "card-body" }, [
                      createVNode("div", { class: "d-flex align-items-start gap-3 mb-3" }, [
                        createVNode("div", { class: "flex-shrink-0" }, [
                          createVNode("i", {
                            class: ["ti ti-shield-lock-filled fs-1", twoFactor.value.requires ? "text-success" : "text-muted"]
                          }, null, 2)
                        ]),
                        createVNode("div", { class: "flex-grow-1" }, [
                          createVNode("h6", { class: "fw-semibold mb-1" }, [
                            createTextVNode(toDisplayString(__props.t.form_2fa_label ?? "Exigir 2FA para todos os usuários") + " ", 1),
                            twoFactor.value.requires ? (openBlock(), createBlock("span", {
                              key: 0,
                              class: "badge badge-soft-success rounded text-success border border-success ms-1"
                            }, " Ativo ")) : (openBlock(), createBlock("span", {
                              key: 1,
                              class: "badge badge-soft-secondary rounded ms-1"
                            }, " Inativo "))
                          ]),
                          createVNode("p", { class: "text-muted small mb-2" }, toDisplayString(__props.t.form_2fa_hint), 1),
                          twoFactor.value.requires && twoFactor.value.enabled_at ? (openBlock(), createBlock("p", {
                            key: 0,
                            class: "small text-muted mb-0"
                          }, [
                            createVNode("i", { class: "ti ti-history me-1" }),
                            createTextVNode(" " + toDisplayString((_d = (_c = __props.t.form_2fa_enabled_at ?? "Ativado em :date por :user") == null ? void 0 : _c.replace(":date", twoFactor.value.enabled_at)) == null ? void 0 : _d.replace(":user", twoFactor.value.enabled_by ?? "—")), 1)
                          ])) : createCommentVNode("", true)
                        ])
                      ]),
                      createVNode("button", {
                        type: "button",
                        class: `btn btn-sm ${twoFactor.value.requires ? "btn-outline-danger" : "btn-primary"}`,
                        onClick: toggleTwoFactor
                      }, [
                        createVNode("i", {
                          class: `ti me-1 ${twoFactor.value.requires ? "ti-shield-off" : "ti-shield-check"}`
                        }, null, 2),
                        createTextVNode(" " + toDisplayString(twoFactor.value.requires ? __props.t.form_2fa_toggle_disable : __props.t.form_2fa_toggle_enable), 1)
                      ], 2)
                    ])
                  ])
                ], 512)), [
                  [vShow, activeTab.value === "security"]
                ]) : createCommentVNode("", true)
              ], 32),
              createVNode(_sfc_main$1, {
                open: unref(reasonModal).open,
                title: unref(reasonModal).title,
                message: unref(reasonModal).message,
                "confirm-variant": unref(reasonModal).confirmVariant,
                saving: unref(reasonModal).saving,
                onClose: unref(closeReasonModal),
                onConfirm: unref(handleReasonConfirm)
              }, null, 8, ["open", "title", "message", "confirm-variant", "saving", "onClose", "onConfirm"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Entities/EntityFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
