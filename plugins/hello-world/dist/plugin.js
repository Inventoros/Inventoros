const w = window.Inventoros.Vue, { BaseTransition: $, BaseTransitionPropsValidators: z, Comment: U, DeprecationTypes: F, EffectScope: L, ErrorCodes: K, ErrorTypeStrings: j, Fragment: v, KeepAlive: q, ReactiveEffect: G, Static: Q, Suspense: Y, Teleport: J, Text: X, TrackOpTypes: Z, Transition: ee, TransitionGroup: te, TriggerOpTypes: oe, VueElement: ne, __esModule: re, assertNumber: se, callWithAsyncErrorHandling: le, callWithErrorHandling: ae, camelize: ie, capitalize: de, cloneVNode: ue, compatUtils: ce, compile: pe, computed: he, createApp: fe, createBlock: me, createCommentVNode: m, createElementBlock: u, createElementVNode: e, createHydrationRenderer: ge, createPropsRestProxy: we, createRenderer: ve, createSSRApp: _e, createSlots: ye, createStaticVNode: be, createTextVNode: n, createVNode: a, customRef: Ce, defineAsyncComponent: Se, defineComponent: He, defineCustomElement: Re, defineEmits: Te, defineExpose: ke, defineModel: xe, defineOptions: We, defineProps: Pe, defineSSRCustomElement: Ee, defineSlots: Ie, devtools: Me, effect: Ve, effectScope: Be, getCurrentInstance: De, getCurrentScope: Ae, getCurrentWatcher: Ne, getTransitionRawChildren: Oe, guardReactiveProps: $e, h: ze, handleError: Ue, hasInjectionContext: Fe, hydrate: Le, hydrateOnIdle: Ke, hydrateOnInteraction: je, hydrateOnMediaQuery: qe, hydrateOnVisible: Ge, initCustomFormatter: Qe, initDirectivesForSSR: Ye, inject: Je, isMemoSame: Xe, isProxy: Ze, isReactive: et, isReadonly: tt, isRef: ot, isRuntimeOnly: nt, isShallow: rt, isVNode: st, markRaw: lt, mergeDefaults: at, mergeModels: it, mergeProps: dt, nextTick: ut, normalizeClass: ct, normalizeProps: pt, normalizeStyle: ht, onActivated: ft, onBeforeMount: mt, onBeforeUnmount: gt, onBeforeUpdate: wt, onDeactivated: vt, onErrorCaptured: _t, onMounted: yt, onRenderTracked: bt, onRenderTriggered: Ct, onScopeDispose: St, onServerPrefetch: Ht, onUnmounted: Rt, onUpdated: Tt, onWatcherCleanup: kt, openBlock: c, popScopeId: xt, provide: Wt, proxyRefs: Pt, pushScopeId: Et, queuePostFlushCb: It, reactive: Mt, readonly: Vt, ref: _, registerRuntimeCompiler: Bt, render: Dt, renderList: At, renderSlot: Nt, resolveComponent: Ot, resolveDirective: $t, resolveDynamicComponent: zt, resolveFilter: Ut, resolveTransitionHooks: Ft, setBlockTracking: Lt, setDevtoolsHook: Kt, setTransitionHooks: jt, shallowReactive: qt, shallowReadonly: Gt, shallowRef: Qt, ssrContextKey: Yt, ssrUtils: Jt, stop: Xt, toDisplayString: d, toHandlerKey: Zt, toHandlers: eo, toRaw: to, toRef: oo, toRefs: no, toValue: ro, transformVNodeArgs: so, triggerRef: lo, unref: i, useAttrs: ao, useCssModule: io, useCssVars: uo, useHost: co, useId: po, useModel: ho, useSSRContext: fo, useShadowRoot: mo, useSlots: go, useTemplateRef: wo, useTransitionState: vo, vModelCheckbox: _o, vModelDynamic: yo, vModelRadio: bo, vModelSelect: Co, vModelText: So, vShow: Ho, version: Ro, warn: To, watch: ko, watchEffect: xo, watchPostEffect: Wo, watchSyncEffect: Po, withAsyncContext: Eo, withCtx: p, withDefaults: Io, withDirectives: Mo, withKeys: Vo, withMemo: Bo, withModifiers: Do, withScopeId: Ao } = w, f = (t, r) => {
  const s = t.__vccOpts || t;
  for (const [o, h] of r)
    s[o] = h;
  return s;
}, y = {
  key: 0,
  class: "hw-banner",
  role: "status"
}, b = { class: "hw-banner__body" }, C = {
  key: 0,
  class: "hw-banner__meta"
}, S = {
  __name: "HelloWorldBanner",
  props: {
    version: {
      type: String,
      default: ""
    }
  },
  setup(t) {
    const r = _(!1);
    return (s, o) => r.value ? m("", !0) : (c(), u("div", y, [
      e("div", b, [
        o[1] || (o[1] = e("p", { class: "hw-banner__title" }, [
          n(" Hello from the Hello World plugin "),
          e("span", { class: "hw-banner__badge" }, "Plugin active")
        ], -1)),
        o[2] || (o[2] = e("p", { class: "hw-banner__text" }, [
          n(" This banner was loaded at runtime from the plugin's own pre-built bundle, so it works on installs that never run npm. Turn it off on the "),
          e("a", { href: "/plugins" }, "Plugins page"),
          n(". ")
        ], -1)),
        t.version ? (c(), u("p", C, "Version " + d(t.version), 1)) : m("", !0)
      ]),
      e("button", {
        type: "button",
        class: "hw-banner__dismiss",
        "aria-label": "Dismiss",
        onClick: o[0] || (o[0] = (h) => r.value = !0)
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
}, H = /* @__PURE__ */ f(S, [["__scopeId", "data-v-5a7f86dc"]]), R = { class: "hw-crumbs" }, T = { class: "hw-page-lead" }, k = {
  key: 0,
  class: "hw-page-text"
}, x = { class: "hw-page-text" }, W = {
  __name: "HelloWorldPage",
  props: {
    title: { type: String, default: "Hello World" },
    name: { type: String, default: "" },
    productCount: { type: Number, default: null }
  },
  setup(t) {
    const {
      layouts: { AppLayout: r },
      ui: { PageHeader: s, Card: o },
      Inertia: { Head: h, Link: g }
    } = window.Inventoros;
    return (O, l) => (c(), u(v, null, [
      a(i(h), { title: t.title }, null, 8, ["title"]),
      a(i(r), null, {
        header: p(() => [
          e("div", R, [
            l[0] || (l[0] = e("span", null, "Plugins", -1)),
            l[1] || (l[1] = e("span", null, "/", -1)),
            e("strong", null, d(t.title), 1)
          ])
        ]),
        default: p(() => [
          e("div", null, [
            a(i(s), {
              title: t.title,
              description: "A page rendered from the Hello World plugin's runtime bundle."
            }, null, 8, ["title"]),
            a(i(o), { class: "hw-page-card" }, {
              default: p(() => [
                e("p", T, "Hello, " + d(t.name || "there") + ".", 1),
                l[3] || (l[3] = e("p", { class: "hw-page-text" }, [
                  n(" This route was registered with "),
                  e("code", null, "register_page()"),
                  n(" in the plugin's PHP, and this page was registered with "),
                  e("code", null, "plugin.registerPage()"),
                  n(" in its pre-built bundle. ")
                ], -1)),
                t.productCount !== null ? (c(), u("p", k, " Your organization has " + d(t.productCount) + " products. ", 1)) : m("", !0),
                e("p", x, [
                  a(i(g), { href: "/dashboard" }, {
                    default: p(() => [...l[2] || (l[2] = [
                      n("Back to the dashboard", -1)
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
}, P = /* @__PURE__ */ f(W, [["__scopeId", "data-v-4d8c67c4"]]), E = { class: "hw-tab" }, I = { class: "hw-tab__title" }, M = {
  __name: "HelloWorldTab",
  props: {
    sku: { type: String, default: "" }
  },
  setup(t) {
    return (r, s) => (c(), u("div", E, [
      e("p", I, "Hello, " + d(t.sku || "product"), 1),
      s[0] || (s[0] = e("p", { class: "hw-tab__text" }, [
        n(" This tab comes from the Hello World plugin. It appears because the plugin placed a component in the product page's "),
        e("code", null, "tabs"),
        n(" slot; the product details stay on the Overview tab. ")
      ], -1))
    ]));
  }
}, V = /* @__PURE__ */ f(M, [["__scopeId", "data-v-05413abb"]]), B = { class: "hw-widget" }, D = { class: "hw-widget__value" }, A = {
  __name: "HelloWorldWidget",
  props: {
    productCount: { type: Number, default: 0 }
  },
  setup(t) {
    const {
      Inertia: { Link: r }
    } = window.Inventoros;
    return (s, o) => (c(), u("div", B, [
      e("p", D, d(t.productCount), 1),
      o[1] || (o[1] = e("p", { class: "hw-widget__label" }, "products say hello", -1)),
      a(i(r), {
        href: "/p/hello-world",
        class: "hw-widget__link"
      }, {
        default: p(() => [...o[0] || (o[0] = [
          n("Open the Hello World page", -1)
        ])]),
        _: 1
      })
    ]));
  }
}, N = /* @__PURE__ */ f(A, [["__scopeId", "data-v-3e72f72c"]]);
function No(t) {
  t.registerComponent("HelloWorldBanner", H), t.registerComponent("HelloWorldWidget", N), t.registerComponent("HelloWorldTab", V), t.registerPage("Hello", P);
}
export {
  No as default
};
