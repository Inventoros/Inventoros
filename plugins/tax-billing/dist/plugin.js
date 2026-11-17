var { BaseTransition: e, BaseTransitionPropsValidators: t, Comment: n, DeprecationTypes: r, EffectScope: i, ErrorCodes: a, ErrorTypeStrings: o, Fragment: s, KeepAlive: c, ReactiveEffect: l, Static: u, Suspense: ee, Teleport: te, Text: ne, TrackOpTypes: re, Transition: ie, TransitionGroup: ae, TriggerOpTypes: oe, VueElement: se, __esModule: ce, assertNumber: le, callWithAsyncErrorHandling: ue, callWithErrorHandling: de, camelize: fe, capitalize: pe, cloneVNode: me, compatUtils: he, compile: ge, computed: d, createApp: _e, createBlock: f, createCommentVNode: p, createElementBlock: m, createElementVNode: h, createHydrationRenderer: ve, createPropsRestProxy: ye, createRenderer: be, createSSRApp: xe, createSlots: Se, createStaticVNode: Ce, createTextVNode: g, createVNode: _, customRef: we, defineAsyncComponent: Te, defineComponent: Ee, defineCustomElement: De, defineEmits: Oe, defineExpose: ke, defineModel: Ae, defineOptions: je, defineProps: Me, defineSSRCustomElement: Ne, defineSlots: Pe, devtools: Fe, effect: Ie, effectScope: Le, getCurrentInstance: Re, getCurrentScope: ze, getCurrentWatcher: Be, getTransitionRawChildren: Ve, guardReactiveProps: He, h: Ue, handleError: We, hasInjectionContext: Ge, hydrate: Ke, hydrateOnIdle: qe, hydrateOnInteraction: Je, hydrateOnMediaQuery: Ye, hydrateOnVisible: Xe, initCustomFormatter: Ze, initDirectivesForSSR: Qe, inject: $e, isMemoSame: et, isProxy: tt, isReactive: nt, isReadonly: rt, isRef: it, isRuntimeOnly: at, isShallow: ot, isVNode: st, markRaw: ct, mergeDefaults: lt, mergeModels: ut, mergeProps: dt, nextTick: ft, nodeOps: pt, normalizeClass: mt, normalizeProps: ht, normalizeStyle: gt, onActivated: _t, onBeforeMount: vt, onBeforeUnmount: yt, onBeforeUpdate: bt, onDeactivated: xt, onErrorCaptured: St, onMounted: Ct, onRenderTracked: wt, onRenderTriggered: Tt, onScopeDispose: Et, onServerPrefetch: Dt, onUnmounted: Ot, onUpdated: kt, onWatcherCleanup: At, openBlock: v, patchProp: jt, popScopeId: Mt, provide: Nt, proxyRefs: Pt, pushScopeId: Ft, queuePostFlushCb: It, reactive: Lt, readonly: Rt, ref: zt, registerRuntimeCompiler: Bt, render: Vt, renderList: y, renderSlot: Ht, resolveComponent: Ut, resolveDirective: Wt, resolveDynamicComponent: Gt, resolveFilter: Kt, resolveTransitionHooks: qt, setBlockTracking: Jt, setDevtoolsHook: Yt, setTransitionHooks: Xt, shallowReactive: Zt, shallowReadonly: Qt, shallowRef: $t, ssrContextKey: en, ssrUtils: tn, stop: nn, toDisplayString: b, toHandlerKey: rn, toHandlers: an, toRaw: on, toRef: sn, toRefs: cn, toValue: ln, transformVNodeArgs: un, triggerRef: dn, unref: x, useAttrs: fn, useCssModule: pn, useCssVars: mn, useHost: hn, useId: gn, useModel: _n, useSSRContext: vn, useShadowRoot: yn, useSlots: bn, useTemplateRef: xn, useTransitionState: Sn, vModelCheckbox: S, vModelDynamic: Cn, vModelRadio: wn, vModelSelect: C, vModelText: w, vShow: Tn, version: En, warn: Dn, watch: On, watchEffect: kn, watchPostEffect: An, watchSyncEffect: jn, withAsyncContext: Mn, withCtx: T, withDefaults: Nn, withDirectives: E, withKeys: Pn, withMemo: Fn, withModifiers: D, withScopeId: In } = window.Inventoros.Vue, Ln = /* #__PURE__ */ Object.assign({
	"./locales/ar.json": {
		title: "الفوترة الضريبية",
		settingsDescription: "بيانات التسجيل الضريبي وعرض الفواتير لهذه المؤسسة.",
		settingsHelp: "حدّد المعاملة الضريبية لنشاطك. يجب أن تتطابق ضريبة الطلب المسجلة مع المعدلات المختارة قبل إصدار الفاتورة. لا تختار هذه الوحدة المعدلات ولا تقدم الإقرارات ولا تحصل على إقرارات حكومية بالفواتير الإلكترونية.",
		profile: "الملف",
		customCountryProfile: "ملف دولة مخصص",
		indiaProfile: "GST الهند: توريدات محلية",
		countryCode: "رمز الدولة (حرفان وفق ISO)",
		supplierName: "اسم المورد",
		supplierTaxRegistration: "التسجيل الضريبي للمورد",
		supplierRegionCode: "رمز منطقة/ولاية المورد",
		taxIdentifierLabel: "تسمية المعرّف الضريبي",
		taxIdentifierPlaceholder: "GSTIN، رقم ضريبة القيمة المضافة، TIN",
		supplierAddress: "عنوان المورد",
		sizeGroupingOption: "خيار المتغير لتجميع المقاسات",
		shippingTaxable: "تضمين الشحن في الوعاء الضريبي",
		indiaGst: "GST الهند",
		indiaHelp: "تُطبق CGST وSGST/UTGST عند تطابق رمزي ولاية المورد ومكان التوريد، وإلا تُطبق IGST. أدخل معدل GST الإجمالي. تحتاج الصادرات وSEZ والتكليف العكسي والرسوم الإضافية والفوترة الإلكترونية إلى معالجة خاصة.",
		gstRate: "معدل GST الإجمالي (%)",
		localComponent: "المكوّن المحلي",
		taxComponents: "مكوّنات الضريبة",
		componentsHelp: "اضبط الضرائب المضافة مثل ضريبة القيمة المضافة أو GST/PST أو ضريبة المبيعات. لا تُؤتمت المعدلات المختلطة والضرائب المركبة وقواعد الإقرار الوطنية.",
		componentName: "اسم المكوّن",
		rate: "المعدل (%)",
		removeComponent: "إزالة المكوّن",
		addComponent: "إضافة مكوّن ضريبي",
		saveProfile: "حفظ الملف الضريبي",
		invoiceTitle: "فاتورة ضريبية: {number}",
		invoiceDescription: "راجع البيانات الضريبية قبل الاحتفاظ بالفاتورة الصادرة.",
		issuedSummary: "صدرت في {date} · {total}",
		retainedDetails: "تُحفظ البيانات عند الإصدار. لا تعيد التعديلات اللاحقة على العميل أو الطلب أو الملف الضريبي كتابة هذه الفاتورة.",
		downloadPdf: "تنزيل الفاتورة بصيغة PDF",
		orderTaxSummary: "إجمالي الطلب: {total}. الضريبة المسجلة: {tax}. يجب أن تتوافق مكوّنات الضريبة مع هذا المبلغ. لا يغير الإصدار المخزون أو المدفوعات.",
		supplyCountryCode: "رمز دولة مكان التوريد",
		supplyRegionCode: "رمز منطقة/ولاية مكان التوريد",
		domesticHelp: "يغطي هذا الملف التوريدات المحلية في الهند. تحقق من مكان التوريد وGSTIN وHSN/SAC ورموز الوحدات قبل الإصدار.",
		lineClassification: "تصنيف البنود",
		hsnRequired: "HSN/SAC (مطلوب)",
		hsnOptional: "HSN/SAC (اختياري)",
		hsnFor: "HSN/SAC للمنتج {sku}",
		unitCode: "رمز الوحدة",
		unitFor: "وحدة المنتج {sku}",
		reviewConfirmation: "راجعت التسجيل الضريبي ومكان التوريد والتصنيف والضريبة المسجلة.",
		issueInvoice: "إصدار فاتورة ضريبية",
		authorizedUserRequired: "يجب أن يصدر مستخدم مخوّل الفاتورة الضريبية قبل تنزيلها.",
		customerGstHelp: "أدخل GSTIN للعميل في حقل المعرّف الضريبي/ضريبة القيمة المضافة أعلاه. اتركه فارغًا للعميل غير المسجل.",
		customerTaxHelp: "استخدم حقل المعرّف الضريبي/ضريبة القيمة المضافة للتسجيل الضريبي المحلي للعميل.",
		defaultSupplyRegionCode: "رمز منطقة/ولاية مكان التوريد الافتراضي",
		defaultSupplyRegion: "منطقة/ولاية مكان التوريد الافتراضية",
		taxInvoice: "فاتورة ضريبية",
		permissions: {
			issue: {
				label: "إصدار الفواتير الضريبية",
				description: "الفوترة الضريبية"
			},
			manage: {
				label: "إدارة ملفات الفوترة الضريبية",
				description: "الفوترة الضريبية"
			}
		}
	},
	"./locales/de.json": {
		title: "Steuerabrechnung",
		settingsDescription: "Steuerliche Registrierungsdaten und Rechnungsdarstellung dieser Organisation.",
		settingsHelp: "Legen Sie die steuerliche Behandlung Ihres Unternehmens fest. Die erfasste Auftragssteuer muss vor der Rechnungsausstellung den gewählten Sätzen entsprechen. Dieses Modul wählt keine Steuersätze, reicht keine Erklärungen ein und holt keine behördlichen E-Rechnungsbestätigungen ein.",
		profile: "Profil",
		customCountryProfile: "Benutzerdefiniertes Länderprofil",
		indiaProfile: "Indische GST: Inlandslieferungen",
		countryCode: "Ländercode (ISO, 2 Buchstaben)",
		supplierName: "Lieferantenname",
		supplierTaxRegistration: "Steuerregistrierung des Lieferanten",
		supplierRegionCode: "Regions-/Bundesstaatencode des Lieferanten",
		taxIdentifierLabel: "Bezeichnung der Steuerkennung",
		taxIdentifierPlaceholder: "GSTIN, USt-Nummer, TIN",
		supplierAddress: "Lieferantenadresse",
		sizeGroupingOption: "Variantenoption zur Größengruppierung",
		shippingTaxable: "Versand in die Steuerbemessungsgrundlage einbeziehen",
		indiaGst: "Indische GST",
		indiaHelp: "CGST und SGST/UTGST gelten bei gleichen Bundesstaatencodes von Lieferant und Lieferort; andernfalls gilt IGST. Geben Sie den gesamten GST-Satz ein. Exporte, SEZ, Steuerschuldumkehr, Zusatzabgaben und E-Rechnungen benötigen eine gesonderte Behandlung.",
		gstRate: "Gesamter GST-Satz (%)",
		localComponent: "Lokale Komponente",
		taxComponents: "Steuerkomponenten",
		componentsHelp: "Konfigurieren Sie additive Steuern wie USt, GST/PST oder Verkaufssteuer. Gemischte Sätze, zusammengesetzte Steuern und nationale Meldevorschriften werden nicht automatisiert.",
		componentName: "Komponentenname",
		rate: "Satz (%)",
		removeComponent: "Komponente entfernen",
		addComponent: "Steuerkomponente hinzufügen",
		saveProfile: "Steuerprofil speichern",
		invoiceTitle: "Steuerrechnung: {number}",
		invoiceDescription: "Prüfen Sie die Steuerdaten, bevor Sie die ausgestellte Rechnung aufbewahren.",
		issuedSummary: "Ausgestellt am {date} · {total}",
		retainedDetails: "Diese Daten werden bei Ausstellung festgehalten. Spätere Änderungen an Kunde, Auftrag oder Steuerprofil ändern diese Rechnung nicht.",
		downloadPdf: "Rechnung als PDF herunterladen",
		orderTaxSummary: "Auftragssumme: {total}. Erfasste Steuer: {tax}. Die Steuerkomponenten müssen diesem Betrag entsprechen. Die Ausstellung ändert weder Bestand noch Zahlungen.",
		supplyCountryCode: "Ländercode des Lieferorts",
		supplyRegionCode: "Regions-/Bundesstaatencode des Lieferorts",
		domesticHelp: "Dieses Profil gilt für Inlandslieferungen in Indien. Prüfen Sie Lieferort, GSTIN, HSN/SAC und Einheitencodes vor der Ausstellung.",
		lineClassification: "Positionsklassifizierung",
		hsnRequired: "HSN/SAC (erforderlich)",
		hsnOptional: "HSN/SAC (optional)",
		hsnFor: "HSN/SAC für {sku}",
		unitCode: "Einheitencode",
		unitFor: "Einheit für {sku}",
		reviewConfirmation: "Ich habe Steuerregistrierung, Lieferort, Klassifizierung und erfasste Steuer geprüft.",
		issueInvoice: "Steuerrechnung ausstellen",
		authorizedUserRequired: "Ein berechtigter Benutzer muss die Steuerrechnung vor dem Download ausstellen.",
		customerGstHelp: "Tragen Sie die GSTIN des Kunden oben im Feld Steuer-ID/USt ein. Lassen Sie es bei nicht registrierten Kunden leer.",
		customerTaxHelp: "Verwenden Sie das Feld Steuer-ID/USt für die lokale Steuerregistrierung des Kunden.",
		defaultSupplyRegionCode: "Standard-Regions-/Bundesstaatencode des Lieferorts",
		defaultSupplyRegion: "Standardregion/-bundesstaat des Lieferorts",
		taxInvoice: "Steuerrechnung",
		permissions: {
			issue: {
				label: "Steuerrechnungen ausstellen",
				description: "Steuerabrechnung"
			},
			manage: {
				label: "Steuerabrechnungsprofile verwalten",
				description: "Steuerabrechnung"
			}
		}
	},
	"./locales/en.json": {
		title: "Tax billing",
		settingsDescription: "Tax-registration details and invoice presentation for this organization.",
		settingsHelp: "Set the tax treatment that applies to your business. Recorded order tax must match the selected rates before an invoice can be issued. This module does not choose tax rates, file returns or obtain government e-invoice acknowledgements.",
		profile: "Profile",
		customCountryProfile: "Custom country profile",
		indiaProfile: "India GST: domestic supplies",
		countryCode: "Country code (ISO 2-letter)",
		supplierName: "Supplier name",
		supplierTaxRegistration: "Supplier tax registration",
		supplierRegionCode: "Supplier region/state code",
		taxIdentifierLabel: "Tax identifier label",
		taxIdentifierPlaceholder: "GSTIN, VAT number, TIN",
		supplierAddress: "Supplier address",
		sizeGroupingOption: "Variant option for size grouping",
		shippingTaxable: "Include shipping in the taxable base",
		indiaGst: "India GST",
		indiaHelp: "CGST plus SGST/UTGST applies when supplier and place-of-supply state codes match; IGST is used when they differ. Enter the applicable total GST rate. Exports, SEZ, reverse charge, cess and e-invoicing need dedicated treatment.",
		gstRate: "Total GST rate (%)",
		localComponent: "Local component",
		taxComponents: "Tax components",
		componentsHelp: "Configure additive taxes such as VAT, GST/PST or sales tax. Mixed-rate, compound-tax and country filing rules are not automated.",
		componentName: "Component name",
		rate: "Rate (%)",
		removeComponent: "Remove component",
		addComponent: "Add tax component",
		saveProfile: "Save tax billing profile",
		invoiceTitle: "Tax invoice: {number}",
		invoiceDescription: "Review tax details before retaining an issued invoice.",
		issuedSummary: "Issued {date} · {total}",
		retainedDetails: "These details are retained from issuance. Later edits to the customer, order or tax profile do not rewrite this invoice.",
		downloadPdf: "Download invoice PDF",
		orderTaxSummary: "Order total: {total}. Recorded tax: {tax}. Invoice tax components must reconcile with this amount. Issuing does not change stock or payments.",
		supplyCountryCode: "Place-of-supply country code",
		supplyRegionCode: "Place-of-supply region/state code",
		domesticHelp: "This profile covers domestic India supplies. Check the place of supply, GSTIN, HSN/SAC and unit codes before issuing.",
		lineClassification: "Line classification",
		hsnRequired: "HSN/SAC (required)",
		hsnOptional: "HSN/SAC (optional)",
		hsnFor: "HSN/SAC for {sku}",
		unitCode: "Unit code",
		unitFor: "Unit for {sku}",
		reviewConfirmation: "I reviewed the tax registration, place of supply, classification and recorded tax.",
		issueInvoice: "Issue tax invoice",
		authorizedUserRequired: "An authorized user must issue the tax invoice before it can be downloaded.",
		customerGstHelp: "Enter the customer GSTIN in the Tax ID/VAT field above. Leave it blank for an unregistered customer.",
		customerTaxHelp: "Use the Tax ID/VAT field for the customer’s local tax registration.",
		defaultSupplyRegionCode: "Default place-of-supply region/state code",
		defaultSupplyRegion: "Default place-of-supply region/state",
		taxInvoice: "Tax invoice",
		permissions: {
			issue: {
				label: "Issue tax invoices",
				description: "Tax billing"
			},
			manage: {
				label: "Manage tax billing profiles",
				description: "Tax billing"
			}
		}
	},
	"./locales/es.json": {
		title: "Facturación fiscal",
		settingsDescription: "Datos de registro fiscal y presentación de facturas de esta organización.",
		settingsHelp: "Configura el tratamiento fiscal de tu empresa. El impuesto registrado del pedido debe coincidir con las tasas seleccionadas antes de emitir una factura. Este módulo no elige tasas, presenta declaraciones ni obtiene acuses gubernamentales de facturas electrónicas.",
		profile: "Perfil",
		customCountryProfile: "Perfil de país personalizado",
		indiaProfile: "GST de India: operaciones nacionales",
		countryCode: "Código de país (ISO de 2 letras)",
		supplierName: "Nombre del proveedor",
		supplierTaxRegistration: "Registro fiscal del proveedor",
		supplierRegionCode: "Código de región/estado del proveedor",
		taxIdentifierLabel: "Etiqueta del identificador fiscal",
		taxIdentifierPlaceholder: "GSTIN, número de IVA, TIN",
		supplierAddress: "Dirección del proveedor",
		sizeGroupingOption: "Opción de variante para agrupar tallas",
		shippingTaxable: "Incluir el envío en la base imponible",
		indiaGst: "GST de India",
		indiaHelp: "Se aplica CGST más SGST/UTGST si coinciden los códigos de estado del proveedor y del lugar de suministro; si difieren, se aplica IGST. Introduce la tasa total de GST. Las exportaciones, SEZ, inversión del sujeto pasivo, recargos y facturación electrónica requieren un tratamiento específico.",
		gstRate: "Tasa total de GST (%)",
		localComponent: "Componente local",
		taxComponents: "Componentes fiscales",
		componentsHelp: "Configura impuestos aditivos como IVA, GST/PST o impuestos sobre ventas. No se automatizan tasas mixtas, impuestos compuestos ni normas nacionales de declaración.",
		componentName: "Nombre del componente",
		rate: "Tasa (%)",
		removeComponent: "Eliminar componente",
		addComponent: "Añadir componente fiscal",
		saveProfile: "Guardar perfil fiscal",
		invoiceTitle: "Factura fiscal: {number}",
		invoiceDescription: "Revisa los datos fiscales antes de conservar la factura emitida.",
		issuedSummary: "Emitida el {date} · {total}",
		retainedDetails: "Estos datos se conservan desde la emisión. Los cambios posteriores en el cliente, pedido o perfil fiscal no modifican esta factura.",
		downloadPdf: "Descargar factura en PDF",
		orderTaxSummary: "Total del pedido: {total}. Impuesto registrado: {tax}. Los componentes fiscales deben cuadrar con este importe. La emisión no cambia existencias ni pagos.",
		supplyCountryCode: "Código de país del lugar de suministro",
		supplyRegionCode: "Código de región/estado del lugar de suministro",
		domesticHelp: "Este perfil cubre operaciones nacionales de India. Comprueba el lugar de suministro, GSTIN, HSN/SAC y códigos de unidad antes de emitir.",
		lineClassification: "Clasificación de líneas",
		hsnRequired: "HSN/SAC (obligatorio)",
		hsnOptional: "HSN/SAC (opcional)",
		hsnFor: "HSN/SAC de {sku}",
		unitCode: "Código de unidad",
		unitFor: "Unidad de {sku}",
		reviewConfirmation: "He revisado el registro fiscal, lugar de suministro, clasificación e impuesto registrado.",
		issueInvoice: "Emitir factura fiscal",
		authorizedUserRequired: "Un usuario autorizado debe emitir la factura fiscal antes de descargarla.",
		customerGstHelp: "Introduce el GSTIN del cliente en el campo ID fiscal/IVA anterior. Déjalo vacío si el cliente no está registrado.",
		customerTaxHelp: "Utiliza el campo ID fiscal/IVA para el registro fiscal local del cliente.",
		defaultSupplyRegionCode: "Código predeterminado de región/estado del suministro",
		defaultSupplyRegion: "Región/estado predeterminado del suministro",
		taxInvoice: "Factura fiscal",
		permissions: {
			issue: {
				label: "Emitir facturas fiscales",
				description: "Facturación fiscal"
			},
			manage: {
				label: "Gestionar perfiles fiscales",
				description: "Facturación fiscal"
			}
		}
	},
	"./locales/fr.json": {
		title: "Facturation fiscale",
		settingsDescription: "Coordonnées fiscales et présentation des factures de cette organisation.",
		settingsHelp: "Définissez le régime fiscal de votre entreprise. La taxe enregistrée sur la commande doit correspondre aux taux choisis avant l’émission. Ce module ne choisit pas les taux, ne dépose pas les déclarations et n’obtient pas les accusés gouvernementaux de facturation électronique.",
		profile: "Profil",
		customCountryProfile: "Profil de pays personnalisé",
		indiaProfile: "GST Inde : opérations nationales",
		countryCode: "Code pays (ISO à 2 lettres)",
		supplierName: "Nom du fournisseur",
		supplierTaxRegistration: "Immatriculation fiscale du fournisseur",
		supplierRegionCode: "Code de région/État du fournisseur",
		taxIdentifierLabel: "Libellé de l’identifiant fiscal",
		taxIdentifierPlaceholder: "GSTIN, numéro de TVA, TIN",
		supplierAddress: "Adresse du fournisseur",
		sizeGroupingOption: "Option de variante pour regrouper les tailles",
		shippingTaxable: "Inclure la livraison dans la base imposable",
		indiaGst: "GST Inde",
		indiaHelp: "CGST et SGST/UTGST s’appliquent si les codes d’État du fournisseur et du lieu de fourniture concordent ; sinon, IGST s’applique. Saisissez le taux total de GST. Exportations, SEZ, autoliquidation, prélèvements et facturation électronique nécessitent un traitement spécifique.",
		gstRate: "Taux total de GST (%)",
		localComponent: "Composante locale",
		taxComponents: "Composantes fiscales",
		componentsHelp: "Configurez des taxes additives comme la TVA, GST/PST ou la taxe de vente. Les taux mixtes, taxes composées et règles déclaratives nationales ne sont pas automatisés.",
		componentName: "Nom de la composante",
		rate: "Taux (%)",
		removeComponent: "Supprimer la composante",
		addComponent: "Ajouter une composante fiscale",
		saveProfile: "Enregistrer le profil fiscal",
		invoiceTitle: "Facture fiscale : {number}",
		invoiceDescription: "Vérifiez les données fiscales avant de conserver la facture émise.",
		issuedSummary: "Émise le {date} · {total}",
		retainedDetails: "Ces données sont conservées à l’émission. Les modifications ultérieures du client, de la commande ou du profil fiscal ne modifient pas cette facture.",
		downloadPdf: "Télécharger la facture PDF",
		orderTaxSummary: "Total de la commande : {total}. Taxe enregistrée : {tax}. Les composantes fiscales doivent correspondre à ce montant. L’émission ne modifie ni stocks ni paiements.",
		supplyCountryCode: "Code pays du lieu de fourniture",
		supplyRegionCode: "Code de région/État du lieu de fourniture",
		domesticHelp: "Ce profil couvre les opérations nationales en Inde. Vérifiez le lieu de fourniture, GSTIN, HSN/SAC et les codes d’unité avant l’émission.",
		lineClassification: "Classification des lignes",
		hsnRequired: "HSN/SAC (obligatoire)",
		hsnOptional: "HSN/SAC (facultatif)",
		hsnFor: "HSN/SAC de {sku}",
		unitCode: "Code d’unité",
		unitFor: "Unité de {sku}",
		reviewConfirmation: "J’ai vérifié l’immatriculation fiscale, le lieu de fourniture, la classification et la taxe enregistrée.",
		issueInvoice: "Émettre la facture fiscale",
		authorizedUserRequired: "Un utilisateur autorisé doit émettre la facture fiscale avant son téléchargement.",
		customerGstHelp: "Saisissez le GSTIN du client dans le champ Identifiant fiscal/TVA ci-dessus. Laissez-le vide si le client n’est pas immatriculé.",
		customerTaxHelp: "Utilisez le champ Identifiant fiscal/TVA pour l’immatriculation fiscale locale du client.",
		defaultSupplyRegionCode: "Code de région/État de fourniture par défaut",
		defaultSupplyRegion: "Région/État de fourniture par défaut",
		taxInvoice: "Facture fiscale",
		permissions: {
			issue: {
				label: "Émettre des factures fiscales",
				description: "Facturation fiscale"
			},
			manage: {
				label: "Gérer les profils fiscaux",
				description: "Facturation fiscale"
			}
		}
	},
	"./locales/it.json": {
		title: "Fatturazione fiscale",
		settingsDescription: "Dati di registrazione fiscale e presentazione delle fatture dell’organizzazione.",
		settingsHelp: "Imposta il trattamento fiscale dell’azienda. L’imposta registrata nell’ordine deve corrispondere alle aliquote scelte prima dell’emissione. Questo modulo non sceglie aliquote, presenta dichiarazioni o ottiene conferme governative di fatturazione elettronica.",
		profile: "Profilo",
		customCountryProfile: "Profilo paese personalizzato",
		indiaProfile: "GST India: operazioni nazionali",
		countryCode: "Codice paese (ISO a 2 lettere)",
		supplierName: "Nome del fornitore",
		supplierTaxRegistration: "Registrazione fiscale del fornitore",
		supplierRegionCode: "Codice regione/stato del fornitore",
		taxIdentifierLabel: "Etichetta dell’identificativo fiscale",
		taxIdentifierPlaceholder: "GSTIN, numero IVA, TIN",
		supplierAddress: "Indirizzo del fornitore",
		sizeGroupingOption: "Opzione variante per raggruppare le taglie",
		shippingTaxable: "Includi la spedizione nella base imponibile",
		indiaGst: "GST India",
		indiaHelp: "Si applicano CGST e SGST/UTGST se i codici di stato del fornitore e del luogo di fornitura coincidono; altrimenti si applica IGST. Inserisci l’aliquota GST totale. Esportazioni, SEZ, inversione contabile, tributi aggiuntivi e fatturazione elettronica richiedono un trattamento specifico.",
		gstRate: "Aliquota GST totale (%)",
		localComponent: "Componente locale",
		taxComponents: "Componenti fiscali",
		componentsHelp: "Configura imposte additive come IVA, GST/PST o imposte sulle vendite. Aliquote miste, imposte composte e regole dichiarative nazionali non sono automatizzate.",
		componentName: "Nome componente",
		rate: "Aliquota (%)",
		removeComponent: "Rimuovi componente",
		addComponent: "Aggiungi componente fiscale",
		saveProfile: "Salva profilo fiscale",
		invoiceTitle: "Fattura fiscale: {number}",
		invoiceDescription: "Controlla i dati fiscali prima di conservare la fattura emessa.",
		issuedSummary: "Emessa il {date} · {total}",
		retainedDetails: "Questi dati sono conservati dall’emissione. Le modifiche successive a cliente, ordine o profilo fiscale non riscrivono questa fattura.",
		downloadPdf: "Scarica fattura PDF",
		orderTaxSummary: "Totale ordine: {total}. Imposta registrata: {tax}. I componenti fiscali devono corrispondere a questo importo. L’emissione non modifica scorte o pagamenti.",
		supplyCountryCode: "Codice paese del luogo di fornitura",
		supplyRegionCode: "Codice regione/stato del luogo di fornitura",
		domesticHelp: "Questo profilo copre le operazioni nazionali in India. Verifica luogo di fornitura, GSTIN, HSN/SAC e codici unità prima dell’emissione.",
		lineClassification: "Classificazione righe",
		hsnRequired: "HSN/SAC (obbligatorio)",
		hsnOptional: "HSN/SAC (facoltativo)",
		hsnFor: "HSN/SAC di {sku}",
		unitCode: "Codice unità",
		unitFor: "Unità di {sku}",
		reviewConfirmation: "Ho verificato registrazione fiscale, luogo di fornitura, classificazione e imposta registrata.",
		issueInvoice: "Emetti fattura fiscale",
		authorizedUserRequired: "Un utente autorizzato deve emettere la fattura fiscale prima del download.",
		customerGstHelp: "Inserisci il GSTIN del cliente nel campo ID fiscale/IVA sopra. Lascialo vuoto per un cliente non registrato.",
		customerTaxHelp: "Usa il campo ID fiscale/IVA per la registrazione fiscale locale del cliente.",
		defaultSupplyRegionCode: "Codice predefinito regione/stato di fornitura",
		defaultSupplyRegion: "Regione/stato di fornitura predefinito",
		taxInvoice: "Fattura fiscale",
		permissions: {
			issue: {
				label: "Emettere fatture fiscali",
				description: "Fatturazione fiscale"
			},
			manage: {
				label: "Gestire profili fiscali",
				description: "Fatturazione fiscale"
			}
		}
	},
	"./locales/ja.json": {
		title: "税務請求",
		settingsDescription: "この組織の税務登録情報と請求書の表示設定。",
		settingsHelp: "事業の税務上の扱いを設定します。請求書発行前に、注文に記録された税額と選択した税率が一致する必要があります。このモジュールは税率の選択、申告、政府による電子請求書の承認取得を行いません。",
		profile: "プロファイル",
		customCountryProfile: "カスタム国別プロファイル",
		indiaProfile: "インドGST：国内供給",
		countryCode: "国コード（ISO 2文字）",
		supplierName: "供給者名",
		supplierTaxRegistration: "供給者の税務登録",
		supplierRegionCode: "供給者の地域・州コード",
		taxIdentifierLabel: "税務識別番号のラベル",
		taxIdentifierPlaceholder: "GSTIN、VAT番号、TIN",
		supplierAddress: "供給者住所",
		sizeGroupingOption: "サイズ集計に使うバリエーション項目",
		shippingTaxable: "送料を課税標準に含める",
		indiaGst: "インドGST",
		indiaHelp: "供給者と供給地の州コードが一致する場合はCGSTとSGST/UTGST、異なる場合はIGSTを適用します。GST合計税率を入力してください。輸出、SEZ、リバースチャージ、追加税、電子請求書は個別の対応が必要です。",
		gstRate: "GST合計税率（%）",
		localComponent: "地方税項目",
		taxComponents: "税項目",
		componentsHelp: "VAT、GST/PST、売上税など加算方式の税を設定します。混合税率、複合税、国別申告規則は自動処理されません。",
		componentName: "項目名",
		rate: "税率（%）",
		removeComponent: "項目を削除",
		addComponent: "税項目を追加",
		saveProfile: "税務プロファイルを保存",
		invoiceTitle: "税務請求書：{number}",
		invoiceDescription: "発行済み請求書を保存する前に税務情報を確認してください。",
		issuedSummary: "発行日 {date} · {total}",
		retainedDetails: "発行時の情報が保存されます。その後に顧客、注文、税務プロファイルを変更しても、この請求書は変更されません。",
		downloadPdf: "請求書PDFをダウンロード",
		orderTaxSummary: "注文合計：{total}。記録済み税額：{tax}。税項目の合計がこの金額と一致する必要があります。発行によって在庫や支払いは変更されません。",
		supplyCountryCode: "供給地の国コード",
		supplyRegionCode: "供給地の地域・州コード",
		domesticHelp: "このプロファイルはインド国内供給用です。発行前に供給地、GSTIN、HSN/SAC、単位コードを確認してください。",
		lineClassification: "明細分類",
		hsnRequired: "HSN/SAC（必須）",
		hsnOptional: "HSN/SAC（任意）",
		hsnFor: "{sku}のHSN/SAC",
		unitCode: "単位コード",
		unitFor: "{sku}の単位",
		reviewConfirmation: "税務登録、供給地、分類、記録済み税額を確認しました。",
		issueInvoice: "税務請求書を発行",
		authorizedUserRequired: "ダウンロードするには、権限を持つユーザーが税務請求書を発行する必要があります。",
		customerGstHelp: "上の税務ID/VAT欄に顧客のGSTINを入力してください。未登録の顧客の場合は空欄にします。",
		customerTaxHelp: "顧客の現地税務登録には税務ID/VAT欄を使用してください。",
		defaultSupplyRegionCode: "既定の供給地地域・州コード",
		defaultSupplyRegion: "既定の供給地地域・州",
		taxInvoice: "税務請求書",
		permissions: {
			issue: {
				label: "税務請求書の発行",
				description: "税務請求"
			},
			manage: {
				label: "税務請求プロファイルの管理",
				description: "税務請求"
			}
		}
	},
	"./locales/ko.json": {
		title: "세금 청구",
		settingsDescription: "조직의 세무 등록 정보 및 청구서 표시 설정입니다.",
		settingsHelp: "사업에 적용되는 세금 처리를 설정하세요. 청구서 발행 전 주문에 기록된 세금이 선택한 세율과 일치해야 합니다. 이 모듈은 세율 선택, 세금 신고, 정부 전자 청구서 승인 취득을 수행하지 않습니다.",
		profile: "프로필",
		customCountryProfile: "사용자 지정 국가 프로필",
		indiaProfile: "인도 GST: 국내 공급",
		countryCode: "국가 코드(ISO 2자리)",
		supplierName: "공급자 이름",
		supplierTaxRegistration: "공급자 세무 등록",
		supplierRegionCode: "공급자 지역/주 코드",
		taxIdentifierLabel: "세금 식별자 레이블",
		taxIdentifierPlaceholder: "GSTIN, VAT 번호, TIN",
		supplierAddress: "공급자 주소",
		sizeGroupingOption: "사이즈 그룹에 사용할 옵션",
		shippingTaxable: "배송비를 과세 표준에 포함",
		indiaGst: "인도 GST",
		indiaHelp: "공급자와 공급지의 주 코드가 같으면 CGST와 SGST/UTGST, 다르면 IGST가 적용됩니다. 총 GST 세율을 입력하세요. 수출, SEZ, 역외 과세, 추가 부담금 및 전자 청구서는 별도 처리가 필요합니다.",
		gstRate: "총 GST 세율(%)",
		localComponent: "지역 구성 요소",
		taxComponents: "세금 구성 요소",
		componentsHelp: "VAT, GST/PST, 판매세 등의 가산 세금을 설정하세요. 혼합 세율, 복합 세금, 국가별 신고 규칙은 자동화되지 않습니다.",
		componentName: "구성 요소 이름",
		rate: "세율(%)",
		removeComponent: "구성 요소 삭제",
		addComponent: "세금 구성 요소 추가",
		saveProfile: "세금 프로필 저장",
		invoiceTitle: "세금 청구서: {number}",
		invoiceDescription: "발행된 청구서를 보관하기 전에 세금 정보를 검토하세요.",
		issuedSummary: "발행일 {date} · {total}",
		retainedDetails: "발행 당시 정보가 보존됩니다. 이후 고객, 주문 또는 세금 프로필을 수정해도 이 청구서는 변경되지 않습니다.",
		downloadPdf: "청구서 PDF 다운로드",
		orderTaxSummary: "주문 합계: {total}. 기록된 세금: {tax}. 세금 구성 요소가 이 금액과 일치해야 합니다. 발행은 재고나 결제를 변경하지 않습니다.",
		supplyCountryCode: "공급지 국가 코드",
		supplyRegionCode: "공급지 지역/주 코드",
		domesticHelp: "이 프로필은 인도 국내 공급용입니다. 발행 전에 공급지, GSTIN, HSN/SAC 및 단위 코드를 확인하세요.",
		lineClassification: "품목 분류",
		hsnRequired: "HSN/SAC(필수)",
		hsnOptional: "HSN/SAC(선택)",
		hsnFor: "{sku}의 HSN/SAC",
		unitCode: "단위 코드",
		unitFor: "{sku}의 단위",
		reviewConfirmation: "세무 등록, 공급지, 분류 및 기록된 세금을 검토했습니다.",
		issueInvoice: "세금 청구서 발행",
		authorizedUserRequired: "다운로드하기 전에 권한이 있는 사용자가 세금 청구서를 발행해야 합니다.",
		customerGstHelp: "위의 세금 ID/VAT 필드에 고객 GSTIN을 입력하세요. 미등록 고객은 비워 두세요.",
		customerTaxHelp: "고객의 현지 세무 등록은 세금 ID/VAT 필드를 사용하세요.",
		defaultSupplyRegionCode: "기본 공급지 지역/주 코드",
		defaultSupplyRegion: "기본 공급지 지역/주",
		taxInvoice: "세금 청구서",
		permissions: {
			issue: {
				label: "세금 청구서 발행",
				description: "세금 청구"
			},
			manage: {
				label: "세금 청구 프로필 관리",
				description: "세금 청구"
			}
		}
	},
	"./locales/nl.json": {
		title: "Belastingfacturatie",
		settingsDescription: "Belastingregistratie en factuurweergave voor deze organisatie.",
		settingsHelp: "Stel de belastingbehandeling van uw bedrijf in. De vastgelegde orderbelasting moet vóór uitgifte overeenkomen met de gekozen tarieven. Deze module kiest geen tarieven, dient geen aangiften in en verkrijgt geen overheidsbevestigingen voor elektronische facturen.",
		profile: "Profiel",
		customCountryProfile: "Aangepast landprofiel",
		indiaProfile: "India GST: binnenlandse leveringen",
		countryCode: "Landcode (ISO, 2 letters)",
		supplierName: "Naam leverancier",
		supplierTaxRegistration: "Belastingregistratie leverancier",
		supplierRegionCode: "Regio-/staatcode leverancier",
		taxIdentifierLabel: "Label belastingidentificatie",
		taxIdentifierPlaceholder: "GSTIN, btw-nummer, TIN",
		supplierAddress: "Adres leverancier",
		sizeGroupingOption: "Variantoptie voor groeperen op maat",
		shippingTaxable: "Verzendkosten opnemen in belastinggrondslag",
		indiaGst: "India GST",
		indiaHelp: "CGST en SGST/UTGST gelden als de staatcodes van leverancier en plaats van levering gelijk zijn; anders geldt IGST. Voer het totale GST-tarief in. Export, SEZ, verlegging, heffingen en elektronische facturatie vereisen een aparte behandeling.",
		gstRate: "Totaal GST-tarief (%)",
		localComponent: "Lokale component",
		taxComponents: "Belastingcomponenten",
		componentsHelp: "Configureer optelbare belastingen zoals btw, GST/PST of omzetbelasting. Gemengde tarieven, samengestelde belastingen en nationale aangifteregels zijn niet geautomatiseerd.",
		componentName: "Componentnaam",
		rate: "Tarief (%)",
		removeComponent: "Component verwijderen",
		addComponent: "Belastingcomponent toevoegen",
		saveProfile: "Belastingprofiel opslaan",
		invoiceTitle: "Belastingfactuur: {number}",
		invoiceDescription: "Controleer de belastinggegevens voordat u de uitgegeven factuur bewaart.",
		issuedSummary: "Uitgegeven op {date} · {total}",
		retainedDetails: "Deze gegevens worden bij uitgifte bewaard. Latere wijzigingen aan klant, order of belastingprofiel wijzigen deze factuur niet.",
		downloadPdf: "Factuur als PDF downloaden",
		orderTaxSummary: "Ordertotaal: {total}. Vastgelegde belasting: {tax}. De belastingcomponenten moeten overeenkomen met dit bedrag. Uitgifte verandert geen voorraad of betalingen.",
		supplyCountryCode: "Landcode plaats van levering",
		supplyRegionCode: "Regio-/staatcode plaats van levering",
		domesticHelp: "Dit profiel geldt voor binnenlandse leveringen in India. Controleer plaats van levering, GSTIN, HSN/SAC en eenheidscodes vóór uitgifte.",
		lineClassification: "Regelclassificatie",
		hsnRequired: "HSN/SAC (verplicht)",
		hsnOptional: "HSN/SAC (optioneel)",
		hsnFor: "HSN/SAC voor {sku}",
		unitCode: "Eenheidscode",
		unitFor: "Eenheid voor {sku}",
		reviewConfirmation: "Ik heb belastingregistratie, plaats van levering, classificatie en vastgelegde belasting gecontroleerd.",
		issueInvoice: "Belastingfactuur uitgeven",
		authorizedUserRequired: "Een bevoegde gebruiker moet de belastingfactuur uitgeven voordat deze kan worden gedownload.",
		customerGstHelp: "Voer de GSTIN van de klant in het veld Belasting-ID/btw hierboven in. Laat dit leeg voor een niet-geregistreerde klant.",
		customerTaxHelp: "Gebruik het veld Belasting-ID/btw voor de lokale belastingregistratie van de klant.",
		defaultSupplyRegionCode: "Standaard regio-/staatcode plaats van levering",
		defaultSupplyRegion: "Standaardregio/-staat plaats van levering",
		taxInvoice: "Belastingfactuur",
		permissions: {
			issue: {
				label: "Belastingfacturen uitgeven",
				description: "Belastingfacturatie"
			},
			manage: {
				label: "Belastingprofielen beheren",
				description: "Belastingfacturatie"
			}
		}
	},
	"./locales/pl.json": {
		title: "Fakturowanie podatkowe",
		settingsDescription: "Dane rejestracji podatkowej i wygląd faktur organizacji.",
		settingsHelp: "Ustaw zasady podatkowe firmy. Podatek zapisany w zamówieniu musi odpowiadać wybranym stawkom przed wystawieniem faktury. Moduł nie wybiera stawek, nie składa deklaracji ani nie uzyskuje urzędowych potwierdzeń e-faktur.",
		profile: "Profil",
		customCountryProfile: "Własny profil kraju",
		indiaProfile: "GST Indie: dostawy krajowe",
		countryCode: "Kod kraju (ISO, 2 litery)",
		supplierName: "Nazwa dostawcy",
		supplierTaxRegistration: "Rejestracja podatkowa dostawcy",
		supplierRegionCode: "Kod regionu/stanu dostawcy",
		taxIdentifierLabel: "Etykieta identyfikatora podatkowego",
		taxIdentifierPlaceholder: "GSTIN, numer VAT, TIN",
		supplierAddress: "Adres dostawcy",
		sizeGroupingOption: "Opcja wariantu do grupowania rozmiarów",
		shippingTaxable: "Uwzględnij wysyłkę w podstawie opodatkowania",
		indiaGst: "GST Indie",
		indiaHelp: "CGST i SGST/UTGST obowiązują, gdy kody stanu dostawcy i miejsca dostawy są zgodne; w przeciwnym razie obowiązuje IGST. Podaj łączną stawkę GST. Eksport, SEZ, odwrotne obciążenie, dodatkowe opłaty i e-faktury wymagają osobnej obsługi.",
		gstRate: "Łączna stawka GST (%)",
		localComponent: "Składnik lokalny",
		taxComponents: "Składniki podatku",
		componentsHelp: "Skonfiguruj podatki sumowane, np. VAT, GST/PST lub podatek od sprzedaży. Stawki mieszane, podatki złożone i krajowe zasady deklaracji nie są automatyzowane.",
		componentName: "Nazwa składnika",
		rate: "Stawka (%)",
		removeComponent: "Usuń składnik",
		addComponent: "Dodaj składnik podatku",
		saveProfile: "Zapisz profil podatkowy",
		invoiceTitle: "Faktura podatkowa: {number}",
		invoiceDescription: "Sprawdź dane podatkowe przed zachowaniem wystawionej faktury.",
		issuedSummary: "Wystawiono {date} · {total}",
		retainedDetails: "Dane są zachowywane w chwili wystawienia. Późniejsze zmiany klienta, zamówienia lub profilu nie zmieniają faktury.",
		downloadPdf: "Pobierz fakturę PDF",
		orderTaxSummary: "Suma zamówienia: {total}. Zapisany podatek: {tax}. Składniki podatku muszą odpowiadać tej kwocie. Wystawienie nie zmienia zapasów ani płatności.",
		supplyCountryCode: "Kod kraju miejsca dostawy",
		supplyRegionCode: "Kod regionu/stanu miejsca dostawy",
		domesticHelp: "Profil obejmuje dostawy krajowe w Indiach. Przed wystawieniem sprawdź miejsce dostawy, GSTIN, HSN/SAC i kody jednostek.",
		lineClassification: "Klasyfikacja pozycji",
		hsnRequired: "HSN/SAC (wymagany)",
		hsnOptional: "HSN/SAC (opcjonalny)",
		hsnFor: "HSN/SAC dla {sku}",
		unitCode: "Kod jednostki",
		unitFor: "Jednostka dla {sku}",
		reviewConfirmation: "Sprawdzono rejestrację podatkową, miejsce dostawy, klasyfikację i zapisany podatek.",
		issueInvoice: "Wystaw fakturę podatkową",
		authorizedUserRequired: "Uprawniony użytkownik musi wystawić fakturę podatkową przed pobraniem.",
		customerGstHelp: "Wpisz GSTIN klienta w polu Identyfikator podatkowy/VAT powyżej. Dla niezarejestrowanego klienta pozostaw je puste.",
		customerTaxHelp: "Użyj pola Identyfikator podatkowy/VAT do lokalnej rejestracji podatkowej klienta.",
		defaultSupplyRegionCode: "Domyślny kod regionu/stanu miejsca dostawy",
		defaultSupplyRegion: "Domyślny region/stan miejsca dostawy",
		taxInvoice: "Faktura podatkowa",
		permissions: {
			issue: {
				label: "Wystawianie faktur podatkowych",
				description: "Fakturowanie podatkowe"
			},
			manage: {
				label: "Zarządzanie profilami podatkowymi",
				description: "Fakturowanie podatkowe"
			}
		}
	},
	"./locales/pt-BR.json": {
		title: "Faturamento fiscal",
		settingsDescription: "Dados cadastrais fiscais e apresentação das faturas desta organização.",
		settingsHelp: "Defina o tratamento fiscal da empresa. O imposto registrado no pedido deve corresponder às alíquotas selecionadas antes da emissão. Este módulo não escolhe alíquotas, entrega declarações nem obtém confirmações governamentais de faturas eletrônicas.",
		profile: "Perfil",
		customCountryProfile: "Perfil de país personalizado",
		indiaProfile: "GST da Índia: operações nacionais",
		countryCode: "Código do país (ISO de 2 letras)",
		supplierName: "Nome do fornecedor",
		supplierTaxRegistration: "Registro fiscal do fornecedor",
		supplierRegionCode: "Código da região/estado do fornecedor",
		taxIdentifierLabel: "Rótulo do identificador fiscal",
		taxIdentifierPlaceholder: "GSTIN, número de IVA, TIN",
		supplierAddress: "Endereço do fornecedor",
		sizeGroupingOption: "Opção de variante para agrupar tamanhos",
		shippingTaxable: "Incluir frete na base tributável",
		indiaGst: "GST da Índia",
		indiaHelp: "CGST e SGST/UTGST se aplicam quando os códigos de estado do fornecedor e do local de fornecimento coincidem; caso contrário, aplica-se IGST. Informe a alíquota total de GST. Exportações, SEZ, cobrança reversa, tributos adicionais e faturamento eletrônico exigem tratamento específico.",
		gstRate: "Alíquota total de GST (%)",
		localComponent: "Componente local",
		taxComponents: "Componentes fiscais",
		componentsHelp: "Configure impostos aditivos como IVA, GST/PST ou imposto sobre vendas. Alíquotas mistas, impostos compostos e regras nacionais de declaração não são automatizados.",
		componentName: "Nome do componente",
		rate: "Alíquota (%)",
		removeComponent: "Remover componente",
		addComponent: "Adicionar componente fiscal",
		saveProfile: "Salvar perfil fiscal",
		invoiceTitle: "Fatura fiscal: {number}",
		invoiceDescription: "Revise os dados fiscais antes de guardar a fatura emitida.",
		issuedSummary: "Emitida em {date} · {total}",
		retainedDetails: "Estes dados são preservados na emissão. Alterações posteriores no cliente, pedido ou perfil fiscal não reescrevem esta fatura.",
		downloadPdf: "Baixar fatura em PDF",
		orderTaxSummary: "Total do pedido: {total}. Imposto registrado: {tax}. Os componentes fiscais devem corresponder a esse valor. A emissão não altera estoques nem pagamentos.",
		supplyCountryCode: "Código do país do local de fornecimento",
		supplyRegionCode: "Código da região/estado do local de fornecimento",
		domesticHelp: "Este perfil cobre operações nacionais na Índia. Verifique o local de fornecimento, GSTIN, HSN/SAC e códigos de unidade antes de emitir.",
		lineClassification: "Classificação das linhas",
		hsnRequired: "HSN/SAC (obrigatório)",
		hsnOptional: "HSN/SAC (opcional)",
		hsnFor: "HSN/SAC de {sku}",
		unitCode: "Código da unidade",
		unitFor: "Unidade de {sku}",
		reviewConfirmation: "Revisei o registro fiscal, local de fornecimento, classificação e imposto registrado.",
		issueInvoice: "Emitir fatura fiscal",
		authorizedUserRequired: "Um usuário autorizado deve emitir a fatura fiscal antes do download.",
		customerGstHelp: "Informe o GSTIN do cliente no campo ID fiscal/IVA acima. Deixe em branco se o cliente não for registrado.",
		customerTaxHelp: "Use o campo ID fiscal/IVA para o registro fiscal local do cliente.",
		defaultSupplyRegionCode: "Código padrão da região/estado de fornecimento",
		defaultSupplyRegion: "Região/estado padrão de fornecimento",
		taxInvoice: "Fatura fiscal",
		permissions: {
			issue: {
				label: "Emitir faturas fiscais",
				description: "Faturamento fiscal"
			},
			manage: {
				label: "Gerenciar perfis fiscais",
				description: "Faturamento fiscal"
			}
		}
	},
	"./locales/ru.json": {
		title: "Налоговые счета",
		settingsDescription: "Налоговые реквизиты и оформление счетов организации.",
		settingsHelp: "Укажите налоговый режим компании. Налог заказа должен соответствовать выбранным ставкам до выпуска счёта. Модуль не выбирает ставки, не подаёт декларации и не получает государственные подтверждения электронных счетов.",
		profile: "Профиль",
		customCountryProfile: "Свой профиль страны",
		indiaProfile: "GST Индии: внутренние поставки",
		countryCode: "Код страны (ISO, 2 буквы)",
		supplierName: "Название поставщика",
		supplierTaxRegistration: "Налоговая регистрация поставщика",
		supplierRegionCode: "Код региона/штата поставщика",
		taxIdentifierLabel: "Название налогового идентификатора",
		taxIdentifierPlaceholder: "GSTIN, номер НДС, TIN",
		supplierAddress: "Адрес поставщика",
		sizeGroupingOption: "Параметр варианта для группировки размеров",
		shippingTaxable: "Включить доставку в налоговую базу",
		indiaGst: "GST Индии",
		indiaHelp: "CGST и SGST/UTGST применяются при совпадении кодов штата поставщика и места поставки, иначе — IGST. Укажите общую ставку GST. Экспорт, SEZ, обратное начисление, дополнительные сборы и электронные счета требуют отдельной обработки.",
		gstRate: "Общая ставка GST (%)",
		localComponent: "Местный компонент",
		taxComponents: "Налоговые компоненты",
		componentsHelp: "Настройте складываемые налоги, например НДС, GST/PST или налог с продаж. Смешанные ставки, сложные налоги и правила подачи деклараций не автоматизированы.",
		componentName: "Название компонента",
		rate: "Ставка (%)",
		removeComponent: "Удалить компонент",
		addComponent: "Добавить налоговый компонент",
		saveProfile: "Сохранить налоговый профиль",
		invoiceTitle: "Налоговый счёт: {number}",
		invoiceDescription: "Проверьте налоговые данные перед сохранением выпущенного счёта.",
		issuedSummary: "Выпущен {date} · {total}",
		retainedDetails: "Данные сохраняются при выпуске. Изменения клиента, заказа или налогового профиля не меняют этот счёт.",
		downloadPdf: "Скачать счёт в PDF",
		orderTaxSummary: "Итого по заказу: {total}. Учтённый налог: {tax}. Налоговые компоненты должны совпадать с этой суммой. Выпуск не меняет остатки и платежи.",
		supplyCountryCode: "Код страны места поставки",
		supplyRegionCode: "Код региона/штата места поставки",
		domesticHelp: "Профиль предназначен для внутренних поставок в Индии. До выпуска проверьте место поставки, GSTIN, HSN/SAC и коды единиц.",
		lineClassification: "Классификация строк",
		hsnRequired: "HSN/SAC (обязательно)",
		hsnOptional: "HSN/SAC (необязательно)",
		hsnFor: "HSN/SAC для {sku}",
		unitCode: "Код единицы",
		unitFor: "Единица для {sku}",
		reviewConfirmation: "Я проверил налоговую регистрацию, место поставки, классификацию и учтённый налог.",
		issueInvoice: "Выпустить налоговый счёт",
		authorizedUserRequired: "До скачивания налоговый счёт должен выпустить уполномоченный пользователь.",
		customerGstHelp: "Введите GSTIN клиента в поле «Налоговый ID/НДС» выше. Для незарегистрированного клиента оставьте поле пустым.",
		customerTaxHelp: "Используйте поле «Налоговый ID/НДС» для местной налоговой регистрации клиента.",
		defaultSupplyRegionCode: "Код региона/штата поставки по умолчанию",
		defaultSupplyRegion: "Регион/штат поставки по умолчанию",
		taxInvoice: "Налоговый счёт",
		permissions: {
			issue: {
				label: "Выпуск налоговых счетов",
				description: "Налоговые счета"
			},
			manage: {
				label: "Управление налоговыми профилями",
				description: "Налоговые счета"
			}
		}
	},
	"./locales/tr.json": {
		title: "Vergi faturalandırması",
		settingsDescription: "Kuruluşun vergi kayıt bilgileri ve fatura görünümü.",
		settingsHelp: "İşletmenizin vergi uygulamasını belirleyin. Fatura düzenlenmeden önce kayıtlı sipariş vergisi seçilen oranlarla eşleşmelidir. Bu modül vergi oranı seçmez, beyanname vermez veya resmî e-fatura onayı almaz.",
		profile: "Profil",
		customCountryProfile: "Özel ülke profili",
		indiaProfile: "Hindistan GST: yurt içi teslimatlar",
		countryCode: "Ülke kodu (ISO, 2 harf)",
		supplierName: "Tedarikçi adı",
		supplierTaxRegistration: "Tedarikçi vergi kaydı",
		supplierRegionCode: "Tedarikçi bölge/eyalet kodu",
		taxIdentifierLabel: "Vergi kimliği etiketi",
		taxIdentifierPlaceholder: "GSTIN, KDV numarası, TIN",
		supplierAddress: "Tedarikçi adresi",
		sizeGroupingOption: "Beden gruplama için varyant seçeneği",
		shippingTaxable: "Kargoyu vergi matrahına dahil et",
		indiaGst: "Hindistan GST",
		indiaHelp: "Tedarikçi ve teslimat yeri eyalet kodları aynıysa CGST ve SGST/UTGST, farklıysa IGST uygulanır. Toplam GST oranını girin. İhracat, SEZ, tersine vergilendirme, ek vergiler ve e-fatura ayrı işlem gerektirir.",
		gstRate: "Toplam GST oranı (%)",
		localComponent: "Yerel bileşen",
		taxComponents: "Vergi bileşenleri",
		componentsHelp: "KDV, GST/PST veya satış vergisi gibi eklenen vergileri yapılandırın. Karma oranlar, bileşik vergiler ve ülke beyan kuralları otomatik değildir.",
		componentName: "Bileşen adı",
		rate: "Oran (%)",
		removeComponent: "Bileşeni kaldır",
		addComponent: "Vergi bileşeni ekle",
		saveProfile: "Vergi profilini kaydet",
		invoiceTitle: "Vergi faturası: {number}",
		invoiceDescription: "Düzenlenen faturayı saklamadan önce vergi bilgilerini inceleyin.",
		issuedSummary: "Düzenlenme: {date} · {total}",
		retainedDetails: "Bu bilgiler düzenleme anında korunur. Müşteri, sipariş veya vergi profilindeki sonraki değişiklikler bu faturayı değiştirmez.",
		downloadPdf: "Fatura PDF’sini indir",
		orderTaxSummary: "Sipariş toplamı: {total}. Kayıtlı vergi: {tax}. Fatura vergi bileşenleri bu tutarla uyuşmalıdır. Düzenleme stokları veya ödemeleri değiştirmez.",
		supplyCountryCode: "Teslimat yeri ülke kodu",
		supplyRegionCode: "Teslimat yeri bölge/eyalet kodu",
		domesticHelp: "Bu profil Hindistan içi teslimatları kapsar. Düzenlemeden önce teslimat yerini, GSTIN, HSN/SAC ve birim kodlarını kontrol edin.",
		lineClassification: "Satır sınıflandırması",
		hsnRequired: "HSN/SAC (zorunlu)",
		hsnOptional: "HSN/SAC (isteğe bağlı)",
		hsnFor: "{sku} için HSN/SAC",
		unitCode: "Birim kodu",
		unitFor: "{sku} için birim",
		reviewConfirmation: "Vergi kaydını, teslimat yerini, sınıflandırmayı ve kayıtlı vergiyi inceledim.",
		issueInvoice: "Vergi faturası düzenle",
		authorizedUserRequired: "İndirmeden önce vergi faturasını yetkili bir kullanıcı düzenlemelidir.",
		customerGstHelp: "Müşteri GSTIN bilgisini yukarıdaki Vergi Kimliği/KDV alanına girin. Kayıtsız müşteriler için boş bırakın.",
		customerTaxHelp: "Müşterinin yerel vergi kaydı için Vergi Kimliği/KDV alanını kullanın.",
		defaultSupplyRegionCode: "Varsayılan teslimat yeri bölge/eyalet kodu",
		defaultSupplyRegion: "Varsayılan teslimat yeri bölgesi/eyaleti",
		taxInvoice: "Vergi faturası",
		permissions: {
			issue: {
				label: "Vergi faturaları düzenleme",
				description: "Vergi faturalandırması"
			},
			manage: {
				label: "Vergi faturalandırma profillerini yönetme",
				description: "Vergi faturalandırması"
			}
		}
	},
	"./locales/zh-CN.json": {
		title: "税务开票",
		settingsDescription: "此组织的税务登记信息及发票展示设置。",
		settingsHelp: "设置适用于企业的税务处理方式。开票前，订单记录的税额必须与所选税率一致。本模块不选择税率、不提交申报，也不获取政府电子发票确认。",
		profile: "配置",
		customCountryProfile: "自定义国家配置",
		indiaProfile: "印度 GST：国内供应",
		countryCode: "国家代码（ISO 两个字母）",
		supplierName: "供应商名称",
		supplierTaxRegistration: "供应商税务登记",
		supplierRegionCode: "供应商地区/邦代码",
		taxIdentifierLabel: "税务标识标签",
		taxIdentifierPlaceholder: "GSTIN、增值税号、TIN",
		supplierAddress: "供应商地址",
		sizeGroupingOption: "用于尺码分组的规格选项",
		shippingTaxable: "将运费计入计税基础",
		indiaGst: "印度 GST",
		indiaHelp: "供应商与供应地点的邦代码相同，适用 CGST 加 SGST/UTGST；不同时适用 IGST。请输入 GST 总税率。出口、SEZ、反向征税、附加税及电子发票需专门处理。",
		gstRate: "GST 总税率（%）",
		localComponent: "地方税项",
		taxComponents: "税项",
		componentsHelp: "配置增值税、GST/PST 或销售税等加算税。混合税率、复合税及各国申报规则不自动处理。",
		componentName: "税项名称",
		rate: "税率（%）",
		removeComponent: "删除税项",
		addComponent: "添加税项",
		saveProfile: "保存税务配置",
		invoiceTitle: "税务发票：{number}",
		invoiceDescription: "保存已开具的发票前，请检查税务信息。",
		issuedSummary: "开具日期 {date} · {total}",
		retainedDetails: "这些信息按开票时的状态保存。以后修改客户、订单或税务配置不会更改此发票。",
		downloadPdf: "下载发票 PDF",
		orderTaxSummary: "订单总额：{total}。记录税额：{tax}。发票税项必须与此金额一致。开票不会更改库存或付款。",
		supplyCountryCode: "供应地点国家代码",
		supplyRegionCode: "供应地点地区/邦代码",
		domesticHelp: "此配置适用于印度国内供应。开票前请检查供应地点、GSTIN、HSN/SAC 及单位代码。",
		lineClassification: "明细分类",
		hsnRequired: "HSN/SAC（必填）",
		hsnOptional: "HSN/SAC（可选）",
		hsnFor: "{sku} 的 HSN/SAC",
		unitCode: "单位代码",
		unitFor: "{sku} 的单位",
		reviewConfirmation: "我已检查税务登记、供应地点、分类及记录税额。",
		issueInvoice: "开具税务发票",
		authorizedUserRequired: "必须由授权用户开具税务发票后才能下载。",
		customerGstHelp: "请在上方税号/增值税号字段中输入客户 GSTIN。未登记客户可留空。",
		customerTaxHelp: "请使用税号/增值税号字段填写客户的当地税务登记。",
		defaultSupplyRegionCode: "默认供应地点地区/邦代码",
		defaultSupplyRegion: "默认供应地点地区/邦",
		taxInvoice: "税务发票",
		permissions: {
			issue: {
				label: "开具税务发票",
				description: "税务开票"
			},
			manage: {
				label: "管理税务开票配置",
				description: "税务开票"
			}
		}
	}
}), O;
function Rn(e) {
	O = e.i18n;
	for (let [e, t] of Object.entries(Ln)) O.addMessages(e.split("/").pop().replace(".json", ""), t);
}
var k = (e, t = {}) => O.t(e, t), A = (e) => new Intl.NumberFormat(O.locale.value).format(e), j = (e, t) => new Intl.NumberFormat(O.locale.value, {
	style: "currency",
	currency: t
}).format(e), M = (e) => new Intl.DateTimeFormat(O.locale.value, {
	dateStyle: "medium",
	timeZone: "UTC"
}).format(/* @__PURE__ */ new Date(`${e}T00:00:00Z`)), N = { class: "tb-help" }, P = { class: "tb-grid" }, F = { class: "tb-field" }, I = { value: "generic" }, L = { value: "india_gst" }, R = { class: "tb-field" }, z = { class: "tb-field" }, B = { class: "tb-field" }, V = { class: "tb-field" }, H = { class: "tb-field" }, U = ["placeholder"], W = { class: "tb-field tb-wide" }, G = { class: "tb-field" }, K = { class: "tb-check" }, q = { class: "tb-title" }, J = { class: "tb-help" }, Y = { class: "tb-grid" }, X = { class: "tb-field" }, Z = { class: "tb-field" }, Q = { class: "tb-title" }, zn = { class: "tb-help" }, Bn = { class: "tb-field" }, Vn = ["onUpdate:modelValue"], Hn = { class: "tb-field" }, Un = ["onUpdate:modelValue"], Wn = {
	key: 2,
	role: "alert",
	class: "tb-errors"
}, Gn = {
	__name: "Settings",
	props: {
		profile: Object,
		saveUrl: String
	},
	setup(e) {
		let { layouts: { AppLayout: t }, ui: { PageHeader: n, Card: r, Button: i }, Inertia: { Head: a, useForm: o } } = window.Inventoros, c = o(JSON.parse(JSON.stringify(e.profile)));
		function l() {
			c.mode === "india_gst" && (c.country = "IN", c.tax_id_label = "GSTIN");
		}
		return (o, u) => (v(), m(s, null, [_(x(a), { title: x(k)("title") }, null, 8, ["title"]), _(x(t), null, {
			default: T(() => [_(x(n), {
				title: x(k)("title"),
				description: x(k)("settingsDescription")
			}, null, 8, ["title", "description"]), h("form", {
				class: "tb-stack",
				onSubmit: u[13] ||= D((t) => x(c).post(e.saveUrl, { preserveScroll: !0 }), ["prevent"])
			}, [
				_(x(r), null, {
					default: T(() => [
						h("p", N, b(x(k)("settingsHelp")), 1),
						h("div", P, [
							h("label", F, [g(b(x(k)("profile")), 1), E(h("select", {
								"onUpdate:modelValue": u[0] ||= (e) => x(c).mode = e,
								class: "tb-control",
								onChange: l
							}, [h("option", I, b(x(k)("customCountryProfile")), 1), h("option", L, b(x(k)("indiaProfile")), 1)], 544), [[C, x(c).mode]])]),
							h("label", R, [g(b(x(k)("countryCode")), 1), E(h("input", {
								"onUpdate:modelValue": u[1] ||= (e) => x(c).country = e,
								class: "tb-control",
								maxlength: "2",
								placeholder: "IN, GB, CA",
								onInput: u[2] ||= (e) => x(c).country = x(c).country.toUpperCase()
							}, null, 544), [[w, x(c).country]])]),
							h("label", z, [g(b(x(k)("supplierName")), 1), E(h("input", {
								"onUpdate:modelValue": u[3] ||= (e) => x(c).issuer_name = e,
								class: "tb-control",
								autocomplete: "organization"
							}, null, 512), [[w, x(c).issuer_name]])]),
							h("label", B, [g(b(x(k)("supplierTaxRegistration")), 1), E(h("input", {
								"onUpdate:modelValue": u[4] ||= (e) => x(c).issuer_tax_id = e,
								class: "tb-control"
							}, null, 512), [[w, x(c).issuer_tax_id]])]),
							h("label", V, [g(b(x(k)("supplierRegionCode")), 1), E(h("input", {
								"onUpdate:modelValue": u[5] ||= (e) => x(c).region_code = e,
								class: "tb-control"
							}, null, 512), [[w, x(c).region_code]])]),
							h("label", H, [g(b(x(k)("taxIdentifierLabel")), 1), E(h("input", {
								"onUpdate:modelValue": u[6] ||= (e) => x(c).tax_id_label = e,
								class: "tb-control",
								placeholder: x(k)("taxIdentifierPlaceholder")
							}, null, 8, U), [[w, x(c).tax_id_label]])]),
							h("label", W, [g(b(x(k)("supplierAddress")), 1), E(h("textarea", {
								"onUpdate:modelValue": u[7] ||= (e) => x(c).issuer_address = e,
								class: "tb-control",
								rows: "3",
								autocomplete: "street-address"
							}, null, 512), [[w, x(c).issuer_address]])]),
							h("label", G, [g(b(x(k)("sizeGroupingOption")), 1), E(h("input", {
								"onUpdate:modelValue": u[8] ||= (e) => x(c).size_option = e,
								class: "tb-control"
							}, null, 512), [[w, x(c).size_option]])])
						]),
						h("label", K, [E(h("input", {
							"onUpdate:modelValue": u[9] ||= (e) => x(c).shipping_taxable = e,
							type: "checkbox"
						}, null, 512), [[S, x(c).shipping_taxable]]), g(" " + b(x(k)("shippingTaxable")), 1)])
					]),
					_: 1
				}),
				x(c).mode === "india_gst" ? (v(), f(x(r), { key: 0 }, {
					default: T(() => [
						h("h2", q, b(x(k)("indiaGst")), 1),
						h("p", J, b(x(k)("indiaHelp")), 1),
						h("div", Y, [h("label", X, [g(b(x(k)("gstRate")), 1), E(h("input", {
							"onUpdate:modelValue": u[10] ||= (e) => x(c).gst_rate = e,
							class: "tb-control",
							type: "number",
							min: "0",
							max: "100",
							step: "0.0001"
						}, null, 512), [[w, x(c).gst_rate]])]), h("label", Z, [g(b(x(k)("localComponent")), 1), E(h("select", {
							"onUpdate:modelValue": u[11] ||= (e) => x(c).local_tax_label = e,
							class: "tb-control"
						}, [...u[14] ||= [h("option", null, "SGST", -1), h("option", null, "UTGST", -1)]], 512), [[C, x(c).local_tax_label]])])])
					]),
					_: 1
				})) : (v(), f(x(r), { key: 1 }, {
					default: T(() => [
						h("h2", Q, b(x(k)("taxComponents")), 1),
						h("p", zn, b(x(k)("componentsHelp")), 1),
						(v(!0), m(s, null, y(x(c).components, (e, t) => (v(), m("div", {
							key: t,
							class: "tb-grid tb-row"
						}, [
							h("label", Bn, [g(b(x(k)("componentName")), 1), E(h("input", {
								"onUpdate:modelValue": (t) => e.label = t,
								class: "tb-control"
							}, null, 8, Vn), [[w, e.label]])]),
							h("label", Hn, [g(b(x(k)("rate")), 1), E(h("input", {
								"onUpdate:modelValue": (t) => e.rate = t,
								class: "tb-control",
								type: "number",
								min: "0",
								max: "100",
								step: "0.0001"
							}, null, 8, Un), [[w, e.rate]])]),
							x(c).components.length > 1 ? (v(), f(x(i), {
								key: 0,
								type: "button",
								variant: "ghost",
								onClick: (e) => x(c).components.splice(t, 1)
							}, {
								default: T(() => [g(b(x(k)("removeComponent")), 1)]),
								_: 1
							}, 8, ["onClick"])) : p("", !0)
						]))), 128)),
						x(c).components.length < 10 ? (v(), f(x(i), {
							key: 0,
							type: "button",
							variant: "secondary",
							onClick: u[12] ||= (e) => x(c).components.push({
								label: "",
								rate: "0"
							})
						}, {
							default: T(() => [g(b(x(k)("addComponent")), 1)]),
							_: 1
						})) : p("", !0)
					]),
					_: 1
				})),
				Object.keys(x(c).errors).length ? (v(), m("div", Wn, [(v(!0), m(s, null, y(x(c).errors, (e, t) => (v(), m("p", { key: t }, b(e), 1))), 128))])) : p("", !0),
				_(x(i), {
					type: "submit",
					loading: x(c).processing
				}, {
					default: T(() => [g(b(x(k)("saveProfile")), 1)]),
					_: 1
				}, 8, ["loading"])
			], 32)]),
			_: 1
		})], 64));
	}
}, Kn = { class: "tb-title" }, qn = { class: "tb-help" }, Jn = { class: "tb-help" }, Yn = { class: "tb-grid" }, Xn = { class: "tb-field" }, Zn = { class: "tb-field" }, Qn = {
	key: 0,
	class: "tb-help"
}, $n = { class: "tb-title" }, er = { class: "tb-grid" }, tr = { class: "tb-field" }, nr = ["onUpdate:modelValue", "aria-label"], rr = { class: "tb-field" }, ir = ["onUpdate:modelValue", "aria-label"], ar = {
	key: 0,
	role: "alert",
	class: "tb-errors"
}, or = { class: "tb-check" }, sr = {
	__name: "Invoice",
	props: {
		order: Object,
		items: Array,
		profile: Object,
		snapshot: Object,
		canIssue: Boolean,
		regionCode: String,
		supplyCountry: String,
		issueUrl: String,
		downloadUrl: String
	},
	setup(e) {
		let { layouts: { AppLayout: t }, ui: { PageHeader: n, Card: r, Button: i }, Inertia: { Head: a, useForm: o } } = window.Inventoros, c = e, l = o({
			confirmed: !1,
			region_code: c.regionCode || "",
			supply_country: c.supplyCountry || "",
			items: Object.fromEntries(c.items.map((e) => [e.id, {
				hsn_code: "",
				unit: "NOS"
			}]))
		});
		return (o, c) => (v(), m(s, null, [_(x(a), { title: x(k)("invoiceTitle", { number: e.order.order_number }) }, null, 8, ["title"]), _(x(t), null, {
			default: T(() => [_(x(n), {
				title: x(k)("invoiceTitle", { number: e.order.order_number }),
				description: x(k)("invoiceDescription")
			}, null, 8, ["title", "description"]), e.snapshot ? (v(), f(x(r), {
				key: 0,
				class: "tb-stack"
			}, {
				default: T(() => [
					h("h2", Kn, b(e.snapshot.number), 1),
					h("p", null, b(x(k)("issuedSummary", {
						date: x(M)(e.snapshot.issued_date),
						total: x(j)(e.snapshot.total, e.snapshot.currency)
					})), 1),
					h("p", qn, b(x(k)("retainedDetails")), 1),
					(v(!0), m(s, null, y(e.snapshot.components, (t) => (v(), m("p", { key: t.label }, b(t.label) + ": " + b(x(j)(t.amount, e.snapshot.currency)), 1))), 128)),
					_(x(i), {
						as: "a",
						href: e.downloadUrl
					}, {
						default: T(() => [g(b(x(k)("downloadPdf")), 1)]),
						_: 1
					}, 8, ["href"])
				]),
				_: 1
			})) : e.canIssue ? (v(), m("form", {
				key: 1,
				class: "tb-stack",
				onSubmit: c[4] ||= D((t) => x(l).post(e.issueUrl, { preserveScroll: !0 }), ["prevent"])
			}, [
				_(x(r), null, {
					default: T(() => [
						h("p", Jn, b(x(k)("orderTaxSummary", {
							total: x(j)(e.order.total, e.order.currency),
							tax: x(j)(e.order.tax, e.order.currency)
						})), 1),
						h("div", Yn, [h("label", Xn, [g(b(x(k)("supplyCountryCode")), 1), E(h("input", {
							"onUpdate:modelValue": c[0] ||= (e) => x(l).supply_country = e,
							class: "tb-control",
							maxlength: "2",
							onInput: c[1] ||= (e) => x(l).supply_country = x(l).supply_country.toUpperCase()
						}, null, 544), [[w, x(l).supply_country]])]), h("label", Zn, [g(b(x(k)("supplyRegionCode")), 1), E(h("input", {
							"onUpdate:modelValue": c[2] ||= (e) => x(l).region_code = e,
							class: "tb-control"
						}, null, 512), [[w, x(l).region_code]])])]),
						e.profile.mode === "india_gst" ? (v(), m("p", Qn, b(x(k)("domesticHelp")), 1)) : p("", !0)
					]),
					_: 1
				}),
				_(x(r), null, {
					default: T(() => [h("h2", $n, b(x(k)("lineClassification")), 1), (v(!0), m(s, null, y(e.items, (t) => (v(), m("div", {
						key: t.id,
						class: "tb-row"
					}, [h("p", null, [h("strong", null, b(t.product_name), 1), g(" · " + b(t.sku) + " · " + b(x(A)(t.quantity)) + " × " + b(x(j)(t.unit_price, e.order.currency)), 1)]), h("div", er, [h("label", tr, [g(b(e.profile.mode === "india_gst" ? x(k)("hsnRequired") : x(k)("hsnOptional")), 1), E(h("input", {
						"onUpdate:modelValue": (e) => x(l).items[t.id].hsn_code = e,
						class: "tb-control",
						"aria-label": x(k)("hsnFor", { sku: t.sku })
					}, null, 8, nr), [[w, x(l).items[t.id].hsn_code]])]), h("label", rr, [g(b(x(k)("unitCode")), 1), E(h("input", {
						"onUpdate:modelValue": (e) => x(l).items[t.id].unit = e,
						class: "tb-control",
						"aria-label": x(k)("unitFor", { sku: t.sku })
					}, null, 8, ir), [[w, x(l).items[t.id].unit]])])])]))), 128))]),
					_: 1
				}),
				Object.keys(x(l).errors).length ? (v(), m("div", ar, [(v(!0), m(s, null, y(x(l).errors, (e, t) => (v(), m("p", { key: t }, b(e), 1))), 128))])) : p("", !0),
				h("label", or, [E(h("input", {
					"onUpdate:modelValue": c[3] ||= (e) => x(l).confirmed = e,
					type: "checkbox"
				}, null, 512), [[S, x(l).confirmed]]), g(" " + b(x(k)("reviewConfirmation")), 1)]),
				_(x(i), {
					type: "submit",
					loading: x(l).processing,
					disabled: !x(l).confirmed
				}, {
					default: T(() => [g(b(x(k)("issueInvoice")), 1)]),
					_: 1
				}, 8, ["loading", "disabled"])
			], 32)) : (v(), f(x(r), {
				key: 2,
				class: "tb-stack"
			}, {
				default: T(() => [h("p", null, b(x(k)("authorizedUserRequired")), 1)]),
				_: 1
			}))]),
			_: 1
		})], 64));
	}
}, cr = {
	key: 0,
	class: "tb-row"
}, lr = { class: "tb-help" }, ur = { class: "tb-field" }, $ = ["maxlength"], dr = {
	key: 0,
	class: "tb-errors"
}, fr = {
	__name: "CustomerFields",
	props: {
		form: Object,
		mode: String
	},
	setup(e) {
		let t = e, n = d(() => t.form.plugin_fields.tax_billing);
		return (t, r) => n.value ? (v(), m("div", cr, [
			h("p", lr, b(e.mode === "india_gst" ? x(k)("customerGstHelp") : x(k)("customerTaxHelp")), 1),
			h("label", ur, [g(b(x(k)("defaultSupplyRegionCode")), 1), E(h("input", {
				"onUpdate:modelValue": r[0] ||= (e) => n.value.region_code = e,
				class: "tb-control",
				maxlength: e.mode === "india_gst" ? 2 : 80
			}, null, 8, $), [[w, n.value.region_code]])]),
			e.form.errors["plugin_fields.tax_billing.region_code"] ? (v(), m("p", dr, b(e.form.errors["plugin_fields.tax_billing.region_code"]), 1)) : p("", !0)
		])) : p("", !0);
	}
}, pr = {
	key: 0,
	class: "tb-row"
}, mr = { class: "tb-help" }, hr = {
	__name: "CustomerTaxDetails",
	props: { customer: Object },
	setup(e) {
		return (t, n) => e.customer?.metadata?.tax_billing?.region_code ? (v(), m("div", pr, [h("p", mr, b(x(k)("defaultSupplyRegion")), 1), h("p", null, b(e.customer.metadata.tax_billing.region_code), 1)])) : p("", !0);
	}
}, gr = {
	__name: "InvoiceLink",
	props: { url: String },
	setup(e) {
		let { ui: { Button: t } } = window.Inventoros;
		return (n, r) => e.url ? (v(), f(x(t), {
			key: 0,
			as: "Link",
			variant: "secondary",
			size: "sm",
			href: e.url
		}, {
			default: T(() => [g(b(x(k)("taxInvoice")), 1)]),
			_: 1
		}, 8, ["href"])) : p("", !0);
	}
};
// plugins/tax-billing/ui/src/main.js
function _r(e) {
	Rn(e), e.registerPage("Settings", Gn), e.registerPage("Invoice", sr), e.registerComponent("CustomerFields", fr), e.registerComponent("CustomerTaxDetails", hr), e.registerComponent("InvoiceLink", gr);
}
export { _r as default };
