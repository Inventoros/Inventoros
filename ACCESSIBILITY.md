# Accessibility

Inventoros is used every day by warehouse, purchasing and sales staff, and by customers through the customer portal. Everyone who works with inventory should be able to use it, including people who use a keyboard instead of a mouse, screen readers, screen magnification, voice control, or high zoom levels.

## Our commitment

We aim to meet the [Web Content Accessibility Guidelines (WCAG) 2.2](https://www.w3.org/TR/WCAG22/) at Level AA for the web application and customer portal. We treat accessibility barriers as bugs, and new interface work is expected to be usable with a keyboard and at narrow widths before it is merged.

## Current status

Inventoros is **partially conformant** with WCAG 2.2 Level AA. Some parts of the product have not yet been verified against the standard, and there are known limitations, listed below. We have not completed a formal third-party audit.

## What we do today

- **Automated checks.** The [`ui-ux-audit`](.github/workflows/ui-ux-audit.yml) workflow renders the application in a real browser and scans pages with [axe-core](https://github.com/dequelabs/axe-core) using the WCAG 2.0, 2.1 and 2.2 Level A and AA rule sets. It also checks for page-level horizontal overflow and JavaScript errors at widths from 320 to 1920 pixels. The most recent recorded run is summarised in [the v2.5.0 verification record](https://github.com/Inventoros/Inventoros/blob/v2.5.0/docs/audits/ui-ux-verification.json). Automated checks find only part of the possible problems, so a clean scan does not mean full conformance.
- **Keyboard and focus.** Pop-up menus such as the warehouse switcher have regression tests for keyboard use and for returning to the page after they close.
- **Reduced motion.** Animations and transitions are shortened when the operating system's "reduce motion" setting is on.
- **Languages and direction.** The interface is available in 14 languages. Arabic is shown right-to-left.
- **Themes.** Light and dark themes are available.
- **Small screens and zoom.** Layouts are checked at phone, tablet and desktop widths, down to 320 pixels.

## Supported environments

- **Browsers:** current versions of Chrome, Edge, Firefox and Safari, on desktop and mobile.
- **Assistive technology:** we intend Inventoros to work with current screen readers (NVDA and JAWS on Windows, VoiceOver on macOS and iOS, TalkBack on Android) and with browser zoom up to 400%. We do not yet test each of these systematically, so please tell us about any problem you find.

## Known limitations

- **Screen-reader testing is not systematic.** Testing is mostly automated, and not every page has been checked with a screen reader.
- **Generated documents.** PDF invoices, purchase orders, packing slips, pick lists and labels are not tagged PDFs, so screen readers may read them poorly. The same information is available on the corresponding pages in the application.
- **Plugins.** Pages and widgets provided by plugins, including third-party plugins, are not covered by these checks. Their accessibility depends on the plugin author.
- **Emails.** Notification emails have not been audited against WCAG.
- **Barcode and QR scanning.** Scanning with a camera needs a working camera and sight. You can always type the SKU, barcode or serial number instead.

## Reporting a barrier

If something in Inventoros is hard or impossible for you to use, please tell us. You do not need to know WCAG or any technical terms.

- Open an issue with the [**Accessibility barrier**](https://github.com/Inventoros/Inventoros/issues/new?template=accessibility.yml) form. Say what you were trying to do, where it happened, and, if you are comfortable sharing it, the browser and assistive technology you use.
- Please leave out personal data, customer data, and your server's address if you prefer.

Maintainers triage accessibility reports in the same way as other bugs, and we prioritise barriers that stop someone from completing a task. If a problem also has security implications, report it privately as described in [SECURITY.md](SECURITY.md).

## Contributing

When you change the interface, please:

- make sure every action works with a keyboard alone, with a visible focus indicator;
- give form fields labels and give icon-only buttons an accessible name;
- check the page at a narrow width and in both themes;
- keep text in translation files so every language, including right-to-left Arabic, receives it.

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to set up and test the project.
