const w = window.Inventoros.Vue, { BaseTransition: Ga, BaseTransitionPropsValidators: Ha, Comment: La, DeprecationTypes: Ra, EffectScope: Ca, ErrorCodes: ja, ErrorTypeStrings: Sa, Fragment: k, KeepAlive: Wa, ReactiveEffect: Aa, Static: Ma, Suspense: xa, Teleport: Ea, Text: Ba, TrackOpTypes: Oa, Transition: Va, TransitionGroup: Ia, TriggerOpTypes: Na, VueElement: Ua, __esModule: qa, assertNumber: Ka, callWithAsyncErrorHandling: Fa, callWithErrorHandling: Qa, camelize: Za, capitalize: Ya, cloneVNode: Ja, compatUtils: Xa, compile: es, computed: y, createApp: ts, createBlock: ns, createCommentVNode: h, createElementBlock: l, createElementVNode: n, createHydrationRenderer: os, createPropsRestProxy: is, createRenderer: as, createSSRApp: ss, createSlots: rs, createStaticVNode: cs, createTextVNode: u, createVNode: c, customRef: ls, defineAsyncComponent: gs, defineComponent: ps, defineCustomElement: ds, defineEmits: us, defineExpose: bs, defineModel: ms, defineOptions: $s, defineProps: hs, defineSSRCustomElement: vs, defineSlots: Ds, devtools: Ps, effect: _s, effectScope: ws, getCurrentInstance: ks, getCurrentScope: ys, getCurrentWatcher: fs, getTransitionRawChildren: zs, guardReactiveProps: Ts, h: Gs, handleError: Hs, hasInjectionContext: Ls, hydrate: Rs, hydrateOnIdle: Cs, hydrateOnInteraction: js, hydrateOnMediaQuery: Ss, hydrateOnVisible: Ws, initCustomFormatter: As, initDirectivesForSSR: Ms, inject: xs, isMemoSame: Es, isProxy: Bs, isReactive: Os, isReadonly: Vs, isRef: Is, isRuntimeOnly: Ns, isShallow: Us, isVNode: qs, markRaw: Ks, mergeDefaults: Fs, mergeModels: Qs, mergeProps: Zs, nextTick: Ys, nodeOps: Js, normalizeClass: Xs, normalizeProps: er, normalizeStyle: tr, onActivated: nr, onBeforeMount: or, onBeforeUnmount: ir, onBeforeUpdate: ar, onDeactivated: sr, onErrorCaptured: rr, onMounted: cr, onRenderTracked: lr, onRenderTriggered: gr, onScopeDispose: pr, onServerPrefetch: dr, onUnmounted: ur, onUpdated: br, onWatcherCleanup: mr, openBlock: g, patchProp: $r, popScopeId: hr, provide: vr, proxyRefs: Dr, pushScopeId: Pr, queuePostFlushCb: _r, reactive: wr, readonly: kr, ref: f, registerRuntimeCompiler: yr, render: fr, renderList: zr, renderSlot: Tr, resolveComponent: Gr, resolveDirective: Hr, resolveDynamicComponent: Lr, resolveFilter: Rr, resolveTransitionHooks: Cr, setBlockTracking: jr, setDevtoolsHook: Sr, setTransitionHooks: Wr, shallowReactive: Ar, shallowReadonly: Mr, shallowRef: xr, ssrContextKey: Er, ssrUtils: Br, stop: Or, toDisplayString: i, toHandlerKey: Vr, toHandlers: Ir, toRaw: Nr, toRef: Ur, toRefs: qr, toValue: Kr, transformVNodeArgs: Fr, triggerRef: Qr, unref: t, useAttrs: Zr, useCssModule: Yr, useCssVars: Jr, useHost: Xr, useId: ec, useModel: tc, useSSRContext: nc, useShadowRoot: oc, useSlots: ic, useTemplateRef: ac, useTransitionState: sc, vModelCheckbox: rc, vModelDynamic: cc, vModelRadio: lc, vModelSelect: gc, vModelText: pc, vShow: dc, version: uc, warn: bc, watch: mc, watchEffect: $c, watchPostEffect: hc, watchSyncEffect: vc, withAsyncContext: Dc, withCtx: p, withDefaults: Pc, withDirectives: _c, withKeys: wc, withMemo: kc, withModifiers: yc, withScopeId: fc } = w, z = "مرحبًا بالعالم", T = "مرحبًا", G = "الإضافات", H = "مرحبًا من إضافة مرحبًا بالعالم", L = "الإضافة نشطة", R = "يُحمّل هذا التنبيه من حزمة الإضافة المبنية مسبقًا، لذا يعمل دون تشغيل npm.", C = "الإدارة من صفحة الإضافات.", j = "الإصدار {version}", S = "تجاهل", W = "مرحبًا، {name}", A = "مرحبًا بالمنتج", M = "تأتي علامة التبويب هذه من إضافة مرحبًا بالعالم، التي وضعت مكوّنًا في موضع {slot} بصفحة المنتج؛ وتبقى تفاصيل المنتج في علامة نظرة عامة.", x = "المنتج يرحب بك | المنتجات ترحب بك", E = "فتح صفحة مرحبًا بالعالم", B = "صفحة تُعرض من حزمة إضافة مرحبًا بالعالم.", O = "مرحبًا، {name}.", V = "مرحبًا بك.", I = "سُجّل هذا المسار باستخدام {phpRegistration} في شيفرة PHP للإضافة، وسُجّلت الصفحة باستخدام {bundleRegistration} في الحزمة المبنية مسبقًا.", N = "عدد منتجات مؤسستك: {total}. | عدد منتجات مؤسستك: {total}.", U = "العودة إلى لوحة التحكم", q = {
  title: z,
  tabLabel: T,
  plugins: G,
  bannerTitle: H,
  pluginActive: L,
  bannerDescription: R,
  managePlugins: C,
  version: j,
  dismiss: S,
  greeting: W,
  productGreeting: A,
  tabDescription: M,
  widgetLabel: x,
  openPage: E,
  pageDescription: B,
  pageGreeting: O,
  anonymousGreeting: V,
  registrationDescription: I,
  organizationProducts: N,
  backToDashboard: U
}, K = "Hallo Welt", F = "Hallo", Q = "Plugins", Z = "Hallo vom Hallo-Welt-Plugin", Y = "Plugin aktiv", J = "Dieser Hinweis wird aus dem vorgefertigten Plugin-Paket geladen und funktioniert auch ohne npm.", X = "Auf der Plugin-Seite verwalten.", ee = "Version {version}", te = "Schließen", ne = "Hallo, {name}", oe = "Hallo, Produkt", ie = "Dieser Tab stammt vom Hallo-Welt-Plugin. Das Plugin hat eine Komponente im Bereich {slot} der Produktseite platziert; die Produktdetails bleiben im Tab Übersicht.", ae = "ein Produkt sagt Hallo | Produkte sagen Hallo", se = "Hallo-Welt-Seite öffnen", re = "Eine Seite aus dem Laufzeitpaket des Hallo-Welt-Plugins.", ce = "Hallo, {name}.", le = "Hallo.", ge = "Diese Route wurde mit {phpRegistration} im PHP-Code des Plugins registriert und diese Seite mit {bundleRegistration} im vorgefertigten Paket.", pe = "Ihre Organisation hat {total} Produkt. | Ihre Organisation hat {total} Produkte.", de = "Zurück zum Dashboard", ue = {
  title: K,
  tabLabel: F,
  plugins: Q,
  bannerTitle: Z,
  pluginActive: Y,
  bannerDescription: J,
  managePlugins: X,
  version: ee,
  dismiss: te,
  greeting: ne,
  productGreeting: oe,
  tabDescription: ie,
  widgetLabel: ae,
  openPage: se,
  pageDescription: re,
  pageGreeting: ce,
  anonymousGreeting: le,
  registrationDescription: ge,
  organizationProducts: pe,
  backToDashboard: de
}, be = "Hello World", me = "Hello", $e = "Plugins", he = "Hello from the Hello World plugin", ve = "Plugin active", De = "This banner was loaded at runtime from the plugin's own pre-built bundle, so it works on installs that never run npm.", Pe = "Manage on the Plugins page.", _e = "Version {version}", we = "Dismiss", ke = "Hello, {name}", ye = "Hello, product", fe = "This tab comes from the Hello World plugin. It appears because the plugin placed a component in the product page's {slot} slot; the product details stay on the Overview tab.", ze = "product says hello | products say hello", Te = "Open the Hello World page", Ge = "A page rendered from the Hello World plugin's runtime bundle.", He = "Hello, {name}.", Le = "Hello, there.", Re = "This route was registered with {phpRegistration} in the plugin's PHP, and this page was registered with {bundleRegistration} in its pre-built bundle.", Ce = "Your organization has {total} product. | Your organization has {total} products.", je = "Back to the dashboard", Se = {
  title: be,
  tabLabel: me,
  plugins: $e,
  bannerTitle: he,
  pluginActive: ve,
  bannerDescription: De,
  managePlugins: Pe,
  version: _e,
  dismiss: we,
  greeting: ke,
  productGreeting: ye,
  tabDescription: fe,
  widgetLabel: ze,
  openPage: Te,
  pageDescription: Ge,
  pageGreeting: He,
  anonymousGreeting: Le,
  registrationDescription: Re,
  organizationProducts: Ce,
  backToDashboard: je
}, We = "Hola Mundo", Ae = "Hola", Me = "Plugins", xe = "Hola desde el complemento Hola Mundo", Ee = "Complemento activo", Be = "Este aviso se carga desde el paquete precompilado del complemento, por lo que funciona sin ejecutar npm.", Oe = "Gestionar en la página de complementos.", Ve = "Versión {version}", Ie = "Descartar", Ne = "Hola, {name}", Ue = "Hola, producto", qe = "Esta pestaña pertenece al complemento Hola Mundo. Aparece porque el complemento colocó un componente en el espacio {slot} de la página del producto; los detalles siguen en la pestaña Resumen.", Ke = "un producto te saluda | los productos te saludan", Fe = "Abrir la página de Hola Mundo", Qe = "Una página renderizada desde el paquete del complemento Hola Mundo.", Ze = "Hola, {name}.", Ye = "Hola.", Je = "Esta ruta se registró con {phpRegistration} en el PHP del complemento y esta página con {bundleRegistration} en su paquete precompilado.", Xe = "Tu organización tiene {total} producto. | Tu organización tiene {total} productos.", et = "Volver al panel", tt = {
  title: We,
  tabLabel: Ae,
  plugins: Me,
  bannerTitle: xe,
  pluginActive: Ee,
  bannerDescription: Be,
  managePlugins: Oe,
  version: Ve,
  dismiss: Ie,
  greeting: Ne,
  productGreeting: Ue,
  tabDescription: qe,
  widgetLabel: Ke,
  openPage: Fe,
  pageDescription: Qe,
  pageGreeting: Ze,
  anonymousGreeting: Ye,
  registrationDescription: Je,
  organizationProducts: Xe,
  backToDashboard: et
}, nt = "Bonjour le monde", ot = "Bonjour", it = "Plugins", at = "Bonjour depuis l’extension Bonjour le monde", st = "Extension active", rt = "Cette bannière est chargée depuis le paquet précompilé de l’extension et fonctionne sans exécuter npm.", ct = "Gérer dans la page Extensions.", lt = "Version {version}", gt = "Fermer", pt = "Bonjour, {name}", dt = "Bonjour, produit", ut = "Cet onglet provient de l’extension Bonjour le monde. L’extension a placé un composant dans l’emplacement {slot} de la page produit ; les détails restent dans l’onglet Vue d’ensemble.", bt = "un produit vous salue | les produits vous saluent", mt = "Ouvrir la page Bonjour le monde", $t = "Une page affichée depuis le paquet de l’extension Bonjour le monde.", ht = "Bonjour, {name}.", vt = "Bonjour.", Dt = "Cette route a été enregistrée avec {phpRegistration} dans le PHP de l’extension, et cette page avec {bundleRegistration} dans son paquet précompilé.", Pt = "Votre organisation possède {total} produit. | Votre organisation possède {total} produits.", _t = "Retour au tableau de bord", wt = {
  title: nt,
  tabLabel: ot,
  plugins: it,
  bannerTitle: at,
  pluginActive: st,
  bannerDescription: rt,
  managePlugins: ct,
  version: lt,
  dismiss: gt,
  greeting: pt,
  productGreeting: dt,
  tabDescription: ut,
  widgetLabel: bt,
  openPage: mt,
  pageDescription: $t,
  pageGreeting: ht,
  anonymousGreeting: vt,
  registrationDescription: Dt,
  organizationProducts: Pt,
  backToDashboard: _t
}, kt = "Ciao mondo", yt = "Ciao", ft = "Plugin", zt = "Ciao dal plugin Ciao mondo", Tt = "Plugin attivo", Gt = "Questo avviso viene caricato dal pacchetto precompilato del plugin e funziona senza eseguire npm.", Ht = "Gestisci nella pagina Plugin.", Lt = "Versione {version}", Rt = "Chiudi", Ct = "Ciao, {name}", jt = "Ciao, prodotto", St = "Questa scheda proviene dal plugin Ciao mondo. Compare perché il plugin ha inserito un componente nello spazio {slot} della pagina prodotto; i dettagli restano nella scheda Panoramica.", Wt = "un prodotto ti saluta | i prodotti ti salutano", At = "Apri la pagina Ciao mondo", Mt = "Una pagina visualizzata dal pacchetto del plugin Ciao mondo.", xt = "Ciao, {name}.", Et = "Ciao.", Bt = "Questa rotta è stata registrata con {phpRegistration} nel PHP del plugin e questa pagina con {bundleRegistration} nel pacchetto precompilato.", Ot = "La tua organizzazione ha {total} prodotto. | La tua organizzazione ha {total} prodotti.", Vt = "Torna alla dashboard", It = {
  title: kt,
  tabLabel: yt,
  plugins: ft,
  bannerTitle: zt,
  pluginActive: Tt,
  bannerDescription: Gt,
  managePlugins: Ht,
  version: Lt,
  dismiss: Rt,
  greeting: Ct,
  productGreeting: jt,
  tabDescription: St,
  widgetLabel: Wt,
  openPage: At,
  pageDescription: Mt,
  pageGreeting: xt,
  anonymousGreeting: Et,
  registrationDescription: Bt,
  organizationProducts: Ot,
  backToDashboard: Vt
}, Nt = "ハローワールド", Ut = "こんにちは", qt = "プラグイン", Kt = "ハローワールドプラグインからこんにちは", Ft = "プラグイン有効", Qt = "このバナーはプラグインのビルド済みパッケージから読み込まれるため、npmを実行しない環境でも動作します。", Zt = "プラグインページで管理。", Yt = "バージョン {version}", Jt = "閉じる", Xt = "こんにちは、{name}", en = "こんにちは、商品", tn = "このタブはハローワールドプラグインが追加したものです。商品ページの {slot} スロットにコンポーネントを配置しています。商品情報は概要タブにあります。", nn = "商品からこんにちは | 商品からこんにちは", on = "ハローワールドページを開く", an = "ハローワールドプラグインのパッケージから表示されるページです。", sn = "こんにちは、{name}。", rn = "こんにちは。", cn = "このルートはプラグインのPHPで {phpRegistration} を使い、このページはビルド済みパッケージで {bundleRegistration} を使って登録されています。", ln = "組織の商品数は{total}件です。 | 組織の商品数は{total}件です。", gn = "ダッシュボードに戻る", pn = {
  title: Nt,
  tabLabel: Ut,
  plugins: qt,
  bannerTitle: Kt,
  pluginActive: Ft,
  bannerDescription: Qt,
  managePlugins: Zt,
  version: Yt,
  dismiss: Jt,
  greeting: Xt,
  productGreeting: en,
  tabDescription: tn,
  widgetLabel: nn,
  openPage: on,
  pageDescription: an,
  pageGreeting: sn,
  anonymousGreeting: rn,
  registrationDescription: cn,
  organizationProducts: ln,
  backToDashboard: gn
}, dn = "헬로 월드", un = "안녕하세요", bn = "플러그인", mn = "헬로 월드 플러그인에서 인사합니다", $n = "플러그인 활성화됨", hn = "이 배너는 플러그인의 사전 빌드 패키지에서 로드되므로 npm을 실행하지 않는 환경에서도 작동합니다.", vn = "플러그인 페이지에서 관리하세요.", Dn = "버전 {version}", Pn = "닫기", _n = "안녕하세요, {name}", wn = "안녕하세요, 상품", kn = "이 탭은 헬로 월드 플러그인이 상품 페이지의 {slot} 슬롯에 구성 요소를 배치하여 표시됩니다. 상품 정보는 개요 탭에 있습니다.", yn = "상품이 인사합니다 | 상품들이 인사합니다", fn = "헬로 월드 페이지 열기", zn = "헬로 월드 플러그인 패키지에서 표시되는 페이지입니다.", Tn = "안녕하세요, {name}.", Gn = "안녕하세요.", Hn = "이 경로는 플러그인의 PHP에서 {phpRegistration}으로, 이 페이지는 사전 빌드 패키지에서 {bundleRegistration}으로 등록되었습니다.", Ln = "조직에 상품이 {total}개 있습니다. | 조직에 상품이 {total}개 있습니다.", Rn = "대시보드로 돌아가기", Cn = {
  title: dn,
  tabLabel: un,
  plugins: bn,
  bannerTitle: mn,
  pluginActive: $n,
  bannerDescription: hn,
  managePlugins: vn,
  version: Dn,
  dismiss: Pn,
  greeting: _n,
  productGreeting: wn,
  tabDescription: kn,
  widgetLabel: yn,
  openPage: fn,
  pageDescription: zn,
  pageGreeting: Tn,
  anonymousGreeting: Gn,
  registrationDescription: Hn,
  organizationProducts: Ln,
  backToDashboard: Rn
}, jn = "Hallo wereld", Sn = "Hallo", Wn = "Plugins", An = "Hallo van de plug-in Hallo wereld", Mn = "Plug-in actief", xn = "Deze melding wordt geladen uit het vooraf gebouwde pakket van de plug-in en werkt zonder npm uit te voeren.", En = "Beheren op de pagina Plug-ins.", Bn = "Versie {version}", On = "Sluiten", Vn = "Hallo, {name}", In = "Hallo, product", Nn = "Dit tabblad komt van de plug-in Hallo wereld. De plug-in heeft een onderdeel in de {slot}-positie van de productpagina geplaatst; de productgegevens blijven op Overzicht staan.", Un = "een product zegt hallo | producten zeggen hallo", qn = "De pagina Hallo wereld openen", Kn = "Een pagina uit het runtimepakket van de plug-in Hallo wereld.", Fn = "Hallo, {name}.", Qn = "Hallo.", Zn = "Deze route is geregistreerd met {phpRegistration} in de PHP-code van de plug-in en deze pagina met {bundleRegistration} in het vooraf gebouwde pakket.", Yn = "Uw organisatie heeft {total} product. | Uw organisatie heeft {total} producten.", Jn = "Terug naar het dashboard", Xn = {
  title: jn,
  tabLabel: Sn,
  plugins: Wn,
  bannerTitle: An,
  pluginActive: Mn,
  bannerDescription: xn,
  managePlugins: En,
  version: Bn,
  dismiss: On,
  greeting: Vn,
  productGreeting: In,
  tabDescription: Nn,
  widgetLabel: Un,
  openPage: qn,
  pageDescription: Kn,
  pageGreeting: Fn,
  anonymousGreeting: Qn,
  registrationDescription: Zn,
  organizationProducts: Yn,
  backToDashboard: Jn
}, eo = "Witaj, świecie", to = "Witaj", no = "Wtyczki", oo = "Witaj z wtyczki „Witaj, świecie”", io = "Wtyczka aktywna", ao = "Ten baner jest ładowany z gotowego pakietu wtyczki i działa bez uruchamiania npm.", so = "Zarządzaj na stronie wtyczek.", ro = "Wersja {version}", co = "Zamknij", lo = "Witaj, {name}", go = "Witaj, produkcie", po = "Ta karta pochodzi z wtyczki „Witaj, świecie”. Wtyczka umieściła komponent w slocie {slot} strony produktu; dane produktu pozostają na karcie Przegląd.", uo = "produkt mówi cześć | produkty mówią cześć", bo = "Otwórz stronę „Witaj, świecie”", mo = "Strona wyświetlana z pakietu wtyczki „Witaj, świecie”.", $o = "Witaj, {name}.", ho = "Witaj.", vo = "Ta trasa została zarejestrowana przez {phpRegistration} w kodzie PHP wtyczki, a strona przez {bundleRegistration} w gotowym pakiecie.", Do = "Liczba produktów w organizacji: {total}. | Liczba produktów w organizacji: {total}.", Po = "Wróć do pulpitu", _o = {
  title: eo,
  tabLabel: to,
  plugins: no,
  bannerTitle: oo,
  pluginActive: io,
  bannerDescription: ao,
  managePlugins: so,
  version: ro,
  dismiss: co,
  greeting: lo,
  productGreeting: go,
  tabDescription: po,
  widgetLabel: uo,
  openPage: bo,
  pageDescription: mo,
  pageGreeting: $o,
  anonymousGreeting: ho,
  registrationDescription: vo,
  organizationProducts: Do,
  backToDashboard: Po
}, wo = "Olá Mundo", ko = "Olá", yo = "Plugins", fo = "Olá do plugin Olá Mundo", zo = "Plugin ativo", To = "Este aviso é carregado do pacote pré-compilado do plugin e funciona sem executar npm.", Go = "Gerenciar na página de plugins.", Ho = "Versão {version}", Lo = "Dispensar", Ro = "Olá, {name}", Co = "Olá, produto", jo = "Esta aba vem do plugin Olá Mundo. Ela aparece porque o plugin adicionou um componente ao espaço {slot} da página do produto; os detalhes ficam na aba Visão geral.", So = "um produto manda um olá | os produtos mandam um olá", Wo = "Abrir a página Olá Mundo", Ao = "Uma página renderizada pelo pacote do plugin Olá Mundo.", Mo = "Olá, {name}.", xo = "Olá.", Eo = "Esta rota foi registrada com {phpRegistration} no PHP do plugin, e esta página com {bundleRegistration} no pacote pré-compilado.", Bo = "Sua organização tem {total} produto. | Sua organização tem {total} produtos.", Oo = "Voltar ao painel", Vo = {
  title: wo,
  tabLabel: ko,
  plugins: yo,
  bannerTitle: fo,
  pluginActive: zo,
  bannerDescription: To,
  managePlugins: Go,
  version: Ho,
  dismiss: Lo,
  greeting: Ro,
  productGreeting: Co,
  tabDescription: jo,
  widgetLabel: So,
  openPage: Wo,
  pageDescription: Ao,
  pageGreeting: Mo,
  anonymousGreeting: xo,
  registrationDescription: Eo,
  organizationProducts: Bo,
  backToDashboard: Oo
}, Io = "Привет, мир", No = "Привет", Uo = "Плагины", qo = "Привет от плагина «Привет, мир»", Ko = "Плагин активен", Fo = "Этот баннер загружается из готового пакета плагина и работает без запуска npm.", Qo = "Настроить на странице плагинов.", Zo = "Версия {version}", Yo = "Закрыть", Jo = "Привет, {name}", Xo = "Привет, товар", ei = "Эта вкладка добавлена плагином «Привет, мир» через слот {slot} страницы товара; сведения о товаре остаются на вкладке «Обзор».", ti = "товар передаёт привет | товары передают привет", ni = "Открыть страницу «Привет, мир»", oi = "Страница из пакета плагина «Привет, мир».", ii = "Привет, {name}.", ai = "Здравствуйте.", si = "Этот маршрут зарегистрирован через {phpRegistration} в PHP-коде плагина, а страница — через {bundleRegistration} в готовом пакете.", ri = "Товаров в вашей организации: {total}. | Товаров в вашей организации: {total}.", ci = "Назад к панели управления", li = {
  title: Io,
  tabLabel: No,
  plugins: Uo,
  bannerTitle: qo,
  pluginActive: Ko,
  bannerDescription: Fo,
  managePlugins: Qo,
  version: Zo,
  dismiss: Yo,
  greeting: Jo,
  productGreeting: Xo,
  tabDescription: ei,
  widgetLabel: ti,
  openPage: ni,
  pageDescription: oi,
  pageGreeting: ii,
  anonymousGreeting: ai,
  registrationDescription: si,
  organizationProducts: ri,
  backToDashboard: ci
}, gi = "Merhaba Dünya", pi = "Merhaba", di = "Eklentiler", ui = "Merhaba Dünya eklentisinden merhaba", bi = "Eklenti etkin", mi = "Bu bildirim eklentinin önceden derlenmiş paketinden yüklenir ve npm çalıştırmadan kullanılabilir.", $i = "Eklentiler sayfasında yönetin.", hi = "Sürüm {version}", vi = "Kapat", Di = "Merhaba, {name}", Pi = "Merhaba, ürün", _i = "Bu sekme Merhaba Dünya eklentisinden gelir. Eklenti, ürün sayfasının {slot} alanına bir bileşen yerleştirmiştir; ürün bilgileri Genel Bakış sekmesinde kalır.", wi = "ürün merhaba diyor | ürünler merhaba diyor", ki = "Merhaba Dünya sayfasını aç", yi = "Merhaba Dünya eklentisinin paketinden oluşturulan bir sayfa.", fi = "Merhaba, {name}.", zi = "Merhaba.", Ti = "Bu rota eklentinin PHP kodunda {phpRegistration} ile, bu sayfa ise önceden derlenmiş paketinde {bundleRegistration} ile kaydedildi.", Gi = "Kuruluşunuzda {total} ürün var. | Kuruluşunuzda {total} ürün var.", Hi = "Panoya dön", Li = {
  title: gi,
  tabLabel: pi,
  plugins: di,
  bannerTitle: ui,
  pluginActive: bi,
  bannerDescription: mi,
  managePlugins: $i,
  version: hi,
  dismiss: vi,
  greeting: Di,
  productGreeting: Pi,
  tabDescription: _i,
  widgetLabel: wi,
  openPage: ki,
  pageDescription: yi,
  pageGreeting: fi,
  anonymousGreeting: zi,
  registrationDescription: Ti,
  organizationProducts: Gi,
  backToDashboard: Hi
}, Ri = "你好世界", Ci = "你好", ji = "插件", Si = "你好世界插件向你问好", Wi = "插件已启用", Ai = "此横幅从插件的预构建包加载，因此无需运行 npm 即可使用。", Mi = "在插件页面中管理。", xi = "版本 {version}", Ei = "关闭", Bi = "你好，{name}", Oi = "你好，商品", Vi = "此标签页由你好世界插件提供。插件在商品页面的 {slot} 插槽放置了组件；商品详情仍在概览标签页中。", Ii = "商品向你问好 | 商品向你问好", Ni = "打开你好世界页面", Ui = "从你好世界插件包呈现的页面。", qi = "你好，{name}。", Ki = "你好。", Fi = "此路由通过插件 PHP 中的 {phpRegistration} 注册，此页面通过预构建包中的 {bundleRegistration} 注册。", Qi = "你的组织有 {total} 件商品。 | 你的组织有 {total} 件商品。", Zi = "返回仪表盘", Yi = {
  title: Ri,
  tabLabel: Ci,
  plugins: ji,
  bannerTitle: Si,
  pluginActive: Wi,
  bannerDescription: Ai,
  managePlugins: Mi,
  version: xi,
  dismiss: Ei,
  greeting: Bi,
  productGreeting: Oi,
  tabDescription: Vi,
  widgetLabel: Ii,
  openPage: Ni,
  pageDescription: Ui,
  pageGreeting: qi,
  anonymousGreeting: Ki,
  registrationDescription: Fi,
  organizationProducts: Qi,
  backToDashboard: Zi
}, Ji = /* @__PURE__ */ Object.assign({ "./locales/ar.json": q, "./locales/de.json": ue, "./locales/en.json": Se, "./locales/es.json": tt, "./locales/fr.json": wt, "./locales/it.json": It, "./locales/ja.json": pn, "./locales/ko.json": Cn, "./locales/nl.json": Xn, "./locales/pl.json": _o, "./locales/pt-BR.json": Vo, "./locales/ru.json": li, "./locales/tr.json": Li, "./locales/zh-CN.json": Yi });
let b;
function Xi(e) {
  b = e.i18n;
  for (const [a, s] of Object.entries(Ji))
    b.addMessages(a.split("/").pop().replace(".json", ""), s);
}
const o = (e, a = {}) => b.t(e, a), P = (e) => new Intl.NumberFormat(b.locale.value).format(e), m = (e, a) => {
  const s = e.__vccOpts || e;
  for (const [r, d] of a)
    s[r] = d;
  return s;
}, ea = {
  key: 0,
  class: "hw-banner",
  role: "status"
}, ta = { class: "hw-banner__body" }, na = { class: "hw-banner__title" }, oa = { class: "hw-banner__badge" }, ia = { class: "hw-banner__text" }, aa = { href: "/plugins" }, sa = {
  key: 0,
  class: "hw-banner__meta"
}, ra = ["aria-label"], ca = {
  __name: "HelloWorldBanner",
  props: {
    version: {
      type: String,
      default: ""
    }
  },
  setup(e) {
    const a = f(!1);
    return (s, r) => a.value ? h("", !0) : (g(), l("div", ea, [
      n("div", ta, [
        n("p", na, [
          u(i(t(o)("bannerTitle")) + " ", 1),
          n("span", oa, i(t(o)("pluginActive")), 1)
        ]),
        n("p", ia, [
          u(i(t(o)("bannerDescription")) + " ", 1),
          n("a", aa, i(t(o)("managePlugins")), 1)
        ]),
        e.version ? (g(), l("p", sa, i(t(o)("version", { version: e.version })), 1)) : h("", !0)
      ]),
      n("button", {
        type: "button",
        class: "hw-banner__dismiss",
        "aria-label": t(o)("dismiss"),
        onClick: r[0] || (r[0] = (d) => a.value = !0)
      }, [...r[1] || (r[1] = [
        n("svg", {
          viewBox: "0 0 24 24",
          width: "16",
          height: "16",
          fill: "none",
          stroke: "currentColor",
          "stroke-width": "2",
          "aria-hidden": "true"
        }, [
          n("path", {
            "stroke-linecap": "round",
            "stroke-linejoin": "round",
            d: "M6 18L18 6M6 6l12 12"
          })
        ], -1)
      ])], 8, ra)
    ]));
  }
}, la = /* @__PURE__ */ m(ca, [["__scopeId", "data-v-09944957"]]), ga = { class: "hw-crumbs" }, pa = { class: "hw-page-lead" }, da = { class: "hw-page-text" }, ua = {
  key: 0,
  class: "hw-page-text"
}, ba = { class: "hw-page-text" }, ma = {
  __name: "HelloWorldPage",
  props: {
    title: { type: String, default: "Hello World" },
    name: { type: String, default: "" },
    productCount: { type: Number, default: null }
  },
  setup(e) {
    const {
      layouts: { AppLayout: a },
      ui: { PageHeader: s, Card: r },
      Inertia: { Head: d, Link: _ }
    } = window.Inventoros, v = e, $ = y(() => v.title === "Hello World" ? o("title") : v.title);
    return (Ta, D) => (g(), l(k, null, [
      c(t(d), { title: $.value }, null, 8, ["title"]),
      c(t(a), null, {
        header: p(() => [
          n("div", ga, [
            n("span", null, i(t(o)("plugins")), 1),
            D[0] || (D[0] = n("span", null, "/", -1)),
            n("strong", null, i($.value), 1)
          ])
        ]),
        default: p(() => [
          n("div", null, [
            c(t(s), {
              title: $.value,
              description: t(o)("pageDescription")
            }, null, 8, ["title", "description"]),
            c(t(r), { class: "hw-page-card" }, {
              default: p(() => [
                n("p", pa, i(e.name ? t(o)("pageGreeting", { name: e.name }) : t(o)("anonymousGreeting")), 1),
                n("p", da, i(t(o)("registrationDescription", { phpRegistration: "register_page()", bundleRegistration: "plugin.registerPage()" })), 1),
                e.productCount !== null ? (g(), l("p", ua, i(t(o)("organizationProducts", { count: e.productCount, total: t(P)(e.productCount) })), 1)) : h("", !0),
                n("p", ba, [
                  c(t(_), { href: "/dashboard" }, {
                    default: p(() => [
                      u(i(t(o)("backToDashboard")), 1)
                    ]),
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
}, $a = /* @__PURE__ */ m(ma, [["__scopeId", "data-v-6b627745"]]), ha = { class: "hw-tab" }, va = { class: "hw-tab__title" }, Da = { class: "hw-tab__text" }, Pa = {
  __name: "HelloWorldTab",
  props: {
    sku: { type: String, default: "" }
  },
  setup(e) {
    return (a, s) => (g(), l("div", ha, [
      n("p", va, i(e.sku ? t(o)("greeting", { name: e.sku }) : t(o)("productGreeting")), 1),
      n("p", Da, i(t(o)("tabDescription", { slot: "tabs" })), 1)
    ]));
  }
}, _a = /* @__PURE__ */ m(Pa, [["__scopeId", "data-v-d2385bbf"]]), wa = { class: "hw-widget" }, ka = { class: "hw-widget__value" }, ya = { class: "hw-widget__label" }, fa = {
  __name: "HelloWorldWidget",
  props: {
    productCount: { type: Number, default: 0 }
  },
  setup(e) {
    const {
      Inertia: { Link: a }
    } = window.Inventoros;
    return (s, r) => (g(), l("div", wa, [
      n("p", ka, i(t(P)(e.productCount)), 1),
      n("p", ya, i(t(o)("widgetLabel", { count: e.productCount })), 1),
      c(t(a), {
        href: "/p/hello-world",
        class: "hw-widget__link"
      }, {
        default: p(() => [
          u(i(t(o)("openPage")), 1)
        ]),
        _: 1
      })
    ]));
  }
}, za = /* @__PURE__ */ m(fa, [["__scopeId", "data-v-b513be6b"]]);
function zc(e) {
  Xi(e), e.registerComponent("HelloWorldBanner", la), e.registerComponent("HelloWorldWidget", za), e.registerComponent("HelloWorldTab", _a), e.registerPage("Hello", $a);
}
export {
  zc as default
};
