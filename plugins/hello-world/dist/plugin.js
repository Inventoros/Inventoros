const w = window.Inventoros.Vue, { BaseTransition: D, BaseTransitionPropsValidators: A, Comment: N, DeprecationTypes: O, EffectScope: $, ErrorCodes: z, ErrorTypeStrings: U, Fragment: v, KeepAlive: F, ReactiveEffect: L, Static: K, Suspense: j, Teleport: q, Text: G, TrackOpTypes: Q, Transition: Y, TransitionGroup: J, TriggerOpTypes: X, VueElement: Z, __esModule: ee, assertNumber: te, callWithAsyncErrorHandling: oe, callWithErrorHandling: ne, camelize: re, capitalize: se, cloneVNode: le, compatUtils: ie, compile: ae, computed: de, createApp: ue, createBlock: ce, createCommentVNode: f, createElementBlock: c, createElementVNode: e, createHydrationRenderer: pe, createPropsRestProxy: he, createRenderer: fe, createSSRApp: ge, createSlots: me, createStaticVNode: we, createTextVNode: s, createVNode: i, customRef: ve, defineAsyncComponent: ye, defineComponent: _e, defineCustomElement: Ce, defineEmits: Se, defineExpose: Re, defineModel: be, defineOptions: ke, defineProps: He, defineSSRCustomElement: Te, defineSlots: xe, devtools: Pe, effect: We, effectScope: Ee, getCurrentInstance: Me, getCurrentScope: Ie, getCurrentWatcher: Ve, getTransitionRawChildren: Be, guardReactiveProps: De, h: Ae, handleError: Ne, hasInjectionContext: Oe, hydrate: $e, hydrateOnIdle: ze, hydrateOnInteraction: Ue, hydrateOnMediaQuery: Fe, hydrateOnVisible: Le, initCustomFormatter: Ke, initDirectivesForSSR: je, inject: qe, isMemoSame: Ge, isProxy: Qe, isReactive: Ye, isReadonly: Je, isRef: Xe, isRuntimeOnly: Ze, isShallow: et, isVNode: tt, markRaw: ot, mergeDefaults: nt, mergeModels: rt, mergeProps: st, nextTick: lt, normalizeClass: it, normalizeProps: at, normalizeStyle: dt, onActivated: ut, onBeforeMount: ct, onBeforeUnmount: pt, onBeforeUpdate: ht, onDeactivated: ft, onErrorCaptured: gt, onMounted: mt, onRenderTracked: wt, onRenderTriggered: vt, onScopeDispose: yt, onServerPrefetch: _t, onUnmounted: Ct, onUpdated: St, onWatcherCleanup: Rt, openBlock: p, popScopeId: bt, provide: kt, proxyRefs: Ht, pushScopeId: Tt, queuePostFlushCb: xt, reactive: Pt, readonly: Wt, ref: y, registerRuntimeCompiler: Et, render: Mt, renderList: It, renderSlot: Vt, resolveComponent: Bt, resolveDirective: Dt, resolveDynamicComponent: At, resolveFilter: Nt, resolveTransitionHooks: Ot, setBlockTracking: $t, setDevtoolsHook: zt, setTransitionHooks: Ut, shallowReactive: Ft, shallowReadonly: Lt, shallowRef: Kt, ssrContextKey: jt, ssrUtils: qt, stop: Gt, toDisplayString: u, toHandlerKey: Qt, toHandlers: Yt, toRaw: Jt, toRef: Xt, toRefs: Zt, toValue: eo, transformVNodeArgs: to, triggerRef: oo, unref: a, useAttrs: no, useCssModule: ro, useCssVars: so, useHost: lo, useId: io, useModel: ao, useSSRContext: uo, useShadowRoot: co, useSlots: po, useTemplateRef: ho, useTransitionState: fo, vModelCheckbox: go, vModelDynamic: mo, vModelRadio: wo, vModelSelect: vo, vModelText: yo, vShow: _o, version: Co, warn: So, watch: Ro, watchEffect: bo, watchPostEffect: ko, watchSyncEffect: Ho, withAsyncContext: To, withCtx: d, withDefaults: xo, withDirectives: Po, withKeys: Wo, withMemo: Eo, withModifiers: Mo, withScopeId: Io } = w, g = (t, n) => {
  const l = t.__vccOpts || t;
  for (const [o, h] of n)
    l[o] = h;
  return l;
}, _ = {
  key: 0,
  class: "hw-banner",
  role: "status"
}, C = { class: "hw-banner__body" }, S = {
  key: 0,
  class: "hw-banner__meta"
}, R = {
  __name: "HelloWorldBanner",
  props: {
    version: {
      type: String,
      default: ""
    }
  },
  setup(t) {
    const n = y(!1);
    return (l, o) => n.value ? f("", !0) : (p(), c("div", _, [
      e("div", C, [
        o[1] || (o[1] = e("p", { class: "hw-banner__title" }, [
          s(" Hello from the Hello World plugin "),
          e("span", { class: "hw-banner__badge" }, "Plugin active")
        ], -1)),
        o[2] || (o[2] = e("p", { class: "hw-banner__text" }, [
          s(" This banner was loaded at runtime from the plugin's own pre-built bundle, so it works on installs that never run npm. Turn it off on the "),
          e("a", { href: "/plugins" }, "Plugins page"),
          s(". ")
        ], -1)),
        t.version ? (p(), c("p", S, "Version " + u(t.version), 1)) : f("", !0)
      ]),
      e("button", {
        type: "button",
        class: "hw-banner__dismiss",
        "aria-label": "Dismiss",
        onClick: o[0] || (o[0] = (h) => n.value = !0)
      }, [...o[3] || (o[3] = [
        e("svg", {
          viewBox: "0 0 24 24",
          width: "16",
          height: "16",
          fill: "none",
          stroke: "currentColor",
          "stroke-width": "2",
          "aria-hidden": "true"
        }, [
          e("path", {
            "stroke-linecap": "round",
            "stroke-linejoin": "round",
            d: "M6 18L18 6M6 6l12 12"
          })
        ], -1)
      ])])
    ]));
  }
}, b = /* @__PURE__ */ g(R, [["__scopeId", "data-v-5a7f86dc"]]), k = { class: "hw-crumbs" }, H = { class: "hw-page-lead" }, T = {
  key: 0,
  class: "hw-page-text"
}, x = { class: "hw-page-text" }, P = {
  __name: "HelloWorldPage",
  props: {
    title: { type: String, default: "Hello World" },
    name: { type: String, default: "" },
    productCount: { type: Number, default: null }
  },
  setup(t) {
    const {
      layouts: { AppLayout: n },
      ui: { PageHeader: l, Card: o },
      Inertia: { Head: h, Link: m }
    } = window.Inventoros;
    return (B, r) => (p(), c(v, null, [
      i(a(h), { title: t.title }, null, 8, ["title"]),
      i(a(n), null, {
        header: d(() => [
          e("div", k, [
            r[0] || (r[0] = e("span", null, "Plugins", -1)),
            r[1] || (r[1] = e("span", null, "/", -1)),
            e("strong", null, u(t.title), 1)
          ])
        ]),
        default: d(() => [
          e("div", null, [
            i(a(l), {
              title: t.title,
              description: "A page rendered from the Hello World plugin's runtime bundle."
            }, null, 8, ["title"]),
            i(a(o), { class: "hw-page-card" }, {
              default: d(() => [
                e("p", H, "Hello, " + u(t.name || "there") + ".", 1),
                r[3] || (r[3] = e("p", { class: "hw-page-text" }, [
                  s(" This route was registered with "),
                  e("code", null, "register_page()"),
                  s(" in the plugin's PHP, and this page was registered with "),
                  e("code", null, "plugin.registerPage()"),
                  s(" in its pre-built bundle. ")
                ], -1)),
                t.productCount !== null ? (p(), c("p", T, " Your organization has " + u(t.productCount) + " products. ", 1)) : f("", !0),
                e("p", x, [
                  i(a(m), { href: "/dashboard" }, {
                    default: d(() => [...r[2] || (r[2] = [
                      s("Back to the dashboard", -1)
                    ])]),
                    _: 1
                  })
                ])
              ]),
              _: 1
            })
          ])
        ]),
        _: 1
      })
    ], 64));
  }
}, W = /* @__PURE__ */ g(P, [["__scopeId", "data-v-4d8c67c4"]]), E = { class: "hw-widget" }, M = { class: "hw-widget__value" }, I = {
  __name: "HelloWorldWidget",
  props: {
    productCount: { type: Number, default: 0 }
  },
  setup(t) {
    const {
      Inertia: { Link: n }
    } = window.Inventoros;
    return (l, o) => (p(), c("div", E, [
      e("p", M, u(t.productCount), 1),
      o[1] || (o[1] = e("p", { class: "hw-widget__label" }, "products say hello", -1)),
      i(a(n), {
        href: "/hello-world",
        class: "hw-widget__link"
      }, {
        default: d(() => [...o[0] || (o[0] = [
          s("Open the Hello World page", -1)
        ])]),
        _: 1
      })
    ]));
  }
}, V = /* @__PURE__ */ g(I, [["__scopeId", "data-v-84a7263b"]]);
function Vo(t) {
  t.registerComponent("HelloWorldBanner", b), t.registerComponent("HelloWorldWidget", V), t.registerPage("Hello", W);
}
export {
  Vo as default
};
