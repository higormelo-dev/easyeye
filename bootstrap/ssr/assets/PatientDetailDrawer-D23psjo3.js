import { ref, watch, mergeProps, withCtx, openBlock, createBlock, Fragment, createVNode, createTextVNode, toDisplayString, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderStyle, ssrRenderAttr, ssrRenderClass } from "vue/server-renderer";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "PatientDetailDrawer",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    patientId: { type: [String, Number], default: null }
  },
  emits: ["close"],
  setup(__props) {
    const props = __props;
    const loading = ref(false);
    const patient = ref(null);
    async function loadDetail(id) {
      loading.value = true;
      patient.value = null;
      try {
        const res = await fetch(route("panel.patients.show", id), {
          headers: { Accept: "application/json" }
        });
        const json = await res.json();
        patient.value = json.data;
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, (val) => {
      if (val && props.patientId) loadDetail(props.patientId);
      if (!val) patient.value = null;
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 640,
        loading: loading.value,
        "loading-label": "Carregando...",
        onClose: ($event) => _ctx.$emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b;
          if (_push2) {
            _push2(`<div class="d-flex align-items-center gap-3 flex-grow-1 min-width-0" data-v-c4447036${_scopeId}>`);
            if (patient.value) {
              _push2(`<img${ssrRenderAttr("src", patient.value.photo_url)}${ssrRenderAttr("alt", patient.value.full_name)} class="rounded-circle flex-shrink-0" style="${ssrRenderStyle({ "width": "72px", "height": "72px", "object-fit": "cover", "border": "2px solid #dee2e6" })}" data-v-c4447036${_scopeId}>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="min-width-0 flex-grow-1" data-v-c4447036${_scopeId}><h5 class="mb-0 fw-semibold text-truncate" data-v-c4447036${_scopeId}>${ssrInterpolate(((_a = patient.value) == null ? void 0 : _a.full_name) ?? "Carregando...")}</h5>`);
            if (patient.value) {
              _push2(`<div class="d-flex align-items-center gap-2 flex-wrap mt-1" data-v-c4447036${_scopeId}><code class="text-muted small" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.code)}</code><span class="${ssrRenderClass([patient.value.active ? "bg-success-subtle text-success border border-success" : "bg-danger-subtle text-danger border border-danger", "badge rounded-pill"])}" style="${ssrRenderStyle({ "font-size": ".7rem" })}" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.active ? "Ativo" : "Inativo")}</span>`);
              if (patient.value.deleted_at) {
                _push2(`<span class="badge bg-secondary rounded-pill" style="${ssrRenderStyle({ "font-size": ".7rem" })}" data-v-c4447036${_scopeId}>Excluído</span>`);
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
                patient.value ? (openBlock(), createBlock("img", {
                  key: 0,
                  src: patient.value.photo_url,
                  alt: patient.value.full_name,
                  class: "rounded-circle flex-shrink-0",
                  style: { "width": "72px", "height": "72px", "object-fit": "cover", "border": "2px solid #dee2e6" }
                }, null, 8, ["src", "alt"])) : createCommentVNode("", true),
                createVNode("div", { class: "min-width-0 flex-grow-1" }, [
                  createVNode("h5", { class: "mb-0 fw-semibold text-truncate" }, toDisplayString(((_b = patient.value) == null ? void 0 : _b.full_name) ?? "Carregando..."), 1),
                  patient.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "d-flex align-items-center gap-2 flex-wrap mt-1"
                  }, [
                    createVNode("code", { class: "text-muted small" }, toDisplayString(patient.value.code), 1),
                    createVNode("span", {
                      class: ["badge rounded-pill", patient.value.active ? "bg-success-subtle text-success border border-success" : "bg-danger-subtle text-danger border border-danger"],
                      style: { "font-size": ".7rem" }
                    }, toDisplayString(patient.value.active ? "Ativo" : "Inativo"), 3),
                    patient.value.deleted_at ? (openBlock(), createBlock("span", {
                      key: 0,
                      class: "badge bg-secondary rounded-pill",
                      style: { "font-size": ".7rem" }
                    }, "Excluído")) : createCommentVNode("", true)
                  ])) : createCommentVNode("", true)
                ])
              ])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            if (patient.value) {
              _push2(`<!--[--><div class="detail-section" data-v-c4447036${_scopeId}><div class="detail-section__title" data-v-c4447036${_scopeId}><i class="ti ti-id-badge me-1" data-v-c4447036${_scopeId}></i> Identificação</div><div class="detail-table" data-v-c4447036${_scopeId}><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Código</span><span class="detail-value" data-v-c4447036${_scopeId}><code data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.code)}</code></span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Convênio</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.covenant ?? "—")}</span></div>`);
              if (patient.value.card_number) {
                _push2(`<div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Nº Cartão</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.card_number)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Tipo de Pele</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.skin_type ?? "—")}</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Tipo de Íris</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.iris_type ?? "—")}</span></div></div></div><div class="detail-section" data-v-c4447036${_scopeId}><div class="detail-section__title" data-v-c4447036${_scopeId}><i class="ti ti-user me-1" data-v-c4447036${_scopeId}></i> Dados Pessoais</div><div class="detail-table" data-v-c4447036${_scopeId}><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Nome Completo</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.full_name)}</span></div>`);
              if (patient.value.nickname) {
                _push2(`<div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Apelido</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.nickname)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>CPF</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.cpf ?? "—")}</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Nascimento</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.birth_date ?? "—")} `);
              if (patient.value.age) {
                _push2(`<span class="text-muted small" data-v-c4447036${_scopeId}>(${ssrInterpolate(patient.value.age)})</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Gênero</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.gender || "—")}</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Estado Civil</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.marital_status || "—")}</span></div>`);
              if (patient.value.mother_name) {
                _push2(`<div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Nome da Mãe</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.mother_name)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              if (patient.value.father_name) {
                _push2(`<div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Nome do Pai</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.father_name)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div><div class="detail-section" data-v-c4447036${_scopeId}><div class="detail-section__title" data-v-c4447036${_scopeId}><i class="ti ti-file-description me-1" data-v-c4447036${_scopeId}></i> Documentos</div><div class="detail-table" data-v-c4447036${_scopeId}><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>RG</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.rg ?? "—")}</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Órgão Expedidor</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.rg_agency ?? "—")}</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>UF</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.rg_state ?? "—")}</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Data de Emissão</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.rg_date ?? "—")}</span></div></div></div><div class="detail-section" data-v-c4447036${_scopeId}><div class="detail-section__title" data-v-c4447036${_scopeId}><i class="ti ti-phone me-1" data-v-c4447036${_scopeId}></i> Contato</div><div class="detail-table" data-v-c4447036${_scopeId}><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>E-mail</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.email ?? "—")}</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Telefone</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.telephone ?? "—")}</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Celular</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.cellphone ?? "—")} `);
              if (patient.value.whatsapp) {
                _push2(`<span class="badge bg-success-subtle text-success border border-success ms-1 rounded-pill" style="${ssrRenderStyle({ "font-size": ".65rem" })}" data-v-c4447036${_scopeId}>WhatsApp</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span></div></div></div><div class="detail-section" data-v-c4447036${_scopeId}><div class="detail-section__title" data-v-c4447036${_scopeId}><i class="ti ti-map-pin me-1" data-v-c4447036${_scopeId}></i> Endereço</div><div class="detail-table" data-v-c4447036${_scopeId}><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>CEP</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.zipcode ?? "—")}</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Endereço</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.address ?? "—")} `);
              if (patient.value.number) {
                _push2(`<!--[-->, ${ssrInterpolate(patient.value.number)}<!--]-->`);
              } else {
                _push2(`<!---->`);
              }
              if (patient.value.complement) {
                _push2(`<!--[--> — ${ssrInterpolate(patient.value.complement)}<!--]-->`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Bairro</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.district ?? "—")}</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Cidade / UF</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.city ?? "—")}${ssrInterpolate(patient.value.state ? ` / ${patient.value.state}` : "")}</span></div></div></div><div class="detail-section" data-v-c4447036${_scopeId}><div class="detail-section__title" data-v-c4447036${_scopeId}><i class="ti ti-info-circle me-1" data-v-c4447036${_scopeId}></i> Sistema</div><div class="detail-table" data-v-c4447036${_scopeId}><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Parceiro</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.partner ? "Sim" : "Não")}</span></div><div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Criado em</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.created_at ?? "—")}</span></div>`);
              if (patient.value.updated_at && patient.value.updated_at !== patient.value.created_at) {
                _push2(`<div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label" data-v-c4447036${_scopeId}>Atualizado em</span><span class="detail-value" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.updated_at)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              if (patient.value.deleted_at) {
                _push2(`<div class="detail-row" data-v-c4447036${_scopeId}><span class="detail-label text-danger" data-v-c4447036${_scopeId}>Excluído em</span><span class="detail-value text-danger" data-v-c4447036${_scopeId}>${ssrInterpolate(patient.value.deleted_at)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div><!--]-->`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              patient.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-id-badge me-1" }),
                    createTextVNode(" Identificação")
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Código"),
                      createVNode("span", { class: "detail-value" }, [
                        createVNode("code", null, toDisplayString(patient.value.code), 1)
                      ])
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Convênio"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.covenant ?? "—"), 1)
                    ]),
                    patient.value.card_number ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Nº Cartão"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.card_number), 1)
                    ])) : createCommentVNode("", true),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Tipo de Pele"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.skin_type ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Tipo de Íris"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.iris_type ?? "—"), 1)
                    ])
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-user me-1" }),
                    createTextVNode(" Dados Pessoais")
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Nome Completo"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.full_name), 1)
                    ]),
                    patient.value.nickname ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Apelido"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.nickname), 1)
                    ])) : createCommentVNode("", true),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "CPF"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.cpf ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Nascimento"),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(toDisplayString(patient.value.birth_date ?? "—") + " ", 1),
                        patient.value.age ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "text-muted small"
                        }, "(" + toDisplayString(patient.value.age) + ")", 1)) : createCommentVNode("", true)
                      ])
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Gênero"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.gender || "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Estado Civil"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.marital_status || "—"), 1)
                    ]),
                    patient.value.mother_name ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Nome da Mãe"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.mother_name), 1)
                    ])) : createCommentVNode("", true),
                    patient.value.father_name ? (openBlock(), createBlock("div", {
                      key: 2,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Nome do Pai"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.father_name), 1)
                    ])) : createCommentVNode("", true)
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-file-description me-1" }),
                    createTextVNode(" Documentos")
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "RG"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.rg ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Órgão Expedidor"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.rg_agency ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "UF"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.rg_state ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Data de Emissão"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.rg_date ?? "—"), 1)
                    ])
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-phone me-1" }),
                    createTextVNode(" Contato")
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "E-mail"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.email ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Telefone"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.telephone ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Celular"),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(toDisplayString(patient.value.cellphone ?? "—") + " ", 1),
                        patient.value.whatsapp ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "badge bg-success-subtle text-success border border-success ms-1 rounded-pill",
                          style: { "font-size": ".65rem" }
                        }, "WhatsApp")) : createCommentVNode("", true)
                      ])
                    ])
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-map-pin me-1" }),
                    createTextVNode(" Endereço")
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "CEP"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.zipcode ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Endereço"),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(toDisplayString(patient.value.address ?? "—") + " ", 1),
                        patient.value.number ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                          createTextVNode(", " + toDisplayString(patient.value.number), 1)
                        ], 64)) : createCommentVNode("", true),
                        patient.value.complement ? (openBlock(), createBlock(Fragment, { key: 1 }, [
                          createTextVNode(" — " + toDisplayString(patient.value.complement), 1)
                        ], 64)) : createCommentVNode("", true)
                      ])
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Bairro"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.district ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Cidade / UF"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.city ?? "—") + toDisplayString(patient.value.state ? ` / ${patient.value.state}` : ""), 1)
                    ])
                  ])
                ]),
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-info-circle me-1" }),
                    createTextVNode(" Sistema")
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Parceiro"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.partner ? "Sim" : "Não"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Criado em"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.created_at ?? "—"), 1)
                    ]),
                    patient.value.updated_at && patient.value.updated_at !== patient.value.created_at ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Atualizado em"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(patient.value.updated_at), 1)
                    ])) : createCommentVNode("", true),
                    patient.value.deleted_at ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label text-danger" }, "Excluído em"),
                      createVNode("span", { class: "detail-value text-danger" }, toDisplayString(patient.value.deleted_at), 1)
                    ])) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Patients/PatientDetailDrawer.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const PatientDetailDrawer = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-c4447036"]]);
export {
  PatientDetailDrawer as default
};
