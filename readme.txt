=== TokenForge ===
Contributors: pristadigital
Tags: design tokens, css variables, accessibility, woocommerce, bricks
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.54.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Design tokens, fluid scales, utility classes, contrast and accessibility checks for any WordPress site, with Bricks and WooCommerce support.

== Description ==

TokenForge is a design system you set up once and use everywhere. Colours, type and spacing scales, layout, buttons, cards, icons, effects and more are all CSS variables and classes, built into one static stylesheet that loads on your front end, in the block editor and, with Bricks, in the builder.

A new site starts ready: a complete starter design system (every token, colour family and setting), so there is nothing to import. Reset to defaults any time, for everything or one section.

**Design system**

* Colour families: shades generated from one colour in light and dark mode, with a live preview. Add, remove or edit your own families.
* Fluid type and spacing scales from one setting each, plus a width scale, a root font size, grids and automatic gaps.
* Layout, gutters, boxed or full width, borders, radius, shadows, icons, header height and prose styles.
* Utility classes for spacing, grids, corners and more. Unused classes can be left out of the stylesheet after a site scan, to keep it small.
* A Tokens screen that lists every variable, the setting that makes it and where it is used. Add your own tokens in a built-in or your own category.
* Dark mode: assign it by section and add a light or dark switch anywhere, with a shortcode or a block.

**Accessibility**

* Contrast check of text, links, focus rings and buttons against their backgrounds, in light and dark mode (WCAG 2 AA, with the APCA value shown). Each problem gets a suggested colour token, applied with one click.
* Automatic attributes for the front end, every one off by default: hidden decorative icons, alt text from the media library, menu labels, the current page mark, a new tab notice and two labelled guesses. TokenForge adds only what is missing and stores nothing.
* Data attributes for layout and behaviour without custom code, listed under Data attributes below, with a screen that shows where each one is used.
* A page check in the builder, based on the open-source axe-core engine. It runs only when an administrator presses Run check, and nothing is saved unless you choose a fix.

**Fonts**

* Self-hosted fonts: upload TTF or OTF files and TokenForge turns them into WOFF2 (or WOFF), preloads them and lists them in the builder's font list.
* Or download a Google font once to your server. Your visitors never contact Google.

**WooCommerce**

* While WooCommerce is active: shop, product, cart, checkout and account pages, product cards and filters, header carts, and the Cart and Checkout blocks, styled with your tokens.
* Every part has its own switch. The shop styles load only on pages that show the shop, or on every page if you choose.

**Data and tools**

* Export and import your settings, and export your tokens as W3C design token files (light and dark) for Figma, Tokens Studio and Style Dictionary. Each import shows a preview of every change first.
* Settings history: before every save, import, reset or setup, TokenForge keeps a copy (the last 20). You can see what changed and put any copy back, and a restore can be undone.
* WP-CLI commands: regenerate, export, import (with --dry-run), snapshot, history, restore, status and contrast.
* Guidance and abilities for AI agents (MCP and Bricks AI).
* The dashboard and its settings are translatable (translate.wordpress.org).

**With Bricks**

When Bricks is active, TokenForge also:

* Syncs your tokens into Bricks global variables, the colour palette and global classes, so they appear in the builder's pickers and autocomplete. The sync runs both ways.
* Keeps a Bricks theme style for the whole website in step with your settings, both ways.
* Adds the full dashboard inside the builder, right-click menus for variables and classes, a BEM class generator, a design check (typed colours and font sizes that should be tokens, contrast in light and dark mode, sizes off the type scale) and the page check.
* Extends the Bricks form element with steps (back, next and a progress bar), conditional fields, required-if rules, calculated fields, sliders, star ratings, ranges, input masks, character counters, date rules, choices filled from your content, live values on the page, send limits, an Update meta action, an Add to cart action, and form presets. Each has its own switch.
* Keeps the form submissions in the builder: search, read and unread marks, statuses, notes, editable entries, CSV or JSON export, and optional auto-delete after a set number of days (only after a dry run you confirm).
* Has a setup wizard that sets Bricks' own settings for working with TokenForge in one go. You choose which, and Undo puts them back.

**Data attributes**

These attributes work on any element in Bricks, the block editor or your own markup. Each one is listed on the Data attributes screen with where it is used.

* data-tf-stack: switches a row to a column below a breakpoint.
* data-tf-space: section spacing or stack gap.
* data-tf-surface: a raised panel from your colour tokens.
* data-tf-reveal: shows an element on scroll, using the visible effects.
* data-tf-clamp and data-tf-ratio: limit lines of text and keep a fixed shape.
* data-tf-copy: a copy button for a value on the page.
* data-tf-tooltip: a tooltip that stays on screen, also on a phone.
* data-tf-filter-group, data-tf-filter and data-tf-term: a list filter with no code.
* data-tf-decorative: marks an image as decorative (Mark as decorative in the builder).
* data-tf-state: marks an element as open, active or disabled, so your CSS can style that state. Disabled also dims the element and blocks mouse clicks.

TokenForge is original software released under the GPLv2 or later.

**Developers**

A developer can add SCSS to the stylesheet from code. TokenForge compiles it with its own mixins and functions and adds it at the end. There is no screen for it, and a compile error never breaks the site.

```
add_filter( 'tokenforge_global_scss', function ( $scss ) {
	return $scss . '.card { @include btn(primary); }';
} );
```

== Installation ==

1. Upload the `tokenforge` folder to `/wp-content/plugins/`, or install the zip from Plugins, Add New, Upload.
2. Activate. A new site starts with the starter design system. On a Bricks site, TokenForge links or creates a theme style for the whole website on the next page load.
3. Open **TokenForge** in the admin menu, change what you like and save.

== Frequently Asked Questions ==

= Do I need a page builder? =

No. TokenForge works on any WordPress site: the stylesheet loads on the front end and in the block editor. The builder tools switch on only when the Bricks theme or plugin is active.

= Will it slow my site down? =

TokenForge writes one static stylesheet, so no CSS is built while a page loads. You can print it inline (no extra request) under Options, Performance, and switch off any section you do not use. Under Options, Utility framework, you can leave out the utility classes your site does not use. With WooCommerce, the shop styles are in a second file that loads only on pages that show the shop.

= Does it work with WooCommerce? =

Yes. While WooCommerce is active, TokenForge styles the shop, product, cart, checkout and account pages, the builder's shop elements and the WooCommerce blocks with your tokens. Every part has its own switch under WooCommerce. With a block theme, the product price, sale badge and rating blocks can take their look from global styles.

= How do I load the shop styles on every page? =

Set WooCommerce, Shop pages, Load shop page styles to On every page. Developers can also decide per page with the `tokenforge_woo_shop_styles` filter.

= Where do the tokens come from? =

Most come from your settings: Colors makes the colour families, and Typography, Spacing, Layout, Borders, Icons and Effects make their own variables, all when the stylesheet is built. The Tokens screen lists every variable with the part that makes it. Add your own tokens with + Add token, in a built-in category or one you name yourself. A token with the same name as a variable a setting makes replaces it; Use settings removes copies that only repeat a setting.

= Can I bring in settings from another framework? =

Yes. Import keeps each brand colour's lightness and keeps the text and spacing sizes fluid. Every import shows a preview first, and the settings history can undo it.

= What happens when I deactivate or delete TokenForge? =

Deactivating stops the stylesheet from loading; your settings stay. Deleting the plugin removes its settings, the generated stylesheets and the variables, colours and classes it added to Bricks. Font files you uploaded stay in `uploads/tokenforge/fonts/`, because other CSS may still use them. On a multisite network, uninstall cleans every site.

= Does TokenForge keep anything about visitors? =

Only what your forms ask for. On your server, a form with a send limit keeps a count of its sends, and a form limited per user keeps the time of each signed-in user's last send in their user data. A form with the Update meta action writes the answers you map to the post or user meta you choose, and the Add to cart action puts the answers you allow on the cart item and the order. Two things stay in the visitor's own browser (local storage): their light or dark choice when you show the dark mode switch, and, only for forms where you turn on Remember answers on this device, what they typed until the form is sent. Passwords, files and hidden fields are never kept, and the saved answers are removed after a successful send. Deleting TokenForge removes its counts and the per-user send times.

= Does TokenForge contact other servers? =

Only when an administrator asks it to. See External services below.

= Can I translate TokenForge? =

Yes. The dashboard and the builder settings use the `tokenforge` text domain, so translations from translate.wordpress.org are loaded automatically.

== Screenshots ==

1. Home: every section at a glance, with a switch for each module.
2. Colour families: light and dark shades generated from one colour, with a live preview.
3. Typography: fluid text and heading sizes from one scale.
4. WooCommerce: shop pages, product lists, filters and blocks, each part with its own switch.
5. Forms: field styles, form classes, steps and conditional fields for the builder's form.

== External services ==

TokenForge contacts an outside service only when an administrator asks for it:

* **Google Fonts**, when you use Download from Google Fonts (Typography, Self-hosted fonts). Your server asks fonts.googleapis.com for the family's CSS and then downloads the font files from fonts.gstatic.com, once. It sends the family name, the weights and styles you picked, and (like any web request) your server's IP address. The files are stored in `uploads/tokenforge/fonts/`; your visitors never contact Google.
  Terms: https://developers.google.com/terms
  Privacy: https://policies.google.com/privacy

Bundled code, no outside contact:

* **axe-core 4.14.0** by Deque Systems, licence MPL 2.0 (which allows distribution with GPL code), in `assets/vendor/axe-core/` with its licence file, unmodified. Source: https://github.com/dequelabs/axe-core (tag v4.14.0). It runs in the builder only when an administrator presses Run check in the TokenForge window. No visitor page loads it.

== Changelog ==

The full history is in CHANGELOG.md, also shown from the Changelog link next to TokenForge on the Plugins screen.

= 0.54.0 =
* The Global SCSS editor is removed from the WordPress.org version. Developers can still add SCSS with the tokenforge_global_scss filter.

= 0.53.7 =
* WordPress.org review: the WP-CLI export no longer writes to a chosen file; use the shell redirect instead.
* WordPress.org review: removed links to outside documentation from the admin screens.

= 0.53.6 =
* The attribute suggestion menu is easier to read and use with a screen reader or in high contrast; unused builder code removed.

= 0.53.5 =
* Form entries: the auto-delete confirmation, the delete dry run, titles, notes, search and the menu entry are tidied.

= 0.53.4 =
* The page check treats an image role written as Presentation or "none " the same way as everywhere else, so it no longer lists that image as having empty alt text.

= 0.53.3 =
* Saving from the dashboard no longer drops a colour family made with Generate in the builder or another window; the stored ones are kept and you are told.
* The Saved message stays visible next to the site scan result, and Save no longer says "CSS updated" when the stylesheet could not be written.
* "Leave out unused utility classes" also leaves out the styles of data attributes the site does not use (after a scan).
* The tooltip bubble stays on screen inside a scaled or zoomed container.
* Nested filter groups work: each group changes only its own buttons and items.

= 0.53.2 =
* A tooltip near the edge of a phone screen stays in view, and a hidden one no longer makes the page scroll sideways.
* Switching on "Leave out unused utility classes" no longer scans the whole site during Save.
* Uninstall cleans every site of a multisite network.

= 0.53.0 =
* New data attributes: data-tf-stack, data-tf-space, data-tf-surface, data-tf-reveal, data-tf-copy, data-tf-tooltip, and a no-code list filter (data-tf-filter-group, data-tf-filter, data-tf-term).
* Data attributes screen under Foundations: lists every TokenForge data attribute with where it is used, unused ones and misspelt names.
* Mark as decorative in the builder's page check; the Attributes control suggests data-scheme and the form attributes.

= 0.52.0 =
* One Accessibility hub in the TokenForge window: the page check, palette contrast, automatic attributes, focus, reduced motion, the external-link indicator and the canvas badge.
* Fix a failing contrast pair with any token or colour you pick, with the ratio shown as you choose.
* Design check in the builder: typed colours and font sizes that should be tokens, contrast in light and dark mode, and sizes off the type scale.
* Global SCSS outline: section comments, a Jump to list with search (Ctrl+Shift+O).

= 0.51.0 =
* Page check in the builder: lists accessibility problems on the page you are editing, with Show and, where it is safe, Fix. Nothing is saved; Undo works.
* Automatic accessibility attributes for the front end, every switch off. Needs WordPress 6.2 or newer.
* Data attributes for clamped text, ratios, states and decorative images, with suggestions in the builder.

= 0.50.0 =
* Forms: submissions in the builder get search, read and unread marks, statuses and notes, editable entries, column choice, CSV or JSON export, and auto-delete after a set number of days (only after a confirmed dry run).
* Dashboard: a Light look beside Builder panels and Flat.

= 0.49.0 =
* Forms: sliders, star ratings, two-handle ranges, input masks, character counters, date rules, choices filled from posts, terms, users or custom field choices.
* Forms: live answers on the page, send counts and places left, close after a number of sends, signed-in only, and once per user. All checked again on the server.
* Forms: Update meta and Add to cart actions, and whole-form, field and style presets.

= 0.48.0 =
* Design token files: export and import W3C design token files (light and dark) with a preview of every change. History keeps a copy, so an import can be undone.
* WP-CLI commands: regenerate, export, import, snapshot, history, restore, status and contrast.

= 0.47.0 =
* Contrast check: text, links, focus rings and buttons against their backgrounds, in light and dark mode, with a suggested colour token for each problem.

= 0.46.0 =
* Settings history: before every save, import, reset and setup, TokenForge keeps a copy of its settings, tokens, colour families, fonts and Global SCSS (the last 20).
* WooCommerce: product, cart and checkout styles load only where the shop appears.

= 0.45.1 =
* On a site without a supported page builder, the builder screen lists the builders TokenForge knows instead of switches that do nothing.
