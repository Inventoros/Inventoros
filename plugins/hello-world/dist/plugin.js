const c = window.Inventoros.Vue, { BaseTransition: g, BaseTransitionPropsValidators: S, Comment: C, DeprecationTypes: R, EffectScope: T, ErrorCodes: k, ErrorTypeStrings: _, Fragment: b, KeepAlive: x, ReactiveEffect: E, Static: M, Suspense: V, Teleport: H, Text: B, TrackOpTypes: D, Transition: P, TransitionGroup: I, TriggerOpTypes: A, VueElement: N, __esModule: O, assertNumber: W, callWithAsyncErrorHandling: U, callWithErrorHandling: z, camelize: F, capitalize: K, cloneVNode: j, compatUtils: L, compile: q, computed: G, createApp: Q, createBlock: $, createCommentVNode: a, createElementBlock: l, createElementVNode: t, createHydrationRenderer: J, createPropsRestProxy: X, createRenderer: Y, createSSRApp: Z, createSlots: ee, createStaticVNode: te, createTextVNode: s, createVNode: oe, customRef: ne, defineAsyncComponent: re, defineComponent: se, defineCustomElement: ie, defineEmits: ae, defineExpose: le, defineModel: de, defineOptions: ce, defineProps: ue, defineSSRCustomElement: pe, defineSlots: fe, devtools: me, effect: he, effectScope: ve, getCurrentInstance: we, getCurrentScope: ye, getCurrentWatcher: ge, getTransitionRawChildren: Se, guardReactiveProps: Ce, h: Re, handleError: Te, hasInjectionContext: ke, hydrate: _e, hydrateOnIdle: be, hydrateOnInteraction: xe, hydrateOnMediaQuery: Ee, hydrateOnVisible: Me, initCustomFormatter: Ve, initDirectivesForSSR: He, inject: Be, isMemoSame: De, isProxy: Pe, isReactive: Ie, isReadonly: Ae, isRef: Ne, isRuntimeOnly: Oe, isShallow: We, isVNode: Ue, markRaw: ze, mergeDefaults: Fe, mergeModels: Ke, mergeProps: je, nextTick: Le, normalizeClass: qe, normalizeProps: Ge, normalizeStyle: Qe, onActivated: $e, onBeforeMount: Je, onBeforeUnmount: Xe, onBeforeUpdate: Ye, onDeactivated: Ze, onErrorCaptured: et, onMounted: tt, onRenderTracked: ot, onRenderTriggered: nt, onScopeDispose: rt, onServerPrefetch: st, onUnmounted: it, onUpdated: at, onWatcherCleanup: lt, openBlock: d, popScopeId: dt, provide: ct, proxyRefs: ut, pushScopeId: pt, queuePostFlushCb: ft, reactive: mt, readonly: ht, ref: u, registerRuntimeCompiler: vt, render: wt, renderList: yt, renderSlot: gt, resolveComponent: St, resolveDirective: Ct, resolveDynamicComponent: Rt, resolveFilter: Tt, resolveTransitionHooks: kt, setBlockTracking: _t, setDevtoolsHook: bt, setTransitionHooks: xt, shallowReactive: Et, shallowReadonly: Mt, shallowRef: Vt, ssrContextKey: Ht, ssrUtils: Bt, stop: Dt, toDisplayString: p, toHandlerKey: Pt, toHandlers: It, toRaw: At, toRef: Nt, toRefs: Ot, toValue: Wt, transformVNodeArgs: Ut, triggerRef: zt, unref: Ft, useAttrs: Kt, useCssModule: jt, useCssVars: Lt, useHost: qt, useId: Gt, useModel: Qt, useSSRContext: $t, useShadowRoot: Jt, useSlots: Xt, useTemplateRef: Yt, useTransitionState: Zt, vModelCheckbox: eo, vModelDynamic: to, vModelRadio: oo, vModelSelect: no, vModelText: ro, vShow: so, version: io, warn: ao, watch: lo, watchEffect: co, watchPostEffect: uo, watchSyncEffect: po, withAsyncContext: fo, withCtx: mo, withDefaults: ho, withDirectives: vo, withKeys: wo, withMemo: yo, withModifiers: go, withScopeId: So } = c, f = (o, n) => {
  const r = o.__vccOpts || o;
  for (const [e, i] of n)
    r[e] = i;
  return r;
}, m = {
  key: 0,
  class: "hw-banner",
  role: "status"
}, h = { class: "hw-banner__body" }, v = {
  key: 0,
  class: "hw-banner__meta"
}, w = {
  __name: "HelloWorldBanner",
  props: {
    version: {
      type: String,
      default: ""
    }
  },
  setup(o) {
    const n = u(!1);
    return (r, e) => n.value ? a("", !0) : (d(), l("div", m, [
      t("div", h, [
        e[1] || (e[1] = t("p", { class: "hw-banner__title" }, [
          s(" Hello from the Hello World plugin "),
          t("span", { class: "hw-banner__badge" }, "Plugin active")
        ], -1)),
        e[2] || (e[2] = t("p", { class: "hw-banner__text" }, [
          s(" This banner was loaded at runtime from the plugin's own pre-built bundle, so it works on installs that never run npm. Turn it off on the "),
          t("a", { href: "/plugins" }, "Plugins page"),
          s(". ")
        ], -1)),
        o.version ? (d(), l("p", v, "Version " + p(o.version), 1)) : a("", !0)
      ]),
      t("button", {
        type: "button",
        class: "hw-banner__dismiss",
        "aria-label": "Dismiss",
        onClick: e[0] || (e[0] = (i) => n.value = !0)
      }, [...e[3] || (e[3] = [
        t("svg", {
          viewBox: "0 0 24 24",
          width: "16",
          height: "16",
          fill: "none",
          stroke: "currentColor",
          "stroke-width": "2",
          "aria-hidden": "true"
        }, [
          t("path", {
            "stroke-linecap": "round",
            "stroke-linejoin": "round",
            d: "M6 18L18 6M6 6l12 12"
          })
        ], -1)
      ])])
    ]));
  }
}, y = /* @__PURE__ */ f(w, [["__scopeId", "data-v-5a7f86dc"]]);
function Co(o) {
  o.registerComponent("HelloWorldBanner", y);
}
export {
  Co as default
};
