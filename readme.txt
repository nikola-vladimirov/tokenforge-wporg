=== TokenForge ===
Contributors: pristadigital
Tags: design tokens, css variables, accessibility, woocommerce
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.58.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Design tokens, fluid scales, utility classes, contrast and accessibility checks for any WordPress site, with Bricks and WooCommerce support.

== Description ==

TokenForge is a design system you set up once and use everywhere. Colours, type and spacing scales, layout, buttons, cards, icons and effects become CSS variables and classes in one static stylesheet, loaded on the front end, in the block editor and in your page builder.

A new site starts ready with a complete starter design system. Reset to defaults any time, for everything or one section. The built-in Manual (TokenForge, Manual) explains every screen.

**Design system**

* Colour families with light and dark shades from one colour, live preview, and dark mode by section with a light or dark switch (shortcode or block).
* Fluid type and spacing scales from one setting each, a width scale, grids and automatic gaps.
* Layout, borders, radius, shadows, icons, buttons, cards, overlays and prose styles.
* Utility classes for spacing, grids, flex grids, flex and alignment, text and more, with optional breakpoint variants. Unused classes can be left out after a site scan.
* Effects: entrance, exit, hover, border and scroll effects, and optional default effects for your buttons.
* A Tokens screen that lists every variable, where it comes from and where it is used.

**Builders and the block editor**

* Works with the block editor, Bricks, Etch, Oxygen, Breakdance, Divi and Elementor. Bricks has the deepest support.
* In every supported builder: the TokenForge window, variables and classes by right-click, the input helper, suggestions and recipes in the custom CSS box, text recipes, a light and dark preview of the canvas, and the TokenForge stylesheet in the canvas.
* Block editor: your tokens become the editor presets, plus patterns, button styles, a class field and optional editor tools.
* Bricks: two-way sync with Bricks variables, colours, global classes and the theme style, a design check, a BEM class generator, form element extensions and a setup wizard.

**Accessibility**

* Contrast check of text, links, focus rings and buttons in light and dark mode (WCAG 2 AA, APCA shown), with a suggested token applied in one click.
* Optional automatic attributes: hidden decorative icons, alt text, menu labels, the current page mark and a new tab notice.
* A page check in the builder (axe-core), only when an administrator presses Run check.
* Data attributes for layout and behaviour without code (data-tf-stack, data-tf-space, data-tf-surface, data-tf-reveal, data-tf-tooltip, data-tf-filter and more).

**Fonts, WooCommerce and tools**

* Self-hosted fonts: upload TTF or OTF and get WOFF2, or download a Google font once to your server.
* WooCommerce shop, product, cart, checkout and account pages and blocks styled with your tokens, each part with its own switch.
* Export and import settings (with a preview), W3C design token files for Figma, Tokens Studio and Style Dictionary, the settings history (last 20 saves, undoable), WP-CLI commands and tools for AI agents.
* Moving from another utility framework? The settings import under Import, export & reset brings your settings across, and its class names work too.

**Developers**

The filter `tokenforge_global_scss` returns SCSS that TokenForge compiles and adds last to the stylesheet (put it in a child theme or a mu-plugin). The action `tokenforge_builder_enqueue` loads an add-on's assets in every supported builder, and `tokenforge_block_editor_enqueue` in the block editor. Details and examples are in the Manual.

TokenForge is original software released under the GPLv2 or later, developed by Prista Digital (https://pristadigital.com).

== Installation ==

1. Install the zip from Plugins, Add New, Upload, or upload the `tokenforge` folder to `/wp-content/plugins/`.
2. Activate. A new site starts with the starter design system.
3. Open **TokenForge** in the admin menu, change what you like and save. TokenForge, Manual explains each screen.

== Frequently Asked Questions ==

= Do I need a page builder? =

No. The stylesheet loads on the front end and in the block editor. The builder tools switch on only with Bricks, Etch, Oxygen, Breakdance, Elementor or Divi 5.

= Will it slow my site down? =

No CSS is built while a page loads: TokenForge writes one static stylesheet when you save. You can print it inline, switch off sections you do not use and leave out unused utility classes. Shop styles are a second file loaded only where the shop shows.

= How do I switch from another utility framework? =

Open Import, export & reset, press the settings import button and confirm. Colours, scales, layout, typography, buttons, cards, components, overlays and fonts come across; custom CSS does not. The settings history can undo it.

= Can I add my own CSS? =

Yes: in your builder's custom CSS box or the Customizer's Additional CSS. Developers can add SCSS with the `tokenforge_global_scss` filter.

= What happens when I deactivate or delete TokenForge? =

Deactivating stops the stylesheet; settings stay. Deleting removes the settings, the generated stylesheets and what TokenForge added to Bricks. Uploaded font files stay in `uploads/tokenforge/fonts/`.

= Does TokenForge keep anything about visitors? =

Only what your forms ask for: send counts for forms with a send limit, and the last send time per signed-in user for per-user limits. In the visitor's browser: their light or dark choice, and, where you turn it on, unsent form answers. Deleting TokenForge removes the counts and send times.

= Where is the full documentation? =

In the plugin: TokenForge, Manual in the admin menu.

== External services ==

TokenForge contacts one outside service, Google Fonts, and only when an administrator presses Download from Google Fonts (Typography, Self-hosted fonts). Your server then asks fonts.googleapis.com for the family's CSS and downloads the font files from fonts.gstatic.com, once. It sends the family name, the weights and styles you picked, and your server's IP address, like any web request. The files are stored in `uploads/tokenforge/fonts/`, so your visitors never contact Google.

Google terms: https://developers.google.com/terms
Google privacy policy: https://policies.google.com/privacy

The accessibility checker (axe-core, MPL 2.0) is bundled and runs in the builder only. Nothing else is contacted.

== Screenshots ==

1. Home: every section at a glance, with a switch for each module.
2. Colour families: light and dark shades generated from one colour, with a live preview.
3. Typography: fluid text and heading sizes from one scale.
4. WooCommerce: shop pages, product lists, filters and blocks, each part with its own switch.
5. Forms: field styles, form classes, steps and conditional fields for the builder's form.

== Changelog ==

Older versions are in changelog.txt.

= 0.58.2 =
* New: TokenForge, Manual, an English user manual in the admin.
* Class names from another utility framework (grid--3, grid--m-2, flex-grid--3, text--center and more) now work too; colour names such as bg--primary have their own switch.
* New: default button effects (hover, entrance and hover border) on the Effects screen.
* New recipes: gradients, ?before, ?after, ?hover, ?focus-visible, ?color-mix, ?tooltip, ?footer-reveal and more.
* Builder: a moon button previews the canvas in dark mode while you build.
* Fixed: a window opened before a newer save (another builder tab or the dashboard) no longer puts older settings back.
* Fixed: switching on a setting that adds classes rechecks the site, so used classes are not left out. Entrance effects on screen at load no longer flash before they play.
* Settings import: more switches, button effects and older breakpoint values come across.

= 0.58.1 =
* Effects: entrance effects on elements already on screen when the page opens (a hero, for example) now play as the page loads. Before, they waited for a scroll that never came.
* Importing another framework's settings now brings over its site colour scheme and card inner radius from its newer version.
