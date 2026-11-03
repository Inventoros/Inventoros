const D = window.Inventoros.Vue, { BaseTransition: ef, BaseTransitionPropsValidators: of, Comment: tf, DeprecationTypes: nf, EffectScope: sf, ErrorCodes: af, ErrorTypeStrings: rf, Fragment: b, KeepAlive: lf, ReactiveEffect: cf, Static: df, Suspense: uf, Teleport: pf, Text: mf, TrackOpTypes: ff, Transition: gf, TransitionGroup: $f, TriggerOpTypes: Sf, VueElement: Cf, __esModule: yf, assertNumber: vf, callWithAsyncErrorHandling: bf, callWithErrorHandling: Tf, camelize: hf, capitalize: xf, cloneVNode: If, compatUtils: Rf, compile: Gf, computed: A, createApp: kf, createBlock: T, createCommentVNode: v, createElementBlock: u, createElementVNode: o, createHydrationRenderer: wf, createPropsRestProxy: Hf, createRenderer: zf, createSSRApp: Pf, createSlots: Nf, createStaticVNode: _f, createTextVNode: l, createVNode: g, customRef: Df, defineAsyncComponent: Af, defineComponent: Ff, defineCustomElement: Uf, defineEmits: Vf, defineExpose: Of, defineModel: jf, defineOptions: qf, defineProps: Lf, defineSSRCustomElement: Ef, defineSlots: Bf, devtools: Kf, effect: Mf, effectScope: Zf, getCurrentInstance: Wf, getCurrentScope: Jf, getCurrentWatcher: Qf, getTransitionRawChildren: Yf, guardReactiveProps: Xf, h: eg, handleError: og, hasInjectionContext: tg, hydrate: ng, hydrateOnIdle: ig, hydrateOnInteraction: sg, hydrateOnMediaQuery: ag, hydrateOnVisible: rg, initCustomFormatter: lg, initDirectivesForSSR: cg, inject: dg, isMemoSame: ug, isProxy: pg, isReactive: mg, isReadonly: fg, isRef: gg, isRuntimeOnly: $g, isShallow: Sg, isVNode: Cg, markRaw: yg, mergeDefaults: vg, mergeModels: bg, mergeProps: Tg, nextTick: hg, nodeOps: xg, normalizeClass: Ig, normalizeProps: Rg, normalizeStyle: Gg, onActivated: kg, onBeforeMount: wg, onBeforeUnmount: Hg, onBeforeUpdate: zg, onDeactivated: Pg, onErrorCaptured: Ng, onMounted: _g, onRenderTracked: Dg, onRenderTriggered: Ag, onScopeDispose: Fg, onServerPrefetch: Ug, onUnmounted: Vg, onUpdated: Og, onWatcherCleanup: jg, openBlock: c, patchProp: qg, popScopeId: Lg, provide: Eg, proxyRefs: Bg, pushScopeId: Kg, queuePostFlushCb: Mg, reactive: Zg, readonly: Wg, ref: Jg, registerRuntimeCompiler: Qg, render: Yg, renderList: R, renderSlot: Xg, resolveComponent: e$, resolveDirective: o$, resolveDynamicComponent: t$, resolveFilter: n$, resolveTransitionHooks: i$, setBlockTracking: s$, setDevtoolsHook: a$, setTransitionHooks: r$, shallowReactive: l$, shallowReadonly: c$, shallowRef: d$, ssrContextKey: u$, ssrUtils: p$, stop: m$, toDisplayString: a, toHandlerKey: f$, toHandlers: g$, toRaw: $$, toRef: S$, toRefs: C$, toValue: y$, transformVNodeArgs: v$, triggerRef: b$, unref: e, useAttrs: T$, useCssModule: h$, useCssVars: x$, useHost: I$, useId: R$, useModel: G$, useSSRContext: k$, useShadowRoot: w$, useSlots: H$, useTemplateRef: z$, useTransitionState: P$, vModelCheckbox: N, vModelDynamic: N$, vModelRadio: _$, vModelSelect: P, vModelText: p, vShow: D$, version: A$, warn: F$, watch: U$, watchEffect: V$, watchPostEffect: O$, watchSyncEffect: j$, withAsyncContext: q$, withCtx: m, withDefaults: L$, withDirectives: d, withKeys: E$, withMemo: B$, withModifiers: _, withScopeId: K$ } = D, F = "الفوترة الضريبية", U = "بيانات التسجيل الضريبي وعرض الفواتير لهذه المؤسسة.", V = "حدّد المعاملة الضريبية لنشاطك. يجب أن تتطابق ضريبة الطلب المسجلة مع المعدلات المختارة قبل إصدار الفاتورة. لا تختار هذه الوحدة المعدلات ولا تقدم الإقرارات ولا تحصل على إقرارات حكومية بالفواتير الإلكترونية.", O = "الملف", j = "ملف دولة مخصص", q = "GST الهند: توريدات محلية", L = "رمز الدولة (حرفان وفق ISO)", E = "اسم المورد", B = "التسجيل الضريبي للمورد", K = "رمز منطقة/ولاية المورد", M = "تسمية المعرّف الضريبي", Z = "GSTIN، رقم ضريبة القيمة المضافة، TIN", W = "عنوان المورد", J = "خيار المتغير لتجميع المقاسات", Q = "تضمين الشحن في الوعاء الضريبي", Y = "GST الهند", X = "تُطبق CGST وSGST/UTGST عند تطابق رمزي ولاية المورد ومكان التوريد، وإلا تُطبق IGST. أدخل معدل GST الإجمالي. تحتاج الصادرات وSEZ والتكليف العكسي والرسوم الإضافية والفوترة الإلكترونية إلى معالجة خاصة.", ee = "معدل GST الإجمالي (%)", oe = "المكوّن المحلي", te = "مكوّنات الضريبة", ne = "اضبط الضرائب المضافة مثل ضريبة القيمة المضافة أو GST/PST أو ضريبة المبيعات. لا تُؤتمت المعدلات المختلطة والضرائب المركبة وقواعد الإقرار الوطنية.", ie = "اسم المكوّن", se = "المعدل (%)", ae = "إزالة المكوّن", re = "إضافة مكوّن ضريبي", le = "حفظ الملف الضريبي", ce = "فاتورة ضريبية: {number}", de = "راجع البيانات الضريبية قبل الاحتفاظ بالفاتورة الصادرة.", ue = "صدرت في {date} · {total}", pe = "تُحفظ البيانات عند الإصدار. لا تعيد التعديلات اللاحقة على العميل أو الطلب أو الملف الضريبي كتابة هذه الفاتورة.", me = "تنزيل الفاتورة بصيغة PDF", fe = "إجمالي الطلب: {total}. الضريبة المسجلة: {tax}. يجب أن تتوافق مكوّنات الضريبة مع هذا المبلغ. لا يغير الإصدار المخزون أو المدفوعات.", ge = "رمز دولة مكان التوريد", $e = "رمز منطقة/ولاية مكان التوريد", Se = "يغطي هذا الملف التوريدات المحلية في الهند. تحقق من مكان التوريد وGSTIN وHSN/SAC ورموز الوحدات قبل الإصدار.", Ce = "تصنيف البنود", ye = "HSN/SAC (مطلوب)", ve = "HSN/SAC (اختياري)", be = "HSN/SAC للمنتج {sku}", Te = "رمز الوحدة", he = "وحدة المنتج {sku}", xe = "راجعت التسجيل الضريبي ومكان التوريد والتصنيف والضريبة المسجلة.", Ie = "إصدار فاتورة ضريبية", Re = "يجب أن يصدر مستخدم مخوّل الفاتورة الضريبية قبل تنزيلها.", Ge = "أدخل GSTIN للعميل في حقل المعرّف الضريبي/ضريبة القيمة المضافة أعلاه. اتركه فارغًا للعميل غير المسجل.", ke = "استخدم حقل المعرّف الضريبي/ضريبة القيمة المضافة للتسجيل الضريبي المحلي للعميل.", we = "رمز منطقة/ولاية مكان التوريد الافتراضي", He = "منطقة/ولاية مكان التوريد الافتراضية", ze = "فاتورة ضريبية", Pe = { issue: { label: "إصدار الفواتير الضريبية", description: "الفوترة الضريبية" }, manage: { label: "إدارة ملفات الفوترة الضريبية", description: "الفوترة الضريبية" } }, Ne = {
  title: F,
  settingsDescription: U,
  settingsHelp: V,
  profile: O,
  customCountryProfile: j,
  indiaProfile: q,
  countryCode: L,
  supplierName: E,
  supplierTaxRegistration: B,
  supplierRegionCode: K,
  taxIdentifierLabel: M,
  taxIdentifierPlaceholder: Z,
  supplierAddress: W,
  sizeGroupingOption: J,
  shippingTaxable: Q,
  indiaGst: Y,
  indiaHelp: X,
  gstRate: ee,
  localComponent: oe,
  taxComponents: te,
  componentsHelp: ne,
  componentName: ie,
  rate: se,
  removeComponent: ae,
  addComponent: re,
  saveProfile: le,
  invoiceTitle: ce,
  invoiceDescription: de,
  issuedSummary: ue,
  retainedDetails: pe,
  downloadPdf: me,
  orderTaxSummary: fe,
  supplyCountryCode: ge,
  supplyRegionCode: $e,
  domesticHelp: Se,
  lineClassification: Ce,
  hsnRequired: ye,
  hsnOptional: ve,
  hsnFor: be,
  unitCode: Te,
  unitFor: he,
  reviewConfirmation: xe,
  issueInvoice: Ie,
  authorizedUserRequired: Re,
  customerGstHelp: Ge,
  customerTaxHelp: ke,
  defaultSupplyRegionCode: we,
  defaultSupplyRegion: He,
  taxInvoice: ze,
  permissions: Pe
}, _e = "Steuerabrechnung", De = "Steuerliche Registrierungsdaten und Rechnungsdarstellung dieser Organisation.", Ae = "Legen Sie die steuerliche Behandlung Ihres Unternehmens fest. Die erfasste Auftragssteuer muss vor der Rechnungsausstellung den gewählten Sätzen entsprechen. Dieses Modul wählt keine Steuersätze, reicht keine Erklärungen ein und holt keine behördlichen E-Rechnungsbestätigungen ein.", Fe = "Profil", Ue = "Benutzerdefiniertes Länderprofil", Ve = "Indische GST: Inlandslieferungen", Oe = "Ländercode (ISO, 2 Buchstaben)", je = "Lieferantenname", qe = "Steuerregistrierung des Lieferanten", Le = "Regions-/Bundesstaatencode des Lieferanten", Ee = "Bezeichnung der Steuerkennung", Be = "GSTIN, USt-Nummer, TIN", Ke = "Lieferantenadresse", Me = "Variantenoption zur Größengruppierung", Ze = "Versand in die Steuerbemessungsgrundlage einbeziehen", We = "Indische GST", Je = "CGST und SGST/UTGST gelten bei gleichen Bundesstaatencodes von Lieferant und Lieferort; andernfalls gilt IGST. Geben Sie den gesamten GST-Satz ein. Exporte, SEZ, Steuerschuldumkehr, Zusatzabgaben und E-Rechnungen benötigen eine gesonderte Behandlung.", Qe = "Gesamter GST-Satz (%)", Ye = "Lokale Komponente", Xe = "Steuerkomponenten", eo = "Konfigurieren Sie additive Steuern wie USt, GST/PST oder Verkaufssteuer. Gemischte Sätze, zusammengesetzte Steuern und nationale Meldevorschriften werden nicht automatisiert.", oo = "Komponentenname", to = "Satz (%)", no = "Komponente entfernen", io = "Steuerkomponente hinzufügen", so = "Steuerprofil speichern", ao = "Steuerrechnung: {number}", ro = "Prüfen Sie die Steuerdaten, bevor Sie die ausgestellte Rechnung aufbewahren.", lo = "Ausgestellt am {date} · {total}", co = "Diese Daten werden bei Ausstellung festgehalten. Spätere Änderungen an Kunde, Auftrag oder Steuerprofil ändern diese Rechnung nicht.", uo = "Rechnung als PDF herunterladen", po = "Auftragssumme: {total}. Erfasste Steuer: {tax}. Die Steuerkomponenten müssen diesem Betrag entsprechen. Die Ausstellung ändert weder Bestand noch Zahlungen.", mo = "Ländercode des Lieferorts", fo = "Regions-/Bundesstaatencode des Lieferorts", go = "Dieses Profil gilt für Inlandslieferungen in Indien. Prüfen Sie Lieferort, GSTIN, HSN/SAC und Einheitencodes vor der Ausstellung.", $o = "Positionsklassifizierung", So = "HSN/SAC (erforderlich)", Co = "HSN/SAC (optional)", yo = "HSN/SAC für {sku}", vo = "Einheitencode", bo = "Einheit für {sku}", To = "Ich habe Steuerregistrierung, Lieferort, Klassifizierung und erfasste Steuer geprüft.", ho = "Steuerrechnung ausstellen", xo = "Ein berechtigter Benutzer muss die Steuerrechnung vor dem Download ausstellen.", Io = "Tragen Sie die GSTIN des Kunden oben im Feld Steuer-ID/USt ein. Lassen Sie es bei nicht registrierten Kunden leer.", Ro = "Verwenden Sie das Feld Steuer-ID/USt für die lokale Steuerregistrierung des Kunden.", Go = "Standard-Regions-/Bundesstaatencode des Lieferorts", ko = "Standardregion/-bundesstaat des Lieferorts", wo = "Steuerrechnung", Ho = { issue: { label: "Steuerrechnungen ausstellen", description: "Steuerabrechnung" }, manage: { label: "Steuerabrechnungsprofile verwalten", description: "Steuerabrechnung" } }, zo = {
  title: _e,
  settingsDescription: De,
  settingsHelp: Ae,
  profile: Fe,
  customCountryProfile: Ue,
  indiaProfile: Ve,
  countryCode: Oe,
  supplierName: je,
  supplierTaxRegistration: qe,
  supplierRegionCode: Le,
  taxIdentifierLabel: Ee,
  taxIdentifierPlaceholder: Be,
  supplierAddress: Ke,
  sizeGroupingOption: Me,
  shippingTaxable: Ze,
  indiaGst: We,
  indiaHelp: Je,
  gstRate: Qe,
  localComponent: Ye,
  taxComponents: Xe,
  componentsHelp: eo,
  componentName: oo,
  rate: to,
  removeComponent: no,
  addComponent: io,
  saveProfile: so,
  invoiceTitle: ao,
  invoiceDescription: ro,
  issuedSummary: lo,
  retainedDetails: co,
  downloadPdf: uo,
  orderTaxSummary: po,
  supplyCountryCode: mo,
  supplyRegionCode: fo,
  domesticHelp: go,
  lineClassification: $o,
  hsnRequired: So,
  hsnOptional: Co,
  hsnFor: yo,
  unitCode: vo,
  unitFor: bo,
  reviewConfirmation: To,
  issueInvoice: ho,
  authorizedUserRequired: xo,
  customerGstHelp: Io,
  customerTaxHelp: Ro,
  defaultSupplyRegionCode: Go,
  defaultSupplyRegion: ko,
  taxInvoice: wo,
  permissions: Ho
}, Po = "Tax billing", No = "Tax-registration details and invoice presentation for this organization.", _o = "Set the tax treatment that applies to your business. Recorded order tax must match the selected rates before an invoice can be issued. This module does not choose tax rates, file returns or obtain government e-invoice acknowledgements.", Do = "Profile", Ao = "Custom country profile", Fo = "India GST: domestic supplies", Uo = "Country code (ISO 2-letter)", Vo = "Supplier name", Oo = "Supplier tax registration", jo = "Supplier region/state code", qo = "Tax identifier label", Lo = "GSTIN, VAT number, TIN", Eo = "Supplier address", Bo = "Variant option for size grouping", Ko = "Include shipping in the taxable base", Mo = "India GST", Zo = "CGST plus SGST/UTGST applies when supplier and place-of-supply state codes match; IGST is used when they differ. Enter the applicable total GST rate. Exports, SEZ, reverse charge, cess and e-invoicing need dedicated treatment.", Wo = "Total GST rate (%)", Jo = "Local component", Qo = "Tax components", Yo = "Configure additive taxes such as VAT, GST/PST or sales tax. Mixed-rate, compound-tax and country filing rules are not automated.", Xo = "Component name", et = "Rate (%)", ot = "Remove component", tt = "Add tax component", nt = "Save tax billing profile", it = "Tax invoice: {number}", st = "Review tax details before retaining an issued invoice.", at = "Issued {date} · {total}", rt = "These details are retained from issuance. Later edits to the customer, order or tax profile do not rewrite this invoice.", lt = "Download invoice PDF", ct = "Order total: {total}. Recorded tax: {tax}. Invoice tax components must reconcile with this amount. Issuing does not change stock or payments.", dt = "Place-of-supply country code", ut = "Place-of-supply region/state code", pt = "This profile covers domestic India supplies. Check the place of supply, GSTIN, HSN/SAC and unit codes before issuing.", mt = "Line classification", ft = "HSN/SAC (required)", gt = "HSN/SAC (optional)", $t = "HSN/SAC for {sku}", St = "Unit code", Ct = "Unit for {sku}", yt = "I reviewed the tax registration, place of supply, classification and recorded tax.", vt = "Issue tax invoice", bt = "An authorized user must issue the tax invoice before it can be downloaded.", Tt = "Enter the customer GSTIN in the Tax ID/VAT field above. Leave it blank for an unregistered customer.", ht = "Use the Tax ID/VAT field for the customer’s local tax registration.", xt = "Default place-of-supply region/state code", It = "Default place-of-supply region/state", Rt = "Tax invoice", Gt = { issue: { label: "Issue tax invoices", description: "Tax billing" }, manage: { label: "Manage tax billing profiles", description: "Tax billing" } }, kt = {
  title: Po,
  settingsDescription: No,
  settingsHelp: _o,
  profile: Do,
  customCountryProfile: Ao,
  indiaProfile: Fo,
  countryCode: Uo,
  supplierName: Vo,
  supplierTaxRegistration: Oo,
  supplierRegionCode: jo,
  taxIdentifierLabel: qo,
  taxIdentifierPlaceholder: Lo,
  supplierAddress: Eo,
  sizeGroupingOption: Bo,
  shippingTaxable: Ko,
  indiaGst: Mo,
  indiaHelp: Zo,
  gstRate: Wo,
  localComponent: Jo,
  taxComponents: Qo,
  componentsHelp: Yo,
  componentName: Xo,
  rate: et,
  removeComponent: ot,
  addComponent: tt,
  saveProfile: nt,
  invoiceTitle: it,
  invoiceDescription: st,
  issuedSummary: at,
  retainedDetails: rt,
  downloadPdf: lt,
  orderTaxSummary: ct,
  supplyCountryCode: dt,
  supplyRegionCode: ut,
  domesticHelp: pt,
  lineClassification: mt,
  hsnRequired: ft,
  hsnOptional: gt,
  hsnFor: $t,
  unitCode: St,
  unitFor: Ct,
  reviewConfirmation: yt,
  issueInvoice: vt,
  authorizedUserRequired: bt,
  customerGstHelp: Tt,
  customerTaxHelp: ht,
  defaultSupplyRegionCode: xt,
  defaultSupplyRegion: It,
  taxInvoice: Rt,
  permissions: Gt
}, wt = "Facturación fiscal", Ht = "Datos de registro fiscal y presentación de facturas de esta organización.", zt = "Configura el tratamiento fiscal de tu empresa. El impuesto registrado del pedido debe coincidir con las tasas seleccionadas antes de emitir una factura. Este módulo no elige tasas, presenta declaraciones ni obtiene acuses gubernamentales de facturas electrónicas.", Pt = "Perfil", Nt = "Perfil de país personalizado", _t = "GST de India: operaciones nacionales", Dt = "Código de país (ISO de 2 letras)", At = "Nombre del proveedor", Ft = "Registro fiscal del proveedor", Ut = "Código de región/estado del proveedor", Vt = "Etiqueta del identificador fiscal", Ot = "GSTIN, número de IVA, TIN", jt = "Dirección del proveedor", qt = "Opción de variante para agrupar tallas", Lt = "Incluir el envío en la base imponible", Et = "GST de India", Bt = "Se aplica CGST más SGST/UTGST si coinciden los códigos de estado del proveedor y del lugar de suministro; si difieren, se aplica IGST. Introduce la tasa total de GST. Las exportaciones, SEZ, inversión del sujeto pasivo, recargos y facturación electrónica requieren un tratamiento específico.", Kt = "Tasa total de GST (%)", Mt = "Componente local", Zt = "Componentes fiscales", Wt = "Configura impuestos aditivos como IVA, GST/PST o impuestos sobre ventas. No se automatizan tasas mixtas, impuestos compuestos ni normas nacionales de declaración.", Jt = "Nombre del componente", Qt = "Tasa (%)", Yt = "Eliminar componente", Xt = "Añadir componente fiscal", en = "Guardar perfil fiscal", on = "Factura fiscal: {number}", tn = "Revisa los datos fiscales antes de conservar la factura emitida.", nn = "Emitida el {date} · {total}", sn = "Estos datos se conservan desde la emisión. Los cambios posteriores en el cliente, pedido o perfil fiscal no modifican esta factura.", an = "Descargar factura en PDF", rn = "Total del pedido: {total}. Impuesto registrado: {tax}. Los componentes fiscales deben cuadrar con este importe. La emisión no cambia existencias ni pagos.", ln = "Código de país del lugar de suministro", cn = "Código de región/estado del lugar de suministro", dn = "Este perfil cubre operaciones nacionales de India. Comprueba el lugar de suministro, GSTIN, HSN/SAC y códigos de unidad antes de emitir.", un = "Clasificación de líneas", pn = "HSN/SAC (obligatorio)", mn = "HSN/SAC (opcional)", fn = "HSN/SAC de {sku}", gn = "Código de unidad", $n = "Unidad de {sku}", Sn = "He revisado el registro fiscal, lugar de suministro, clasificación e impuesto registrado.", Cn = "Emitir factura fiscal", yn = "Un usuario autorizado debe emitir la factura fiscal antes de descargarla.", vn = "Introduce el GSTIN del cliente en el campo ID fiscal/IVA anterior. Déjalo vacío si el cliente no está registrado.", bn = "Utiliza el campo ID fiscal/IVA para el registro fiscal local del cliente.", Tn = "Código predeterminado de región/estado del suministro", hn = "Región/estado predeterminado del suministro", xn = "Factura fiscal", In = { issue: { label: "Emitir facturas fiscales", description: "Facturación fiscal" }, manage: { label: "Gestionar perfiles fiscales", description: "Facturación fiscal" } }, Rn = {
  title: wt,
  settingsDescription: Ht,
  settingsHelp: zt,
  profile: Pt,
  customCountryProfile: Nt,
  indiaProfile: _t,
  countryCode: Dt,
  supplierName: At,
  supplierTaxRegistration: Ft,
  supplierRegionCode: Ut,
  taxIdentifierLabel: Vt,
  taxIdentifierPlaceholder: Ot,
  supplierAddress: jt,
  sizeGroupingOption: qt,
  shippingTaxable: Lt,
  indiaGst: Et,
  indiaHelp: Bt,
  gstRate: Kt,
  localComponent: Mt,
  taxComponents: Zt,
  componentsHelp: Wt,
  componentName: Jt,
  rate: Qt,
  removeComponent: Yt,
  addComponent: Xt,
  saveProfile: en,
  invoiceTitle: on,
  invoiceDescription: tn,
  issuedSummary: nn,
  retainedDetails: sn,
  downloadPdf: an,
  orderTaxSummary: rn,
  supplyCountryCode: ln,
  supplyRegionCode: cn,
  domesticHelp: dn,
  lineClassification: un,
  hsnRequired: pn,
  hsnOptional: mn,
  hsnFor: fn,
  unitCode: gn,
  unitFor: $n,
  reviewConfirmation: Sn,
  issueInvoice: Cn,
  authorizedUserRequired: yn,
  customerGstHelp: vn,
  customerTaxHelp: bn,
  defaultSupplyRegionCode: Tn,
  defaultSupplyRegion: hn,
  taxInvoice: xn,
  permissions: In
}, Gn = "Facturation fiscale", kn = "Coordonnées fiscales et présentation des factures de cette organisation.", wn = "Définissez le régime fiscal de votre entreprise. La taxe enregistrée sur la commande doit correspondre aux taux choisis avant l’émission. Ce module ne choisit pas les taux, ne dépose pas les déclarations et n’obtient pas les accusés gouvernementaux de facturation électronique.", Hn = "Profil", zn = "Profil de pays personnalisé", Pn = "GST Inde : opérations nationales", Nn = "Code pays (ISO à 2 lettres)", _n = "Nom du fournisseur", Dn = "Immatriculation fiscale du fournisseur", An = "Code de région/État du fournisseur", Fn = "Libellé de l’identifiant fiscal", Un = "GSTIN, numéro de TVA, TIN", Vn = "Adresse du fournisseur", On = "Option de variante pour regrouper les tailles", jn = "Inclure la livraison dans la base imposable", qn = "GST Inde", Ln = "CGST et SGST/UTGST s’appliquent si les codes d’État du fournisseur et du lieu de fourniture concordent ; sinon, IGST s’applique. Saisissez le taux total de GST. Exportations, SEZ, autoliquidation, prélèvements et facturation électronique nécessitent un traitement spécifique.", En = "Taux total de GST (%)", Bn = "Composante locale", Kn = "Composantes fiscales", Mn = "Configurez des taxes additives comme la TVA, GST/PST ou la taxe de vente. Les taux mixtes, taxes composées et règles déclaratives nationales ne sont pas automatisés.", Zn = "Nom de la composante", Wn = "Taux (%)", Jn = "Supprimer la composante", Qn = "Ajouter une composante fiscale", Yn = "Enregistrer le profil fiscal", Xn = "Facture fiscale : {number}", ei = "Vérifiez les données fiscales avant de conserver la facture émise.", oi = "Émise le {date} · {total}", ti = "Ces données sont conservées à l’émission. Les modifications ultérieures du client, de la commande ou du profil fiscal ne modifient pas cette facture.", ni = "Télécharger la facture PDF", ii = "Total de la commande : {total}. Taxe enregistrée : {tax}. Les composantes fiscales doivent correspondre à ce montant. L’émission ne modifie ni stocks ni paiements.", si = "Code pays du lieu de fourniture", ai = "Code de région/État du lieu de fourniture", ri = "Ce profil couvre les opérations nationales en Inde. Vérifiez le lieu de fourniture, GSTIN, HSN/SAC et les codes d’unité avant l’émission.", li = "Classification des lignes", ci = "HSN/SAC (obligatoire)", di = "HSN/SAC (facultatif)", ui = "HSN/SAC de {sku}", pi = "Code d’unité", mi = "Unité de {sku}", fi = "J’ai vérifié l’immatriculation fiscale, le lieu de fourniture, la classification et la taxe enregistrée.", gi = "Émettre la facture fiscale", $i = "Un utilisateur autorisé doit émettre la facture fiscale avant son téléchargement.", Si = "Saisissez le GSTIN du client dans le champ Identifiant fiscal/TVA ci-dessus. Laissez-le vide si le client n’est pas immatriculé.", Ci = "Utilisez le champ Identifiant fiscal/TVA pour l’immatriculation fiscale locale du client.", yi = "Code de région/État de fourniture par défaut", vi = "Région/État de fourniture par défaut", bi = "Facture fiscale", Ti = { issue: { label: "Émettre des factures fiscales", description: "Facturation fiscale" }, manage: { label: "Gérer les profils fiscaux", description: "Facturation fiscale" } }, hi = {
  title: Gn,
  settingsDescription: kn,
  settingsHelp: wn,
  profile: Hn,
  customCountryProfile: zn,
  indiaProfile: Pn,
  countryCode: Nn,
  supplierName: _n,
  supplierTaxRegistration: Dn,
  supplierRegionCode: An,
  taxIdentifierLabel: Fn,
  taxIdentifierPlaceholder: Un,
  supplierAddress: Vn,
  sizeGroupingOption: On,
  shippingTaxable: jn,
  indiaGst: qn,
  indiaHelp: Ln,
  gstRate: En,
  localComponent: Bn,
  taxComponents: Kn,
  componentsHelp: Mn,
  componentName: Zn,
  rate: Wn,
  removeComponent: Jn,
  addComponent: Qn,
  saveProfile: Yn,
  invoiceTitle: Xn,
  invoiceDescription: ei,
  issuedSummary: oi,
  retainedDetails: ti,
  downloadPdf: ni,
  orderTaxSummary: ii,
  supplyCountryCode: si,
  supplyRegionCode: ai,
  domesticHelp: ri,
  lineClassification: li,
  hsnRequired: ci,
  hsnOptional: di,
  hsnFor: ui,
  unitCode: pi,
  unitFor: mi,
  reviewConfirmation: fi,
  issueInvoice: gi,
  authorizedUserRequired: $i,
  customerGstHelp: Si,
  customerTaxHelp: Ci,
  defaultSupplyRegionCode: yi,
  defaultSupplyRegion: vi,
  taxInvoice: bi,
  permissions: Ti
}, xi = "Fatturazione fiscale", Ii = "Dati di registrazione fiscale e presentazione delle fatture dell’organizzazione.", Ri = "Imposta il trattamento fiscale dell’azienda. L’imposta registrata nell’ordine deve corrispondere alle aliquote scelte prima dell’emissione. Questo modulo non sceglie aliquote, presenta dichiarazioni o ottiene conferme governative di fatturazione elettronica.", Gi = "Profilo", ki = "Profilo paese personalizzato", wi = "GST India: operazioni nazionali", Hi = "Codice paese (ISO a 2 lettere)", zi = "Nome del fornitore", Pi = "Registrazione fiscale del fornitore", Ni = "Codice regione/stato del fornitore", _i = "Etichetta dell’identificativo fiscale", Di = "GSTIN, numero IVA, TIN", Ai = "Indirizzo del fornitore", Fi = "Opzione variante per raggruppare le taglie", Ui = "Includi la spedizione nella base imponibile", Vi = "GST India", Oi = "Si applicano CGST e SGST/UTGST se i codici di stato del fornitore e del luogo di fornitura coincidono; altrimenti si applica IGST. Inserisci l’aliquota GST totale. Esportazioni, SEZ, inversione contabile, tributi aggiuntivi e fatturazione elettronica richiedono un trattamento specifico.", ji = "Aliquota GST totale (%)", qi = "Componente locale", Li = "Componenti fiscali", Ei = "Configura imposte additive come IVA, GST/PST o imposte sulle vendite. Aliquote miste, imposte composte e regole dichiarative nazionali non sono automatizzate.", Bi = "Nome componente", Ki = "Aliquota (%)", Mi = "Rimuovi componente", Zi = "Aggiungi componente fiscale", Wi = "Salva profilo fiscale", Ji = "Fattura fiscale: {number}", Qi = "Controlla i dati fiscali prima di conservare la fattura emessa.", Yi = "Emessa il {date} · {total}", Xi = "Questi dati sono conservati dall’emissione. Le modifiche successive a cliente, ordine o profilo fiscale non riscrivono questa fattura.", es = "Scarica fattura PDF", os = "Totale ordine: {total}. Imposta registrata: {tax}. I componenti fiscali devono corrispondere a questo importo. L’emissione non modifica scorte o pagamenti.", ts = "Codice paese del luogo di fornitura", ns = "Codice regione/stato del luogo di fornitura", is = "Questo profilo copre le operazioni nazionali in India. Verifica luogo di fornitura, GSTIN, HSN/SAC e codici unità prima dell’emissione.", ss = "Classificazione righe", as = "HSN/SAC (obbligatorio)", rs = "HSN/SAC (facoltativo)", ls = "HSN/SAC di {sku}", cs = "Codice unità", ds = "Unità di {sku}", us = "Ho verificato registrazione fiscale, luogo di fornitura, classificazione e imposta registrata.", ps = "Emetti fattura fiscale", ms = "Un utente autorizzato deve emettere la fattura fiscale prima del download.", fs = "Inserisci il GSTIN del cliente nel campo ID fiscale/IVA sopra. Lascialo vuoto per un cliente non registrato.", gs = "Usa il campo ID fiscale/IVA per la registrazione fiscale locale del cliente.", $s = "Codice predefinito regione/stato di fornitura", Ss = "Regione/stato di fornitura predefinito", Cs = "Fattura fiscale", ys = { issue: { label: "Emettere fatture fiscali", description: "Fatturazione fiscale" }, manage: { label: "Gestire profili fiscali", description: "Fatturazione fiscale" } }, vs = {
  title: xi,
  settingsDescription: Ii,
  settingsHelp: Ri,
  profile: Gi,
  customCountryProfile: ki,
  indiaProfile: wi,
  countryCode: Hi,
  supplierName: zi,
  supplierTaxRegistration: Pi,
  supplierRegionCode: Ni,
  taxIdentifierLabel: _i,
  taxIdentifierPlaceholder: Di,
  supplierAddress: Ai,
  sizeGroupingOption: Fi,
  shippingTaxable: Ui,
  indiaGst: Vi,
  indiaHelp: Oi,
  gstRate: ji,
  localComponent: qi,
  taxComponents: Li,
  componentsHelp: Ei,
  componentName: Bi,
  rate: Ki,
  removeComponent: Mi,
  addComponent: Zi,
  saveProfile: Wi,
  invoiceTitle: Ji,
  invoiceDescription: Qi,
  issuedSummary: Yi,
  retainedDetails: Xi,
  downloadPdf: es,
  orderTaxSummary: os,
  supplyCountryCode: ts,
  supplyRegionCode: ns,
  domesticHelp: is,
  lineClassification: ss,
  hsnRequired: as,
  hsnOptional: rs,
  hsnFor: ls,
  unitCode: cs,
  unitFor: ds,
  reviewConfirmation: us,
  issueInvoice: ps,
  authorizedUserRequired: ms,
  customerGstHelp: fs,
  customerTaxHelp: gs,
  defaultSupplyRegionCode: $s,
  defaultSupplyRegion: Ss,
  taxInvoice: Cs,
  permissions: ys
}, bs = "税務請求", Ts = "この組織の税務登録情報と請求書の表示設定。", hs = "事業の税務上の扱いを設定します。請求書発行前に、注文に記録された税額と選択した税率が一致する必要があります。このモジュールは税率の選択、申告、政府による電子請求書の承認取得を行いません。", xs = "プロファイル", Is = "カスタム国別プロファイル", Rs = "インドGST：国内供給", Gs = "国コード（ISO 2文字）", ks = "供給者名", ws = "供給者の税務登録", Hs = "供給者の地域・州コード", zs = "税務識別番号のラベル", Ps = "GSTIN、VAT番号、TIN", Ns = "供給者住所", _s = "サイズ集計に使うバリエーション項目", Ds = "送料を課税標準に含める", As = "インドGST", Fs = "供給者と供給地の州コードが一致する場合はCGSTとSGST/UTGST、異なる場合はIGSTを適用します。GST合計税率を入力してください。輸出、SEZ、リバースチャージ、追加税、電子請求書は個別の対応が必要です。", Us = "GST合計税率（%）", Vs = "地方税項目", Os = "税項目", js = "VAT、GST/PST、売上税など加算方式の税を設定します。混合税率、複合税、国別申告規則は自動処理されません。", qs = "項目名", Ls = "税率（%）", Es = "項目を削除", Bs = "税項目を追加", Ks = "税務プロファイルを保存", Ms = "税務請求書：{number}", Zs = "発行済み請求書を保存する前に税務情報を確認してください。", Ws = "発行日 {date} · {total}", Js = "発行時の情報が保存されます。その後に顧客、注文、税務プロファイルを変更しても、この請求書は変更されません。", Qs = "請求書PDFをダウンロード", Ys = "注文合計：{total}。記録済み税額：{tax}。税項目の合計がこの金額と一致する必要があります。発行によって在庫や支払いは変更されません。", Xs = "供給地の国コード", ea = "供給地の地域・州コード", oa = "このプロファイルはインド国内供給用です。発行前に供給地、GSTIN、HSN/SAC、単位コードを確認してください。", ta = "明細分類", na = "HSN/SAC（必須）", ia = "HSN/SAC（任意）", sa = "{sku}のHSN/SAC", aa = "単位コード", ra = "{sku}の単位", la = "税務登録、供給地、分類、記録済み税額を確認しました。", ca = "税務請求書を発行", da = "ダウンロードするには、権限を持つユーザーが税務請求書を発行する必要があります。", ua = "上の税務ID/VAT欄に顧客のGSTINを入力してください。未登録の顧客の場合は空欄にします。", pa = "顧客の現地税務登録には税務ID/VAT欄を使用してください。", ma = "既定の供給地地域・州コード", fa = "既定の供給地地域・州", ga = "税務請求書", $a = { issue: { label: "税務請求書の発行", description: "税務請求" }, manage: { label: "税務請求プロファイルの管理", description: "税務請求" } }, Sa = {
  title: bs,
  settingsDescription: Ts,
  settingsHelp: hs,
  profile: xs,
  customCountryProfile: Is,
  indiaProfile: Rs,
  countryCode: Gs,
  supplierName: ks,
  supplierTaxRegistration: ws,
  supplierRegionCode: Hs,
  taxIdentifierLabel: zs,
  taxIdentifierPlaceholder: Ps,
  supplierAddress: Ns,
  sizeGroupingOption: _s,
  shippingTaxable: Ds,
  indiaGst: As,
  indiaHelp: Fs,
  gstRate: Us,
  localComponent: Vs,
  taxComponents: Os,
  componentsHelp: js,
  componentName: qs,
  rate: Ls,
  removeComponent: Es,
  addComponent: Bs,
  saveProfile: Ks,
  invoiceTitle: Ms,
  invoiceDescription: Zs,
  issuedSummary: Ws,
  retainedDetails: Js,
  downloadPdf: Qs,
  orderTaxSummary: Ys,
  supplyCountryCode: Xs,
  supplyRegionCode: ea,
  domesticHelp: oa,
  lineClassification: ta,
  hsnRequired: na,
  hsnOptional: ia,
  hsnFor: sa,
  unitCode: aa,
  unitFor: ra,
  reviewConfirmation: la,
  issueInvoice: ca,
  authorizedUserRequired: da,
  customerGstHelp: ua,
  customerTaxHelp: pa,
  defaultSupplyRegionCode: ma,
  defaultSupplyRegion: fa,
  taxInvoice: ga,
  permissions: $a
}, Ca = "세금 청구", ya = "조직의 세무 등록 정보 및 청구서 표시 설정입니다.", va = "사업에 적용되는 세금 처리를 설정하세요. 청구서 발행 전 주문에 기록된 세금이 선택한 세율과 일치해야 합니다. 이 모듈은 세율 선택, 세금 신고, 정부 전자 청구서 승인 취득을 수행하지 않습니다.", ba = "프로필", Ta = "사용자 지정 국가 프로필", ha = "인도 GST: 국내 공급", xa = "국가 코드(ISO 2자리)", Ia = "공급자 이름", Ra = "공급자 세무 등록", Ga = "공급자 지역/주 코드", ka = "세금 식별자 레이블", wa = "GSTIN, VAT 번호, TIN", Ha = "공급자 주소", za = "사이즈 그룹에 사용할 옵션", Pa = "배송비를 과세 표준에 포함", Na = "인도 GST", _a = "공급자와 공급지의 주 코드가 같으면 CGST와 SGST/UTGST, 다르면 IGST가 적용됩니다. 총 GST 세율을 입력하세요. 수출, SEZ, 역외 과세, 추가 부담금 및 전자 청구서는 별도 처리가 필요합니다.", Da = "총 GST 세율(%)", Aa = "지역 구성 요소", Fa = "세금 구성 요소", Ua = "VAT, GST/PST, 판매세 등의 가산 세금을 설정하세요. 혼합 세율, 복합 세금, 국가별 신고 규칙은 자동화되지 않습니다.", Va = "구성 요소 이름", Oa = "세율(%)", ja = "구성 요소 삭제", qa = "세금 구성 요소 추가", La = "세금 프로필 저장", Ea = "세금 청구서: {number}", Ba = "발행된 청구서를 보관하기 전에 세금 정보를 검토하세요.", Ka = "발행일 {date} · {total}", Ma = "발행 당시 정보가 보존됩니다. 이후 고객, 주문 또는 세금 프로필을 수정해도 이 청구서는 변경되지 않습니다.", Za = "청구서 PDF 다운로드", Wa = "주문 합계: {total}. 기록된 세금: {tax}. 세금 구성 요소가 이 금액과 일치해야 합니다. 발행은 재고나 결제를 변경하지 않습니다.", Ja = "공급지 국가 코드", Qa = "공급지 지역/주 코드", Ya = "이 프로필은 인도 국내 공급용입니다. 발행 전에 공급지, GSTIN, HSN/SAC 및 단위 코드를 확인하세요.", Xa = "품목 분류", er = "HSN/SAC(필수)", or = "HSN/SAC(선택)", tr = "{sku}의 HSN/SAC", nr = "단위 코드", ir = "{sku}의 단위", sr = "세무 등록, 공급지, 분류 및 기록된 세금을 검토했습니다.", ar = "세금 청구서 발행", rr = "다운로드하기 전에 권한이 있는 사용자가 세금 청구서를 발행해야 합니다.", lr = "위의 세금 ID/VAT 필드에 고객 GSTIN을 입력하세요. 미등록 고객은 비워 두세요.", cr = "고객의 현지 세무 등록은 세금 ID/VAT 필드를 사용하세요.", dr = "기본 공급지 지역/주 코드", ur = "기본 공급지 지역/주", pr = "세금 청구서", mr = { issue: { label: "세금 청구서 발행", description: "세금 청구" }, manage: { label: "세금 청구 프로필 관리", description: "세금 청구" } }, fr = {
  title: Ca,
  settingsDescription: ya,
  settingsHelp: va,
  profile: ba,
  customCountryProfile: Ta,
  indiaProfile: ha,
  countryCode: xa,
  supplierName: Ia,
  supplierTaxRegistration: Ra,
  supplierRegionCode: Ga,
  taxIdentifierLabel: ka,
  taxIdentifierPlaceholder: wa,
  supplierAddress: Ha,
  sizeGroupingOption: za,
  shippingTaxable: Pa,
  indiaGst: Na,
  indiaHelp: _a,
  gstRate: Da,
  localComponent: Aa,
  taxComponents: Fa,
  componentsHelp: Ua,
  componentName: Va,
  rate: Oa,
  removeComponent: ja,
  addComponent: qa,
  saveProfile: La,
  invoiceTitle: Ea,
  invoiceDescription: Ba,
  issuedSummary: Ka,
  retainedDetails: Ma,
  downloadPdf: Za,
  orderTaxSummary: Wa,
  supplyCountryCode: Ja,
  supplyRegionCode: Qa,
  domesticHelp: Ya,
  lineClassification: Xa,
  hsnRequired: er,
  hsnOptional: or,
  hsnFor: tr,
  unitCode: nr,
  unitFor: ir,
  reviewConfirmation: sr,
  issueInvoice: ar,
  authorizedUserRequired: rr,
  customerGstHelp: lr,
  customerTaxHelp: cr,
  defaultSupplyRegionCode: dr,
  defaultSupplyRegion: ur,
  taxInvoice: pr,
  permissions: mr
}, gr = "Belastingfacturatie", $r = "Belastingregistratie en factuurweergave voor deze organisatie.", Sr = "Stel de belastingbehandeling van uw bedrijf in. De vastgelegde orderbelasting moet vóór uitgifte overeenkomen met de gekozen tarieven. Deze module kiest geen tarieven, dient geen aangiften in en verkrijgt geen overheidsbevestigingen voor elektronische facturen.", Cr = "Profiel", yr = "Aangepast landprofiel", vr = "India GST: binnenlandse leveringen", br = "Landcode (ISO, 2 letters)", Tr = "Naam leverancier", hr = "Belastingregistratie leverancier", xr = "Regio-/staatcode leverancier", Ir = "Label belastingidentificatie", Rr = "GSTIN, btw-nummer, TIN", Gr = "Adres leverancier", kr = "Variantoptie voor groeperen op maat", wr = "Verzendkosten opnemen in belastinggrondslag", Hr = "India GST", zr = "CGST en SGST/UTGST gelden als de staatcodes van leverancier en plaats van levering gelijk zijn; anders geldt IGST. Voer het totale GST-tarief in. Export, SEZ, verlegging, heffingen en elektronische facturatie vereisen een aparte behandeling.", Pr = "Totaal GST-tarief (%)", Nr = "Lokale component", _r = "Belastingcomponenten", Dr = "Configureer optelbare belastingen zoals btw, GST/PST of omzetbelasting. Gemengde tarieven, samengestelde belastingen en nationale aangifteregels zijn niet geautomatiseerd.", Ar = "Componentnaam", Fr = "Tarief (%)", Ur = "Component verwijderen", Vr = "Belastingcomponent toevoegen", Or = "Belastingprofiel opslaan", jr = "Belastingfactuur: {number}", qr = "Controleer de belastinggegevens voordat u de uitgegeven factuur bewaart.", Lr = "Uitgegeven op {date} · {total}", Er = "Deze gegevens worden bij uitgifte bewaard. Latere wijzigingen aan klant, order of belastingprofiel wijzigen deze factuur niet.", Br = "Factuur als PDF downloaden", Kr = "Ordertotaal: {total}. Vastgelegde belasting: {tax}. De belastingcomponenten moeten overeenkomen met dit bedrag. Uitgifte verandert geen voorraad of betalingen.", Mr = "Landcode plaats van levering", Zr = "Regio-/staatcode plaats van levering", Wr = "Dit profiel geldt voor binnenlandse leveringen in India. Controleer plaats van levering, GSTIN, HSN/SAC en eenheidscodes vóór uitgifte.", Jr = "Regelclassificatie", Qr = "HSN/SAC (verplicht)", Yr = "HSN/SAC (optioneel)", Xr = "HSN/SAC voor {sku}", el = "Eenheidscode", ol = "Eenheid voor {sku}", tl = "Ik heb belastingregistratie, plaats van levering, classificatie en vastgelegde belasting gecontroleerd.", nl = "Belastingfactuur uitgeven", il = "Een bevoegde gebruiker moet de belastingfactuur uitgeven voordat deze kan worden gedownload.", sl = "Voer de GSTIN van de klant in het veld Belasting-ID/btw hierboven in. Laat dit leeg voor een niet-geregistreerde klant.", al = "Gebruik het veld Belasting-ID/btw voor de lokale belastingregistratie van de klant.", rl = "Standaard regio-/staatcode plaats van levering", ll = "Standaardregio/-staat plaats van levering", cl = "Belastingfactuur", dl = { issue: { label: "Belastingfacturen uitgeven", description: "Belastingfacturatie" }, manage: { label: "Belastingprofielen beheren", description: "Belastingfacturatie" } }, ul = {
  title: gr,
  settingsDescription: $r,
  settingsHelp: Sr,
  profile: Cr,
  customCountryProfile: yr,
  indiaProfile: vr,
  countryCode: br,
  supplierName: Tr,
  supplierTaxRegistration: hr,
  supplierRegionCode: xr,
  taxIdentifierLabel: Ir,
  taxIdentifierPlaceholder: Rr,
  supplierAddress: Gr,
  sizeGroupingOption: kr,
  shippingTaxable: wr,
  indiaGst: Hr,
  indiaHelp: zr,
  gstRate: Pr,
  localComponent: Nr,
  taxComponents: _r,
  componentsHelp: Dr,
  componentName: Ar,
  rate: Fr,
  removeComponent: Ur,
  addComponent: Vr,
  saveProfile: Or,
  invoiceTitle: jr,
  invoiceDescription: qr,
  issuedSummary: Lr,
  retainedDetails: Er,
  downloadPdf: Br,
  orderTaxSummary: Kr,
  supplyCountryCode: Mr,
  supplyRegionCode: Zr,
  domesticHelp: Wr,
  lineClassification: Jr,
  hsnRequired: Qr,
  hsnOptional: Yr,
  hsnFor: Xr,
  unitCode: el,
  unitFor: ol,
  reviewConfirmation: tl,
  issueInvoice: nl,
  authorizedUserRequired: il,
  customerGstHelp: sl,
  customerTaxHelp: al,
  defaultSupplyRegionCode: rl,
  defaultSupplyRegion: ll,
  taxInvoice: cl,
  permissions: dl
}, pl = "Fakturowanie podatkowe", ml = "Dane rejestracji podatkowej i wygląd faktur organizacji.", fl = "Ustaw zasady podatkowe firmy. Podatek zapisany w zamówieniu musi odpowiadać wybranym stawkom przed wystawieniem faktury. Moduł nie wybiera stawek, nie składa deklaracji ani nie uzyskuje urzędowych potwierdzeń e-faktur.", gl = "Profil", $l = "Własny profil kraju", Sl = "GST Indie: dostawy krajowe", Cl = "Kod kraju (ISO, 2 litery)", yl = "Nazwa dostawcy", vl = "Rejestracja podatkowa dostawcy", bl = "Kod regionu/stanu dostawcy", Tl = "Etykieta identyfikatora podatkowego", hl = "GSTIN, numer VAT, TIN", xl = "Adres dostawcy", Il = "Opcja wariantu do grupowania rozmiarów", Rl = "Uwzględnij wysyłkę w podstawie opodatkowania", Gl = "GST Indie", kl = "CGST i SGST/UTGST obowiązują, gdy kody stanu dostawcy i miejsca dostawy są zgodne; w przeciwnym razie obowiązuje IGST. Podaj łączną stawkę GST. Eksport, SEZ, odwrotne obciążenie, dodatkowe opłaty i e-faktury wymagają osobnej obsługi.", wl = "Łączna stawka GST (%)", Hl = "Składnik lokalny", zl = "Składniki podatku", Pl = "Skonfiguruj podatki sumowane, np. VAT, GST/PST lub podatek od sprzedaży. Stawki mieszane, podatki złożone i krajowe zasady deklaracji nie są automatyzowane.", Nl = "Nazwa składnika", _l = "Stawka (%)", Dl = "Usuń składnik", Al = "Dodaj składnik podatku", Fl = "Zapisz profil podatkowy", Ul = "Faktura podatkowa: {number}", Vl = "Sprawdź dane podatkowe przed zachowaniem wystawionej faktury.", Ol = "Wystawiono {date} · {total}", jl = "Dane są zachowywane w chwili wystawienia. Późniejsze zmiany klienta, zamówienia lub profilu nie zmieniają faktury.", ql = "Pobierz fakturę PDF", Ll = "Suma zamówienia: {total}. Zapisany podatek: {tax}. Składniki podatku muszą odpowiadać tej kwocie. Wystawienie nie zmienia zapasów ani płatności.", El = "Kod kraju miejsca dostawy", Bl = "Kod regionu/stanu miejsca dostawy", Kl = "Profil obejmuje dostawy krajowe w Indiach. Przed wystawieniem sprawdź miejsce dostawy, GSTIN, HSN/SAC i kody jednostek.", Ml = "Klasyfikacja pozycji", Zl = "HSN/SAC (wymagany)", Wl = "HSN/SAC (opcjonalny)", Jl = "HSN/SAC dla {sku}", Ql = "Kod jednostki", Yl = "Jednostka dla {sku}", Xl = "Sprawdzono rejestrację podatkową, miejsce dostawy, klasyfikację i zapisany podatek.", ec = "Wystaw fakturę podatkową", oc = "Uprawniony użytkownik musi wystawić fakturę podatkową przed pobraniem.", tc = "Wpisz GSTIN klienta w polu Identyfikator podatkowy/VAT powyżej. Dla niezarejestrowanego klienta pozostaw je puste.", nc = "Użyj pola Identyfikator podatkowy/VAT do lokalnej rejestracji podatkowej klienta.", ic = "Domyślny kod regionu/stanu miejsca dostawy", sc = "Domyślny region/stan miejsca dostawy", ac = "Faktura podatkowa", rc = { issue: { label: "Wystawianie faktur podatkowych", description: "Fakturowanie podatkowe" }, manage: { label: "Zarządzanie profilami podatkowymi", description: "Fakturowanie podatkowe" } }, lc = {
  title: pl,
  settingsDescription: ml,
  settingsHelp: fl,
  profile: gl,
  customCountryProfile: $l,
  indiaProfile: Sl,
  countryCode: Cl,
  supplierName: yl,
  supplierTaxRegistration: vl,
  supplierRegionCode: bl,
  taxIdentifierLabel: Tl,
  taxIdentifierPlaceholder: hl,
  supplierAddress: xl,
  sizeGroupingOption: Il,
  shippingTaxable: Rl,
  indiaGst: Gl,
  indiaHelp: kl,
  gstRate: wl,
  localComponent: Hl,
  taxComponents: zl,
  componentsHelp: Pl,
  componentName: Nl,
  rate: _l,
  removeComponent: Dl,
  addComponent: Al,
  saveProfile: Fl,
  invoiceTitle: Ul,
  invoiceDescription: Vl,
  issuedSummary: Ol,
  retainedDetails: jl,
  downloadPdf: ql,
  orderTaxSummary: Ll,
  supplyCountryCode: El,
  supplyRegionCode: Bl,
  domesticHelp: Kl,
  lineClassification: Ml,
  hsnRequired: Zl,
  hsnOptional: Wl,
  hsnFor: Jl,
  unitCode: Ql,
  unitFor: Yl,
  reviewConfirmation: Xl,
  issueInvoice: ec,
  authorizedUserRequired: oc,
  customerGstHelp: tc,
  customerTaxHelp: nc,
  defaultSupplyRegionCode: ic,
  defaultSupplyRegion: sc,
  taxInvoice: ac,
  permissions: rc
}, cc = "Faturamento fiscal", dc = "Dados cadastrais fiscais e apresentação das faturas desta organização.", uc = "Defina o tratamento fiscal da empresa. O imposto registrado no pedido deve corresponder às alíquotas selecionadas antes da emissão. Este módulo não escolhe alíquotas, entrega declarações nem obtém confirmações governamentais de faturas eletrônicas.", pc = "Perfil", mc = "Perfil de país personalizado", fc = "GST da Índia: operações nacionais", gc = "Código do país (ISO de 2 letras)", $c = "Nome do fornecedor", Sc = "Registro fiscal do fornecedor", Cc = "Código da região/estado do fornecedor", yc = "Rótulo do identificador fiscal", vc = "GSTIN, número de IVA, TIN", bc = "Endereço do fornecedor", Tc = "Opção de variante para agrupar tamanhos", hc = "Incluir frete na base tributável", xc = "GST da Índia", Ic = "CGST e SGST/UTGST se aplicam quando os códigos de estado do fornecedor e do local de fornecimento coincidem; caso contrário, aplica-se IGST. Informe a alíquota total de GST. Exportações, SEZ, cobrança reversa, tributos adicionais e faturamento eletrônico exigem tratamento específico.", Rc = "Alíquota total de GST (%)", Gc = "Componente local", kc = "Componentes fiscais", wc = "Configure impostos aditivos como IVA, GST/PST ou imposto sobre vendas. Alíquotas mistas, impostos compostos e regras nacionais de declaração não são automatizados.", Hc = "Nome do componente", zc = "Alíquota (%)", Pc = "Remover componente", Nc = "Adicionar componente fiscal", _c = "Salvar perfil fiscal", Dc = "Fatura fiscal: {number}", Ac = "Revise os dados fiscais antes de guardar a fatura emitida.", Fc = "Emitida em {date} · {total}", Uc = "Estes dados são preservados na emissão. Alterações posteriores no cliente, pedido ou perfil fiscal não reescrevem esta fatura.", Vc = "Baixar fatura em PDF", Oc = "Total do pedido: {total}. Imposto registrado: {tax}. Os componentes fiscais devem corresponder a esse valor. A emissão não altera estoques nem pagamentos.", jc = "Código do país do local de fornecimento", qc = "Código da região/estado do local de fornecimento", Lc = "Este perfil cobre operações nacionais na Índia. Verifique o local de fornecimento, GSTIN, HSN/SAC e códigos de unidade antes de emitir.", Ec = "Classificação das linhas", Bc = "HSN/SAC (obrigatório)", Kc = "HSN/SAC (opcional)", Mc = "HSN/SAC de {sku}", Zc = "Código da unidade", Wc = "Unidade de {sku}", Jc = "Revisei o registro fiscal, local de fornecimento, classificação e imposto registrado.", Qc = "Emitir fatura fiscal", Yc = "Um usuário autorizado deve emitir a fatura fiscal antes do download.", Xc = "Informe o GSTIN do cliente no campo ID fiscal/IVA acima. Deixe em branco se o cliente não for registrado.", ed = "Use o campo ID fiscal/IVA para o registro fiscal local do cliente.", od = "Código padrão da região/estado de fornecimento", td = "Região/estado padrão de fornecimento", nd = "Fatura fiscal", id = { issue: { label: "Emitir faturas fiscais", description: "Faturamento fiscal" }, manage: { label: "Gerenciar perfis fiscais", description: "Faturamento fiscal" } }, sd = {
  title: cc,
  settingsDescription: dc,
  settingsHelp: uc,
  profile: pc,
  customCountryProfile: mc,
  indiaProfile: fc,
  countryCode: gc,
  supplierName: $c,
  supplierTaxRegistration: Sc,
  supplierRegionCode: Cc,
  taxIdentifierLabel: yc,
  taxIdentifierPlaceholder: vc,
  supplierAddress: bc,
  sizeGroupingOption: Tc,
  shippingTaxable: hc,
  indiaGst: xc,
  indiaHelp: Ic,
  gstRate: Rc,
  localComponent: Gc,
  taxComponents: kc,
  componentsHelp: wc,
  componentName: Hc,
  rate: zc,
  removeComponent: Pc,
  addComponent: Nc,
  saveProfile: _c,
  invoiceTitle: Dc,
  invoiceDescription: Ac,
  issuedSummary: Fc,
  retainedDetails: Uc,
  downloadPdf: Vc,
  orderTaxSummary: Oc,
  supplyCountryCode: jc,
  supplyRegionCode: qc,
  domesticHelp: Lc,
  lineClassification: Ec,
  hsnRequired: Bc,
  hsnOptional: Kc,
  hsnFor: Mc,
  unitCode: Zc,
  unitFor: Wc,
  reviewConfirmation: Jc,
  issueInvoice: Qc,
  authorizedUserRequired: Yc,
  customerGstHelp: Xc,
  customerTaxHelp: ed,
  defaultSupplyRegionCode: od,
  defaultSupplyRegion: td,
  taxInvoice: nd,
  permissions: id
}, ad = "Налоговые счета", rd = "Налоговые реквизиты и оформление счетов организации.", ld = "Укажите налоговый режим компании. Налог заказа должен соответствовать выбранным ставкам до выпуска счёта. Модуль не выбирает ставки, не подаёт декларации и не получает государственные подтверждения электронных счетов.", cd = "Профиль", dd = "Свой профиль страны", ud = "GST Индии: внутренние поставки", pd = "Код страны (ISO, 2 буквы)", md = "Название поставщика", fd = "Налоговая регистрация поставщика", gd = "Код региона/штата поставщика", $d = "Название налогового идентификатора", Sd = "GSTIN, номер НДС, TIN", Cd = "Адрес поставщика", yd = "Параметр варианта для группировки размеров", vd = "Включить доставку в налоговую базу", bd = "GST Индии", Td = "CGST и SGST/UTGST применяются при совпадении кодов штата поставщика и места поставки, иначе — IGST. Укажите общую ставку GST. Экспорт, SEZ, обратное начисление, дополнительные сборы и электронные счета требуют отдельной обработки.", hd = "Общая ставка GST (%)", xd = "Местный компонент", Id = "Налоговые компоненты", Rd = "Настройте складываемые налоги, например НДС, GST/PST или налог с продаж. Смешанные ставки, сложные налоги и правила подачи деклараций не автоматизированы.", Gd = "Название компонента", kd = "Ставка (%)", wd = "Удалить компонент", Hd = "Добавить налоговый компонент", zd = "Сохранить налоговый профиль", Pd = "Налоговый счёт: {number}", Nd = "Проверьте налоговые данные перед сохранением выпущенного счёта.", _d = "Выпущен {date} · {total}", Dd = "Данные сохраняются при выпуске. Изменения клиента, заказа или налогового профиля не меняют этот счёт.", Ad = "Скачать счёт в PDF", Fd = "Итого по заказу: {total}. Учтённый налог: {tax}. Налоговые компоненты должны совпадать с этой суммой. Выпуск не меняет остатки и платежи.", Ud = "Код страны места поставки", Vd = "Код региона/штата места поставки", Od = "Профиль предназначен для внутренних поставок в Индии. До выпуска проверьте место поставки, GSTIN, HSN/SAC и коды единиц.", jd = "Классификация строк", qd = "HSN/SAC (обязательно)", Ld = "HSN/SAC (необязательно)", Ed = "HSN/SAC для {sku}", Bd = "Код единицы", Kd = "Единица для {sku}", Md = "Я проверил налоговую регистрацию, место поставки, классификацию и учтённый налог.", Zd = "Выпустить налоговый счёт", Wd = "До скачивания налоговый счёт должен выпустить уполномоченный пользователь.", Jd = "Введите GSTIN клиента в поле «Налоговый ID/НДС» выше. Для незарегистрированного клиента оставьте поле пустым.", Qd = "Используйте поле «Налоговый ID/НДС» для местной налоговой регистрации клиента.", Yd = "Код региона/штата поставки по умолчанию", Xd = "Регион/штат поставки по умолчанию", eu = "Налоговый счёт", ou = { issue: { label: "Выпуск налоговых счетов", description: "Налоговые счета" }, manage: { label: "Управление налоговыми профилями", description: "Налоговые счета" } }, tu = {
  title: ad,
  settingsDescription: rd,
  settingsHelp: ld,
  profile: cd,
  customCountryProfile: dd,
  indiaProfile: ud,
  countryCode: pd,
  supplierName: md,
  supplierTaxRegistration: fd,
  supplierRegionCode: gd,
  taxIdentifierLabel: $d,
  taxIdentifierPlaceholder: Sd,
  supplierAddress: Cd,
  sizeGroupingOption: yd,
  shippingTaxable: vd,
  indiaGst: bd,
  indiaHelp: Td,
  gstRate: hd,
  localComponent: xd,
  taxComponents: Id,
  componentsHelp: Rd,
  componentName: Gd,
  rate: kd,
  removeComponent: wd,
  addComponent: Hd,
  saveProfile: zd,
  invoiceTitle: Pd,
  invoiceDescription: Nd,
  issuedSummary: _d,
  retainedDetails: Dd,
  downloadPdf: Ad,
  orderTaxSummary: Fd,
  supplyCountryCode: Ud,
  supplyRegionCode: Vd,
  domesticHelp: Od,
  lineClassification: jd,
  hsnRequired: qd,
  hsnOptional: Ld,
  hsnFor: Ed,
  unitCode: Bd,
  unitFor: Kd,
  reviewConfirmation: Md,
  issueInvoice: Zd,
  authorizedUserRequired: Wd,
  customerGstHelp: Jd,
  customerTaxHelp: Qd,
  defaultSupplyRegionCode: Yd,
  defaultSupplyRegion: Xd,
  taxInvoice: eu,
  permissions: ou
}, nu = "Vergi faturalandırması", iu = "Kuruluşun vergi kayıt bilgileri ve fatura görünümü.", su = "İşletmenizin vergi uygulamasını belirleyin. Fatura düzenlenmeden önce kayıtlı sipariş vergisi seçilen oranlarla eşleşmelidir. Bu modül vergi oranı seçmez, beyanname vermez veya resmî e-fatura onayı almaz.", au = "Profil", ru = "Özel ülke profili", lu = "Hindistan GST: yurt içi teslimatlar", cu = "Ülke kodu (ISO, 2 harf)", du = "Tedarikçi adı", uu = "Tedarikçi vergi kaydı", pu = "Tedarikçi bölge/eyalet kodu", mu = "Vergi kimliği etiketi", fu = "GSTIN, KDV numarası, TIN", gu = "Tedarikçi adresi", $u = "Beden gruplama için varyant seçeneği", Su = "Kargoyu vergi matrahına dahil et", Cu = "Hindistan GST", yu = "Tedarikçi ve teslimat yeri eyalet kodları aynıysa CGST ve SGST/UTGST, farklıysa IGST uygulanır. Toplam GST oranını girin. İhracat, SEZ, tersine vergilendirme, ek vergiler ve e-fatura ayrı işlem gerektirir.", vu = "Toplam GST oranı (%)", bu = "Yerel bileşen", Tu = "Vergi bileşenleri", hu = "KDV, GST/PST veya satış vergisi gibi eklenen vergileri yapılandırın. Karma oranlar, bileşik vergiler ve ülke beyan kuralları otomatik değildir.", xu = "Bileşen adı", Iu = "Oran (%)", Ru = "Bileşeni kaldır", Gu = "Vergi bileşeni ekle", ku = "Vergi profilini kaydet", wu = "Vergi faturası: {number}", Hu = "Düzenlenen faturayı saklamadan önce vergi bilgilerini inceleyin.", zu = "Düzenlenme: {date} · {total}", Pu = "Bu bilgiler düzenleme anında korunur. Müşteri, sipariş veya vergi profilindeki sonraki değişiklikler bu faturayı değiştirmez.", Nu = "Fatura PDF’sini indir", _u = "Sipariş toplamı: {total}. Kayıtlı vergi: {tax}. Fatura vergi bileşenleri bu tutarla uyuşmalıdır. Düzenleme stokları veya ödemeleri değiştirmez.", Du = "Teslimat yeri ülke kodu", Au = "Teslimat yeri bölge/eyalet kodu", Fu = "Bu profil Hindistan içi teslimatları kapsar. Düzenlemeden önce teslimat yerini, GSTIN, HSN/SAC ve birim kodlarını kontrol edin.", Uu = "Satır sınıflandırması", Vu = "HSN/SAC (zorunlu)", Ou = "HSN/SAC (isteğe bağlı)", ju = "{sku} için HSN/SAC", qu = "Birim kodu", Lu = "{sku} için birim", Eu = "Vergi kaydını, teslimat yerini, sınıflandırmayı ve kayıtlı vergiyi inceledim.", Bu = "Vergi faturası düzenle", Ku = "İndirmeden önce vergi faturasını yetkili bir kullanıcı düzenlemelidir.", Mu = "Müşteri GSTIN bilgisini yukarıdaki Vergi Kimliği/KDV alanına girin. Kayıtsız müşteriler için boş bırakın.", Zu = "Müşterinin yerel vergi kaydı için Vergi Kimliği/KDV alanını kullanın.", Wu = "Varsayılan teslimat yeri bölge/eyalet kodu", Ju = "Varsayılan teslimat yeri bölgesi/eyaleti", Qu = "Vergi faturası", Yu = { issue: { label: "Vergi faturaları düzenleme", description: "Vergi faturalandırması" }, manage: { label: "Vergi faturalandırma profillerini yönetme", description: "Vergi faturalandırması" } }, Xu = {
  title: nu,
  settingsDescription: iu,
  settingsHelp: su,
  profile: au,
  customCountryProfile: ru,
  indiaProfile: lu,
  countryCode: cu,
  supplierName: du,
  supplierTaxRegistration: uu,
  supplierRegionCode: pu,
  taxIdentifierLabel: mu,
  taxIdentifierPlaceholder: fu,
  supplierAddress: gu,
  sizeGroupingOption: $u,
  shippingTaxable: Su,
  indiaGst: Cu,
  indiaHelp: yu,
  gstRate: vu,
  localComponent: bu,
  taxComponents: Tu,
  componentsHelp: hu,
  componentName: xu,
  rate: Iu,
  removeComponent: Ru,
  addComponent: Gu,
  saveProfile: ku,
  invoiceTitle: wu,
  invoiceDescription: Hu,
  issuedSummary: zu,
  retainedDetails: Pu,
  downloadPdf: Nu,
  orderTaxSummary: _u,
  supplyCountryCode: Du,
  supplyRegionCode: Au,
  domesticHelp: Fu,
  lineClassification: Uu,
  hsnRequired: Vu,
  hsnOptional: Ou,
  hsnFor: ju,
  unitCode: qu,
  unitFor: Lu,
  reviewConfirmation: Eu,
  issueInvoice: Bu,
  authorizedUserRequired: Ku,
  customerGstHelp: Mu,
  customerTaxHelp: Zu,
  defaultSupplyRegionCode: Wu,
  defaultSupplyRegion: Ju,
  taxInvoice: Qu,
  permissions: Yu
}, ep = "税务开票", op = "此组织的税务登记信息及发票展示设置。", tp = "设置适用于企业的税务处理方式。开票前，订单记录的税额必须与所选税率一致。本模块不选择税率、不提交申报，也不获取政府电子发票确认。", np = "配置", ip = "自定义国家配置", sp = "印度 GST：国内供应", ap = "国家代码（ISO 两个字母）", rp = "供应商名称", lp = "供应商税务登记", cp = "供应商地区/邦代码", dp = "税务标识标签", up = "GSTIN、增值税号、TIN", pp = "供应商地址", mp = "用于尺码分组的规格选项", fp = "将运费计入计税基础", gp = "印度 GST", $p = "供应商与供应地点的邦代码相同，适用 CGST 加 SGST/UTGST；不同时适用 IGST。请输入 GST 总税率。出口、SEZ、反向征税、附加税及电子发票需专门处理。", Sp = "GST 总税率（%）", Cp = "地方税项", yp = "税项", vp = "配置增值税、GST/PST 或销售税等加算税。混合税率、复合税及各国申报规则不自动处理。", bp = "税项名称", Tp = "税率（%）", hp = "删除税项", xp = "添加税项", Ip = "保存税务配置", Rp = "税务发票：{number}", Gp = "保存已开具的发票前，请检查税务信息。", kp = "开具日期 {date} · {total}", wp = "这些信息按开票时的状态保存。以后修改客户、订单或税务配置不会更改此发票。", Hp = "下载发票 PDF", zp = "订单总额：{total}。记录税额：{tax}。发票税项必须与此金额一致。开票不会更改库存或付款。", Pp = "供应地点国家代码", Np = "供应地点地区/邦代码", _p = "此配置适用于印度国内供应。开票前请检查供应地点、GSTIN、HSN/SAC 及单位代码。", Dp = "明细分类", Ap = "HSN/SAC（必填）", Fp = "HSN/SAC（可选）", Up = "{sku} 的 HSN/SAC", Vp = "单位代码", Op = "{sku} 的单位", jp = "我已检查税务登记、供应地点、分类及记录税额。", qp = "开具税务发票", Lp = "必须由授权用户开具税务发票后才能下载。", Ep = "请在上方税号/增值税号字段中输入客户 GSTIN。未登记客户可留空。", Bp = "请使用税号/增值税号字段填写客户的当地税务登记。", Kp = "默认供应地点地区/邦代码", Mp = "默认供应地点地区/邦", Zp = "税务发票", Wp = { issue: { label: "开具税务发票", description: "税务开票" }, manage: { label: "管理税务开票配置", description: "税务开票" } }, Jp = {
  title: ep,
  settingsDescription: op,
  settingsHelp: tp,
  profile: np,
  customCountryProfile: ip,
  indiaProfile: sp,
  countryCode: ap,
  supplierName: rp,
  supplierTaxRegistration: lp,
  supplierRegionCode: cp,
  taxIdentifierLabel: dp,
  taxIdentifierPlaceholder: up,
  supplierAddress: pp,
  sizeGroupingOption: mp,
  shippingTaxable: fp,
  indiaGst: gp,
  indiaHelp: $p,
  gstRate: Sp,
  localComponent: Cp,
  taxComponents: yp,
  componentsHelp: vp,
  componentName: bp,
  rate: Tp,
  removeComponent: hp,
  addComponent: xp,
  saveProfile: Ip,
  invoiceTitle: Rp,
  invoiceDescription: Gp,
  issuedSummary: kp,
  retainedDetails: wp,
  downloadPdf: Hp,
  orderTaxSummary: zp,
  supplyCountryCode: Pp,
  supplyRegionCode: Np,
  domesticHelp: _p,
  lineClassification: Dp,
  hsnRequired: Ap,
  hsnOptional: Fp,
  hsnFor: Up,
  unitCode: Vp,
  unitFor: Op,
  reviewConfirmation: jp,
  issueInvoice: qp,
  authorizedUserRequired: Lp,
  customerGstHelp: Ep,
  customerTaxHelp: Bp,
  defaultSupplyRegionCode: Kp,
  defaultSupplyRegion: Mp,
  taxInvoice: Zp,
  permissions: Wp
}, Qp = /* @__PURE__ */ Object.assign({ "./locales/ar.json": Ne, "./locales/de.json": zo, "./locales/en.json": kt, "./locales/es.json": Rn, "./locales/fr.json": hi, "./locales/it.json": vs, "./locales/ja.json": Sa, "./locales/ko.json": fr, "./locales/nl.json": ul, "./locales/pl.json": lc, "./locales/pt-BR.json": sd, "./locales/ru.json": tu, "./locales/tr.json": Xu, "./locales/zh-CN.json": Jp });
let h;
function Yp(i) {
  h = i.i18n;
  for (const [f, $] of Object.entries(Qp))
    h.addMessages(f.split("/").pop().replace(".json", ""), $);
}
const s = (i, f = {}) => h.t(i, f), Xp = (i) => new Intl.NumberFormat(h.locale.value).format(i), I = (i, f) => new Intl.NumberFormat(h.locale.value, {
  style: "currency",
  currency: f
}).format(i), em = (i) => new Intl.DateTimeFormat(h.locale.value, {
  dateStyle: "medium",
  timeZone: "UTC"
}).format(/* @__PURE__ */ new Date(`${i}T00:00:00Z`)), om = { class: "tb-help" }, tm = { class: "tb-grid" }, nm = { class: "tb-field" }, im = { value: "generic" }, sm = { value: "india_gst" }, am = { class: "tb-field" }, rm = { class: "tb-field" }, lm = { class: "tb-field" }, cm = { class: "tb-field" }, dm = { class: "tb-field" }, um = ["placeholder"], pm = { class: "tb-field tb-wide" }, mm = { class: "tb-field" }, fm = { class: "tb-check" }, gm = { class: "tb-title" }, $m = { class: "tb-help" }, Sm = { class: "tb-grid" }, Cm = { class: "tb-field" }, ym = { class: "tb-field" }, vm = { class: "tb-title" }, bm = { class: "tb-help" }, Tm = { class: "tb-field" }, hm = ["onUpdate:modelValue"], xm = { class: "tb-field" }, Im = ["onUpdate:modelValue"], Rm = {
  key: 2,
  role: "alert",
  class: "tb-errors"
}, Gm = {
  __name: "Settings",
  props: { profile: Object, saveUrl: String },
  setup(i) {
    const { layouts: { AppLayout: f }, ui: { PageHeader: $, Card: C, Button: y }, Inertia: { Head: x, useForm: z } } = window.Inventoros, n = z(JSON.parse(JSON.stringify(i.profile)));
    function k() {
      n.mode === "india_gst" && (n.country = "IN", n.tax_id_label = "GSTIN");
    }
    return (S, t) => (c(), u(b, null, [
      g(e(x), {
        title: e(s)("title")
      }, null, 8, ["title"]),
      g(e(f), null, {
        default: m(() => [
          g(e($), {
            title: e(s)("title"),
            description: e(s)("settingsDescription")
          }, null, 8, ["title", "description"]),
          o("form", {
            class: "tb-stack",
            onSubmit: t[13] || (t[13] = _((r) => e(n).post(i.saveUrl, { preserveScroll: !0 }), ["prevent"]))
          }, [
            g(e(C), null, {
              default: m(() => [
                o("p", om, a(e(s)("settingsHelp")), 1),
                o("div", tm, [
                  o("label", nm, [
                    l(a(e(s)("profile")), 1),
                    d(o("select", {
                      "onUpdate:modelValue": t[0] || (t[0] = (r) => e(n).mode = r),
                      class: "tb-control",
                      onChange: k
                    }, [
                      o("option", im, a(e(s)("customCountryProfile")), 1),
                      o("option", sm, a(e(s)("indiaProfile")), 1)
                    ], 544), [
                      [P, e(n).mode]
                    ])
                  ]),
                  o("label", am, [
                    l(a(e(s)("countryCode")), 1),
                    d(o("input", {
                      "onUpdate:modelValue": t[1] || (t[1] = (r) => e(n).country = r),
                      class: "tb-control",
                      maxlength: "2",
                      placeholder: "IN, GB, CA",
                      onInput: t[2] || (t[2] = (r) => e(n).country = e(n).country.toUpperCase())
                    }, null, 544), [
                      [p, e(n).country]
                    ])
                  ]),
                  o("label", rm, [
                    l(a(e(s)("supplierName")), 1),
                    d(o("input", {
                      "onUpdate:modelValue": t[3] || (t[3] = (r) => e(n).issuer_name = r),
                      class: "tb-control",
                      autocomplete: "organization"
                    }, null, 512), [
                      [p, e(n).issuer_name]
                    ])
                  ]),
                  o("label", lm, [
                    l(a(e(s)("supplierTaxRegistration")), 1),
                    d(o("input", {
                      "onUpdate:modelValue": t[4] || (t[4] = (r) => e(n).issuer_tax_id = r),
                      class: "tb-control"
                    }, null, 512), [
                      [p, e(n).issuer_tax_id]
                    ])
                  ]),
                  o("label", cm, [
                    l(a(e(s)("supplierRegionCode")), 1),
                    d(o("input", {
                      "onUpdate:modelValue": t[5] || (t[5] = (r) => e(n).region_code = r),
                      class: "tb-control"
                    }, null, 512), [
                      [p, e(n).region_code]
                    ])
                  ]),
                  o("label", dm, [
                    l(a(e(s)("taxIdentifierLabel")), 1),
                    d(o("input", {
                      "onUpdate:modelValue": t[6] || (t[6] = (r) => e(n).tax_id_label = r),
                      class: "tb-control",
                      placeholder: e(s)("taxIdentifierPlaceholder")
                    }, null, 8, um), [
                      [p, e(n).tax_id_label]
                    ])
                  ]),
                  o("label", pm, [
                    l(a(e(s)("supplierAddress")), 1),
                    d(o("textarea", {
                      "onUpdate:modelValue": t[7] || (t[7] = (r) => e(n).issuer_address = r),
                      class: "tb-control",
                      rows: "3",
                      autocomplete: "street-address"
                    }, null, 512), [
                      [p, e(n).issuer_address]
                    ])
                  ]),
                  o("label", mm, [
                    l(a(e(s)("sizeGroupingOption")), 1),
                    d(o("input", {
                      "onUpdate:modelValue": t[8] || (t[8] = (r) => e(n).size_option = r),
                      class: "tb-control"
                    }, null, 512), [
                      [p, e(n).size_option]
                    ])
                  ])
                ]),
                o("label", fm, [
                  d(o("input", {
                    "onUpdate:modelValue": t[9] || (t[9] = (r) => e(n).shipping_taxable = r),
                    type: "checkbox"
                  }, null, 512), [
                    [N, e(n).shipping_taxable]
                  ]),
                  l(" " + a(e(s)("shippingTaxable")), 1)
                ])
              ]),
              _: 1
            }),
            e(n).mode === "india_gst" ? (c(), T(e(C), { key: 0 }, {
              default: m(() => [
                o("h2", gm, a(e(s)("indiaGst")), 1),
                o("p", $m, a(e(s)("indiaHelp")), 1),
                o("div", Sm, [
                  o("label", Cm, [
                    l(a(e(s)("gstRate")), 1),
                    d(o("input", {
                      "onUpdate:modelValue": t[10] || (t[10] = (r) => e(n).gst_rate = r),
                      class: "tb-control",
                      type: "number",
                      min: "0",
                      max: "100",
                      step: "0.0001"
                    }, null, 512), [
                      [p, e(n).gst_rate]
                    ])
                  ]),
                  o("label", ym, [
                    l(a(e(s)("localComponent")), 1),
                    d(o("select", {
                      "onUpdate:modelValue": t[11] || (t[11] = (r) => e(n).local_tax_label = r),
                      class: "tb-control"
                    }, [...t[14] || (t[14] = [
                      o("option", null, "SGST", -1),
                      o("option", null, "UTGST", -1)
                    ])], 512), [
                      [P, e(n).local_tax_label]
                    ])
                  ])
                ])
              ]),
              _: 1
            })) : (c(), T(e(C), { key: 1 }, {
              default: m(() => [
                o("h2", vm, a(e(s)("taxComponents")), 1),
                o("p", bm, a(e(s)("componentsHelp")), 1),
                (c(!0), u(b, null, R(e(n).components, (r, w) => (c(), u("div", {
                  key: w,
                  class: "tb-grid tb-row"
                }, [
                  o("label", Tm, [
                    l(a(e(s)("componentName")), 1),
                    d(o("input", {
                      "onUpdate:modelValue": (H) => r.label = H,
                      class: "tb-control"
                    }, null, 8, hm), [
                      [p, r.label]
                    ])
                  ]),
                  o("label", xm, [
                    l(a(e(s)("rate")), 1),
                    d(o("input", {
                      "onUpdate:modelValue": (H) => r.rate = H,
                      class: "tb-control",
                      type: "number",
                      min: "0",
                      max: "100",
                      step: "0.0001"
                    }, null, 8, Im), [
                      [p, r.rate]
                    ])
                  ]),
                  e(n).components.length > 1 ? (c(), T(e(y), {
                    key: 0,
                    type: "button",
                    variant: "ghost",
                    onClick: (H) => e(n).components.splice(w, 1)
                  }, {
                    default: m(() => [
                      l(a(e(s)("removeComponent")), 1)
                    ]),
                    _: 1
                  }, 8, ["onClick"])) : v("", !0)
                ]))), 128)),
                e(n).components.length < 10 ? (c(), T(e(y), {
                  key: 0,
                  type: "button",
                  variant: "secondary",
                  onClick: t[12] || (t[12] = (r) => e(n).components.push({ label: "", rate: "0" }))
                }, {
                  default: m(() => [
                    l(a(e(s)("addComponent")), 1)
                  ]),
                  _: 1
                })) : v("", !0)
              ]),
              _: 1
            })),
            Object.keys(e(n).errors).length ? (c(), u("div", Rm, [
              (c(!0), u(b, null, R(e(n).errors, (r, w) => (c(), u("p", { key: w }, a(r), 1))), 128))
            ])) : v("", !0),
            g(e(y), {
              type: "submit",
              loading: e(n).processing
            }, {
              default: m(() => [
                l(a(e(s)("saveProfile")), 1)
              ]),
              _: 1
            }, 8, ["loading"])
          ], 32)
        ]),
        _: 1
      })
    ], 64));
  }
}, km = { class: "tb-title" }, wm = { class: "tb-help" }, Hm = { class: "tb-help" }, zm = { class: "tb-grid" }, Pm = { class: "tb-field" }, Nm = { class: "tb-field" }, _m = {
  key: 0,
  class: "tb-help"
}, Dm = { class: "tb-title" }, Am = { class: "tb-grid" }, Fm = { class: "tb-field" }, Um = ["onUpdate:modelValue", "aria-label"], Vm = { class: "tb-field" }, Om = ["onUpdate:modelValue", "aria-label"], jm = {
  key: 0,
  role: "alert",
  class: "tb-errors"
}, qm = { class: "tb-check" }, Lm = {
  __name: "Invoice",
  props: { order: Object, items: Array, profile: Object, snapshot: Object, canIssue: Boolean, regionCode: String, supplyCountry: String, issueUrl: String, downloadUrl: String },
  setup(i) {
    const { layouts: { AppLayout: f }, ui: { PageHeader: $, Card: C, Button: y }, Inertia: { Head: x, useForm: z } } = window.Inventoros, G = i, n = z({ confirmed: !1, region_code: G.regionCode || "", supply_country: G.supplyCountry || "", items: Object.fromEntries(G.items.map((k) => [k.id, { hsn_code: "", unit: "NOS" }])) });
    return (k, S) => (c(), u(b, null, [
      g(e(x), {
        title: e(s)("invoiceTitle", { number: i.order.order_number })
      }, null, 8, ["title"]),
      g(e(f), null, {
        default: m(() => [
          g(e($), {
            title: e(s)("invoiceTitle", { number: i.order.order_number }),
            description: e(s)("invoiceDescription")
          }, null, 8, ["title", "description"]),
          i.snapshot ? (c(), T(e(C), {
            key: 0,
            class: "tb-stack"
          }, {
            default: m(() => [
              o("h2", km, a(i.snapshot.number), 1),
              o("p", null, a(e(s)("issuedSummary", { date: e(em)(i.snapshot.issued_date), total: e(I)(i.snapshot.total, i.snapshot.currency) })), 1),
              o("p", wm, a(e(s)("retainedDetails")), 1),
              (c(!0), u(b, null, R(i.snapshot.components, (t) => (c(), u("p", {
                key: t.label
              }, a(t.label) + ": " + a(e(I)(t.amount, i.snapshot.currency)), 1))), 128)),
              g(e(y), {
                as: "a",
                href: i.downloadUrl
              }, {
                default: m(() => [
                  l(a(e(s)("downloadPdf")), 1)
                ]),
                _: 1
              }, 8, ["href"])
            ]),
            _: 1
          })) : i.canIssue ? (c(), u("form", {
            key: 1,
            class: "tb-stack",
            onSubmit: S[4] || (S[4] = _((t) => e(n).post(i.issueUrl, { preserveScroll: !0 }), ["prevent"]))
          }, [
            g(e(C), null, {
              default: m(() => [
                o("p", Hm, a(e(s)("orderTaxSummary", { total: e(I)(i.order.total, i.order.currency), tax: e(I)(i.order.tax, i.order.currency) })), 1),
                o("div", zm, [
                  o("label", Pm, [
                    l(a(e(s)("supplyCountryCode")), 1),
                    d(o("input", {
                      "onUpdate:modelValue": S[0] || (S[0] = (t) => e(n).supply_country = t),
                      class: "tb-control",
                      maxlength: "2",
                      onInput: S[1] || (S[1] = (t) => e(n).supply_country = e(n).supply_country.toUpperCase())
                    }, null, 544), [
                      [p, e(n).supply_country]
                    ])
                  ]),
                  o("label", Nm, [
                    l(a(e(s)("supplyRegionCode")), 1),
                    d(o("input", {
                      "onUpdate:modelValue": S[2] || (S[2] = (t) => e(n).region_code = t),
                      class: "tb-control"
                    }, null, 512), [
                      [p, e(n).region_code]
                    ])
                  ])
                ]),
                i.profile.mode === "india_gst" ? (c(), u("p", _m, a(e(s)("domesticHelp")), 1)) : v("", !0)
              ]),
              _: 1
            }),
            g(e(C), null, {
              default: m(() => [
                o("h2", Dm, a(e(s)("lineClassification")), 1),
                (c(!0), u(b, null, R(i.items, (t) => (c(), u("div", {
                  key: t.id,
                  class: "tb-row"
                }, [
                  o("p", null, [
                    o("strong", null, a(t.product_name), 1),
                    l(" · " + a(t.sku) + " · " + a(e(Xp)(t.quantity)) + " × " + a(e(I)(t.unit_price, i.order.currency)), 1)
                  ]),
                  o("div", Am, [
                    o("label", Fm, [
                      l(a(i.profile.mode === "india_gst" ? e(s)("hsnRequired") : e(s)("hsnOptional")), 1),
                      d(o("input", {
                        "onUpdate:modelValue": (r) => e(n).items[t.id].hsn_code = r,
                        class: "tb-control",
                        "aria-label": e(s)("hsnFor", { sku: t.sku })
                      }, null, 8, Um), [
                        [p, e(n).items[t.id].hsn_code]
                      ])
                    ]),
                    o("label", Vm, [
                      l(a(e(s)("unitCode")), 1),
                      d(o("input", {
                        "onUpdate:modelValue": (r) => e(n).items[t.id].unit = r,
                        class: "tb-control",
                        "aria-label": e(s)("unitFor", { sku: t.sku })
                      }, null, 8, Om), [
                        [p, e(n).items[t.id].unit]
                      ])
                    ])
                  ])
                ]))), 128))
              ]),
              _: 1
            }),
            Object.keys(e(n).errors).length ? (c(), u("div", jm, [
              (c(!0), u(b, null, R(e(n).errors, (t, r) => (c(), u("p", { key: r }, a(t), 1))), 128))
            ])) : v("", !0),
            o("label", qm, [
              d(o("input", {
                "onUpdate:modelValue": S[3] || (S[3] = (t) => e(n).confirmed = t),
                type: "checkbox"
              }, null, 512), [
                [N, e(n).confirmed]
              ]),
              l(" " + a(e(s)("reviewConfirmation")), 1)
            ]),
            g(e(y), {
              type: "submit",
              loading: e(n).processing,
              disabled: !e(n).confirmed
            }, {
              default: m(() => [
                l(a(e(s)("issueInvoice")), 1)
              ]),
              _: 1
            }, 8, ["loading", "disabled"])
          ], 32)) : (c(), T(e(C), {
            key: 2,
            class: "tb-stack"
          }, {
            default: m(() => [
              o("p", null, a(e(s)("authorizedUserRequired")), 1)
            ]),
            _: 1
          }))
        ]),
        _: 1
      })
    ], 64));
  }
}, Em = {
  key: 0,
  class: "tb-row"
}, Bm = { class: "tb-help" }, Km = { class: "tb-field" }, Mm = ["maxlength"], Zm = {
  key: 0,
  class: "tb-errors"
}, Wm = {
  __name: "CustomerFields",
  props: { form: Object, mode: String },
  setup(i) {
    const f = i, $ = A(() => f.form.plugin_fields.tax_billing);
    return (C, y) => $.value ? (c(), u("div", Em, [
      o("p", Bm, a(i.mode === "india_gst" ? e(s)("customerGstHelp") : e(s)("customerTaxHelp")), 1),
      o("label", Km, [
        l(a(e(s)("defaultSupplyRegionCode")), 1),
        d(o("input", {
          "onUpdate:modelValue": y[0] || (y[0] = (x) => $.value.region_code = x),
          class: "tb-control",
          maxlength: i.mode === "india_gst" ? 2 : 80
        }, null, 8, Mm), [
          [p, $.value.region_code]
        ])
      ]),
      i.form.errors["plugin_fields.tax_billing.region_code"] ? (c(), u("p", Zm, a(i.form.errors["plugin_fields.tax_billing.region_code"]), 1)) : v("", !0)
    ])) : v("", !0);
  }
}, Jm = {
  key: 0,
  class: "tb-row"
}, Qm = { class: "tb-help" }, Ym = {
  __name: "CustomerTaxDetails",
  props: { customer: Object },
  setup(i) {
    return (f, $) => i.customer?.metadata?.tax_billing?.region_code ? (c(), u("div", Jm, [
      o("p", Qm, a(e(s)("defaultSupplyRegion")), 1),
      o("p", null, a(i.customer.metadata.tax_billing.region_code), 1)
    ])) : v("", !0);
  }
}, Xm = {
  __name: "InvoiceLink",
  props: { url: String },
  setup(i) {
    const { ui: { Button: f } } = window.Inventoros;
    return ($, C) => i.url ? (c(), T(e(f), {
      key: 0,
      as: "Link",
      variant: "secondary",
      size: "sm",
      href: i.url
    }, {
      default: m(() => [
        l(a(e(s)("taxInvoice")), 1)
      ]),
      _: 1
    }, 8, ["href"])) : v("", !0);
  }
};
function M$(i) {
  Yp(i), i.registerPage("Settings", Gm), i.registerPage("Invoice", Lm), i.registerComponent("CustomerFields", Wm), i.registerComponent("CustomerTaxDetails", Ym), i.registerComponent("InvoiceLink", Xm);
}
export {
  M$ as default
};
