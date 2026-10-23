const E = window.Inventoros.Vue, { BaseTransition: Ce, BaseTransitionPropsValidators: ke, Comment: we, DeprecationTypes: he, EffectScope: Ve, ErrorCodes: Ie, ErrorTypeStrings: Ue, Fragment: x, KeepAlive: Re, ReactiveEffect: $e, Static: _e, Suspense: Ee, Teleport: Oe, Text: Ae, TrackOpTypes: Ne, Transition: Pe, TransitionGroup: De, TriggerOpTypes: Me, VueElement: Ge, __esModule: He, assertNumber: Be, callWithAsyncErrorHandling: ze, callWithErrorHandling: je, camelize: Fe, capitalize: Le, cloneVNode: Ke, compatUtils: We, compile: qe, computed: O, createApp: Je, createBlock: T, createCommentVNode: v, createElementBlock: p, createElementVNode: o, createHydrationRenderer: Qe, createPropsRestProxy: Ze, createRenderer: Xe, createSSRApp: Ye, createSlots: et, createStaticVNode: tt, createTextVNode: i, createVNode: f, customRef: ot, defineAsyncComponent: st, defineComponent: nt, defineCustomElement: lt, defineEmits: rt, defineExpose: it, defineModel: at, defineOptions: dt, defineProps: ut, defineSSRCustomElement: pt, defineSlots: ct, devtools: mt, effect: bt, effectScope: ft, getCurrentInstance: gt, getCurrentScope: yt, getCurrentWatcher: vt, getTransitionRawChildren: St, guardReactiveProps: xt, h: Tt, handleError: Ct, hasInjectionContext: kt, hydrate: wt, hydrateOnIdle: ht, hydrateOnInteraction: Vt, hydrateOnMediaQuery: It, hydrateOnVisible: Ut, initCustomFormatter: Rt, initDirectivesForSSR: $t, inject: _t, isMemoSame: Et, isProxy: Ot, isReactive: At, isReadonly: Nt, isRef: Pt, isRuntimeOnly: Dt, isShallow: Mt, isVNode: Gt, markRaw: Ht, mergeDefaults: Bt, mergeModels: zt, mergeProps: jt, nextTick: Ft, nodeOps: Lt, normalizeClass: Kt, normalizeProps: Wt, normalizeStyle: qt, onActivated: Jt, onBeforeMount: Qt, onBeforeUnmount: Zt, onBeforeUpdate: Xt, onDeactivated: Yt, onErrorCaptured: eo, onMounted: to, onRenderTracked: oo, onRenderTriggered: so, onScopeDispose: no, onServerPrefetch: lo, onUnmounted: ro, onUpdated: io, onWatcherCleanup: ao, openBlock: a, patchProp: uo, popScopeId: po, provide: co, proxyRefs: mo, pushScopeId: bo, queuePostFlushCb: fo, reactive: go, readonly: yo, ref: vo, registerRuntimeCompiler: So, render: xo, renderList: k, renderSlot: To, resolveComponent: Co, resolveDirective: ko, resolveDynamicComponent: wo, resolveFilter: ho, resolveTransitionHooks: Vo, setBlockTracking: Io, setDevtoolsHook: Uo, setTransitionHooks: Ro, shallowReactive: $o, shallowReadonly: _o, shallowRef: Eo, ssrContextKey: Oo, ssrUtils: Ao, stop: No, toDisplayString: d, toHandlerKey: Po, toHandlers: Do, toRaw: Mo, toRef: Go, toRefs: Ho, toValue: Bo, transformVNodeArgs: zo, triggerRef: jo, unref: t, useAttrs: Fo, useCssModule: Lo, useCssVars: Ko, useHost: Wo, useId: qo, useModel: Jo, useSSRContext: Qo, useShadowRoot: Zo, useSlots: Xo, useTemplateRef: Yo, useTransitionState: es, vModelCheckbox: $, vModelDynamic: ts, vModelRadio: os, vModelSelect: R, vModelText: c, vShow: ss, version: ns, warn: ls, watch: rs, watchEffect: is, watchPostEffect: as, watchSyncEffect: ds, withAsyncContext: us, withCtx: m, withDefaults: ps, withDirectives: u, withKeys: cs, withMemo: ms, withModifiers: _, withScopeId: bs } = E, A = { class: "tb-grid" }, N = { class: "tb-field" }, P = { class: "tb-field" }, D = { class: "tb-field" }, M = { class: "tb-field" }, G = { class: "tb-field" }, H = { class: "tb-field" }, B = { class: "tb-field tb-wide" }, z = { class: "tb-field" }, j = { class: "tb-check" }, F = { class: "tb-grid" }, L = { class: "tb-field" }, K = { class: "tb-field" }, W = { class: "tb-field" }, q = ["onUpdate:modelValue"], J = { class: "tb-field" }, Q = ["onUpdate:modelValue"], Z = {
  key: 2,
  role: "alert",
  class: "tb-errors"
}, X = {
  __name: "Settings",
  props: { profile: Object, saveUrl: String },
  setup(n) {
    const { layouts: { AppLayout: S }, ui: { PageHeader: g, Card: b, Button: y }, Inertia: { Head: C, useForm: U } } = window.Inventoros, s = U(JSON.parse(JSON.stringify(n.profile)));
    function h() {
      s.mode === "india_gst" && (s.country = "IN", s.tax_id_label = "GSTIN");
    }
    return (r, e) => (a(), p(x, null, [
      f(t(C), { title: "Tax billing" }),
      f(t(S), null, {
        default: m(() => [
          f(t(g), {
            title: "Tax billing",
            description: "Tax-registration details and invoice presentation for this organization."
          }),
          o("form", {
            class: "tb-stack",
            onSubmit: e[13] || (e[13] = _((l) => t(s).post(n.saveUrl, { preserveScroll: !0 }), ["prevent"]))
          }, [
            f(t(b), null, {
              default: m(() => [
                e[24] || (e[24] = o("p", { class: "tb-help" }, "Set the tax treatment that applies to your business. Recorded order tax must match the selected rates before an invoice can be issued. This module does not choose tax rates, file returns or obtain government e-invoice acknowledgements.", -1)),
                o("div", A, [
                  o("label", N, [
                    e[15] || (e[15] = i("Profile", -1)),
                    u(o("select", {
                      "onUpdate:modelValue": e[0] || (e[0] = (l) => t(s).mode = l),
                      class: "tb-control",
                      onChange: h
                    }, [...e[14] || (e[14] = [
                      o("option", { value: "generic" }, "Custom country profile", -1),
                      o("option", { value: "india_gst" }, "India GST: domestic supplies", -1)
                    ])], 544), [
                      [R, t(s).mode]
                    ])
                  ]),
                  o("label", P, [
                    e[16] || (e[16] = i("Country code (ISO 2-letter)", -1)),
                    u(o("input", {
                      "onUpdate:modelValue": e[1] || (e[1] = (l) => t(s).country = l),
                      class: "tb-control",
                      maxlength: "2",
                      placeholder: "IN, GB, CA",
                      onInput: e[2] || (e[2] = (l) => t(s).country = t(s).country.toUpperCase())
                    }, null, 544), [
                      [c, t(s).country]
                    ])
                  ]),
                  o("label", D, [
                    e[17] || (e[17] = i("Supplier name", -1)),
                    u(o("input", {
                      "onUpdate:modelValue": e[3] || (e[3] = (l) => t(s).issuer_name = l),
                      class: "tb-control",
                      autocomplete: "organization"
                    }, null, 512), [
                      [c, t(s).issuer_name]
                    ])
                  ]),
                  o("label", M, [
                    e[18] || (e[18] = i("Supplier tax registration", -1)),
                    u(o("input", {
                      "onUpdate:modelValue": e[4] || (e[4] = (l) => t(s).issuer_tax_id = l),
                      class: "tb-control"
                    }, null, 512), [
                      [c, t(s).issuer_tax_id]
                    ])
                  ]),
                  o("label", G, [
                    e[19] || (e[19] = i("Supplier region/state code", -1)),
                    u(o("input", {
                      "onUpdate:modelValue": e[5] || (e[5] = (l) => t(s).region_code = l),
                      class: "tb-control"
                    }, null, 512), [
                      [c, t(s).region_code]
                    ])
                  ]),
                  o("label", H, [
                    e[20] || (e[20] = i("Tax identifier label", -1)),
                    u(o("input", {
                      "onUpdate:modelValue": e[6] || (e[6] = (l) => t(s).tax_id_label = l),
                      class: "tb-control",
                      placeholder: "GSTIN, VAT number, TIN"
                    }, null, 512), [
                      [c, t(s).tax_id_label]
                    ])
                  ]),
                  o("label", B, [
                    e[21] || (e[21] = i("Supplier address", -1)),
                    u(o("textarea", {
                      "onUpdate:modelValue": e[7] || (e[7] = (l) => t(s).issuer_address = l),
                      class: "tb-control",
                      rows: "3",
                      autocomplete: "street-address"
                    }, null, 512), [
                      [c, t(s).issuer_address]
                    ])
                  ]),
                  o("label", z, [
                    e[22] || (e[22] = i("Variant option for size grouping", -1)),
                    u(o("input", {
                      "onUpdate:modelValue": e[8] || (e[8] = (l) => t(s).size_option = l),
                      class: "tb-control"
                    }, null, 512), [
                      [c, t(s).size_option]
                    ])
                  ])
                ]),
                o("label", j, [
                  u(o("input", {
                    "onUpdate:modelValue": e[9] || (e[9] = (l) => t(s).shipping_taxable = l),
                    type: "checkbox"
                  }, null, 512), [
                    [$, t(s).shipping_taxable]
                  ]),
                  e[23] || (e[23] = i(" Include shipping in the taxable base", -1))
                ])
              ]),
              _: 1
            }),
            t(s).mode === "india_gst" ? (a(), T(t(b), { key: 0 }, {
              default: m(() => [
                e[28] || (e[28] = o("h2", { class: "tb-title" }, "India GST", -1)),
                e[29] || (e[29] = o("p", { class: "tb-help" }, "CGST plus SGST/UTGST applies when supplier and place-of-supply state codes match; IGST is used when they differ. Enter the applicable total GST rate. Exports, SEZ, reverse charge, cess and e-invoicing need dedicated treatment.", -1)),
                o("div", F, [
                  o("label", L, [
                    e[25] || (e[25] = i("Total GST rate (%)", -1)),
                    u(o("input", {
                      "onUpdate:modelValue": e[10] || (e[10] = (l) => t(s).gst_rate = l),
                      class: "tb-control",
                      type: "number",
                      min: "0",
                      max: "100",
                      step: "0.0001"
                    }, null, 512), [
                      [c, t(s).gst_rate]
                    ])
                  ]),
                  o("label", K, [
                    e[27] || (e[27] = i("Local component", -1)),
                    u(o("select", {
                      "onUpdate:modelValue": e[11] || (e[11] = (l) => t(s).local_tax_label = l),
                      class: "tb-control"
                    }, [...e[26] || (e[26] = [
                      o("option", null, "SGST", -1),
                      o("option", null, "UTGST", -1)
                    ])], 512), [
                      [R, t(s).local_tax_label]
                    ])
                  ])
                ])
              ]),
              _: 1
            })) : (a(), T(t(b), { key: 1 }, {
              default: m(() => [
                e[34] || (e[34] = o("h2", { class: "tb-title" }, "Tax components", -1)),
                e[35] || (e[35] = o("p", { class: "tb-help" }, "Configure additive taxes such as VAT, GST/PST or sales tax. Mixed-rate, compound-tax and country filing rules are not automated.", -1)),
                (a(!0), p(x, null, k(t(s).components, (l, V) => (a(), p("div", {
                  key: V,
                  class: "tb-grid tb-row"
                }, [
                  o("label", W, [
                    e[30] || (e[30] = i("Component name", -1)),
                    u(o("input", {
                      "onUpdate:modelValue": (I) => l.label = I,
                      class: "tb-control"
                    }, null, 8, q), [
                      [c, l.label]
                    ])
                  ]),
                  o("label", J, [
                    e[31] || (e[31] = i("Rate (%)", -1)),
                    u(o("input", {
                      "onUpdate:modelValue": (I) => l.rate = I,
                      class: "tb-control",
                      type: "number",
                      min: "0",
                      max: "100",
                      step: "0.0001"
                    }, null, 8, Q), [
                      [c, l.rate]
                    ])
                  ]),
                  t(s).components.length > 1 ? (a(), T(t(y), {
                    key: 0,
                    variant: "ghost",
                    onClick: (I) => t(s).components.splice(V, 1)
                  }, {
                    default: m(() => [...e[32] || (e[32] = [
                      i("Remove component", -1)
                    ])]),
                    _: 1
                  }, 8, ["onClick"])) : v("", !0)
                ]))), 128)),
                t(s).components.length < 10 ? (a(), T(t(y), {
                  key: 0,
                  variant: "secondary",
                  onClick: e[12] || (e[12] = (l) => t(s).components.push({ label: "", rate: "0" }))
                }, {
                  default: m(() => [...e[33] || (e[33] = [
                    i("Add tax component", -1)
                  ])]),
                  _: 1
                })) : v("", !0)
              ]),
              _: 1
            })),
            Object.keys(t(s).errors).length ? (a(), p("div", Z, [
              (a(!0), p(x, null, k(t(s).errors, (l, V) => (a(), p("p", { key: V }, d(l), 1))), 128))
            ])) : v("", !0),
            f(t(y), {
              type: "submit",
              loading: t(s).processing
            }, {
              default: m(() => [...e[36] || (e[36] = [
                i("Save tax billing profile", -1)
              ])]),
              _: 1
            }, 8, ["loading"])
          ], 32)
        ]),
        _: 1
      })
    ], 64));
  }
}, Y = { class: "tb-title" }, ee = { class: "tb-help" }, te = { class: "tb-grid" }, oe = { class: "tb-field" }, se = { class: "tb-field" }, ne = {
  key: 0,
  class: "tb-help"
}, le = { class: "tb-grid" }, re = { class: "tb-field" }, ie = ["onUpdate:modelValue", "aria-label"], ae = { class: "tb-field" }, de = ["onUpdate:modelValue", "aria-label"], ue = {
  key: 0,
  role: "alert",
  class: "tb-errors"
}, pe = { class: "tb-check" }, ce = {
  __name: "Invoice",
  props: { order: Object, items: Array, profile: Object, snapshot: Object, canIssue: Boolean, regionCode: String, supplyCountry: String, issueUrl: String, downloadUrl: String },
  setup(n) {
    const { layouts: { AppLayout: S }, ui: { PageHeader: g, Card: b, Button: y }, Inertia: { Head: C, useForm: U } } = window.Inventoros, w = n, s = U({ confirmed: !1, region_code: w.regionCode || "", supply_country: w.supplyCountry || "", items: Object.fromEntries(w.items.map((h) => [h.id, { hsn_code: "", unit: "NOS" }])) });
    return (h, r) => (a(), p(x, null, [
      f(t(C), {
        title: `Tax invoice: ${n.order.order_number}`
      }, null, 8, ["title"]),
      f(t(S), null, {
        default: m(() => [
          f(t(g), {
            title: `Tax invoice: ${n.order.order_number}`,
            description: "Review tax details before retaining an issued invoice."
          }, null, 8, ["title"]),
          n.snapshot ? (a(), T(t(b), {
            key: 0,
            class: "tb-stack"
          }, {
            default: m(() => [
              o("h2", Y, d(n.snapshot.number), 1),
              o("p", null, "Issued " + d(n.snapshot.issued_date) + " · " + d(n.snapshot.currency) + " " + d(n.snapshot.total), 1),
              r[6] || (r[6] = o("p", { class: "tb-help" }, "These details are retained from issuance. Later edits to the customer, order or tax profile do not rewrite this invoice.", -1)),
              (a(!0), p(x, null, k(n.snapshot.components, (e) => (a(), p("p", {
                key: e.label
              }, d(e.label) + ": " + d(e.amount), 1))), 128)),
              f(t(y), {
                as: "a",
                href: n.downloadUrl
              }, {
                default: m(() => [...r[5] || (r[5] = [
                  i("Download invoice PDF", -1)
                ])]),
                _: 1
              }, 8, ["href"])
            ]),
            _: 1
          })) : n.canIssue ? (a(), p("form", {
            key: 1,
            class: "tb-stack",
            onSubmit: r[4] || (r[4] = _((e) => t(s).post(n.issueUrl, { preserveScroll: !0 }), ["prevent"]))
          }, [
            f(t(b), null, {
              default: m(() => [
                o("p", ee, "Order total: " + d(n.order.currency) + " " + d(n.order.total) + ". Recorded tax: " + d(n.order.tax) + ". Invoice tax components must reconcile with this amount. Issuing does not change stock or payments.", 1),
                o("div", te, [
                  o("label", oe, [
                    r[7] || (r[7] = i("Place-of-supply country code", -1)),
                    u(o("input", {
                      "onUpdate:modelValue": r[0] || (r[0] = (e) => t(s).supply_country = e),
                      class: "tb-control",
                      maxlength: "2",
                      onInput: r[1] || (r[1] = (e) => t(s).supply_country = t(s).supply_country.toUpperCase())
                    }, null, 544), [
                      [c, t(s).supply_country]
                    ])
                  ]),
                  o("label", se, [
                    r[8] || (r[8] = i("Place-of-supply region/state code", -1)),
                    u(o("input", {
                      "onUpdate:modelValue": r[2] || (r[2] = (e) => t(s).region_code = e),
                      class: "tb-control"
                    }, null, 512), [
                      [c, t(s).region_code]
                    ])
                  ])
                ]),
                n.profile.mode === "india_gst" ? (a(), p("p", ne, "This profile covers domestic India supplies. Check the place of supply, GSTIN, HSN/SAC and unit codes before issuing.")) : v("", !0)
              ]),
              _: 1
            }),
            f(t(b), null, {
              default: m(() => [
                r[10] || (r[10] = o("h2", { class: "tb-title" }, "Line classification", -1)),
                (a(!0), p(x, null, k(n.items, (e) => (a(), p("div", {
                  key: e.id,
                  class: "tb-row"
                }, [
                  o("p", null, [
                    o("strong", null, d(e.product_name), 1),
                    i(" · " + d(e.sku) + " · " + d(e.quantity) + " × " + d(e.unit_price), 1)
                  ]),
                  o("div", le, [
                    o("label", re, [
                      i("HSN/SAC" + d(n.profile.mode === "india_gst" ? " (required)" : " (optional)"), 1),
                      u(o("input", {
                        "onUpdate:modelValue": (l) => t(s).items[e.id].hsn_code = l,
                        class: "tb-control",
                        "aria-label": `HSN/SAC for ${e.sku}`
                      }, null, 8, ie), [
                        [c, t(s).items[e.id].hsn_code]
                      ])
                    ]),
                    o("label", ae, [
                      r[9] || (r[9] = i("Unit code", -1)),
                      u(o("input", {
                        "onUpdate:modelValue": (l) => t(s).items[e.id].unit = l,
                        class: "tb-control",
                        "aria-label": `Unit for ${e.sku}`
                      }, null, 8, de), [
                        [c, t(s).items[e.id].unit]
                      ])
                    ])
                  ])
                ]))), 128))
              ]),
              _: 1
            }),
            Object.keys(t(s).errors).length ? (a(), p("div", ue, [
              (a(!0), p(x, null, k(t(s).errors, (e, l) => (a(), p("p", { key: l }, d(e), 1))), 128))
            ])) : v("", !0),
            o("label", pe, [
              u(o("input", {
                "onUpdate:modelValue": r[3] || (r[3] = (e) => t(s).confirmed = e),
                type: "checkbox"
              }, null, 512), [
                [$, t(s).confirmed]
              ]),
              r[11] || (r[11] = i(" I reviewed the tax registration, place of supply, classification and recorded tax.", -1))
            ]),
            f(t(y), {
              type: "submit",
              loading: t(s).processing,
              disabled: !t(s).confirmed
            }, {
              default: m(() => [...r[12] || (r[12] = [
                i("Issue tax invoice", -1)
              ])]),
              _: 1
            }, 8, ["loading", "disabled"])
          ], 32)) : (a(), T(t(b), {
            key: 2,
            class: "tb-stack"
          }, {
            default: m(() => [...r[13] || (r[13] = [
              o("p", null, "An authorized user must issue the tax invoice before it can be downloaded.", -1)
            ])]),
            _: 1
          }))
        ]),
        _: 1
      })
    ], 64));
  }
}, me = {
  key: 0,
  class: "tb-row"
}, be = { class: "tb-help" }, fe = { class: "tb-field" }, ge = ["maxlength"], ye = {
  key: 0,
  class: "tb-errors"
}, ve = {
  __name: "CustomerFields",
  props: { form: Object, mode: String },
  setup(n) {
    const S = n, g = O(() => S.form.plugin_fields.tax_billing);
    return (b, y) => g.value ? (a(), p("div", me, [
      o("p", be, d(n.mode === "india_gst" ? "Enter the customer GSTIN in the Tax ID/VAT field above. Leave it blank for an unregistered customer." : "Use the Tax ID/VAT field for the customer’s local tax registration."), 1),
      o("label", fe, [
        y[1] || (y[1] = i("Default place-of-supply region/state code", -1)),
        u(o("input", {
          "onUpdate:modelValue": y[0] || (y[0] = (C) => g.value.region_code = C),
          class: "tb-control",
          maxlength: n.mode === "india_gst" ? 2 : 80
        }, null, 8, ge), [
          [c, g.value.region_code]
        ])
      ]),
      n.form.errors["plugin_fields.tax_billing.region_code"] ? (a(), p("p", ye, d(n.form.errors["plugin_fields.tax_billing.region_code"]), 1)) : v("", !0)
    ])) : v("", !0);
  }
}, Se = {
  key: 0,
  class: "tb-row"
}, xe = {
  __name: "CustomerTaxDetails",
  props: { customer: Object },
  setup(n) {
    return (S, g) => n.customer?.metadata?.tax_billing?.region_code ? (a(), p("div", Se, [
      g[0] || (g[0] = o("p", { class: "tb-help" }, "Default place-of-supply region/state", -1)),
      o("p", null, d(n.customer.metadata.tax_billing.region_code), 1)
    ])) : v("", !0);
  }
}, Te = {
  __name: "InvoiceLink",
  props: { url: String },
  setup(n) {
    const { ui: { Button: S } } = window.Inventoros;
    return (g, b) => n.url ? (a(), T(t(S), {
      key: 0,
      as: "Link",
      variant: "secondary",
      size: "sm",
      href: n.url
    }, {
      default: m(() => [...b[0] || (b[0] = [
        i("Tax invoice", -1)
      ])]),
      _: 1
    }, 8, ["href"])) : v("", !0);
  }
};
function fs(n) {
  n.registerPage("Settings", X), n.registerPage("Invoice", ce), n.registerComponent("CustomerFields", ve), n.registerComponent("CustomerTaxDetails", xe), n.registerComponent("InvoiceLink", Te);
}
export {
  fs as default
};
