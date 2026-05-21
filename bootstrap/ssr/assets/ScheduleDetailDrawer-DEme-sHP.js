import { ref, watch, mergeProps, withCtx, openBlock, createBlock, Fragment, createVNode, createTextVNode, toDisplayString, createCommentVNode, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderClass, ssrRenderAttr, ssrRenderStyle, ssrRenderList } from "vue/server-renderer";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "ScheduleDetailDrawer",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    scheduleId: { type: [String, Number], default: null },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close"],
  setup(__props) {
    const props = __props;
    const loading = ref(false);
    const schedule = ref(null);
    async function loadDetail(id) {
      loading.value = true;
      schedule.value = null;
      try {
        const res = await fetch(route("panel.schedules.show", id), {
          headers: { Accept: "application/json" }
        });
        const json = await res.json();
        schedule.value = json.data;
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, (val) => {
      if (val && props.scheduleId) loadDetail(props.scheduleId);
      if (!val) schedule.value = null;
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 640,
        loading: loading.value,
        "loading-label": __props.t.drawer_loading ?? "Carregando...",
        onClose: ($event) => _ctx.$emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b;
          if (_push2) {
            _push2(`<div class="d-flex align-items-center gap-3 flex-grow-1 min-width-0" data-v-913b2707${_scopeId}>`);
            if (schedule.value) {
              _push2(`<div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="${ssrRenderStyle([{ "width": "64px", "height": "64px" }, {
                background: (schedule.value.doctor_color ?? "#6c757d") + "22",
                border: `2px solid ${schedule.value.doctor_color ?? "#6c757d"}`
              }])}" data-v-913b2707${_scopeId}><i class="ti ti-calendar-event fs-3" style="${ssrRenderStyle({ color: schedule.value.doctor_color ?? "#6c757d" })}" data-v-913b2707${_scopeId}></i></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="min-width-0 flex-grow-1" data-v-913b2707${_scopeId}><h5 class="mb-0 fw-semibold text-truncate" data-v-913b2707${_scopeId}>${ssrInterpolate(((_a = schedule.value) == null ? void 0 : _a.patient_name) ?? (__props.t.drawer_loading ?? "Carregando..."))}</h5>`);
            if (schedule.value) {
              _push2(`<div class="d-flex align-items-center gap-2 flex-wrap mt-1" data-v-913b2707${_scopeId}><code class="text-muted small" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.code)}</code><span class="${ssrRenderClass([schedule.value.situation_badge, "badge rounded-pill"])}" style="${ssrRenderStyle({ "font-size": ".7rem" })}" data-v-913b2707${_scopeId}><i class="${ssrRenderClass([schedule.value.situation_icon, "fas me-1"])}" data-v-913b2707${_scopeId}></i>${ssrInterpolate(schedule.value.situation_label)}</span>`);
              if (!schedule.value.patient_is_registered) {
                _push2(`<span class="badge bg-secondary rounded-pill" style="${ssrRenderStyle({ "font-size": ".7rem" })}" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_unregistered ?? "Sem cadastro")}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div>`);
          } else {
            return [
              createVNode("div", { class: "d-flex align-items-center gap-3 flex-grow-1 min-width-0" }, [
                schedule.value ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "rounded-circle d-flex align-items-center justify-content-center flex-shrink-0",
                  style: [{ "width": "64px", "height": "64px" }, {
                    background: (schedule.value.doctor_color ?? "#6c757d") + "22",
                    border: `2px solid ${schedule.value.doctor_color ?? "#6c757d"}`
                  }]
                }, [
                  createVNode("i", {
                    class: "ti ti-calendar-event fs-3",
                    style: { color: schedule.value.doctor_color ?? "#6c757d" }
                  }, null, 4)
                ], 4)) : createCommentVNode("", true),
                createVNode("div", { class: "min-width-0 flex-grow-1" }, [
                  createVNode("h5", { class: "mb-0 fw-semibold text-truncate" }, toDisplayString(((_b = schedule.value) == null ? void 0 : _b.patient_name) ?? (__props.t.drawer_loading ?? "Carregando...")), 1),
                  schedule.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "d-flex align-items-center gap-2 flex-wrap mt-1"
                  }, [
                    createVNode("code", { class: "text-muted small" }, toDisplayString(schedule.value.code), 1),
                    createVNode("span", {
                      class: ["badge rounded-pill", schedule.value.situation_badge],
                      style: { "font-size": ".7rem" }
                    }, [
                      createVNode("i", {
                        class: ["fas me-1", schedule.value.situation_icon]
                      }, null, 2),
                      createTextVNode(toDisplayString(schedule.value.situation_label), 1)
                    ], 2),
                    !schedule.value.patient_is_registered ? (openBlock(), createBlock("span", {
                      key: 0,
                      class: "badge bg-secondary rounded-pill",
                      style: { "font-size": ".7rem" }
                    }, toDisplayString(__props.t.drawer_unregistered ?? "Sem cadastro"), 1)) : createCommentVNode("", true)
                  ])) : createCommentVNode("", true)
                ])
              ])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            if (schedule.value) {
              _push2(`<!--[--><div class="detail-section" data-v-913b2707${_scopeId}><div class="detail-section__title" data-v-913b2707${_scopeId}><i class="ti ti-id-badge me-1" data-v-913b2707${_scopeId}></i> ${ssrInterpolate(__props.t.drawer_section_id ?? "Identificação")}</div><div class="detail-table" data-v-913b2707${_scopeId}><div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_code ?? "Código")}</span><span class="detail-value" data-v-913b2707${_scopeId}><code data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.code)}</code></span></div><div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_datetime ?? "Data e hora")}</span><span class="detail-value" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.date_time)}</span></div><div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_situation ?? "Situação")}</span><span class="detail-value" data-v-913b2707${_scopeId}><span class="${ssrRenderClass([schedule.value.situation_badge, "badge"])}" data-v-913b2707${_scopeId}><i class="${ssrRenderClass([schedule.value.situation_icon, "fas me-1"])}" data-v-913b2707${_scopeId}></i>${ssrInterpolate(schedule.value.situation_label)}</span></span></div></div></div><div class="detail-section" data-v-913b2707${_scopeId}><div class="detail-section__title" data-v-913b2707${_scopeId}><i class="ti ti-user me-1" data-v-913b2707${_scopeId}></i> ${ssrInterpolate(__props.t.drawer_section_patient ?? "Paciente")}</div><div class="detail-table" data-v-913b2707${_scopeId}><div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_name ?? "Nome")}</span><span class="detail-value" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.patient_name ?? "—")} `);
              if (schedule.value.medical_records_url) {
                _push2(`<a${ssrRenderAttr("href", schedule.value.medical_records_url)} class="ms-2 small text-decoration-none"${ssrRenderAttr("title", schedule.value.patient_code)} data-v-913b2707${_scopeId}><i class="ti ti-stethoscope" data-v-913b2707${_scopeId}></i></a>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span></div>`);
              if (schedule.value.patient_code) {
                _push2(`<div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_code ?? "Código")}</span><span class="detail-value" data-v-913b2707${_scopeId}><code data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.patient_code)}</code></span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div><div class="detail-section" data-v-913b2707${_scopeId}><div class="detail-section__title" data-v-913b2707${_scopeId}><i class="ti ti-stethoscope me-1" data-v-913b2707${_scopeId}></i> ${ssrInterpolate(__props.t.drawer_section_doctor ?? "Médico")}</div><div class="detail-table" data-v-913b2707${_scopeId}><div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_name ?? "Nome")}</span><span class="detail-value d-flex align-items-center gap-2" data-v-913b2707${_scopeId}>`);
              if (schedule.value.doctor_color) {
                _push2(`<span class="rounded-circle d-inline-block" style="${ssrRenderStyle({ background: schedule.value.doctor_color, width: "10px", height: "10px" })}" data-v-913b2707${_scopeId}></span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<span data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.doctor_name ?? "—")}</span></span></div>`);
              if (schedule.value.doctor_code) {
                _push2(`<div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_code ?? "Código")}</span><span class="detail-value" data-v-913b2707${_scopeId}><code data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.doctor_code)}</code></span></div>`);
              } else {
                _push2(`<!---->`);
              }
              if (schedule.value.doctor_record) {
                _push2(`<div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_record ?? "CRM")}</span><span class="detail-value" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.doctor_record)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div><div class="detail-section" data-v-913b2707${_scopeId}><div class="detail-section__title" data-v-913b2707${_scopeId}><i class="ti ti-clipboard-list me-1" data-v-913b2707${_scopeId}></i> ${ssrInterpolate(__props.t.drawer_section_visit ?? "Atendimento")}</div><div class="detail-table" data-v-913b2707${_scopeId}><div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_visit ?? "Tipo de visita")}</span><span class="detail-value" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.visit_type_name ?? "—")}</span></div><div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_covenant ?? "Convênio")}</span><span class="detail-value" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.covenant_name ?? "—")}</span></div>`);
              if (schedule.value.arrived_at) {
                _push2(`<div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_arrived ?? "Chegou em")}</span><span class="detail-value" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.arrived_at)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              if (schedule.value.confirmed_at) {
                _push2(`<div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_confirmed ?? "Confirmado em")}</span><span class="detail-value" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.confirmed_at)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              if (schedule.value.notes) {
                _push2(`<div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.show_notes ?? "Observações")}</span><span class="detail-value text-prewrap" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.notes)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              if (schedule.value.cancellation_reason) {
                _push2(`<div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label text-danger" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.show_cancel_reason ?? "Motivo do cancelamento")}</span><span class="detail-value text-danger text-prewrap" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.cancellation_reason)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div>`);
              if (schedule.value.telephone || schedule.value.cellphone) {
                _push2(`<div class="detail-section" data-v-913b2707${_scopeId}><div class="detail-section__title" data-v-913b2707${_scopeId}><i class="ti ti-phone me-1" data-v-913b2707${_scopeId}></i> ${ssrInterpolate(__props.t.drawer_section_contact ?? "Contato")}</div><div class="detail-table" data-v-913b2707${_scopeId}>`);
                if (schedule.value.telephone) {
                  _push2(`<div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_telephone ?? "Telefone")}</span><span class="detail-value" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.telephone)}</span></div>`);
                } else {
                  _push2(`<!---->`);
                }
                if (schedule.value.cellphone) {
                  _push2(`<div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_cellphone ?? "Celular")}</span><span class="detail-value" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.cellphone)} `);
                  if (schedule.value.cellphone_whatsapp) {
                    _push2(`<span class="badge bg-success-subtle text-success border border-success ms-1 rounded-pill" style="${ssrRenderStyle({ "font-size": ".65rem" })}" data-v-913b2707${_scopeId}> WhatsApp </span>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  _push2(`</span></div>`);
                } else {
                  _push2(`<!---->`);
                }
                _push2(`</div></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="detail-section" data-v-913b2707${_scopeId}><div class="detail-section__title" data-v-913b2707${_scopeId}><i class="ti ti-package me-1" data-v-913b2707${_scopeId}></i> ${ssrInterpolate(__props.t.drawer_section_resources ?? "Recursos")}</div>`);
              if (schedule.value.resources.length === 0) {
                _push2(`<div class="text-muted small fst-italic" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_no_resources ?? "Nenhum recurso vinculado")}</div>`);
              } else {
                _push2(`<ul class="list-unstyled mb-0" data-v-913b2707${_scopeId}><!--[-->`);
                ssrRenderList(schedule.value.resources, (r) => {
                  _push2(`<li class="d-flex align-items-start gap-2 mb-2" data-v-913b2707${_scopeId}><i class="ti ti-circle-filled text-primary mt-1" style="${ssrRenderStyle({ "font-size": ".5rem" })}" data-v-913b2707${_scopeId}></i><div class="flex-grow-1" data-v-913b2707${_scopeId}><div class="fw-medium small" data-v-913b2707${_scopeId}>${ssrInterpolate(r.name)}</div>`);
                  if (r.type || r.code) {
                    _push2(`<div class="text-muted" style="${ssrRenderStyle({ "font-size": ".75rem" })}" data-v-913b2707${_scopeId}>`);
                    if (r.type) {
                      _push2(`<span data-v-913b2707${_scopeId}>${ssrInterpolate(r.type)}</span>`);
                    } else {
                      _push2(`<!---->`);
                    }
                    if (r.type && r.code) {
                      _push2(`<span data-v-913b2707${_scopeId}> · </span>`);
                    } else {
                      _push2(`<!---->`);
                    }
                    if (r.code) {
                      _push2(`<code data-v-913b2707${_scopeId}>${ssrInterpolate(r.code)}</code>`);
                    } else {
                      _push2(`<!---->`);
                    }
                    _push2(`</div>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  if (r.description) {
                    _push2(`<div class="text-muted small" data-v-913b2707${_scopeId}>${ssrInterpolate(r.description)}</div>`);
                  } else {
                    _push2(`<!---->`);
                  }
                  _push2(`</div></li>`);
                });
                _push2(`<!--]--></ul>`);
              }
              _push2(`</div><div class="detail-section" data-v-913b2707${_scopeId}><div class="detail-section__title" data-v-913b2707${_scopeId}><i class="ti ti-history me-1" data-v-913b2707${_scopeId}></i> ${ssrInterpolate(__props.t.show_history ?? "Histórico")}</div>`);
              if (schedule.value.situation_logs.length === 0) {
                _push2(`<div class="text-muted small fst-italic" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_no_history ?? "Nenhuma alteração de situação registrada")}</div>`);
              } else {
                _push2(`<div class="table-responsive" data-v-913b2707${_scopeId}><table class="table table-sm align-middle mb-0" data-v-913b2707${_scopeId}><thead class="table-light" data-v-913b2707${_scopeId}><tr data-v-913b2707${_scopeId}><th class="small" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.show_from ?? "De")}</th><th class="small" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.show_to ?? "Para")}</th><th class="small" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.show_by ?? "Por")}</th><th class="small text-nowrap" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.show_when ?? "Quando")}</th></tr></thead><tbody data-v-913b2707${_scopeId}><!--[-->`);
                ssrRenderList(schedule.value.situation_logs, (log) => {
                  _push2(`<tr data-v-913b2707${_scopeId}><td data-v-913b2707${_scopeId}>`);
                  if (log.from_label) {
                    _push2(`<span class="${ssrRenderClass([log.from_badge, "badge"])}" style="${ssrRenderStyle({ "font-size": ".65rem" })}" data-v-913b2707${_scopeId}>${ssrInterpolate(log.from_label)}</span>`);
                  } else {
                    _push2(`<span class="text-muted" data-v-913b2707${_scopeId}>—</span>`);
                  }
                  _push2(`</td><td data-v-913b2707${_scopeId}><span class="${ssrRenderClass([log.to_badge, "badge"])}" style="${ssrRenderStyle({ "font-size": ".65rem" })}" data-v-913b2707${_scopeId}>${ssrInterpolate(log.to_label)}</span></td><td class="small" data-v-913b2707${_scopeId}>${ssrInterpolate(log.user_name ?? "—")}</td><td class="small text-nowrap text-muted" data-v-913b2707${_scopeId}>${ssrInterpolate(log.created_at)}</td></tr>`);
                });
                _push2(`<!--]--></tbody></table></div>`);
              }
              _push2(`</div><div class="detail-section" data-v-913b2707${_scopeId}><div class="detail-section__title" data-v-913b2707${_scopeId}><i class="ti ti-info-circle me-1" data-v-913b2707${_scopeId}></i> ${ssrInterpolate(__props.t.drawer_section_system ?? "Sistema")}</div><div class="detail-table" data-v-913b2707${_scopeId}><div class="detail-row" data-v-913b2707${_scopeId}><span class="detail-label" data-v-913b2707${_scopeId}>${ssrInterpolate(__props.t.drawer_label_created ?? "Criado em")}</span><span class="detail-value" data-v-913b2707${_scopeId}>${ssrInterpolate(schedule.value.created_at ?? "—")}</span></div></div></div><!--]-->`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              schedule.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-id-badge me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.drawer_section_id ?? "Identificação"), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_code ?? "Código"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createVNode("code", null, toDisplayString(schedule.value.code), 1)
                      ])
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_datetime ?? "Data e hora"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(schedule.value.date_time), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_situation ?? "Situação"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createVNode("span", {
                          class: ["badge", schedule.value.situation_badge]
                        }, [
                          createVNode("i", {
                            class: ["fas me-1", schedule.value.situation_icon]
                          }, null, 2),
                          createTextVNode(toDisplayString(schedule.value.situation_label), 1)
                        ], 2)
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-user me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.drawer_section_patient ?? "Paciente"), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_name ?? "Nome"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(toDisplayString(schedule.value.patient_name ?? "—") + " ", 1),
                        schedule.value.medical_records_url ? (openBlock(), createBlock("a", {
                          key: 0,
                          href: schedule.value.medical_records_url,
                          class: "ms-2 small text-decoration-none",
                          title: schedule.value.patient_code
                        }, [
                          createVNode("i", { class: "ti ti-stethoscope" })
                        ], 8, ["href", "title"])) : createCommentVNode("", true)
                      ])
                    ]),
                    schedule.value.patient_code ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_code ?? "Código"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createVNode("code", null, toDisplayString(schedule.value.patient_code), 1)
                      ])
                    ])) : createCommentVNode("", true)
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-stethoscope me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.drawer_section_doctor ?? "Médico"), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_name ?? "Nome"), 1),
                      createVNode("span", { class: "detail-value d-flex align-items-center gap-2" }, [
                        schedule.value.doctor_color ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "rounded-circle d-inline-block",
                          style: { background: schedule.value.doctor_color, width: "10px", height: "10px" }
                        }, null, 4)) : createCommentVNode("", true),
                        createVNode("span", null, toDisplayString(schedule.value.doctor_name ?? "—"), 1)
                      ])
                    ]),
                    schedule.value.doctor_code ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_code ?? "Código"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createVNode("code", null, toDisplayString(schedule.value.doctor_code), 1)
                      ])
                    ])) : createCommentVNode("", true),
                    schedule.value.doctor_record ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_record ?? "CRM"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(schedule.value.doctor_record), 1)
                    ])) : createCommentVNode("", true)
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-clipboard-list me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.drawer_section_visit ?? "Atendimento"), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_visit ?? "Tipo de visita"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(schedule.value.visit_type_name ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_covenant ?? "Convênio"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(schedule.value.covenant_name ?? "—"), 1)
                    ]),
                    schedule.value.arrived_at ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_arrived ?? "Chegou em"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(schedule.value.arrived_at), 1)
                    ])) : createCommentVNode("", true),
                    schedule.value.confirmed_at ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_confirmed ?? "Confirmado em"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(schedule.value.confirmed_at), 1)
                    ])) : createCommentVNode("", true),
                    schedule.value.notes ? (openBlock(), createBlock("div", {
                      key: 2,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.show_notes ?? "Observações"), 1),
                      createVNode("span", { class: "detail-value text-prewrap" }, toDisplayString(schedule.value.notes), 1)
                    ])) : createCommentVNode("", true),
                    schedule.value.cancellation_reason ? (openBlock(), createBlock("div", {
                      key: 3,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label text-danger" }, toDisplayString(__props.t.show_cancel_reason ?? "Motivo do cancelamento"), 1),
                      createVNode("span", { class: "detail-value text-danger text-prewrap" }, toDisplayString(schedule.value.cancellation_reason), 1)
                    ])) : createCommentVNode("", true)
                  ])
                ]),
                schedule.value.telephone || schedule.value.cellphone ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "detail-section"
                }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-phone me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.drawer_section_contact ?? "Contato"), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    schedule.value.telephone ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_telephone ?? "Telefone"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(schedule.value.telephone), 1)
                    ])) : createCommentVNode("", true),
                    schedule.value.cellphone ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_cellphone ?? "Celular"), 1),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(toDisplayString(schedule.value.cellphone) + " ", 1),
                        schedule.value.cellphone_whatsapp ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "badge bg-success-subtle text-success border border-success ms-1 rounded-pill",
                          style: { "font-size": ".65rem" }
                        }, " WhatsApp ")) : createCommentVNode("", true)
                      ])
                    ])) : createCommentVNode("", true)
                  ])
                ])) : createCommentVNode("", true),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-package me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.drawer_section_resources ?? "Recursos"), 1)
                  ]),
                  schedule.value.resources.length === 0 ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "text-muted small fst-italic"
                  }, toDisplayString(__props.t.drawer_no_resources ?? "Nenhum recurso vinculado"), 1)) : (openBlock(), createBlock("ul", {
                    key: 1,
                    class: "list-unstyled mb-0"
                  }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(schedule.value.resources, (r) => {
                      return openBlock(), createBlock("li", {
                        key: r.id,
                        class: "d-flex align-items-start gap-2 mb-2"
                      }, [
                        createVNode("i", {
                          class: "ti ti-circle-filled text-primary mt-1",
                          style: { "font-size": ".5rem" }
                        }),
                        createVNode("div", { class: "flex-grow-1" }, [
                          createVNode("div", { class: "fw-medium small" }, toDisplayString(r.name), 1),
                          r.type || r.code ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "text-muted",
                            style: { "font-size": ".75rem" }
                          }, [
                            r.type ? (openBlock(), createBlock("span", { key: 0 }, toDisplayString(r.type), 1)) : createCommentVNode("", true),
                            r.type && r.code ? (openBlock(), createBlock("span", { key: 1 }, " · ")) : createCommentVNode("", true),
                            r.code ? (openBlock(), createBlock("code", { key: 2 }, toDisplayString(r.code), 1)) : createCommentVNode("", true)
                          ])) : createCommentVNode("", true),
                          r.description ? (openBlock(), createBlock("div", {
                            key: 1,
                            class: "text-muted small"
                          }, toDisplayString(r.description), 1)) : createCommentVNode("", true)
                        ])
                      ]);
                    }), 128))
                  ]))
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-history me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.show_history ?? "Histórico"), 1)
                  ]),
                  schedule.value.situation_logs.length === 0 ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "text-muted small fst-italic"
                  }, toDisplayString(__props.t.drawer_no_history ?? "Nenhuma alteração de situação registrada"), 1)) : (openBlock(), createBlock("div", {
                    key: 1,
                    class: "table-responsive"
                  }, [
                    createVNode("table", { class: "table table-sm align-middle mb-0" }, [
                      createVNode("thead", { class: "table-light" }, [
                        createVNode("tr", null, [
                          createVNode("th", { class: "small" }, toDisplayString(__props.t.show_from ?? "De"), 1),
                          createVNode("th", { class: "small" }, toDisplayString(__props.t.show_to ?? "Para"), 1),
                          createVNode("th", { class: "small" }, toDisplayString(__props.t.show_by ?? "Por"), 1),
                          createVNode("th", { class: "small text-nowrap" }, toDisplayString(__props.t.show_when ?? "Quando"), 1)
                        ])
                      ]),
                      createVNode("tbody", null, [
                        (openBlock(true), createBlock(Fragment, null, renderList(schedule.value.situation_logs, (log) => {
                          return openBlock(), createBlock("tr", {
                            key: log.id
                          }, [
                            createVNode("td", null, [
                              log.from_label ? (openBlock(), createBlock("span", {
                                key: 0,
                                class: ["badge", log.from_badge],
                                style: { "font-size": ".65rem" }
                              }, toDisplayString(log.from_label), 3)) : (openBlock(), createBlock("span", {
                                key: 1,
                                class: "text-muted"
                              }, "—"))
                            ]),
                            createVNode("td", null, [
                              createVNode("span", {
                                class: ["badge", log.to_badge],
                                style: { "font-size": ".65rem" }
                              }, toDisplayString(log.to_label), 3)
                            ]),
                            createVNode("td", { class: "small" }, toDisplayString(log.user_name ?? "—"), 1),
                            createVNode("td", { class: "small text-nowrap text-muted" }, toDisplayString(log.created_at), 1)
                          ]);
                        }), 128))
                      ])
                    ])
                  ]))
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-info-circle me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.drawer_section_system ?? "Sistema"), 1)
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, toDisplayString(__props.t.drawer_label_created ?? "Criado em"), 1),
                      createVNode("span", { class: "detail-value" }, toDisplayString(schedule.value.created_at ?? "—"), 1)
                    ])
                  ])
                ])
              ], 64)) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Schedules/ScheduleDetailDrawer.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const ScheduleDetailDrawer = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-913b2707"]]);
export {
  ScheduleDetailDrawer as default
};
