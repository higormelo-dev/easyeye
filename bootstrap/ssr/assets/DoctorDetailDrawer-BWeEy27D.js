import { ref, watch, mergeProps, withCtx, openBlock, createBlock, Fragment, createVNode, createTextVNode, toDisplayString, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderStyle, ssrRenderAttr, ssrRenderClass } from "vue/server-renderer";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "DoctorDetailDrawer",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    doctorId: { type: [String, Number], default: null }
  },
  emits: ["close"],
  setup(__props) {
    const props = __props;
    const loading = ref(false);
    const doctor = ref(null);
    async function loadDetail(id) {
      loading.value = true;
      doctor.value = null;
      try {
        const res = await fetch(route("panel.doctors.show", id), {
          headers: { Accept: "application/json" }
        });
        const json = await res.json();
        doctor.value = json.data;
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, (val) => {
      if (val && props.doctorId) loadDetail(props.doctorId);
      if (!val) doctor.value = null;
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
            _push2(`<div class="d-flex align-items-center gap-3 flex-grow-1 min-width-0" data-v-5180cb41${_scopeId}>`);
            if (doctor.value) {
              _push2(`<img${ssrRenderAttr("src", doctor.value.photo_url)}${ssrRenderAttr("alt", doctor.value.full_name)} class="rounded-circle flex-shrink-0" style="${ssrRenderStyle({ "width": "72px", "height": "72px", "object-fit": "cover", "border": "2px solid #dee2e6" })}" data-v-5180cb41${_scopeId}>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="min-width-0 flex-grow-1" data-v-5180cb41${_scopeId}><h5 class="mb-0 fw-semibold text-truncate" data-v-5180cb41${_scopeId}>${ssrInterpolate(((_a = doctor.value) == null ? void 0 : _a.full_name) ?? "Carregando...")}</h5>`);
            if (doctor.value) {
              _push2(`<div class="d-flex align-items-center gap-2 flex-wrap mt-1" data-v-5180cb41${_scopeId}><code class="text-muted small" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.code)}</code><span class="${ssrRenderClass([doctor.value.active ? "bg-success-subtle text-success border border-success" : "bg-danger-subtle text-danger border border-danger", "badge rounded-pill"])}" style="${ssrRenderStyle({ "font-size": ".7rem" })}" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.active ? "Ativo" : "Inativo")}</span>`);
              if (doctor.value.deleted_at) {
                _push2(`<span class="badge bg-secondary rounded-pill" style="${ssrRenderStyle({ "font-size": ".7rem" })}" data-v-5180cb41${_scopeId}>Excluído</span>`);
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
                doctor.value ? (openBlock(), createBlock("img", {
                  key: 0,
                  src: doctor.value.photo_url,
                  alt: doctor.value.full_name,
                  class: "rounded-circle flex-shrink-0",
                  style: { "width": "72px", "height": "72px", "object-fit": "cover", "border": "2px solid #dee2e6" }
                }, null, 8, ["src", "alt"])) : createCommentVNode("", true),
                createVNode("div", { class: "min-width-0 flex-grow-1" }, [
                  createVNode("h5", { class: "mb-0 fw-semibold text-truncate" }, toDisplayString(((_b = doctor.value) == null ? void 0 : _b.full_name) ?? "Carregando..."), 1),
                  doctor.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "d-flex align-items-center gap-2 flex-wrap mt-1"
                  }, [
                    createVNode("code", { class: "text-muted small" }, toDisplayString(doctor.value.code), 1),
                    createVNode("span", {
                      class: ["badge rounded-pill", doctor.value.active ? "bg-success-subtle text-success border border-success" : "bg-danger-subtle text-danger border border-danger"],
                      style: { "font-size": ".7rem" }
                    }, toDisplayString(doctor.value.active ? "Ativo" : "Inativo"), 3),
                    doctor.value.deleted_at ? (openBlock(), createBlock("span", {
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
            if (doctor.value) {
              _push2(`<!--[--><div class="detail-section" data-v-5180cb41${_scopeId}><div class="detail-section__title" data-v-5180cb41${_scopeId}><i class="ti ti-stethoscope me-1" data-v-5180cb41${_scopeId}></i> Profissional</div><div class="detail-table" data-v-5180cb41${_scopeId}><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Código</span><span class="detail-value" data-v-5180cb41${_scopeId}><code data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.code)}</code></span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>CRM</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.record ?? "—")}</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Especialidade</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.record_specialty ?? "—")}</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Cor</span><span class="detail-value d-flex align-items-center gap-2" data-v-5180cb41${_scopeId}>`);
              if (doctor.value.color) {
                _push2(`<span class="rounded-circle d-inline-block border" style="${ssrRenderStyle({ background: doctor.value.color, width: "16px", height: "16px" })}" data-v-5180cb41${_scopeId}></span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<span data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.color ?? "—")}</span></span></div>`);
              if (doctor.value.observation) {
                _push2(`<div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Observação</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.observation)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div><div class="detail-section" data-v-5180cb41${_scopeId}><div class="detail-section__title" data-v-5180cb41${_scopeId}><i class="ti ti-user me-1" data-v-5180cb41${_scopeId}></i> Dados Pessoais</div><div class="detail-table" data-v-5180cb41${_scopeId}><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Nome Completo</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.full_name)}</span></div>`);
              if (doctor.value.nickname) {
                _push2(`<div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Apelido</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.nickname)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>CPF</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.cpf ?? "—")}</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Nascimento</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.birth_date ?? "—")} `);
              if (doctor.value.age) {
                _push2(`<span class="text-muted small" data-v-5180cb41${_scopeId}>(${ssrInterpolate(doctor.value.age)})</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Gênero</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.gender || "—")}</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Estado Civil</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.marital_status || "—")}</span></div>`);
              if (doctor.value.mother_name) {
                _push2(`<div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Nome da Mãe</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.mother_name)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              if (doctor.value.father_name) {
                _push2(`<div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Nome do Pai</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.father_name)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div><div class="detail-section" data-v-5180cb41${_scopeId}><div class="detail-section__title" data-v-5180cb41${_scopeId}><i class="ti ti-file-description me-1" data-v-5180cb41${_scopeId}></i> Documentos</div><div class="detail-table" data-v-5180cb41${_scopeId}><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>RG</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.rg ?? "—")}</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Órgão Expedidor</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.rg_agency ?? "—")}</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>UF</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.rg_state ?? "—")}</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Data de Emissão</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.rg_date ?? "—")}</span></div></div></div><div class="detail-section" data-v-5180cb41${_scopeId}><div class="detail-section__title" data-v-5180cb41${_scopeId}><i class="ti ti-phone me-1" data-v-5180cb41${_scopeId}></i> Contato</div><div class="detail-table" data-v-5180cb41${_scopeId}><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>E-mail</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.email ?? "—")}</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Telefone</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.telephone ?? "—")}</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Celular</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.cellphone ?? "—")} `);
              if (doctor.value.whatsapp) {
                _push2(`<span class="badge bg-success-subtle text-success border border-success ms-1 rounded-pill" style="${ssrRenderStyle({ "font-size": ".65rem" })}" data-v-5180cb41${_scopeId}>WhatsApp</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span></div></div></div><div class="detail-section" data-v-5180cb41${_scopeId}><div class="detail-section__title" data-v-5180cb41${_scopeId}><i class="ti ti-map-pin me-1" data-v-5180cb41${_scopeId}></i> Endereço</div><div class="detail-table" data-v-5180cb41${_scopeId}><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>CEP</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.zipcode ?? "—")}</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Endereço</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.address ?? "—")} `);
              if (doctor.value.number) {
                _push2(`<!--[-->, ${ssrInterpolate(doctor.value.number)}<!--]-->`);
              } else {
                _push2(`<!---->`);
              }
              if (doctor.value.complement) {
                _push2(`<!--[--> — ${ssrInterpolate(doctor.value.complement)}<!--]-->`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Bairro</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.district ?? "—")}</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Cidade / UF</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.city ?? "—")}${ssrInterpolate(doctor.value.state ? ` / ${doctor.value.state}` : "")}</span></div></div></div><div class="detail-section" data-v-5180cb41${_scopeId}><div class="detail-section__title" data-v-5180cb41${_scopeId}><i class="ti ti-info-circle me-1" data-v-5180cb41${_scopeId}></i> Sistema</div><div class="detail-table" data-v-5180cb41${_scopeId}><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Parceiro</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.partner ? "Sim" : "Não")}</span></div><div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Criado em</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.created_at ?? "—")}</span></div>`);
              if (doctor.value.updated_at && doctor.value.updated_at !== doctor.value.created_at) {
                _push2(`<div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label" data-v-5180cb41${_scopeId}>Atualizado em</span><span class="detail-value" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.updated_at)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              if (doctor.value.deleted_at) {
                _push2(`<div class="detail-row" data-v-5180cb41${_scopeId}><span class="detail-label text-danger" data-v-5180cb41${_scopeId}>Excluído em</span><span class="detail-value text-danger" data-v-5180cb41${_scopeId}>${ssrInterpolate(doctor.value.deleted_at)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div></div><!--]-->`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              doctor.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                createVNode("div", { class: "detail-section" }, [
                  createVNode("div", { class: "detail-section__title" }, [
                    createVNode("i", { class: "ti ti-stethoscope me-1" }),
                    createTextVNode(" Profissional")
                  ]),
                  createVNode("div", { class: "detail-table" }, [
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Código"),
                      createVNode("span", { class: "detail-value" }, [
                        createVNode("code", null, toDisplayString(doctor.value.code), 1)
                      ])
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "CRM"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.record ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Especialidade"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.record_specialty ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Cor"),
                      createVNode("span", { class: "detail-value d-flex align-items-center gap-2" }, [
                        doctor.value.color ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "rounded-circle d-inline-block border",
                          style: { background: doctor.value.color, width: "16px", height: "16px" }
                        }, null, 4)) : createCommentVNode("", true),
                        createVNode("span", null, toDisplayString(doctor.value.color ?? "—"), 1)
                      ])
                    ]),
                    doctor.value.observation ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Observação"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.observation), 1)
                    ])) : createCommentVNode("", true)
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
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.full_name), 1)
                    ]),
                    doctor.value.nickname ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Apelido"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.nickname), 1)
                    ])) : createCommentVNode("", true),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "CPF"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.cpf ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Nascimento"),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(toDisplayString(doctor.value.birth_date ?? "—") + " ", 1),
                        doctor.value.age ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "text-muted small"
                        }, "(" + toDisplayString(doctor.value.age) + ")", 1)) : createCommentVNode("", true)
                      ])
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Gênero"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.gender || "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Estado Civil"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.marital_status || "—"), 1)
                    ]),
                    doctor.value.mother_name ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Nome da Mãe"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.mother_name), 1)
                    ])) : createCommentVNode("", true),
                    doctor.value.father_name ? (openBlock(), createBlock("div", {
                      key: 2,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Nome do Pai"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.father_name), 1)
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
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.rg ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Órgão Expedidor"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.rg_agency ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "UF"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.rg_state ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Data de Emissão"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.rg_date ?? "—"), 1)
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
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.email ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Telefone"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.telephone ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Celular"),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(toDisplayString(doctor.value.cellphone ?? "—") + " ", 1),
                        doctor.value.whatsapp ? (openBlock(), createBlock("span", {
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
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.zipcode ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Endereço"),
                      createVNode("span", { class: "detail-value" }, [
                        createTextVNode(toDisplayString(doctor.value.address ?? "—") + " ", 1),
                        doctor.value.number ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                          createTextVNode(", " + toDisplayString(doctor.value.number), 1)
                        ], 64)) : createCommentVNode("", true),
                        doctor.value.complement ? (openBlock(), createBlock(Fragment, { key: 1 }, [
                          createTextVNode(" — " + toDisplayString(doctor.value.complement), 1)
                        ], 64)) : createCommentVNode("", true)
                      ])
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Bairro"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.district ?? "—"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Cidade / UF"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.city ?? "—") + toDisplayString(doctor.value.state ? ` / ${doctor.value.state}` : ""), 1)
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
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.partner ? "Sim" : "Não"), 1)
                    ]),
                    createVNode("div", { class: "detail-row" }, [
                      createVNode("span", { class: "detail-label" }, "Criado em"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.created_at ?? "—"), 1)
                    ]),
                    doctor.value.updated_at && doctor.value.updated_at !== doctor.value.created_at ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label" }, "Atualizado em"),
                      createVNode("span", { class: "detail-value" }, toDisplayString(doctor.value.updated_at), 1)
                    ])) : createCommentVNode("", true),
                    doctor.value.deleted_at ? (openBlock(), createBlock("div", {
                      key: 1,
                      class: "detail-row"
                    }, [
                      createVNode("span", { class: "detail-label text-danger" }, "Excluído em"),
                      createVNode("span", { class: "detail-value text-danger" }, toDisplayString(doctor.value.deleted_at), 1)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Doctors/DoctorDetailDrawer.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const DoctorDetailDrawer = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-5180cb41"]]);
export {
  DoctorDetailDrawer as default
};
