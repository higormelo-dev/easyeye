import { computed, ref, mergeProps, withCtx, unref, createTextVNode, createVNode, toDisplayString, openBlock, createBlock, createCommentVNode, withModifiers, withDirectives, vModelText, vModelDynamic, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderAttr, ssrRenderStyle, ssrInterpolate, ssrRenderClass, ssrIncludeBooleanAttr, ssrRenderDynamicModel } from "vue/server-renderer";
import { usePage, useForm, Link } from "@inertiajs/vue3";
import { A as AppLayout } from "./AppLayout-CkzITmof.js";
import { _ as _sfc_main$1 } from "./PageHeader-CYjDf0Y-.js";
import "./logo-small-Br31EOC_.js";
import "./logo-white-hVd1h5De.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "Edit",
  __ssrInlineRender: true,
  props: {
    breadcrumbs: { type: Array, default: () => [] },
    user: { type: Object, required: true },
    urls: { type: Object, required: true }
  },
  setup(__props) {
    const props = __props;
    const page = usePage();
    const flashStatus = computed(() => {
      var _a, _b;
      return (_b = (_a = page.props) == null ? void 0 : _a.flash) == null ? void 0 : _b.status;
    });
    const profileForm = useForm({
      _method: "PATCH",
      name: props.user.name,
      email: props.user.email,
      photo: null
    });
    const photoPreview = ref(props.user.photo_url);
    function handlePhotoChange(e) {
      const file = e.target.files[0];
      if (file) {
        profileForm.photo = file;
        photoPreview.value = URL.createObjectURL(file);
      }
    }
    function submitProfile() {
      profileForm.post(props.urls.update, {
        forceFormData: true,
        preserveScroll: true
      });
    }
    const passwordForm = useForm({
      _method: "PUT",
      current_password: "",
      password: "",
      password_confirmation: ""
    });
    const showCurrent = ref(false);
    const showNew = ref(false);
    const showConfirm = ref(false);
    function submitPassword() {
      passwordForm.post(props.urls.password_update, {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset()
      });
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(AppLayout, mergeProps({
        title: "Meu perfil",
        breadcrumbs: __props.breadcrumbs
      }, _attrs), {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="container-fluid py-3"${_scopeId}>`);
            _push2(ssrRenderComponent(_sfc_main$1, { title: "Meu perfil" }, null, _parent2, _scopeId));
            if (flashStatus.value === "profile-updated") {
              _push2(`<div class="alert alert-success alert-dismissible fade show mb-3"${_scopeId}><i class="ti ti-circle-check me-2"${_scopeId}></i>Perfil salvo com sucesso. <button type="button" class="btn-close" data-bs-dismiss="alert"${_scopeId}></button></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (flashStatus.value === "password-updated") {
              _push2(`<div class="alert alert-success alert-dismissible fade show mb-3"${_scopeId}><i class="ti ti-circle-check me-2"${_scopeId}></i>Senha alterada com sucesso. <button type="button" class="btn-close" data-bs-dismiss="alert"${_scopeId}></button></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="card"${_scopeId}><div class="card-body"${_scopeId}><form class="border-bottom mb-4 pb-3"${_scopeId}><div class="row align-items-center mb-4"${_scopeId}><div class="col-lg-2"${_scopeId}><label class="form-label mb-0 fw-medium"${_scopeId}>Foto</label><p class="text-muted small mb-0 mt-1"${_scopeId}>JPG, PNG ou WebP, máx 2MB.</p></div><div class="col-lg-10"${_scopeId}><div class="d-flex align-items-center gap-3"${_scopeId}><img${ssrRenderAttr("src", photoPreview.value)}${ssrRenderAttr("alt", __props.user.name)} class="rounded-circle border" style="${ssrRenderStyle({ "width": "80px", "height": "80px", "object-fit": "cover" })}"${_scopeId}><label class="btn btn-outline-secondary btn-sm mb-0"${_scopeId}><i class="ti ti-photo me-1"${_scopeId}></i>Alterar foto <input type="file" accept="image/jpeg,image/png,image/webp" class="d-none"${_scopeId}></label></div>`);
            if (unref(profileForm).errors.photo) {
              _push2(`<div class="text-danger small mt-1"${_scopeId}>${ssrInterpolate(unref(profileForm).errors.photo)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div><div class="row"${_scopeId}><div class="col-md-6 mb-3"${_scopeId}><label for="profile_name" class="form-label"${_scopeId}>Nome <span class="text-danger"${_scopeId}>*</span></label><input id="profile_name"${ssrRenderAttr("value", unref(profileForm).name)} type="text" class="${ssrRenderClass([{ "is-invalid": unref(profileForm).errors.name }, "form-control"])}" required autocomplete="name"${_scopeId}>`);
            if (unref(profileForm).errors.name) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(profileForm).errors.name)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-md-6 mb-3"${_scopeId}><label for="profile_email" class="form-label"${_scopeId}>E-mail <span class="text-danger"${_scopeId}>*</span></label><input id="profile_email"${ssrRenderAttr("value", unref(profileForm).email)} type="email" class="${ssrRenderClass([{ "is-invalid": unref(profileForm).errors.email }, "form-control"])}" required autocomplete="username"${_scopeId}>`);
            if (unref(profileForm).errors.email) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(profileForm).errors.email)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            if (__props.user.must_verify_email && !__props.user.email_verified) {
              _push2(`<div class="mt-2"${_scopeId}><span class="badge bg-warning text-dark me-1"${_scopeId}><i class="ti ti-alert-circle me-1"${_scopeId}></i>E-mail não verificado </span>`);
              _push2(ssrRenderComponent(unref(Link), {
                href: __props.urls.send_verification,
                method: "post",
                as: "button",
                class: "btn btn-link btn-sm p-0 align-baseline"
              }, {
                default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                  if (_push3) {
                    _push3(` Reenviar verificação `);
                  } else {
                    return [
                      createTextVNode(" Reenviar verificação ")
                    ];
                  }
                }),
                _: 1
              }, _parent2, _scopeId));
              _push2(`</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div><div class="d-flex justify-content-end gap-2"${_scopeId}>`);
            _push2(ssrRenderComponent(unref(Link), {
              href: __props.urls.dashboard,
              class: "btn btn-light"
            }, {
              default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`Cancelar`);
                } else {
                  return [
                    createTextVNode("Cancelar")
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`<button type="submit" class="btn btn-primary"${ssrIncludeBooleanAttr(unref(profileForm).processing) ? " disabled" : ""}${_scopeId}>`);
            if (unref(profileForm).processing) {
              _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
            } else {
              _push2(`<i class="ti ti-device-floppy me-1"${_scopeId}></i>`);
            }
            _push2(`Salvar perfil </button></div></form><form class="border-bottom mb-4 pb-3"${_scopeId}><h5 class="fw-bold mb-3"${_scopeId}>Alterar senha</h5><div class="row"${_scopeId}><div class="col-md-4 mb-3"${_scopeId}><label class="form-label"${_scopeId}>Senha atual</label><div class="input-group"${_scopeId}><input${ssrRenderDynamicModel(showCurrent.value ? "text" : "password", unref(passwordForm).current_password, null)}${ssrRenderAttr("type", showCurrent.value ? "text" : "password")} class="${ssrRenderClass([{ "is-invalid": unref(passwordForm).errors.current_password }, "form-control"])}" autocomplete="current-password"${_scopeId}><button type="button" class="btn btn-outline-secondary" tabindex="-1"${_scopeId}><i class="${ssrRenderClass(showCurrent.value ? "ti ti-eye-off" : "ti ti-eye")}"${_scopeId}></i></button></div>`);
            if (unref(passwordForm).errors.current_password) {
              _push2(`<div class="text-danger small"${_scopeId}>${ssrInterpolate(unref(passwordForm).errors.current_password)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-md-4 mb-3"${_scopeId}><label class="form-label"${_scopeId}>Nova senha</label><div class="input-group"${_scopeId}><input${ssrRenderDynamicModel(showNew.value ? "text" : "password", unref(passwordForm).password, null)}${ssrRenderAttr("type", showNew.value ? "text" : "password")} class="${ssrRenderClass([{ "is-invalid": unref(passwordForm).errors.password }, "form-control"])}" autocomplete="new-password"${_scopeId}><button type="button" class="btn btn-outline-secondary" tabindex="-1"${_scopeId}><i class="${ssrRenderClass(showNew.value ? "ti ti-eye-off" : "ti ti-eye")}"${_scopeId}></i></button></div>`);
            if (unref(passwordForm).errors.password) {
              _push2(`<div class="text-danger small"${_scopeId}>${ssrInterpolate(unref(passwordForm).errors.password)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-md-4 mb-3"${_scopeId}><label class="form-label"${_scopeId}>Confirmar nova senha</label><div class="input-group"${_scopeId}><input${ssrRenderDynamicModel(showConfirm.value ? "text" : "password", unref(passwordForm).password_confirmation, null)}${ssrRenderAttr("type", showConfirm.value ? "text" : "password")} class="form-control" autocomplete="new-password"${_scopeId}><button type="button" class="btn btn-outline-secondary" tabindex="-1"${_scopeId}><i class="${ssrRenderClass(showConfirm.value ? "ti ti-eye-off" : "ti ti-eye")}"${_scopeId}></i></button></div></div></div><div class="d-flex justify-content-end"${_scopeId}><button type="submit" class="btn btn-outline-primary btn-sm"${ssrIncludeBooleanAttr(unref(passwordForm).processing) ? " disabled" : ""}${_scopeId}>`);
            if (unref(passwordForm).processing) {
              _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
            } else {
              _push2(`<i class="ti ti-lock me-1"${_scopeId}></i>`);
            }
            _push2(`Atualizar senha </button></div></form><div class="pb-2"${_scopeId}><h5 class="fw-bold mb-3"${_scopeId}>Autenticação em dois fatores</h5><div class="d-flex align-items-center gap-3"${_scopeId}>`);
            if (__props.user.has_two_factor_enabled) {
              _push2(`<span class="badge badge-soft-success rounded text-success border border-success"${_scopeId}><i class="ti ti-shield-check me-1"${_scopeId}></i>Ativo </span>`);
            } else {
              _push2(`<span class="badge badge-soft-secondary rounded"${_scopeId}><i class="ti ti-shield-off me-1"${_scopeId}></i>Inativo </span>`);
            }
            _push2(ssrRenderComponent(unref(Link), {
              href: __props.urls.two_factor_setup,
              class: "btn btn-sm btn-outline-primary"
            }, {
              default: withCtx((_2, _push3, _parent3, _scopeId2) => {
                if (_push3) {
                  _push3(`<i class="ti ti-settings me-1"${_scopeId2}></i>${ssrInterpolate(__props.user.has_two_factor_enabled ? "Gerenciar" : "Configurar")}`);
                } else {
                  return [
                    createVNode("i", { class: "ti ti-settings me-1" }),
                    createTextVNode(toDisplayString(__props.user.has_two_factor_enabled ? "Gerenciar" : "Configurar"), 1)
                  ];
                }
              }),
              _: 1
            }, _parent2, _scopeId));
            _push2(`</div></div></div></div></div>`);
          } else {
            return [
              createVNode("div", { class: "container-fluid py-3" }, [
                createVNode(_sfc_main$1, { title: "Meu perfil" }),
                flashStatus.value === "profile-updated" ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "alert alert-success alert-dismissible fade show mb-3"
                }, [
                  createVNode("i", { class: "ti ti-circle-check me-2" }),
                  createTextVNode("Perfil salvo com sucesso. "),
                  createVNode("button", {
                    type: "button",
                    class: "btn-close",
                    "data-bs-dismiss": "alert"
                  })
                ])) : createCommentVNode("", true),
                flashStatus.value === "password-updated" ? (openBlock(), createBlock("div", {
                  key: 1,
                  class: "alert alert-success alert-dismissible fade show mb-3"
                }, [
                  createVNode("i", { class: "ti ti-circle-check me-2" }),
                  createTextVNode("Senha alterada com sucesso. "),
                  createVNode("button", {
                    type: "button",
                    class: "btn-close",
                    "data-bs-dismiss": "alert"
                  })
                ])) : createCommentVNode("", true),
                createVNode("div", { class: "card" }, [
                  createVNode("div", { class: "card-body" }, [
                    createVNode("form", {
                      onSubmit: withModifiers(submitProfile, ["prevent"]),
                      class: "border-bottom mb-4 pb-3"
                    }, [
                      createVNode("div", { class: "row align-items-center mb-4" }, [
                        createVNode("div", { class: "col-lg-2" }, [
                          createVNode("label", { class: "form-label mb-0 fw-medium" }, "Foto"),
                          createVNode("p", { class: "text-muted small mb-0 mt-1" }, "JPG, PNG ou WebP, máx 2MB.")
                        ]),
                        createVNode("div", { class: "col-lg-10" }, [
                          createVNode("div", { class: "d-flex align-items-center gap-3" }, [
                            createVNode("img", {
                              src: photoPreview.value,
                              alt: __props.user.name,
                              class: "rounded-circle border",
                              style: { "width": "80px", "height": "80px", "object-fit": "cover" }
                            }, null, 8, ["src", "alt"]),
                            createVNode("label", { class: "btn btn-outline-secondary btn-sm mb-0" }, [
                              createVNode("i", { class: "ti ti-photo me-1" }),
                              createTextVNode("Alterar foto "),
                              createVNode("input", {
                                type: "file",
                                accept: "image/jpeg,image/png,image/webp",
                                class: "d-none",
                                onChange: handlePhotoChange
                              }, null, 32)
                            ])
                          ]),
                          unref(profileForm).errors.photo ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "text-danger small mt-1"
                          }, toDisplayString(unref(profileForm).errors.photo), 1)) : createCommentVNode("", true)
                        ])
                      ]),
                      createVNode("div", { class: "row" }, [
                        createVNode("div", { class: "col-md-6 mb-3" }, [
                          createVNode("label", {
                            for: "profile_name",
                            class: "form-label"
                          }, [
                            createTextVNode("Nome "),
                            createVNode("span", { class: "text-danger" }, "*")
                          ]),
                          withDirectives(createVNode("input", {
                            id: "profile_name",
                            "onUpdate:modelValue": ($event) => unref(profileForm).name = $event,
                            type: "text",
                            class: ["form-control", { "is-invalid": unref(profileForm).errors.name }],
                            required: "",
                            autocomplete: "name"
                          }, null, 10, ["onUpdate:modelValue"]), [
                            [vModelText, unref(profileForm).name]
                          ]),
                          unref(profileForm).errors.name ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "invalid-feedback"
                          }, toDisplayString(unref(profileForm).errors.name), 1)) : createCommentVNode("", true)
                        ]),
                        createVNode("div", { class: "col-md-6 mb-3" }, [
                          createVNode("label", {
                            for: "profile_email",
                            class: "form-label"
                          }, [
                            createTextVNode("E-mail "),
                            createVNode("span", { class: "text-danger" }, "*")
                          ]),
                          withDirectives(createVNode("input", {
                            id: "profile_email",
                            "onUpdate:modelValue": ($event) => unref(profileForm).email = $event,
                            type: "email",
                            class: ["form-control", { "is-invalid": unref(profileForm).errors.email }],
                            required: "",
                            autocomplete: "username"
                          }, null, 10, ["onUpdate:modelValue"]), [
                            [vModelText, unref(profileForm).email]
                          ]),
                          unref(profileForm).errors.email ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "invalid-feedback"
                          }, toDisplayString(unref(profileForm).errors.email), 1)) : createCommentVNode("", true),
                          __props.user.must_verify_email && !__props.user.email_verified ? (openBlock(), createBlock("div", {
                            key: 1,
                            class: "mt-2"
                          }, [
                            createVNode("span", { class: "badge bg-warning text-dark me-1" }, [
                              createVNode("i", { class: "ti ti-alert-circle me-1" }),
                              createTextVNode("E-mail não verificado ")
                            ]),
                            createVNode(unref(Link), {
                              href: __props.urls.send_verification,
                              method: "post",
                              as: "button",
                              class: "btn btn-link btn-sm p-0 align-baseline"
                            }, {
                              default: withCtx(() => [
                                createTextVNode(" Reenviar verificação ")
                              ]),
                              _: 1
                            }, 8, ["href"])
                          ])) : createCommentVNode("", true)
                        ])
                      ]),
                      createVNode("div", { class: "d-flex justify-content-end gap-2" }, [
                        createVNode(unref(Link), {
                          href: __props.urls.dashboard,
                          class: "btn btn-light"
                        }, {
                          default: withCtx(() => [
                            createTextVNode("Cancelar")
                          ]),
                          _: 1
                        }, 8, ["href"]),
                        createVNode("button", {
                          type: "submit",
                          class: "btn btn-primary",
                          disabled: unref(profileForm).processing
                        }, [
                          unref(profileForm).processing ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "spinner-border spinner-border-sm me-1"
                          })) : (openBlock(), createBlock("i", {
                            key: 1,
                            class: "ti ti-device-floppy me-1"
                          })),
                          createTextVNode("Salvar perfil ")
                        ], 8, ["disabled"])
                      ])
                    ], 32),
                    createVNode("form", {
                      onSubmit: withModifiers(submitPassword, ["prevent"]),
                      class: "border-bottom mb-4 pb-3"
                    }, [
                      createVNode("h5", { class: "fw-bold mb-3" }, "Alterar senha"),
                      createVNode("div", { class: "row" }, [
                        createVNode("div", { class: "col-md-4 mb-3" }, [
                          createVNode("label", { class: "form-label" }, "Senha atual"),
                          createVNode("div", { class: "input-group" }, [
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(passwordForm).current_password = $event,
                              type: showCurrent.value ? "text" : "password",
                              class: ["form-control", { "is-invalid": unref(passwordForm).errors.current_password }],
                              autocomplete: "current-password"
                            }, null, 10, ["onUpdate:modelValue", "type"]), [
                              [vModelDynamic, unref(passwordForm).current_password]
                            ]),
                            createVNode("button", {
                              type: "button",
                              class: "btn btn-outline-secondary",
                              tabindex: "-1",
                              onClick: ($event) => showCurrent.value = !showCurrent.value
                            }, [
                              createVNode("i", {
                                class: showCurrent.value ? "ti ti-eye-off" : "ti ti-eye"
                              }, null, 2)
                            ], 8, ["onClick"])
                          ]),
                          unref(passwordForm).errors.current_password ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "text-danger small"
                          }, toDisplayString(unref(passwordForm).errors.current_password), 1)) : createCommentVNode("", true)
                        ]),
                        createVNode("div", { class: "col-md-4 mb-3" }, [
                          createVNode("label", { class: "form-label" }, "Nova senha"),
                          createVNode("div", { class: "input-group" }, [
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(passwordForm).password = $event,
                              type: showNew.value ? "text" : "password",
                              class: ["form-control", { "is-invalid": unref(passwordForm).errors.password }],
                              autocomplete: "new-password"
                            }, null, 10, ["onUpdate:modelValue", "type"]), [
                              [vModelDynamic, unref(passwordForm).password]
                            ]),
                            createVNode("button", {
                              type: "button",
                              class: "btn btn-outline-secondary",
                              tabindex: "-1",
                              onClick: ($event) => showNew.value = !showNew.value
                            }, [
                              createVNode("i", {
                                class: showNew.value ? "ti ti-eye-off" : "ti ti-eye"
                              }, null, 2)
                            ], 8, ["onClick"])
                          ]),
                          unref(passwordForm).errors.password ? (openBlock(), createBlock("div", {
                            key: 0,
                            class: "text-danger small"
                          }, toDisplayString(unref(passwordForm).errors.password), 1)) : createCommentVNode("", true)
                        ]),
                        createVNode("div", { class: "col-md-4 mb-3" }, [
                          createVNode("label", { class: "form-label" }, "Confirmar nova senha"),
                          createVNode("div", { class: "input-group" }, [
                            withDirectives(createVNode("input", {
                              "onUpdate:modelValue": ($event) => unref(passwordForm).password_confirmation = $event,
                              type: showConfirm.value ? "text" : "password",
                              class: "form-control",
                              autocomplete: "new-password"
                            }, null, 8, ["onUpdate:modelValue", "type"]), [
                              [vModelDynamic, unref(passwordForm).password_confirmation]
                            ]),
                            createVNode("button", {
                              type: "button",
                              class: "btn btn-outline-secondary",
                              tabindex: "-1",
                              onClick: ($event) => showConfirm.value = !showConfirm.value
                            }, [
                              createVNode("i", {
                                class: showConfirm.value ? "ti ti-eye-off" : "ti ti-eye"
                              }, null, 2)
                            ], 8, ["onClick"])
                          ])
                        ])
                      ]),
                      createVNode("div", { class: "d-flex justify-content-end" }, [
                        createVNode("button", {
                          type: "submit",
                          class: "btn btn-outline-primary btn-sm",
                          disabled: unref(passwordForm).processing
                        }, [
                          unref(passwordForm).processing ? (openBlock(), createBlock("span", {
                            key: 0,
                            class: "spinner-border spinner-border-sm me-1"
                          })) : (openBlock(), createBlock("i", {
                            key: 1,
                            class: "ti ti-lock me-1"
                          })),
                          createTextVNode("Atualizar senha ")
                        ], 8, ["disabled"])
                      ])
                    ], 32),
                    createVNode("div", { class: "pb-2" }, [
                      createVNode("h5", { class: "fw-bold mb-3" }, "Autenticação em dois fatores"),
                      createVNode("div", { class: "d-flex align-items-center gap-3" }, [
                        __props.user.has_two_factor_enabled ? (openBlock(), createBlock("span", {
                          key: 0,
                          class: "badge badge-soft-success rounded text-success border border-success"
                        }, [
                          createVNode("i", { class: "ti ti-shield-check me-1" }),
                          createTextVNode("Ativo ")
                        ])) : (openBlock(), createBlock("span", {
                          key: 1,
                          class: "badge badge-soft-secondary rounded"
                        }, [
                          createVNode("i", { class: "ti ti-shield-off me-1" }),
                          createTextVNode("Inativo ")
                        ])),
                        createVNode(unref(Link), {
                          href: __props.urls.two_factor_setup,
                          class: "btn btn-sm btn-outline-primary"
                        }, {
                          default: withCtx(() => [
                            createVNode("i", { class: "ti ti-settings me-1" }),
                            createTextVNode(toDisplayString(__props.user.has_two_factor_enabled ? "Gerenciar" : "Configurar"), 1)
                          ]),
                          _: 1
                        }, 8, ["href"])
                      ])
                    ])
                  ])
                ])
              ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Profile/Edit.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
