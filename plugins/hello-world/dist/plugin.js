var { BaseTransition: e, BaseTransitionPropsValidators: t, Comment: n, DeprecationTypes: r, EffectScope: i, ErrorCodes: a, ErrorTypeStrings: o, Fragment: s, KeepAlive: c, ReactiveEffect: l, Static: ee, Suspense: te, Teleport: ne, Text: re, TrackOpTypes: ie, Transition: ae, TransitionGroup: oe, TriggerOpTypes: se, VueElement: ce, __esModule: le, assertNumber: ue, callWithAsyncErrorHandling: de, callWithErrorHandling: fe, camelize: pe, capitalize: me, cloneVNode: he, compatUtils: ge, compile: _e, computed: u, createApp: ve, createBlock: ye, createCommentVNode: d, createElementBlock: f, createElementVNode: p, createHydrationRenderer: be, createPropsRestProxy: xe, createRenderer: Se, createSSRApp: Ce, createSlots: we, createStaticVNode: Te, createTextVNode: m, createVNode: h, customRef: Ee, defineAsyncComponent: De, defineComponent: Oe, defineCustomElement: ke, defineEmits: Ae, defineExpose: je, defineModel: Me, defineOptions: Ne, defineProps: Pe, defineSSRCustomElement: Fe, defineSlots: Ie, devtools: g, effect: _, effectScope: Le, getCurrentInstance: Re, getCurrentScope: ze, getCurrentWatcher: Be, getTransitionRawChildren: Ve, guardReactiveProps: He, h: Ue, handleError: We, hasInjectionContext: Ge, hydrate: Ke, hydrateOnIdle: qe, hydrateOnInteraction: Je, hydrateOnMediaQuery: Ye, hydrateOnVisible: Xe, initCustomFormatter: Ze, initDirectivesForSSR: Qe, inject: $e, isMemoSame: et, isProxy: tt, isReactive: nt, isReadonly: rt, isRef: it, isRuntimeOnly: at, isShallow: ot, isVNode: st, markRaw: ct, mergeDefaults: lt, mergeModels: ut, mergeProps: dt, nextTick: ft, nodeOps: pt, normalizeClass: mt, normalizeProps: ht, normalizeStyle: gt, onActivated: _t, onBeforeMount: vt, onBeforeUnmount: yt, onBeforeUpdate: bt, onDeactivated: xt, onErrorCaptured: St, onMounted: Ct, onRenderTracked: wt, onRenderTriggered: Tt, onScopeDispose: Et, onServerPrefetch: Dt, onUnmounted: Ot, onUpdated: kt, onWatcherCleanup: At, openBlock: v, patchProp: jt, popScopeId: Mt, provide: Nt, proxyRefs: Pt, pushScopeId: Ft, queuePostFlushCb: It, reactive: Lt, readonly: Rt, ref: y, registerRuntimeCompiler: zt, render: Bt, renderList: Vt, renderSlot: Ht, resolveComponent: Ut, resolveDirective: Wt, resolveDynamicComponent: Gt, resolveFilter: Kt, resolveTransitionHooks: b, setBlockTracking: qt, setDevtoolsHook: Jt, setTransitionHooks: Yt, shallowReactive: Xt, shallowReadonly: Zt, shallowRef: Qt, ssrContextKey: $t, ssrUtils: en, stop: tn, toDisplayString: x, toHandlerKey: nn, toHandlers: rn, toRaw: an, toRef: on, toRefs: sn, toValue: cn, transformVNodeArgs: ln, triggerRef: un, unref: S, useAttrs: dn, useCssModule: fn, useCssVars: pn, useHost: mn, useId: hn, useModel: gn, useSSRContext: _n, useShadowRoot: vn, useSlots: yn, useTemplateRef: bn, useTransitionState: xn, vModelCheckbox: Sn, vModelDynamic: Cn, vModelRadio: wn, vModelSelect: Tn, vModelText: En, vShow: Dn, version: On, warn: kn, watch: An, watchEffect: jn, watchPostEffect: Mn, watchSyncEffect: Nn, withAsyncContext: Pn, withCtx: C, withDefaults: Fn, withDirectives: In, withKeys: Ln, withMemo: Rn, withModifiers: zn, withScopeId: Bn } = window.Inventoros.Vue, w = /* #__PURE__ */ Object.assign({
	"./locales/ar.json": {
		title: "مرحبًا بالعالم",
		tabLabel: "مرحبًا",
		plugins: "الإضافات",
		bannerTitle: "مرحبًا من إضافة مرحبًا بالعالم",
		pluginActive: "الإضافة نشطة",
		bannerDescription: "يُحمّل هذا التنبيه من حزمة الإضافة المبنية مسبقًا، لذا يعمل دون تشغيل npm.",
		managePlugins: "الإدارة من صفحة الإضافات.",
		version: "الإصدار {version}",
		dismiss: "تجاهل",
		greeting: "مرحبًا، {name}",
		productGreeting: "مرحبًا بالمنتج",
		tabDescription: "تأتي علامة التبويب هذه من إضافة مرحبًا بالعالم، التي وضعت مكوّنًا في موضع {slot} بصفحة المنتج؛ وتبقى تفاصيل المنتج في علامة نظرة عامة.",
		widgetLabel: "المنتج يرحب بك | المنتجات ترحب بك",
		openPage: "فتح صفحة مرحبًا بالعالم",
		pageDescription: "صفحة تُعرض من حزمة إضافة مرحبًا بالعالم.",
		pageGreeting: "مرحبًا، {name}.",
		anonymousGreeting: "مرحبًا بك.",
		registrationDescription: "سُجّل هذا المسار باستخدام {phpRegistration} في شيفرة PHP للإضافة، وسُجّلت الصفحة باستخدام {bundleRegistration} في الحزمة المبنية مسبقًا.",
		organizationProducts: "عدد منتجات مؤسستك: {total}. | عدد منتجات مؤسستك: {total}.",
		backToDashboard: "العودة إلى لوحة التحكم"
	},
	"./locales/de.json": {
		title: "Hallo Welt",
		tabLabel: "Hallo",
		plugins: "Plugins",
		bannerTitle: "Hallo vom Hallo-Welt-Plugin",
		pluginActive: "Plugin aktiv",
		bannerDescription: "Dieser Hinweis wird aus dem vorgefertigten Plugin-Paket geladen und funktioniert auch ohne npm.",
		managePlugins: "Auf der Plugin-Seite verwalten.",
		version: "Version {version}",
		dismiss: "Schließen",
		greeting: "Hallo, {name}",
		productGreeting: "Hallo, Produkt",
		tabDescription: "Dieser Tab stammt vom Hallo-Welt-Plugin. Das Plugin hat eine Komponente im Bereich {slot} der Produktseite platziert; die Produktdetails bleiben im Tab Übersicht.",
		widgetLabel: "ein Produkt sagt Hallo | Produkte sagen Hallo",
		openPage: "Hallo-Welt-Seite öffnen",
		pageDescription: "Eine Seite aus dem Laufzeitpaket des Hallo-Welt-Plugins.",
		pageGreeting: "Hallo, {name}.",
		anonymousGreeting: "Hallo.",
		registrationDescription: "Diese Route wurde mit {phpRegistration} im PHP-Code des Plugins registriert und diese Seite mit {bundleRegistration} im vorgefertigten Paket.",
		organizationProducts: "Ihre Organisation hat {total} Produkt. | Ihre Organisation hat {total} Produkte.",
		backToDashboard: "Zurück zum Dashboard"
	},
	"./locales/en.json": {
		title: "Hello World",
		tabLabel: "Hello",
		plugins: "Plugins",
		bannerTitle: "Hello from the Hello World plugin",
		pluginActive: "Plugin active",
		bannerDescription: "This banner was loaded at runtime from the plugin's own pre-built bundle, so it works on installs that never run npm.",
		managePlugins: "Manage on the Plugins page.",
		version: "Version {version}",
		dismiss: "Dismiss",
		greeting: "Hello, {name}",
		productGreeting: "Hello, product",
		tabDescription: "This tab comes from the Hello World plugin. It appears because the plugin placed a component in the product page's {slot} slot; the product details stay on the Overview tab.",
		widgetLabel: "product says hello | products say hello",
		openPage: "Open the Hello World page",
		pageDescription: "A page rendered from the Hello World plugin's runtime bundle.",
		pageGreeting: "Hello, {name}.",
		anonymousGreeting: "Hello, there.",
		registrationDescription: "This route was registered with {phpRegistration} in the plugin's PHP, and this page was registered with {bundleRegistration} in its pre-built bundle.",
		organizationProducts: "Your organization has {total} product. | Your organization has {total} products.",
		backToDashboard: "Back to the dashboard"
	},
	"./locales/es.json": {
		title: "Hola Mundo",
		tabLabel: "Hola",
		plugins: "Plugins",
		bannerTitle: "Hola desde el complemento Hola Mundo",
		pluginActive: "Complemento activo",
		bannerDescription: "Este aviso se carga desde el paquete precompilado del complemento, por lo que funciona sin ejecutar npm.",
		managePlugins: "Gestionar en la página de complementos.",
		version: "Versión {version}",
		dismiss: "Descartar",
		greeting: "Hola, {name}",
		productGreeting: "Hola, producto",
		tabDescription: "Esta pestaña pertenece al complemento Hola Mundo. Aparece porque el complemento colocó un componente en el espacio {slot} de la página del producto; los detalles siguen en la pestaña Resumen.",
		widgetLabel: "un producto te saluda | los productos te saludan",
		openPage: "Abrir la página de Hola Mundo",
		pageDescription: "Una página renderizada desde el paquete del complemento Hola Mundo.",
		pageGreeting: "Hola, {name}.",
		anonymousGreeting: "Hola.",
		registrationDescription: "Esta ruta se registró con {phpRegistration} en el PHP del complemento y esta página con {bundleRegistration} en su paquete precompilado.",
		organizationProducts: "Tu organización tiene {total} producto. | Tu organización tiene {total} productos.",
		backToDashboard: "Volver al panel"
	},
	"./locales/fr.json": {
		title: "Bonjour le monde",
		tabLabel: "Bonjour",
		plugins: "Plugins",
		bannerTitle: "Bonjour depuis l’extension Bonjour le monde",
		pluginActive: "Extension active",
		bannerDescription: "Cette bannière est chargée depuis le paquet précompilé de l’extension et fonctionne sans exécuter npm.",
		managePlugins: "Gérer dans la page Extensions.",
		version: "Version {version}",
		dismiss: "Fermer",
		greeting: "Bonjour, {name}",
		productGreeting: "Bonjour, produit",
		tabDescription: "Cet onglet provient de l’extension Bonjour le monde. L’extension a placé un composant dans l’emplacement {slot} de la page produit ; les détails restent dans l’onglet Vue d’ensemble.",
		widgetLabel: "un produit vous salue | les produits vous saluent",
		openPage: "Ouvrir la page Bonjour le monde",
		pageDescription: "Une page affichée depuis le paquet de l’extension Bonjour le monde.",
		pageGreeting: "Bonjour, {name}.",
		anonymousGreeting: "Bonjour.",
		registrationDescription: "Cette route a été enregistrée avec {phpRegistration} dans le PHP de l’extension, et cette page avec {bundleRegistration} dans son paquet précompilé.",
		organizationProducts: "Votre organisation possède {total} produit. | Votre organisation possède {total} produits.",
		backToDashboard: "Retour au tableau de bord"
	},
	"./locales/it.json": {
		title: "Ciao mondo",
		tabLabel: "Ciao",
		plugins: "Plugin",
		bannerTitle: "Ciao dal plugin Ciao mondo",
		pluginActive: "Plugin attivo",
		bannerDescription: "Questo avviso viene caricato dal pacchetto precompilato del plugin e funziona senza eseguire npm.",
		managePlugins: "Gestisci nella pagina Plugin.",
		version: "Versione {version}",
		dismiss: "Chiudi",
		greeting: "Ciao, {name}",
		productGreeting: "Ciao, prodotto",
		tabDescription: "Questa scheda proviene dal plugin Ciao mondo. Compare perché il plugin ha inserito un componente nello spazio {slot} della pagina prodotto; i dettagli restano nella scheda Panoramica.",
		widgetLabel: "un prodotto ti saluta | i prodotti ti salutano",
		openPage: "Apri la pagina Ciao mondo",
		pageDescription: "Una pagina visualizzata dal pacchetto del plugin Ciao mondo.",
		pageGreeting: "Ciao, {name}.",
		anonymousGreeting: "Ciao.",
		registrationDescription: "Questa rotta è stata registrata con {phpRegistration} nel PHP del plugin e questa pagina con {bundleRegistration} nel pacchetto precompilato.",
		organizationProducts: "La tua organizzazione ha {total} prodotto. | La tua organizzazione ha {total} prodotti.",
		backToDashboard: "Torna alla dashboard"
	},
	"./locales/ja.json": {
		title: "ハローワールド",
		tabLabel: "こんにちは",
		plugins: "プラグイン",
		bannerTitle: "ハローワールドプラグインからこんにちは",
		pluginActive: "プラグイン有効",
		bannerDescription: "このバナーはプラグインのビルド済みパッケージから読み込まれるため、npmを実行しない環境でも動作します。",
		managePlugins: "プラグインページで管理。",
		version: "バージョン {version}",
		dismiss: "閉じる",
		greeting: "こんにちは、{name}",
		productGreeting: "こんにちは、商品",
		tabDescription: "このタブはハローワールドプラグインが追加したものです。商品ページの {slot} スロットにコンポーネントを配置しています。商品情報は概要タブにあります。",
		widgetLabel: "商品からこんにちは | 商品からこんにちは",
		openPage: "ハローワールドページを開く",
		pageDescription: "ハローワールドプラグインのパッケージから表示されるページです。",
		pageGreeting: "こんにちは、{name}。",
		anonymousGreeting: "こんにちは。",
		registrationDescription: "このルートはプラグインのPHPで {phpRegistration} を使い、このページはビルド済みパッケージで {bundleRegistration} を使って登録されています。",
		organizationProducts: "組織の商品数は{total}件です。 | 組織の商品数は{total}件です。",
		backToDashboard: "ダッシュボードに戻る"
	},
	"./locales/ko.json": {
		title: "헬로 월드",
		tabLabel: "안녕하세요",
		plugins: "플러그인",
		bannerTitle: "헬로 월드 플러그인에서 인사합니다",
		pluginActive: "플러그인 활성화됨",
		bannerDescription: "이 배너는 플러그인의 사전 빌드 패키지에서 로드되므로 npm을 실행하지 않는 환경에서도 작동합니다.",
		managePlugins: "플러그인 페이지에서 관리하세요.",
		version: "버전 {version}",
		dismiss: "닫기",
		greeting: "안녕하세요, {name}",
		productGreeting: "안녕하세요, 상품",
		tabDescription: "이 탭은 헬로 월드 플러그인이 상품 페이지의 {slot} 슬롯에 구성 요소를 배치하여 표시됩니다. 상품 정보는 개요 탭에 있습니다.",
		widgetLabel: "상품이 인사합니다 | 상품들이 인사합니다",
		openPage: "헬로 월드 페이지 열기",
		pageDescription: "헬로 월드 플러그인 패키지에서 표시되는 페이지입니다.",
		pageGreeting: "안녕하세요, {name}.",
		anonymousGreeting: "안녕하세요.",
		registrationDescription: "이 경로는 플러그인의 PHP에서 {phpRegistration}으로, 이 페이지는 사전 빌드 패키지에서 {bundleRegistration}으로 등록되었습니다.",
		organizationProducts: "조직에 상품이 {total}개 있습니다. | 조직에 상품이 {total}개 있습니다.",
		backToDashboard: "대시보드로 돌아가기"
	},
	"./locales/nl.json": {
		title: "Hallo wereld",
		tabLabel: "Hallo",
		plugins: "Plugins",
		bannerTitle: "Hallo van de plug-in Hallo wereld",
		pluginActive: "Plug-in actief",
		bannerDescription: "Deze melding wordt geladen uit het vooraf gebouwde pakket van de plug-in en werkt zonder npm uit te voeren.",
		managePlugins: "Beheren op de pagina Plug-ins.",
		version: "Versie {version}",
		dismiss: "Sluiten",
		greeting: "Hallo, {name}",
		productGreeting: "Hallo, product",
		tabDescription: "Dit tabblad komt van de plug-in Hallo wereld. De plug-in heeft een onderdeel in de {slot}-positie van de productpagina geplaatst; de productgegevens blijven op Overzicht staan.",
		widgetLabel: "een product zegt hallo | producten zeggen hallo",
		openPage: "De pagina Hallo wereld openen",
		pageDescription: "Een pagina uit het runtimepakket van de plug-in Hallo wereld.",
		pageGreeting: "Hallo, {name}.",
		anonymousGreeting: "Hallo.",
		registrationDescription: "Deze route is geregistreerd met {phpRegistration} in de PHP-code van de plug-in en deze pagina met {bundleRegistration} in het vooraf gebouwde pakket.",
		organizationProducts: "Uw organisatie heeft {total} product. | Uw organisatie heeft {total} producten.",
		backToDashboard: "Terug naar het dashboard"
	},
	"./locales/pl.json": {
		title: "Witaj, świecie",
		tabLabel: "Witaj",
		plugins: "Wtyczki",
		bannerTitle: "Witaj z wtyczki „Witaj, świecie”",
		pluginActive: "Wtyczka aktywna",
		bannerDescription: "Ten baner jest ładowany z gotowego pakietu wtyczki i działa bez uruchamiania npm.",
		managePlugins: "Zarządzaj na stronie wtyczek.",
		version: "Wersja {version}",
		dismiss: "Zamknij",
		greeting: "Witaj, {name}",
		productGreeting: "Witaj, produkcie",
		tabDescription: "Ta karta pochodzi z wtyczki „Witaj, świecie”. Wtyczka umieściła komponent w slocie {slot} strony produktu; dane produktu pozostają na karcie Przegląd.",
		widgetLabel: "produkt mówi cześć | produkty mówią cześć",
		openPage: "Otwórz stronę „Witaj, świecie”",
		pageDescription: "Strona wyświetlana z pakietu wtyczki „Witaj, świecie”.",
		pageGreeting: "Witaj, {name}.",
		anonymousGreeting: "Witaj.",
		registrationDescription: "Ta trasa została zarejestrowana przez {phpRegistration} w kodzie PHP wtyczki, a strona przez {bundleRegistration} w gotowym pakiecie.",
		organizationProducts: "Liczba produktów w organizacji: {total}. | Liczba produktów w organizacji: {total}.",
		backToDashboard: "Wróć do pulpitu"
	},
	"./locales/pt-BR.json": {
		title: "Olá Mundo",
		tabLabel: "Olá",
		plugins: "Plugins",
		bannerTitle: "Olá do plugin Olá Mundo",
		pluginActive: "Plugin ativo",
		bannerDescription: "Este aviso é carregado do pacote pré-compilado do plugin e funciona sem executar npm.",
		managePlugins: "Gerenciar na página de plugins.",
		version: "Versão {version}",
		dismiss: "Dispensar",
		greeting: "Olá, {name}",
		productGreeting: "Olá, produto",
		tabDescription: "Esta aba vem do plugin Olá Mundo. Ela aparece porque o plugin adicionou um componente ao espaço {slot} da página do produto; os detalhes ficam na aba Visão geral.",
		widgetLabel: "um produto manda um olá | os produtos mandam um olá",
		openPage: "Abrir a página Olá Mundo",
		pageDescription: "Uma página renderizada pelo pacote do plugin Olá Mundo.",
		pageGreeting: "Olá, {name}.",
		anonymousGreeting: "Olá.",
		registrationDescription: "Esta rota foi registrada com {phpRegistration} no PHP do plugin, e esta página com {bundleRegistration} no pacote pré-compilado.",
		organizationProducts: "Sua organização tem {total} produto. | Sua organização tem {total} produtos.",
		backToDashboard: "Voltar ao painel"
	},
	"./locales/ru.json": {
		title: "Привет, мир",
		tabLabel: "Привет",
		plugins: "Плагины",
		bannerTitle: "Привет от плагина «Привет, мир»",
		pluginActive: "Плагин активен",
		bannerDescription: "Этот баннер загружается из готового пакета плагина и работает без запуска npm.",
		managePlugins: "Настроить на странице плагинов.",
		version: "Версия {version}",
		dismiss: "Закрыть",
		greeting: "Привет, {name}",
		productGreeting: "Привет, товар",
		tabDescription: "Эта вкладка добавлена плагином «Привет, мир» через слот {slot} страницы товара; сведения о товаре остаются на вкладке «Обзор».",
		widgetLabel: "товар передаёт привет | товары передают привет",
		openPage: "Открыть страницу «Привет, мир»",
		pageDescription: "Страница из пакета плагина «Привет, мир».",
		pageGreeting: "Привет, {name}.",
		anonymousGreeting: "Здравствуйте.",
		registrationDescription: "Этот маршрут зарегистрирован через {phpRegistration} в PHP-коде плагина, а страница — через {bundleRegistration} в готовом пакете.",
		organizationProducts: "Товаров в вашей организации: {total}. | Товаров в вашей организации: {total}.",
		backToDashboard: "Назад к панели управления"
	},
	"./locales/tr.json": {
		title: "Merhaba Dünya",
		tabLabel: "Merhaba",
		plugins: "Eklentiler",
		bannerTitle: "Merhaba Dünya eklentisinden merhaba",
		pluginActive: "Eklenti etkin",
		bannerDescription: "Bu bildirim eklentinin önceden derlenmiş paketinden yüklenir ve npm çalıştırmadan kullanılabilir.",
		managePlugins: "Eklentiler sayfasında yönetin.",
		version: "Sürüm {version}",
		dismiss: "Kapat",
		greeting: "Merhaba, {name}",
		productGreeting: "Merhaba, ürün",
		tabDescription: "Bu sekme Merhaba Dünya eklentisinden gelir. Eklenti, ürün sayfasının {slot} alanına bir bileşen yerleştirmiştir; ürün bilgileri Genel Bakış sekmesinde kalır.",
		widgetLabel: "ürün merhaba diyor | ürünler merhaba diyor",
		openPage: "Merhaba Dünya sayfasını aç",
		pageDescription: "Merhaba Dünya eklentisinin paketinden oluşturulan bir sayfa.",
		pageGreeting: "Merhaba, {name}.",
		anonymousGreeting: "Merhaba.",
		registrationDescription: "Bu rota eklentinin PHP kodunda {phpRegistration} ile, bu sayfa ise önceden derlenmiş paketinde {bundleRegistration} ile kaydedildi.",
		organizationProducts: "Kuruluşunuzda {total} ürün var. | Kuruluşunuzda {total} ürün var.",
		backToDashboard: "Panoya dön"
	},
	"./locales/zh-CN.json": {
		title: "你好世界",
		tabLabel: "你好",
		plugins: "插件",
		bannerTitle: "你好世界插件向你问好",
		pluginActive: "插件已启用",
		bannerDescription: "此横幅从插件的预构建包加载，因此无需运行 npm 即可使用。",
		managePlugins: "在插件页面中管理。",
		version: "版本 {version}",
		dismiss: "关闭",
		greeting: "你好，{name}",
		productGreeting: "你好，商品",
		tabDescription: "此标签页由你好世界插件提供。插件在商品页面的 {slot} 插槽放置了组件；商品详情仍在概览标签页中。",
		widgetLabel: "商品向你问好 | 商品向你问好",
		openPage: "打开你好世界页面",
		pageDescription: "从你好世界插件包呈现的页面。",
		pageGreeting: "你好，{name}。",
		anonymousGreeting: "你好。",
		registrationDescription: "此路由通过插件 PHP 中的 {phpRegistration} 注册，此页面通过预构建包中的 {bundleRegistration} 注册。",
		organizationProducts: "你的组织有 {total} 件商品。 | 你的组织有 {total} 件商品。",
		backToDashboard: "返回仪表盘"
	}
}), T;
function E(e) {
	T = e.i18n;
	for (let [e, t] of Object.entries(w)) T.addMessages(e.split("/").pop().replace(".json", ""), t);
}
var D = (e, t = {}) => T.t(e, t), O = (e) => new Intl.NumberFormat(T.locale.value).format(e), k = (e, t) => {
	let n = e.__vccOpts || e;
	for (let [e, r] of t) n[e] = r;
	return n;
}, A = {
	key: 0,
	class: "hw-banner",
	role: "status"
}, j = { class: "hw-banner__body" }, M = { class: "hw-banner__title" }, N = { class: "hw-banner__badge" }, P = { class: "hw-banner__text" }, F = { href: "/plugins" }, I = {
	key: 0,
	class: "hw-banner__meta"
}, L = ["aria-label"], R = /*#__PURE__*/ k({
	__name: "HelloWorldBanner",
	props: { version: {
		type: String,
		default: ""
	} },
	setup(e) {
		let t = y(!1);
		return (n, r) => t.value ? d("", !0) : (v(), f("div", A, [p("div", j, [
			p("p", M, [m(x(S(D)("bannerTitle")) + " ", 1), p("span", N, x(S(D)("pluginActive")), 1)]),
			p("p", P, [m(x(S(D)("bannerDescription")) + " ", 1), p("a", F, x(S(D)("managePlugins")), 1)]),
			e.version ? (v(), f("p", I, x(S(D)("version", { version: e.version })), 1)) : d("", !0)
		]), p("button", {
			type: "button",
			class: "hw-banner__dismiss",
			"aria-label": S(D)("dismiss"),
			onClick: r[0] ||= (e) => t.value = !0
		}, [...r[1] ||= [p("svg", {
			viewBox: "0 0 24 24",
			width: "16",
			height: "16",
			fill: "none",
			stroke: "currentColor",
			"stroke-width": "2",
			"aria-hidden": "true"
		}, [p("path", {
			"stroke-linecap": "round",
			"stroke-linejoin": "round",
			d: "M6 18L18 6M6 6l12 12"
		})], -1)]], 8, L)]));
	}
}, [["__scopeId", "data-v-09944957"]]), z = { class: "hw-crumbs" }, B = { class: "hw-page-lead" }, V = { class: "hw-page-text" }, H = {
	key: 0,
	class: "hw-page-text"
}, U = { class: "hw-page-text" }, W = /*#__PURE__*/ k({
	__name: "HelloWorldPage",
	props: {
		title: {
			type: String,
			default: "Hello World"
		},
		name: {
			type: String,
			default: ""
		},
		productCount: {
			type: Number,
			default: null
		}
	},
	setup(e) {
		let { layouts: { AppLayout: t }, ui: { PageHeader: n, Card: r }, Inertia: { Head: i, Link: a } } = window.Inventoros, o = e, c = u(() => o.title === "Hello World" ? D("title") : o.title);
		return (o, l) => (v(), f(s, null, [h(S(i), { title: c.value }, null, 8, ["title"]), h(S(t), null, {
			header: C(() => [p("div", z, [
				p("span", null, x(S(D)("plugins")), 1),
				l[0] ||= p("span", null, "/", -1),
				p("strong", null, x(c.value), 1)
			])]),
			default: C(() => [p("div", null, [h(S(n), {
				title: c.value,
				description: S(D)("pageDescription")
			}, null, 8, ["title", "description"]), h(S(r), { class: "hw-page-card" }, {
				default: C(() => [
					p("p", B, x(e.name ? S(D)("pageGreeting", { name: e.name }) : S(D)("anonymousGreeting")), 1),
					p("p", V, x(S(D)("registrationDescription", {
						phpRegistration: "register_page()",
						bundleRegistration: "plugin.registerPage()"
					})), 1),
					e.productCount === null ? d("", !0) : (v(), f("p", H, x(S(D)("organizationProducts", {
						count: e.productCount,
						total: S(O)(e.productCount)
					})), 1)),
					p("p", U, [h(S(a), { href: "/dashboard" }, {
						default: C(() => [m(x(S(D)("backToDashboard")), 1)]),
						_: 1
					})])
				]),
				_: 1
			})])]),
			_: 1
		})], 64));
	}
}, [["__scopeId", "data-v-6b627745"]]), G = { class: "hw-tab" }, K = { class: "hw-tab__title" }, q = { class: "hw-tab__text" }, J = /*#__PURE__*/ k({
	__name: "HelloWorldTab",
	props: { sku: {
		type: String,
		default: ""
	} },
	setup(e) {
		return (t, n) => (v(), f("div", G, [p("p", K, x(e.sku ? S(D)("greeting", { name: e.sku }) : S(D)("productGreeting")), 1), p("p", q, x(S(D)("tabDescription", { slot: "tabs" })), 1)]));
	}
}, [["__scopeId", "data-v-d2385bbf"]]), Y = { class: "hw-widget" }, X = { class: "hw-widget__value" }, Z = { class: "hw-widget__label" }, Q = /*#__PURE__*/ k({
	__name: "HelloWorldWidget",
	props: { productCount: {
		type: Number,
		default: 0
	} },
	setup(e) {
		let { Inertia: { Link: t } } = window.Inventoros;
		return (n, r) => (v(), f("div", Y, [
			p("p", X, x(S(O)(e.productCount)), 1),
			p("p", Z, x(S(D)("widgetLabel", { count: e.productCount })), 1),
			h(S(t), {
				href: "/p/hello-world",
				class: "hw-widget__link"
			}, {
				default: C(() => [m(x(S(D)("openPage")), 1)]),
				_: 1
			})
		]));
	}
}, [["__scopeId", "data-v-b513be6b"]]);
// plugins/hello-world/ui/src/main.js
function $(e) {
	E(e), e.registerComponent("HelloWorldBanner", R), e.registerComponent("HelloWorldWidget", Q), e.registerComponent("HelloWorldTab", J), e.registerPage("Hello", W);
}
export { $ as default };
