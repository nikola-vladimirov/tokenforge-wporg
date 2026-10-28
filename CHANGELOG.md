# TokenForge changelog

All the changes to TokenForge, newest first.

## 0.53.7

Fixes from the WordPress.org review.

- The WP-CLI export no longer writes to a chosen file; use the shell redirect instead (`wp tokenforge export > tokenforge-export.json`).
- Removed links to outside documentation from the admin screens.

## 0.53.6

Small tidy-ups in the builder.

- The data attribute suggestion menu shows the selected row with a bar as well as a colour, and its description text is clearer to read.
- The menu announces its results to screen readers reliably, no longer repeats the description as a tooltip, and has no empty entry in its list.
- Removed unused code from the accessibility check (an old "added automatically" list that was always empty).

## 0.53.5

Small fixes to form entries.

- Switching auto-delete off now also forgets the confirmation when Bricks is not active.
- Confirming a delete uses the same cutoff the dry run showed, so entries that became old since are left for the daily run.
- The entries list in the REST API checks its arguments (status, read, page and page size).
- The Form entries menu item appears at the top only when the Bricks submenu page exists.
- Post titles in entries and the dry run no longer show codes such as &amp;.
- Opening an entry from search results keeps the keyboard focus on a button that still exists.
- An empty checkbox list can be filled in when editing an entry, and the note box allows as many characters as the server (5000).
- Opening an entry no longer overwrites who last edited it and when.
- Counts in the delete messages use singular and plural, and a server message such as "the rules changed since that dry run" shows instead of "HTTP 409".

## 0.53.4

A small fix after the review of 0.53.3.

- The page check now reads an image role the same way everywhere: Presentation or "none " (capital letters, a trailing space) counts as decorative, so that image is no longer listed as having empty alt text.

## 0.53.3

Fixes after the review of 0.53.2.

- Saving from the dashboard no longer drops a colour family made with Generate in the builder or in another window after the dashboard was opened. The stored families are kept, the dashboard tells you and shows the current ones, and the colour edits made in that window are not applied.
- After a Save, the "Saved" message stays visible next to the result of the site scan. If only the scan fails, the message says the save worked and the scan failed. "Scan now" with unsaved changes now scans once, not twice.
- Save no longer says "CSS updated" when the stylesheet could not be written (for example the uploads folder is not writable). Regenerate says so too.
- "Leave out unused utility classes" now also leaves out the styles of data attributes the site does not use, after a scan (Scan now, or switching the setting on and saving). A page that uses none gets about 700 bytes (compressed) less. With the setting off, or before a scan, nothing changes; an older Data attributes report is not used until you scan again. An attribute you add later in a page or template joins when you save. One a scan cannot see (a theme file, a shortcode, a script) goes in "Always keep", by name or as data-tf-*.
- The tooltip bubble now stays on screen inside a scaled or zoomed container too, not only in a normal one.
- A data attribute written straight after a quoted value (class="a"data-tf-copy) now loads the TokenForge script and is counted by the scans, like one written after a space.
- "Scan now" also refreshes the Data attributes screen, and says so when the stylesheet could not be written.
- Nested filter groups work: each group changes only its own buttons and items.
- Mark as decorative works on WordPress 5.9 to 6.1 again for a plain image that has no alt text in the media library: it also adds role="presentation" there. A linked or captioned image, or one with alt text in the media library, still needs WordPress 6.2 or newer, and the note under the fix says so.
- The page check now reports an image whose role (presentation or none) hides the alt text it has, for example after the decorative mark was taken off and alt text written, with a one-click "Remove the role".
- A little less work on every page: the accessibility attributes and the data attributes now share the same page hooks.

## 0.53.2

Fixes after the review of 0.53.0, and some tidying.

- A tooltip on an element near the edge of a phone screen is no longer cut off: the bubble slides sideways to stay in view. A hidden bubble also no longer makes the page scroll sideways.
- A page that only shows text such as data-tf-copy (a search page that repeats what was searched for, a code sample) no longer loads the TokenForge script. Only an element that really carries the attribute does.
- The rule behind data-tf-decorative (and so Mark as decorative) needs WordPress 6.2 or newer, like the other automatic accessibility attributes. The plugin still starts on 5.9, but the mark does nothing there.
- The Data attributes report now finds names after a line break inside builder data, which it missed before. The column help says that text in pages counts too.
- Saving from the dashboard no longer drops tokens (colours, variables) that were added in the builder after the dashboard was opened. The stored ones are kept, and the dashboard tells you and shows the current ones. Colour families made with Generate in another window are not covered yet.
- The stylesheet is written to a temporary file and moved into place, so a visitor never gets half a file.
- Switching on "Leave out unused utility classes" no longer reads the whole site while the Save is running. The dashboard starts the scan right after the Save, and the stylesheet keeps every class until it finishes.
- Uninstalling TokenForge on a multisite network now cleans every site, not only the main one.
- Hot reload checks for changes every 15 seconds instead of every 5. A save in the same browser still updates the page at once.
- The release check now runs a PHP syntax check on PHP 7.4 before a release is published.

## 0.53.1

A small speed fix.

- The Data attributes screen opens faster. It used to download the whole Cheatsheet (about 500 KB) to list the attribute names; now the names come with the dashboard itself.
- The list now always includes data-tf-a11y. Before, it showed only while the automatic accessibility attributes were switched on.

## 0.53.0

The rest of the data attributes: layout, spacing, panels, reveal on scroll, copy, tooltips and a list filter, plus a report of where each one is used.

### New data attributes

- **data-tf-stack="m"** turns a row or grid into a column on screens narrower than the breakpoint you pick (xs, s, m or l, using your breakpoint settings). Nothing changes above that width.
- **data-tf-space="m"** gives a section or container the section spacing from your scale (s, m or l). On an element that also has data-tf-stack it sets the gap between the children instead. A spacing set in the element's own style settings still wins; a class on it does not.
- **data-tf-surface="2"** makes a raised panel (1, 2 or 3): a background, a thin border and readable text from your colour tokens, light and dark mode included. It is not the textures from Surfaces and Overlays.
- **data-tf-reveal="up"** shows an element when it scrolls into view (fade, or up to fade it in and slide it up). It uses the Visible effects of Motion, so with those off the element simply shows.
- **data-tf-copy** makes a button or link copy the value you type, or its own text when the value is empty, and tells the visitor and screen readers that it worked. The small script loads only on pages that use it.
- **data-tf-tooltip="Opens in a new tab"** shows a bubble above the element on hover and keyboard focus, hides on Esc, and screen readers read it as the description.
- **Filter a list without code:** put data-tf-filter-group on the box that holds the buttons and the list, data-tf-filter="design" on each button (all shows everything) and data-tf-term="design web" on each item. Without the script every item shows, and items added after the page loads are filtered on the next click.
- All of them have suggestions in the builder's Attributes control, a Cheatsheet entry and the typo check.

### Data attributes report

- The new **Data attributes** screen under Foundations has **Find uses**: it reads your pages, templates, classes and styles and shows every TokenForge data attribute with how often and where it is used, a **Show unused only** switch, and names that look misspelt with a "Did you mean" hint. It only reads, and it does not check theme files or other plugins.

### Builder

- **Mark as decorative** in the page check now writes the attribute data-tf-decorative on the element, so the front end hides it from screen readers by the same rule as hand-typed ones.
- The Attributes control also suggests data-scheme (with light and dark as its values), data-scheme-toggle and the form attributes.

### Fixed

- Visible effects (on-visible--fade and the others) never played in the builder's pages when the effect class was not the first class on the element, which is the usual case there. They play now, also for elements that appear after the page has loaded (carousel clones, Load more), and the same goes for entrance effects in browsers without scroll timelines.

## 0.52.0

Everything about accessibility now sits in one place, failing colour pairs can be fixed with any colour you pick, the builder can check a page against your design system, and the Global SCSS gets an outline you can jump around in.

### Accessibility hub

- The **Check** entry in the TokenForge window is now **Accessibility**. It holds This page (the page check and heading outline, in the builder only), Palette contrast (moved from Colors), Automatic accessibility attributes, Focus, Reduced motion and the External-link indicator (moved from Base, Scrolling and Buttons & Links), and the Canvas badges switches (moved from the Canvas panel).
- Base & Accessibility is now **Base** and keeps Reset and Utilities. Colors keeps one line that takes you to the hub.
- Every moved setting keeps its saved value, and panels you had open stay open.

### Fix contrast with your own colour

- Each failing row in Palette contrast has **Another colour** beside "Use the nearest token": pick a colour token or any colour and see the ratio straight away, in light and in dark mode when your palette has both, with "Passes" or "Fails" in words for each. One setting serves both modes, so Apply is available only when every mode passes. Apply changes only that pair's colour setting, never the token. Save applies it and History can undo it. When other pairs share the same setting, the number of them that would fall below their minimum is shown.
- The colour contrast items of the page check get the same picker. Apply sets that element's text colour in the builder; nothing is saved until you save, and the builder's Undo takes it back. In the Design view the same element's problem in the other mode is measured too; in the page check only the mode on the canvas is known, so the result says the colour applies in both modes and to check again in the other one. If the text colour is set for the breakpoint you are on, the fix says so and changes nothing. The nearest passing token is now shown on each item.

### Design check in the builder

- This page now has two tabs, **Accessibility** and **Design**. Run design check looks at the page you are editing against your design system and lists what drifts from it, with a summary first and every group closed until you open it.
- **Typed values instead of tokens:** a colour or font size typed by hand where a token is close, grouped by value with how often it is used and the nearest token, for example "#333 used 14 times". Use token changes one element, or the one global class the value lives in. Values in the theme style are listed without a fix, locked classes say they are locked, and values in the header or footer say to open that template.
- **Contrast** on the page as it is drawn, in light and in dark mode, with the same engine as the page check. Text on a picture, a gradient or an overlay says "check by eye" and never counts as a pass.
- **Font sizes** that are off your type scale, or smaller than 12px.
- The panel says which breakpoint it checked and that both modes ran. Ignore hides a group for this session, and "Show ignored groups" brings it back.
- As with the page check, a fix only changes the page open in the builder. Nothing is saved until you save, and the builder's Undo takes a fix back.

### Global SCSS outline

- Write section comments as `/* # Hero */`, `/* ## Buttons */` and `/* ### Hover */`: one `#` is a section, two a sub-section, three a level below. Line comments (`//`) do not count.
- **Jump to** above the editor shows the section you are in. Open it to see every section as a tree, type to filter, and press Enter to jump there. Ctrl+Shift+O (Cmd+Shift+O on a Mac) opens it with the filter ready.
- Add sections fast: the **# Section** and **## Sub-section** buttons, Ctrl+Enter for a section and Ctrl+Shift+Enter for a sub-section (Cmd on a Mac), or type `#`, `##` or `###` on an empty line and press Tab.
- Works the same in the dashboard editor and in the SCSS dock in the builder.

### Fixed

- The page check now checks the contrast of all text on the page. Before, text further down than the visible part of the canvas was skipped without a word.
- Contrast in dark mode is measured after the colours have finished changing, not halfway through a fade.

## 0.51.0

Accessibility tools in the builder: check the page you are editing, see heading levels and focus order on the canvas, and switch on automatic accessibility attributes for the front end. Plus a clickable card, new data attributes and attribute suggestions in the builder.

### Page check in the builder

- New **Check** entry in the TokenForge window. Run check looks at the page you are editing, including the header and footer templates it uses, and lists the problems in plain words, grouped, with the worst first. Nothing runs until you press the button.
- Each problem has Show, which selects the element in the builder, and where it is safe a Fix. A fix only changes the page open in the builder. Nothing is saved until you save, and the builder's Undo takes a fix back.
- Fixes in this version: change a heading level, write alt text (typed, or from the media library when it has one), mark an image as decorative, add an aria-label, change the tag of a page region, remove a tabindex above 0. Everything else is reported with an explanation of how to fix it by hand.
- Things a person has to look at, for example text on a picture, are listed apart as "check by eye" and never counted as a pass.
- The heading outline lists the headings in order and says where a level is skipped.
- The check uses the open source axe-core engine (version 4.14.0, bundled). It is loaded only when you run a check, in the builder, for administrators. No visitor page loads it. TokenForge adds its own checks: a second h1, an image with an empty alt that is not marked decorative, a link wrapped around a whole card, misspelt data-tf attribute names, and for low contrast the nearest colour token that passes.

### On the canvas

- Two new switches in the Canvas panel, both off: **Heading levels** (an h1 to h6 mark on each heading, with a warning mark where a level is skipped) and **Focus order** (numbers in the order the Tab key visits things).

### Automatic accessibility attributes (all off)

- New section on Base and Accessibility. Every switch starts off. Each one adds an attribute on the page only where it is missing: anything you set yourself, even an empty alt, is never changed. Nothing is saved in your pages, so switching one off gives back exactly the same page.
- Hide icons that sit inside a link or button that already has text. Alt text from the media library for images with no alt. The menu name as the label of a menu that has none. Mark the menu link of the current page. Announce links that open in a new tab. Two guesses, labelled as guesses: the placeholder as the label of a field, and a label for a link with only an icon.
- Put `data-tf-a11y="off"` on an element to leave it and everything inside it alone.
- Needs WordPress 6.2 or newer; hiding icons and the icon-link guess need 6.5. Below that the switches say so and nothing runs.

### Clickable card

- Class `.is-clickable` on a card: the link in its heading covers the whole card, so the whole card is clickable and a screen reader still hears one link. The keyboard focus ring shows around the card. `.is-above` on a second link or button inside the card keeps it clickable. New recipe `clickable-card`.

### Data attributes

- New: `data-tf-clamp` (cut text after 1 to 6 lines), `data-tf-ratio` (a fixed shape for an image or video), `data-tf-state` (open, active, disabled hooks for your CSS) and `data-tf-decorative` (marks decoration: `aria-hidden` on it and an empty alt on images, added on the front end).
- In the builder's Attributes control, suggestions for TokenForge attribute names and values appear as you type. A right click there offers **Add attribute**. The Cheatsheet has an Attributes tab with one-click copy, and the typo check flags `data-tf-` names that do not exist.

## 0.50.0

Form submissions in the builder get a set of extra tools. The builder's own Form submissions screen keeps its table, search, delete and CSV download as they are. Each part has its own switch under Components > Forms > Form submissions.

### Search and reading

- Search across all forms or one form, and filter by status and by read or unread.
- Open one entry in a window and go to the previous or next one. Opening an entry marks it read.
- Mark entries read or unread. The number of new ones shows next to the menu item.
- Give each entry a status (new, done, spam) and a private note.

### Table and entries

- Choose which columns the table shows and put them in your order with the arrow buttons. Each user keeps their own choice, saved per form.
- Edit an entry to fix an answer, for example a typo. Only administrators can edit. File and password fields cannot be edited.
- Export the rows you tick as CSV or JSON. Cells that start with =, +, - or @ get a leading quote, so a spreadsheet does not run them as formulas.
- Optional top-level menu item, so the screen is one click away.

### Who can use it

- Administrators by default. You can allow editors, authors, contributors and above instead. They also need the builder's own access to form submissions.
- Editing answers and deleting always need an administrator.

### Auto-delete

- Delete submissions older than a number of days, for all forms (site setting) or for one form (in the form's Save submission settings).
- Nothing is deleted until you run a dry run, look at what it would remove and confirm it. After any change to the days, it waits for a new dry run. New forms that use the site setting follow it without one. Switching auto-delete off and on again needs a new dry run.

### Dashboard

- A new Light look sits beside Builder panels and Flat. Choose it under Options > Dashboard look.

### Uninstall

- Uninstalling TokenForge removes its own submission data (statuses, notes, column choices, settings). It never removes the builder's saved submissions.

## 0.49.0

Everything below works inside the builder's own form element: no new elements, and the answers reach the email, webhook, saved submission and other actions as before. Each part has its own switch under Components > Form fields.

### Field looks

- A Number field can show as a slider (with text before and after the value) or as star ratings.
- A Text field can show as a range with two handles; it sends "from - to", for example "100 - 500".
- Input masks for Text and Telephone fields: # is a digit, @ a letter, * either, anything else is typed for the visitor (for example +359 ### ### ###). The field only counts as filled in when the mask is complete.
- A character counter under Text and Textarea fields, with the limit when the field has a max length.

### Date rules

- Earliest and latest day (in days from today), weekdays that cannot be picked, and closed dates or spans.
- An end date can follow a start date, with a smallest number of days between them.
- The same rules are checked again on the server when the form is sent.

### Choices from your content

- A Select, Radio or Checkbox field can take its options from posts or pages, categories or other terms, users, or the choices of an ACF or Meta Box field. New content shows up by itself. Typed options can stay first, for a choice such as "Not sure yet".

### Messages

- Your own message for a field left empty and another for a field filled in wrongly (a wrong email or web address, a mask or pattern not matched, a number out of range, a date that is not allowed).

### Live values and counts

- {tf_form_value:Field} shows an answer anywhere on the page as the visitor types, for example a calculated price in a box beside the form.
- {tf_form_count:form ID} shows how many times a form was sent, {tf_form_left:form ID} how many places are left.

### Limits and the send button

- Close a form after a number of sends, with your message. With Save submission among the actions the saved submissions count, so deleting one opens a place again.
- Only signed-in visitors, and once per user (or once every few days).
- The send button can stay disabled or hidden until conditions hold, for example "{Terms} not empty". Checked again on the server.

### Actions

- Update meta: writes answers to the post the form is on, another post the visitor may edit, or the signed-in user. Replace, add to or subtract from a number, add to or remove from a list. Keys starting with _ and role keys are refused.
- Add to cart (WooCommerce): a product or variation, a quantity, and a price from a calculated field that is worked out again on the server, so a visitor cannot change it. The answers can show in the cart and on the order.

### Presets

- The form's Presets group fills a form from a ready-made one (contact, quote calculator, booking request, sign-up, profile edit, newsletter), adds single fields (name, email, phone with mask, consent, date, slider, stars and more), or saves your own preset to reuse, export and import between sites.
- Style presets for the form: underlined, rounded or soft (filled) fields.

### Fixed

- The field and value pickers under form conditions and calculations did not show in Bricks 2.4.

## 0.48.0

### Design token files

- Import, export & reset has a new "Design token files" panel. "Download token files" saves two files in the W3C design token format: tokenforge.tokens.json with your colours, sizes, fonts and shadows, and tokenforge.dark.tokens.json with the colours that change in dark mode. Figma (with Tokens Studio or variables) and Style Dictionary can read them.
- Fluid sizes go out as their largest size, and the full range rides along, so TokenForge reads them back exactly.
- Choose a tokens file (and the dark file if you have one) to import. Before anything is saved you see what is new, what changes and what is left out, with the reason for each. Apply saves the changes, and History keeps a copy first, so an import can be undone.
- A colour keeps its dark mode value when the file has none for it.
- Colours of your colour families are not changed by an import. Change them under Colors, so their shades follow.
- Files over 2 MB, files that are not design tokens and values that are not valid are refused with a plain message. Nothing from a file reaches your stylesheet unchecked.

### Command line (WP-CLI)

- New commands for site owners who use WP-CLI: `wp tokenforge regenerate`, `export`, `import` (with `--dry-run` to see what would change), `snapshot`, `history`, `restore`, `status` and `contrast`.
- `wp tokenforge contrast` ends with an error when a pair falls short, so it can stop a deploy script.
- They do the same thing as the dashboard, and an import or restore keeps a History copy first.

### Contrast check

- A suggested fix is now the passing colour token closest to the colour you have, so muted text stays muted instead of jumping to the full text colour.

## 0.47.0

### Contrast check

- Colors has a new Contrast panel. It checks the text, muted text, links, link hover and focus ring on the page and on each background class, and the text of every button family in each style, as it is and hovered.
- Each pair shows its colours, its contrast ratio and whether it reaches the WCAG level AA minimum: 4.5 to 1 for text and links, 3 to 1 for focus rings. With dark mode on, light and dark are checked separately.
- The APCA value (Lc) is shown beside the ratio for information. APCA is a draft for the next WCAG version, so it never decides pass or fail.
- A pair that falls short gets a suggested fix: the nearest colour token that passes in light and dark mode, from the same colour family where possible. Use it, then Save. Every Save is kept in History, so a fix can be undone.
- With many problems, "Use all fixes" takes every suggestion at once, or tick the ones you want and press "Use the selected fixes". A tick box in the column head selects them all.
- The Home screen tells you when some pairs fall short, with a button to the panel.
- The check reads the saved colours. After changing a colour, Save to check again.

### Fixed

- Plain tick boxes in the TokenForge dashboard (the Tokens list, the setup wizard) showed as a thin line inside WordPress admin and were hard to find. They are normal tick boxes again.

## 0.46.0

### Settings history

- Before every Save, import, reset, setup wizard run and restore, TokenForge keeps a copy of all its settings, tokens, colour families, fonts and your Global SCSS, with the time and who made the change. Nothing is kept when nothing changed since the last copy.
- Import, export & reset > History lists the last 20 copies. "What changed" shows, setting by setting, the value then and the value now. "Restore" puts a copy back; the state before it is kept too, so a restore can be undone.
- A restore goes through the same checks as an import, and the builder classes follow the restored Global SCSS.
- The copies are stored compressed (at most 5 MB together), never load on visitor pages, and are removed when TokenForge is deleted.

### Smaller main stylesheet with WooCommerce

- The styles for product lists, product cards, prices, sale badges, ratings, the product page, tabs, tables, pagination, cart and checkout buttons and the cart and checkout blocks now live only in the shop sheet, which loads where the shop appears. The main stylesheet is about 20 KB smaller on every other page.
- What a header cart shows (the mini cart, its buttons, line items, totals, quantity boxes, dynamic fragments, the block mini cart) keeps its styles on every page, and so do store notices.
- With Oxygen, Breakdance, Divi or Elementor active, TokenForge cannot tell which pages show their product widgets, so there everything stays in the main stylesheet as before.

## 0.45.1

### No more switches that do nothing

- On a site without a supported page builder (a block theme, for example), the Builder screen becomes "Builder integrations": a list of the page builders TokenForge knows, with what each one's status is on this site (active, installed, or coming soon) and what already works in the block editor today. It has no switches.
- Settings that only work inside a supported page builder are hidden everywhere else too, also in search: builder sync in Options, the builder class sync and the canvas dock in Global SCSS, the builder font list, recipes in builder fields, the builder form extras and layouts, builder button styles and registering classes with the builder.
- Hiding only changes what you see. The hidden settings keep their values, Save leaves them as they were, and "reset this section" leaves them alone, so they are all back as before when the builder is switched on again.
- With a supported builder active, the Builder screen is named after it. Nothing else changes on those sites.
- The setup wizard and the "Connect the builder" step in Getting started only show when there is a builder to connect.
- In the Etch editor, the TokenForge stylesheet and the TokenForge button no longer load into the editor itself (the button sat on top of Etch's own controls). The page you edit keeps its look as before.

## 0.45.0

### Updates from the dashboard

- New TokenForge versions now show up in Dashboard > Updates and on the Plugins screen like any other plugin update, and automatic updates work too. "View version details" shows what changed.
- The versions come from the TokenForge repository on GitHub. While that repository is private, each site needs a read-only access token in wp-config.php: define( 'TOKENFORGE_GITHUB_TOKEN', 'github_pat_...' ); Without it no update is offered and nothing else changes.

## 0.44.1

### Security

- When the stylesheet is printed inside the page (the inline option), it can no longer close its style tag early, whatever ends up in it. Newer WordPress versions already guard against this; the older versions TokenForge still supports do not.
- Uploading a font file now also needs the right to upload files, like the Google Fonts download already did. Administrators have it, so nothing changes for them.

## 0.44.0

### New classes

- Auto grid classes grid--auto-2 to grid--auto-12: up to that many columns, which drop to fewer on their own as space runs out. They make the same columns as the --grid-auto-2 to --grid-auto-12 variables and follow the Auto grid setting (fit or fill). Add grid--auto-fill or grid--auto-fit to change it for one grid. grid--auto-rows gives every row the same height.
- Spacing classes with two dashes, the names sites moved from another framework often use: padding--m, pad--m, margin-top--m (also right, bottom, left, block and inline), gap--m, row-gap--m and col-gap--m. They use your Spacing sizes, xs to xxl.
- mar-inline-m (margin left and right) and flex-nowrap.
- Corner classes radius--m and rounded--m, from xs to xxl, plus 50, circle and none. They use your Radius settings.
- With "Leave out unused utility classes" on, the new classes you don't use are left out of the stylesheet, like the others.

### Fixed

- Importing another framework's settings: when those settings have no shared lightness for the brand colours, every brand colour now keeps its own lightness. Before, they all got the same one, so a dark header could turn light blue.
- After that import, the text and spacing sizes stay fluid. Before, the imported fixed sizes replaced them, so every size stayed the same on every screen and looked about 1.6 times too large.

## 0.43.0

### WooCommerce: much more to set

- Fields (WooCommerce > Fields): height, padding, text size, background, text colour, border colour, border width, corners, focus colour, label colour and label size. They apply to checkout, account and coupon fields, the WooCommerce blocks included. An empty setting keeps following the Forms section.
- With floating labels (the label inside the field), the field keeps the padding it needs for the label, and Field height sets its height.
- Notices (WooCommerce > Notices): padding, side border width, text size, the icon on or off, and the background, text and border colour of each kind: success, info, error and warning. An empty colour keeps using that kind's colour family.
- New panel WooCommerce > Look and spacing: the text colour, soft text colour, accent colour, soft accent background, border colour, small radius, panel padding and the spaces used across every shop part. These were fixed values before; each now has a setting, and an empty one goes back to its default.

### Fixed

- Checkout fields with floating labels: when the browser fills in a saved address, it shows the text before the field really has a value, so the label stayed down and covered the text. The label now moves up while the browser fills the field too.

## 0.42.2

- Plugin Check fix: when TokenForge reads your pages and posts (Find uses on the Tokens screen, and Leave out unused utility classes), it no longer switches off the query filters of other plugins. WordPress.org does not allow that setting. On a normal site the results are the same.

## 0.42.1

Housekeeping. Nothing changes in how TokenForge works.

- Removed three pieces of code that nothing used.
- Parts that were written twice now exist once:
  - the reading of your pages and posts (used by Find uses and by Leave out unused utility classes);
  - the form helpers that turn conditions into what the browser reads;
  - the code that updates the builder's classes (the dashboard window and the Global SCSS dock now share it);
  - the step that rebuilds the stylesheet and then updates the builder.

## 0.42.0

### Setup wizard for the builder

- New section Setup > Setup wizard (and a card on Home until it has run once). It does two things:
  - Step 1 sets the builder's own settings the way TokenForge works best. They come in groups you can tick: working comfort, previews while you hover, classes and CSS, speed, forms and filters, media checks, screenshots, and the shop (with WooCommerce). Each group lists every setting and says which ones would change. Settings that are already right are left alone.
  - Step 2 connects TokenForge and the builder: variables, colours and classes sent to the builder, the stylesheet in the builder canvas, the site's theme style linked to TokenForge, and the live Global SCSS sync.
- What the wizard never touches: which post types use the builder, the builder's language, sign-in and API keys, the licence, remote templates, AI settings and user roles.
- When form submissions or query filters get switched on, the wizard creates their database tables, as the builder's own settings screen does.
- Undo puts the builder's settings back as they were before the wizard first ran.
- Only administrators can run it.

### Forms

- Required only when: a field can be required only while other fields have certain values ({Contact by} = Phone). It works in the browser (the asterisk comes and goes too) and again on the server when the form is sent. A hidden field is never required. For a group of checkboxes, one ticked box is enough.
- Number format for results: 1,234.50, 1.234,50, 1 234,50 or 1'234.50 (text fields; Number and Hidden fields keep the plain number).
- A result can show as plain text (large and bold) instead of a read-only box. It is still sent with the form.
- A result that uses another result counts with its number, so formats and prefixes never get in the way.
- In the builder, under "More conditions", "Required only when", a step's conditions and "Calculate": pick a field from a list, how to compare, and the value (a choice field lists its own options). Add writes the line for you. You can still type the lines.
- Numbers with apostrophes (1'234.50) are now read as numbers.

### A smaller stylesheet

- New option in Options > Utility framework: Leave out unused utility classes. A scan reads your pages, posts, templates, menus, widgets, components and global elements (drafts too) and keeps only the utility classes they use. On a test site that uses components rather than utility classes, the stylesheet went from about 274 KB to 194 KB.
- A class you add later joins the stylesheet as soon as you save the page, the template or the menu.
- The builder canvas and the block editor always get every class, so the class list and the preview are complete while you work.
- "Always keep" takes class names that theme files or other plugins add (a name ending in * keeps every class that starts with it).
- Only the utility classes are left out. Variables, components and your Global SCSS are never left out. A very large site (more than 5,000 posts) keeps every class.
- The Tokens screen still counts variable uses in the complete stylesheet.

## 0.41.1

Fixes found by testing 0.41.0 in the real builder.

### Global SCSS sync

- A class that is open in the builder's panels now shows SCSS changes right away. The builder edits an open class as a separate copy and writes that copy back when you move to another element. TokenForge only updated the class itself, so the panels kept the old values, and moving on could write the old values back into the class (and from there into the SCSS). TokenForge now updates the open copy too, the same way the builder does it, and draws the panels again.
- A change you make in a class's CSS panel is sent to the SCSS once you stop typing for a moment, and not while a rule is half typed. Before, a rule caught halfway (without its closing brace) could land in the SCSS without its last semicolon.

### Recipes

- Text recipes also expand when you type a space after the name, so ?ipsum-short followed by a space becomes the placeholder text (the space stays). The semicolon still works. Style fields keep needing the semicolon (?primary-clr;), so a space never changes a value by accident.
- In text fields, letters typed right after the recipe are no longer lost.
- In the rich text editor, the space after the inserted text is kept.

### Correction

- The 0.39.0 notes say the builder keeps its class list "in a new place" and that this stopped synced values from showing. That was wrong: the builder still offers the class list under the old name too. TokenForge now reads either name, which is harmless. The real reasons were the builder's own CSS sync (fixed in 0.41.0) and the open class copy (fixed here).

## 0.41.0

### Global SCSS and the builder classes: live, and Global SCSS wins

- The sync with the builder's global classes is now live in both directions. You no longer press a Sync button (it is gone) or save the builder to see a change:
  - type in the Global SCSS dock and the classes with a block show it right away: their Style fields, their CSS panel and the canvas;
  - change a class in the builder (Style fields or CSS panel) and the SCSS in the dock follows right away.
- Nothing is stored until you save, as before: Save in the dock stores the SCSS and the classes; saving the builder stores the classes and the SCSS. When the builder's save wrote the same SCSS the dock shows, the dock simply counts as saved.
- Global SCSS wins. What a class block sets is what the builder class shows. The class's CSS panel only holds what has no Style field (custom properties, !important, nested rules such as & .icon), so it can never override the SCSS.
- The builder's own "CSS sync" option (which mirrors the Style fields into the CSS panel) works with this: its copy of the fields is not taken as a change, and the "align-items: initial" it adds for a grid never reaches your SCSS.
- In the builder the CSS panel wins over the Style fields, so when you type a value in the CSS panel, that value goes into the SCSS block as a normal declaration.
- Fixed: the first sync used to copy a class's old CSS panel into the SCSS block (as "builder custom CSS"). There it could beat the block's own values: an old "color: #0c8f0a" beat "color: var(--danger)". The update clears this from every class, keeping the block's values, and removes the copied part from the SCSS.
- The update also switches the sync on where it was off.
- Fixed: a class whose block you removed from the SCSS no longer gets its block back every time the builder saves.

### Forms: more conditions and calculations

- A field can have several conditions. Under "More conditions (one per line)", write one per line, for example {Budget} > 1000 or {Topic} = Web design or {Phone} not empty. Then choose whether all of them or any of them must hold. They count together with "Show only when field".
- Steps get the same: "More step conditions" and "Show this step when".
- New operators: "is at least" and "is at most" (>= and <=).
- New: calculated fields. Fill in "Calculate" on a Number, Text or Hidden field, for example {Quantity} * {Price} + 25:
  - the field becomes a read-only result that updates while the visitor fills in the form;
  - fields are named in braces by their label or ID;
  - you can use + - * / % ^, brackets, round(x, 2), ceil, floor, min, max, abs, and if(test, then, else) with > < >= <= = !=;
  - a choice counts its value (use "Set options as value:label", for example 250:Logo design), ticked checkboxes add up, and hidden fields count as 0;
  - set the number of decimals, and text before and after the number (for example "EUR " or " / month").
- The result is worked out again on the server when the form is sent, so the email and the saved submission always get the right number, whatever the browser sent.

### Recipes

- Recipes now also work in text fields, text areas, the rich text editor and text you edit in place in the canvas. Type ?ipsum-short; (with the semicolon) and it becomes the placeholder text. Style fields keep taking value recipes such as ?primary-clr;.

## 0.40.0

### The builder colour palette shows your colours by name

- The TokenForge palette in the builder's colour picker used to hold only the base colour values of each family (primary-hex and so on), listed by their hex codes. It now lists every colour you use, by name:
  - each colour family (primary, secondary, tertiary, accent, base, neutral, action, success, danger, warning, info) as a main colour, followed by its hover colour;
  - under each family, its shades in the picker's groups: light (ultra-light, light, semi-light), dark (semi-dark, dark, ultra-dark) and transparent (10 to 90);
  - then white and black with their transparent steps, the background and text colours (bg-ultra-light ... text-light-muted), and your own colour tokens.
- Each colour is its variable, for example var(--primary-light), so what you pick in the builder stays linked to TokenForge: change a colour in TokenForge and every element follows, dark mode included.
- Each palette colour carries exactly the value TokenForge gives that variable, so the copy the builder writes into its own stylesheet is the same and can never override TokenForge's.
- Families you switch off are left out. Other palettes are not touched.
- The switch under Options, Builder sync is now called "Colours to the builder colour palette (families with their shades)".

### Global SCSS

- A colour synced from Global SCSS into a builder class (for example color: var(--danger)) now shows as that palette colour in the builder's colour field. Colours synced earlier get the link on the next sync.

### Dashboard

- Search in a narrow TokenForge window (such as in the builder): the side menu closes while you type, so the search box now moves into the top bar and keeps the cursor. Before, only the first letter went in and you had to open the menu again. Escape clears the search and goes back to where you were.
- Opening or closing a panel keeps your place. Before, the whole screen jumped back to the top, so you had to scroll down again after every click. The side menu keeps its scroll position too.
- The Discard changes button only shows when there is something to discard (it was always visible).
- The search result line says "1 setting matches" instead of "1 setting match".

## 0.39.0

### Global SCSS and the builder classes stay in step

- The Global SCSS dock in the builder has a new Sync button. It switches the two-way sync with the builder's global classes on and off, and shows whether it is on. Switching it on syncs straight away. The status line under the dock title now also says "builder sync off" when it is off, so it is clear why nothing moves.
- New sites start with the sync on. Sites that already have it off keep it off until you press Sync (or switch it on under Global SCSS, Sync with builder classes).
- Everything you write in a class block now reaches the builder class of the same name:
  - what has a Style field (spacing, typography, colours, borders, layout and so on) fills that field, as before;
  - other plain CSS moves into the block's "builder custom CSS" part and shows in the class's CSS panel: custom properties, !important, properties without a field, and nested rules such as &::before, & .icon or &:focus-visible;
  - SCSS-only code stays where it is and stays SCSS-only: mixins, $variables, functions such as darken(), #{...}, breakpoint blocks, and &__part or &-modifier rules (those are other classes).
- The other way round: edits in the class's Style fields and in its CSS panel come back into the SCSS when you save in the builder. A class that only has CSS panel code and no block yet gets one (with "Add classes edited in the builder to Global SCSS" on).
- The first time a class syncs, CSS that was already in its CSS panel is brought into the SCSS block, so nothing is lost. Remove it there if the block should win.

### Fixes

- Synced values show in the open builder right away again. The current builder keeps its class list in a new place, so TokenForge could no longer update it and the Style panels kept showing the old values until a reload. The same fix brings back the BEM generator and the right-click class tools, which could not find the class list either.
- A TokenForge window that was left open no longer switches Global SCSS settings back when you save something else in it. Only the switches you changed in that window are saved.
- Saving Global SCSS from the dock while the Global SCSS module is off no longer stores its switches as off.
- Shop: the page numbers under the product list sit in one row again (the list itself was being styled as a button).
- Check CSS (for AI tools) no longer reports var(--white) or var(--black-trans-20) as a hard-coded colour. Plain white and black are still reported.
- Cheatsheet: the pad-space-*, mar-space-* and gap-space-* classes are now described correctly. They use the section spacing (var(--section-space-*)), not var(--space-*).

## 0.38.0

### Find where tokens are used

- New button on the Tokens screen: Find uses. It looks through every page, post and template (drafts too), the builder's global classes, theme styles, components, global elements and custom CSS, and counts each var(--name). It only reads. The result is kept, so the counts are there the next time you open the screen.
- Every row then says how often the variable is used, adding up TokenForge's own stylesheet and your site. Hover the count to see where, for example "Page: Home" or "Template: Header".
- New Unused filter: shows only the variables used nowhere. A bar above the list says what was checked. It also warns that theme files and other plugins are not checked. When your own tokens are among them, one button deletes them all (nothing is saved until you press Save).

### Discard changes

- A Discard changes button appears next to Save while there are unsaved changes. It asks first, then goes back to what was last saved.

### A shorter variable list in the builder

- Options, Builder sync has four new switches for what goes into the builder's variable list:
  - transparent steps (on);
  - colour channel values such as primary-hex, primary-rgb and primary-h (off);
  - dark mode values (-alt) (off);
  - the variables of buttons, cards, blockquotes and the shop (off).
- With these defaults the list goes from about 1,460 variables to about 800, or about 400 without the transparent steps. Colours, scheme colours, sizes, spacing, layout and your own tokens always stay. Variables left out still work in CSS, and the builder's colour palette is not affected.

### Forms: step conditions, a summary and saved answers

- A step can have its own condition: on the field that starts the step, fill in "Show this step only when field". When the condition doesn't hold, the step is skipped, its fields are neither sent nor required, and the progress leaves it out. The check runs in the browser and again on the server.
- New form setting: Summary before sending. It adds a last step that lists every answer, each with an Edit link back to its step, with the send button under it. It also works on a form without other steps.
- New form setting: Remember answers on this device. What a visitor types stays in their own browser until the form is sent. If they reload or come back later (within 30 days), their answers are filled in and they carry on at the step where they were, with a Start over link. Passwords, files and hidden fields are never kept. The saved answers are removed after a successful send. Anyone using the same browser can see them, so leave this off for forms with private details.
- The readme has a new question: "Does TokenForge keep anything about visitors?"

### Fixed

- The scheme colours (--bg-ultra-light, --bg-light, --bg-dark, --bg-ultra-dark, --text-dark, --text-dark-muted, --text-light, --text-light-muted) were only written while Color assignments was on. With it off, nothing defined them, yet buttons, cards, forms and the shop use them. This showed once Use settings had removed the old imported copies. They are now always written, even with the Colors section switched off. The Color assignments switch now only adds the .bg--* and .text--* classes, and it sits inside its panel, so the colours can be edited either way.

## 0.37.0

### Colours are made by Colors, not stored

- The colour families (every shade, hover, dark mode value, hex, RGB and HSL value and transparent step) are now made from the Colors settings each time the stylesheet is built, the same way Typography and Spacing make their variables. They are no longer kept as tokens, so an import or an older palette can't leave stale colour values behind any more.
- On the Tokens screen they show as rows made by Colors. The pencil button still lets you give one of them a value of its own.
- The update removes the stored colour copies that match what Colors makes. Nothing on the site changes. Copies with a different value stay, marked as left over, and Use settings removes them.
- Changing a family in Colors removes stored copies of that family, so the change always shows. Switching a family off leaves out all of its variables.
- The variables import (Import, export and reset) no longer keeps copies of what TokenForge's settings make. It keeps only the variables no setting makes, or makes differently, and tells you how many it left out.
- Files exported before 0.37 import the same way: the colour copies that match are left out.

### Warnings for switched-off tokens

- A token that is switched off but still used in the stylesheet gets a red note in its row, and a bar at the top of the Tokens screen lists them with a Switch them on button. (This is how a site ends up with square corners: --radius switched off while buttons and cards use it.)
- The box that switches a whole group asks first when it would switch off more than three tokens, or any that are still used, and names them.

### Your own categories

- A category you made gets two small buttons next to its tab: rename it, or delete it (its tokens move to other).

### Letting the settings decide

- Tokens that give the same result as their setting now count as repeats, even when they are written differently. For example, 1px next to var(--border-size) when that is 1px, 32px next to var(--icon-size-m), and clamp(0rem, ..., 0rem), which is just 0. Use settings removes them.
- A new reset arrow in the column head lets the settings decide for every token shown. Filter the list first to pick them, for example type grid-auto.
- Our decision on the imported values that replaced settings: the settings decide for all of them. They give the same result, apart from --width-full, which held calc(content width * 100%). That is not valid CSS; the setting makes 100%.

### Cleaner utility classes

- Colour classes are only made when a token really holds a colour, so .text-link-transition is gone.
- Font-size classes are only made for size names (text-s, h2, h2-to-h4 and so on), not for line heights such as .text-m-line-height.
- Padding and margin classes are no longer made from the page width (.pad-width).
- Line heights and transitions whose names start like a colour (base-text-lh, link-transition) are filed under typography and other, on new sites and by the update.

### Factory tokens

- A new site, and Reset to defaults, now start with 36 tokens: white and black with their transparent steps, and the few values no setting makes. Everything else comes from the settings.

## 0.36.0

### Tokens come from your settings

- The Tokens screen now lists every CSS variable TokenForge writes, not only the ones stored in the token list. Variables made by Typography, Spacing, Layout, Borders, Icons and Effects, and the ones other parts of the stylesheet set (focus colour, button radius and so on), appear in their group with a badge that says where they come from. Click the badge to go there.
- Each row says what it is:
  - your own token (edit, category, delete);
  - a token that replaces what a setting makes, with the setting's value shown under it;
  - a token that only repeats what a setting makes: it looks harmless, but it stops later changes to that setting from showing;
  - a token with no effect, because another part of the stylesheet sets the same variable later;
  - a colour token that no longer matches what Colors makes, with the Colors value shown under it.
- Variables a setting makes can be switched off from the list (that keeps the variable out of the stylesheet) or changed with the pencil button, which adds a token with the value ready to edit.
- The reset arrow on a row lets the setting decide again for that one variable.
- New button: Use settings. It removes tokens that only repeat a setting or have no effect, gives colour tokens what Colors makes and adds colour tokens that are missing. Tokens with a value of their own, switched-off tokens and your own tokens stay. It asks first and lists what it will do, and nothing is saved until you press Save.
- Values that differ only in rounding or in how they are written (0.141 and 0.142, or two ways of writing the same clamp) count as the same.

### Categories for your tokens

- + Add token opens a small form: name, value and category. Pick a built-in category or New category and type a name. Enter adds, Escape closes.
- It refuses a name that is already in the list, and a name another part of the stylesheet sets later (a token there would have no effect).
- A token's category can be changed from its row, including to a new category. Your categories get their own tab and are kept when you save.

### Factory tokens tidied

- A new site, and Reset to defaults, now start with 1,051 tokens instead of 1,386. The 178 tokens that only repeated a setting and the 157 that had no effect are gone, so later changes to those settings show at once.
- The colour families' transparent steps, hex, RGB and HSL values and dark-mode values now match the colour families. Before, some still held an older palette (for example a teal secondary-trans-50 next to a dark navy secondary).
- Utility classes made from the removed copies went with them. Nearly all of them were meaningless, like .h1-max-width setting a font size. The icon colour classes (.text-icon-color and so on) are now there.

### Builder, cheatsheet and framework lookup

- The builder's variable list, the Cheatsheet and the framework lookup for AI tools now list every variable the stylesheet sets, including the ones other parts make, so nothing disappears when copies are removed from the token list.

## 0.35.1

### Tokens screen

- The bar with the groups, the filter and Add token stays at the top while you scroll a long list, and the column heads (On, Variable, Value) stay right under it.
- A box in the On column head switches every token shown on or off at once: the whole group, or only the tokens that match the filter. It shows a dash when some are on and some are off. The list keeps its place.
- A short note at the top explains what tokens are: the variables at the top of the stylesheet. Colors writes the colour families here; text and space sizes, widths, radius, shadows and motion are made by their own settings, and a token here with the same name replaces what the setting makes.
- Switching a single token off greys its row at once.

### Dashboard in wp-admin

- The dashboard now fits the browser window: the header with Save stays in view, the section list stays beside it, and long sections scroll inside. (In the builder window this was already so.)
## 0.35.0

### Your setup is the factory setup

- A new site, and Reset to defaults, now give exactly the setup of the site TokenForge is made on: all 1,386 tokens, the colour families and every setting (colours, typography, spacing, buttons, forms, WooCommerce, which modules are on, and so on). The data ships with the plugin in two files: data/defaults.json (settings) and data/default-tokens.json (tokens).
- Why tokens went missing after a reset: resetting the tokens used to give only the starter colours (eleven colour families, white and black), so every other token disappeared. Now the whole set comes back.
- Each setting that is not in the files (a setting added in a later version) keeps its own built-in default.
- Left out on purpose, because they belong to one site: the Global SCSS code, your own recipes, the list of uploaded fonts and the link to the builder's theme style.
- The small "reset to default" arrows next to fields now point back to this setup.

### TokenForge window

- New switch: **Show in builder canvas while editing** (on by default). A TokenForge button sits in the corner of the builder's canvas while you edit and opens the TokenForge window. It takes the place of the button in the corner of the builder, which the builder's panels can cover.
- The two front-end switches have clearer names: "On the site's pages (administrators only)" and "Floating TokenForge button on the site's pages".
- The window on the site's pages loads its translations too.
## 0.34.0

### Lighter pages: shop styles only where the shop is

- The styles for shop pages (product lists, product pages, cart, checkout, account, filters) now live in a second small file. It loads only on pages that show the shop: WooCommerce pages, pages with shop elements or product loops from the builder, and pages with WooCommerce blocks or shortcodes. Every other page skips it, so the stylesheet there is about 35 KB smaller (on this site 256 KB instead of 290 KB, with the new form features included).
- What a header cart needs (line items, quantity box, totals, mini cart) stays in the main stylesheet, so a cart in the header looks right on every page.
- New setting under WooCommerce, Shop pages: Load shop page styles, "Only where the shop appears" (default) or "On every page". Developers can decide per page with the tokenforge_woo_shop_styles filter.
- Live updates after Save refresh this file too.

### WooCommerce

- **Header carts:** the builder's mini cart (count bubble, the panel that opens, its buttons) and the builder's dynamic fragments (the ready "Mini cart" and "Free shipping progress" structures) are styled: tidy rows, a small quantity box, the savings tag, the subtotal row, View cart and Checkout buttons, and a progress bar in your accent colour.
- **Product filters (new part, on by default):** the builder's filters (checkboxes, radios, buttons, search, select, range sliders, active filters), its products filter, WooCommerce's filter widgets and the Product Filters blocks all use your colours, with chips for chosen options and sliders in your accent colour.
- **Whole pictures in product lists (new setting):** WooCommerce crops list pictures to its thumbnail shape, which can cut off part of a product. Choose "Whole pictures" under Product grid and lists use WooCommerce's larger uncropped size instead.
- **Cart and Checkout blocks, tested on real pages:** Proceed to checkout fills the totals panel again, the stray white box behind it is gone, the order summary is one panel instead of two, the chosen payment option gets one frame, the quantity count on pictures is readable again (dark with light text, as WooCommerce 11 draws it), savings tags can wrap on phones instead of pushing the page wider, and the Browse store button of an empty cart or checkout is a main button.
- **Block themes:** the product price, sale badge and rating blocks can take their look from the theme's global styles, as WooCommerce recommends. TokenForge hands its values over, so they show in the Site Editor under Styles and you can change them there. Switch: Blocks, "Block themes: prices, sale badges and ratings through global styles".
- The quantity box no longer cuts off its plus button in a narrow cart row, and the columns of the builder's cart can shrink on small screens.

### Forms (the builder's form)

- **Steps:** tick "New step starts here" on a field and the form shows one part at a time, with Back, Next and a progress display (numbered steps with titles, a bar, or "Step 2 of 3"). Next checks the fields of the step first, Enter moves to the next step, and after sending the form starts again at step 1. The Next button looks like your submit button.
- **Conditional fields:** "Show only when" shows a field only when another field has a certain answer (is, is not, contains, is empty, is not empty, more than, less than). Hidden fields are not sent and are never required, also when the form is checked on the server.
- **Ready layouts:** four classes for the form element: form--columns (two columns), form--inline (one row with the button, for a newsletter), form--compact (smaller) and form--card (a padded panel).
- Both can be switched off under Forms.

### Fixes

- Containers are never wider than the space around them. Before, a container inside a row (like a header) kept the full content width, so on screens narrower than that width the page scrolled sideways. If your header does not fit, its items now wrap instead.
- Range sliders, colour pickers and hidden fields are no longer styled like text fields, and radio buttons and checkboxes in the builder's form no longer stretch across the row.
- Labels the builder hides on purpose (the labels of range sliders) stay hidden.

### Translations

- The whole dashboard (every label, description, tooltip and message) and the new form settings can now be translated. Translations from translate.wordpress.org load automatically.

### WordPress.org

- The readme has a Frequently Asked Questions section and screenshot captions.
- New plugin page images: icon, banner and six screenshots (kept outside the plugin, for the plugin directory).

## 0.33.0

### WooCommerce: every shop page, all of it controlled by TokenForge

- **Win over element settings (new, on by default).** The shop setup in the builder puts many styles on single elements (sizes, colours, spacing). TokenForge's shop styles now beat those, so every shop page follows your design. Turn it off in WooCommerce if you want your own element settings to win again.
- **Even spacing between sections** on product, shop, cart, checkout and account pages, with a new setting for the space. Sections with nothing to show (an empty notice area, for example) no longer leave a big gap.
- **Product cards:** every picture gets the same shape and shows the whole product, with a light background behind it. The New badge sits on the left of the picture and the sale badge on the right (one under the other on phones). A short text placed straight in a card, like the "On sale" label from the shop setup, becomes the sale badge. Quantity and Add to cart share a row, and the button moves under the quantity box when there is no room. New settings: image shape, image background, New badge colour.
- **Product page:** the quantity box and the Add to cart button are the same height and line up. The View cart link that appears after adding to the cart is now a button. Cleaner price, stock line with a dot, swatches, gallery thumbnails, attribute table, reviews and review form.
- **Quantity box:** one design for every layout the builder offers. The minus and plus are drawn, so they sit exactly in the middle.
- **Cart and checkout lines:** thumbnail, name, price and quantity sit tidily with less space between them, and the "Save 24,00" note is a small green tag (colours can be changed). The order summary in the checkout steps is compact.
- **Fields:** checkout, address and account forms put first and last name side by side and every other field on its own line, with even space between fields (a new setting). This also works for the fields the builder creates for the account pages. The password change box on the account page is a clean panel.
- **Buttons:** the builder's buttons inside the cart, checkout and account pages use your shop buttons. Save address, Save changes, Log in and Register are main buttons. On phones the checkout step buttons wrap instead of running off the screen.
- **Also styled now:** the classic WooCommerce cart table and checkout layout, notices that carry a button, sorting and result count, breadcrumbs, pagination, the mini cart, and more of the Cart and Checkout blocks.
- Anything WooCommerce or the builder hides stays hidden.
- Switching off a part (badges, ratings, notices, tables, blocks) now also removes its styles from the shop pages.

### Form classes

- Put `.form--light` or `.form--dark` on the builder's form (or on something around it) and the whole form follows: field colours, even spacing, calm labels, messages in the status colours, and the submit button in your button style. Choose the button's colour family and style under Forms.

### Theme style

- More of the theme style now follows TokenForge: every button style (with outline and hover colours), the button sizes, padding, corners and border, the form fields and the submit button, and the WooCommerce button and notices.
- Changes you make there in the builder come back into TokenForge where it has a matching setting (a button colour becomes that button's colour override, field border, radius and padding, button padding and corners).
- The first time these fields are linked, TokenForge takes over what your site already shows.
- Heading margins and the builder's contextual spacing are left empty on purpose: set there, they would override TokenForge's spacing in rich text.

### Class sync (Global SCSS and builder classes)

- More properties go into the builder's class fields: box shadow, text shadow, filters (blur, brightness, contrast, hue, invert, opacity, saturate, sepia) and transforms (move, scale, rotate, skew, perspective). What the builder cannot hold stays in SCSS only.
- A class's Custom CSS in the builder now appears in its SCSS block between two comment lines, and edits on either side carry over.
- Renaming a class in the builder renames its SCSS block.
- Sites that build mobile first are covered.

### Fonts

- Your font families now also appear in the block editor's font picker (can be switched off).
- Font family fields in TokenForge suggest your fonts and common font stacks as you type.
- **Download from Google Fonts:** type a family name, the weights and whether you want italic, and TokenForge copies the files to your site once. Each character set (Latin, Cyrillic, Greek and so on) is a separate small file that browsers only load when a page needs it. Visitors never contact Google.
- Fonts have a new optional "Characters" field (unicode-range).

### Colours

- Dark mode fine tuning per colour family: the lightness and colourfulness of the main colour and of its hover in dark mode. The hue stays.
- The family strips now show a live preview while you edit: light shades above, dark mode below, before you save.
- Optional variables for the dark mode value of every colour (`--primary-alt`, `--primary-light-alt` and so on).
- The importer brings in these dark mode settings too.

### Dashboard and builder

- A Getting started panel on Home (you can hide it).
- "Learn more" links on many panels, to the official references.
- Live previews for overlays, surfaces and the button colours per family.
- The builder's recipe suggestions show the code of the highlighted recipe.
- The Global SCSS dock can sit at the bottom, on the right or on the left of the canvas.
- A new dock button jumps to the SCSS of the class selected in the builder (or starts a block for it).

### Plugin review fixes

- Font uploads now go through WordPress's own upload handling.
- The uninstall routine uses WordPress's file functions.
- Removed a setting change the plugin review flags, and the translation loading WordPress now does by itself.
- Stylesheets get a version number, so browsers pick up changes.

## 0.32.0

### WooCommerce shop pages, designed

This release gives every shop page and account page a proper design, built from your tokens. It was worked out on real pages made with the builder's WooCommerce setup (cart, checkout steps, account pages, product and product list templates), and it also covers WooCommerce's own pages.

- **Layout fix for the builder's shop elements.** A site-wide Div setting (flex, column) stacked the cart rows, totals and headers on top of each other. Inside the cart, checkout and account elements, divs now behave the way the builder expects, so rows are rows again. Element settings you make in the builder still win.
- **Cart:** tidy product rows with rounded images, bold names, muted details and the sale price in your sale colour. Totals sit in a panel with the labels on the left and the amounts on the right, a larger total and a full-width checkout button. The coupon field and its button share a row.
- **Quantity:** one clean stepper (minus, number, plus) for every kind the builder and WooCommerce use.
- **Checkout:** the step navigation is a row of pills with numbers, where the current step uses your primary colour and finished steps are marked. Step headings get a number badge. The Back and Next buttons sit under a thin line, with Next as the main button. Address fields are laid out in two columns (first and last name side by side) and drop to one on phones. Floating labels work again: TokenForge's field style no longer squeezes them. Payment choices are cards, and the chosen one is highlighted.
- **Place order** uses your main button colour, even where the checkout setup had set it to dark.
- **Account:** the navigation looks like tabs, with Log out at the end. Addresses are cards with the title and the edit button on one line. Login, register and password forms sit in panels.
- **Product page:** larger price, variations laid out as label and field, quantity and Add to cart on one row, cleaner meta, underline tabs, and reviews as cards with the avatar beside them.
- **Product cards** (shop lists, related products, the builder's product loops): square image frames, the badges placed on the image, clear names and prices, and the button at the bottom of each card. You can choose whether images show whole or fill the frame.
- **Checkboxes and radios** use your primary colour, through the builder's own indicator settings.
- **Order received:** the order summary is laid out as a row of figures.
- New settings in WooCommerce, Shop pages: panel background, line colour and panel radius. There is also a switch to turn the whole shop page design off.

### Fixes

- The checkout wizard's step buttons and the builder's quantity fields now follow TokenForge buttons and fields instead of fighting them.

## 0.31.0

### WooCommerce

- When WooCommerce is active, TokenForge now styles your shop with your tokens: buttons, notices, fields, prices, sale badges, review stars, product grids, cart and order tables, product tabs and pagination.
- This covers the classic shop pages, the builder's shop elements and the WooCommerce blocks (Cart, Checkout, Mini-Cart and the product blocks).
- It's on by default. It has its own WooCommerce section under Components and a module switch on Home. Every part can be switched off, and colours, corners, gaps and columns can be changed.
- Main actions (Add to cart on a product page, Proceed to checkout, Place order) and the other shop buttons use TokenForge buttons, each with its own colour family and style. By default the main actions are solid and the others are outlined.
- Product lists become a grid with the TokenForge gap. They keep the number of columns set in WooCommerce and drop to 2, then 1 column on small screens. Products can use the card look from Cards if you like.
- WooCommerce's own colour variables follow your tokens too.
- Inside the blocks only colours and corners change, so their layout stays the way WooCommerce made it (the floating labels in the checkout, for example). TokenForge's general field style no longer reaches those block fields.
- You can also stop WooCommerce from loading its own stylesheets.
- The section only shows while WooCommerce is active, and the stylesheet rebuilds itself when WooCommerce is switched on or off.

### Wording

- The dashboard, tooltips and messages no longer name the builder where it isn't needed. "Set html font size" is now just that, and builder options talk about "the builder".

### Fixes

- Block editor: the post title and heading blocks line up with the rest of the content again. TokenForge's heading width setting stretched them to the full width of the editor, and the boxed page layout reached the editor canvas too. The front end is unchanged.
- Theme style sync: when TokenForge has no value for a font or a weight (after Reset to defaults, for example), the theme style now keeps its own instead of losing it.

## 0.30.0

### A new home for the dashboard

- TokenForge now opens on **Home**: a list of every section, each with a short line about what is in it. Click a row to open that section.
- The menu has a new order. **Start** has Home, Cheatsheet, Global SCSS and Recipes. **Setup** has Options, Builder and the new Import, export and reset section. Foundations, Components and Content and Effects follow.
- Panels start closed. The ones you open stay open the next time you come back (this is remembered per browser).
- Every section has **Open all**, **Close all** and **Reset section** buttons at the top.
- Add buttons now sit at the top of their list, so you don't have to scroll to the end: Add token, Add font, Add a family, Add overlay and Add surface. The new item shows up first and gets the cursor.
- Panel headers work with the keyboard too (Enter or Space).

### Switch whole sections off

- 16 sections are now modules with an on and off switch: Colors, Typography, Spacing, Layout, Borders and Shadows, Buttons, Cards, Icons, Forms, Content, Overlays, Effects, Base and Accessibility, Global SCSS, Recipes and Builder.
- Switch them on Home or at the top of the section. When a module is off, TokenForge adds nothing from it to your site, and your settings in it are kept for when you turn it back on.
- With Builder off, Bricks keeps its own grid overlay as it normally is.
- With Global SCSS off, the SCSS dock under the Bricks canvas is not loaded.

### Starts ready on a new site

- A fresh install comes with a starter design system, so you don't need to import anything first: 11 colour families (primary, secondary, tertiary, accent, base, neutral, action, success, danger, warning, info) with all their shades, white and black with transparent steps, and every setting at its default.
- On a Bricks site, TokenForge sets itself up on the first load after activation (see Bricks theme style below).

### Reset to defaults

- Import, export and reset has a **Reset to defaults** panel. Pick what to reset: all settings, tokens and colour families, Global SCSS code, your own recipes, or the font list. Uploaded font files stay where they are.
- Each section can be reset on its own with Reset section, and each field still has its own reset button.

### Export and import

- **Export** downloads one file with every setting, token, colour family, module switch, font, recipe and your Global SCSS.
- **Import** reads that file on another site. You choose which parts to bring in, and the rest stays as it is.

### Bricks theme style

- TokenForge now keeps a Bricks theme style in step with its settings. On a new site it creates one named after the site and applies it to the entire website. On a site that already has one theme style for the entire website, it uses that one and takes over its fonts and colours first, so the site keeps its look.
- The theme style fields point at TokenForge variables: html font size `var(--root-font-size)`, container width `var(--content-width)`, body size `var(--text-m)`, H1 to H6 sizes `var(--h1)` to `var(--h6)`, text and heading colours, link colours, site background, focus outline, section padding and the six Bricks colours. Fonts, weights, line height, letter spacing and text transform are copied over as plain values, because Bricks needs them that way.
- It works both ways. Change a field in the theme style, and the matching TokenForge setting changes with it. A colour picked as a plain hex becomes the new base colour of that family.
- A new theme style also gets sensible element defaults: headings as H2, text as a paragraph, no image captions, and the Tomorrow Night code theme.
- You can switch this off, or link the theme style again, in Builder, Theme style.

### Fonts

- TTF and OTF files are turned into WOFF2 when you add them, or into WOFF when the server can't make WOFF2 (WOFF2 needs the Brotli extension in PHP). This works for fonts uploaded in TokenForge and for fonts added in the Bricks font manager.
- The converter is written from the WOFF and WOFF2 specs and was checked in Chrome: the converted fonts render the same as the original file.

### A new look

- The dashboard now matches the Bricks builder panels by default: the same dark colours, 4px corners, small uppercase group titles and the yellow accent.
- Prefer something else? Options, Dashboard look has a **Flat** look: plain graphite, square corners and one blue accent.
- No more glows or big shadows. Colours are flat, and the builder window uses the same look.

### Also new

- A **Changelog** link next to TokenForge on the Plugins screen opens this file.
- Tooltips for the new switches and options.

### Fixes

- Resetting or importing and then rebuilding the stylesheet in the same step now always uses the new module switches.
- Button font and icon hover colours have proper defaults, so a fresh site has no empty variables.
- The bundled seed file is gone. The Re-import seed button is replaced by Reset to defaults.

## 0.29.1

- Recipes fit where the cursor is. Inside a value (like `color: ?primary-clr`) you only get value recipes, and they end with a semicolon. On a line of their own, rule and declaration recipes come first, and a value recipe turns into a full declaration with the property selected, so you can type another one.
- Placeholder text and Bricks element snippets are no longer offered in Global SCSS or the Bricks CSS editor. Every recipe compiles in both places, and the same goes for recipes clicked in the dock's Cheatsheet.
- Descriptions, tooltips, messages and the readme now describe TokenForge on its own, without comparing it to other frameworks.

## 0.29.0

- **Global SCSS dock in the Bricks builder.** The SCSS editor sits under the canvas like Bricks' own CSS panel, and you don't need to open the TokenForge window for it. The canvas previews your changes as you type, and nothing is saved until you press Save or Ctrl or Cmd + S. It has a Cheatsheet drawer (search classes, variables, mixins and recipes, click to insert or copy). You can resize, minimise and maximise it, and it remembers how you left it. Open it with Alt+Shift+S, also while the canvas has focus, or with the SCSS tab under the canvas. Keys you type in the dock never trigger Bricks shortcuts. If the SCSS changed somewhere else while you had unsaved edits, the dock asks before it overwrites anything.
- **Friendlier SCSS editor** (dashboard and dock). Brackets and quotes close themselves, typing a closing bracket steps over it, Backspace removes an empty pair, and Enter opens a `{}` block onto its own lines. Accepting a value with Tab or Enter finishes the line with a semicolon, and so does Tab at the end of an unfinished line. Mixins that take a block insert an empty one with the cursor inside.
- **Self-hosted fonts** (Typography). Upload .woff2, .woff, .ttf or .otf files, or use a URL. Set the weight or weight range, style, font-display, variable font settings and preload. They are written as @font-face rules in the stylesheet and show up in the Bricks font picker. The settings importer brings custom fonts along.

## 0.28.0

- **Global SCSS and Bricks classes sync both ways** (off by default, in Global SCSS). A class block in Global SCSS fills in the Style fields of the Bricks global class with the same name when you save: spacing, borders, radius, typography, background colour, size, flex and grid, position and more, including hover and breakpoint blocks. When you save in the builder, the fields you changed are written back into the block, and the rest of your SCSS stays as it is. Mixin output, nested selectors and !important stay in SCSS only. If the SCSS changed in the builder while the dashboard was open, your dashboard edit is kept aside for you to copy.
- **New colour engine.** Families are made in OKLCH. Each shade keeps the family's hue and chroma and only loses chroma where it would leave the Display P3 range. Dark mode swaps the shades with light-dark().
- **Colour families.** Every family has its base colour, an optional dark mode colour and an on and off switch. The new Shade generation panel controls lightness and the transparent steps. You can add and remove your own families.
- **Dashboard grouped** into Start, Foundations, Components, Content and Effects, Code, and Builder and Site. Related settings now sit together, and the old Additional Styling section is gone.

## 0.27.1

- Autocomplete in Global SCSS everywhere (builder, front-end window and wp-admin): CSS properties, values that fit the property (including the matching TokenForge variables), variables, your classes and TokenForge classes, mixins, at-rules, SCSS variables and recipes. Use the arrow keys, Enter or Tab to insert, Esc to close, and Ctrl+Space to open it.

## 0.27.0

- **Tooltips everywhere.** Every option in the dashboard has a "?" that explains it in plain words (590 of them). Search also finds settings by their explanation.
- **Cheatsheet** (new section): every class, variable, mixin, recipe and data attribute TokenForge gives you, grouped, with what it does and the CSS it applies. Click to copy.
- **AI agents.** When an AI builds the site through the WordPress MCP Adapter or Bricks AI, TokenForge offers read-only tools (framework overview, search, a CSS checker that suggests the right variable, recipes, SCSS compile) and tells the agent to build with TokenForge classes and variables. You can switch both off in Options, AI agents.
- No em dashes anywhere in the plugin's text.

## 0.26.0

- **Global SCSS.** Write your own SCSS in the dashboard. It compiles when you save and is added last to the stylesheet, so it wins. A compile error never breaks the site: the last working CSS stays live, and the error shows with its line.
- **Mixins and functions** you can use in Global SCSS without an import, such as btn(), card(), container(), auto-grid(), breakpoint(), fluid() and to-rem(). They use your own settings.
- **Breakpoints** from xs to xxl for the mixins and recipes.
- **Recipes.** Type ?name in the Bricks CSS editor or in Global SCSS and it expands into ready-made CSS. 151 recipes come built in, and you can add your own.
- **Corner ribbons** with the .ribbon class.
- **TokenForge window shortcut** (Alt+Shift+T by default). The window now also opens on the front end for administrators.
- Bundles scssphp 1.12.1 (MIT), namespaced for TokenForge.

## 0.25.0

- Checked every feature against real pages at desktop and mobile widths, with all computed values matching.
- Fix: an imported grid overlay setting outlined every container in the canvas. It now only shows or hides Bricks' own grid overlay, and the container outlines are a separate debug option.
- Fix: dark mode flipped twice with palettes that already used light-dark(). New default scheme setting (light, dark or follow the device).
- Layout: content grid, boxed layout, section spacing steps, header sizes and gap utilities.
- Scales: in-between variables (like --text-l-to-m), grids up to 12 and auto grids.
- Typography: body and heading defaults from variables, per-heading overrides and text size classes.
- Buttons: a full button system with per-family colour overrides, focus rings and buttons that follow their background.
- Links, cards, icons, borders and dividers, inverted radius, flow spacing and colour relationships per background were all extended.
- Finer cascade layers, and the importer maps all of the above.
- New dashboard with sections, panels you can fold, defaults shown as placeholders with a reset button, colour swatches, search across every setting and Ctrl or Cmd + S to save.

## 0.24.0

- **Motion:** transition variables and easing presets.
- **Scroll effects:** fade, float, sink, slide, grow, shrink and blur on entering or leaving the screen, with stagger and parallax. Pure CSS, with a fallback for older browsers.
- **Visible and hover effects**, including border effects.
- Reduced motion is respected everywhere, and effects stay still in the editors.
- **Overlays and surfaces:** a basic overlay, up to 10 custom overlays and up to 10 textured surfaces.
- Site-wide blockquote styling, and an icon for links that open in a new tab.
- The importer maps all of the above.
- For developers: the tokenforge_css_dir filter picks the stylesheet folder.

## 0.23.0

- The importer brings all settings, not only variables, with a report of what it did. It even works after the source plugin is removed.
- New: heading scale, section spacing, automatic gaps, body and heading typography, link colours, button classes, card settings, focus and selection colours, shadows, header height, forced dark or light areas, smart spacing and the block editor colour palette.
- Buttons no longer restyle every Bricks button by default.

## 0.22.0

- The root font size can be any percentage from 50 to 200, with a live readout of what 1rem is in pixels.
- New Light/Dark Toggle block for the block editor.
- Checked on a site with only the block editor (no Bricks).

## 0.21.0

- Fix: widths and sizes were off with Bricks' 62.5% root size. New Root font size setting, and every px to rem conversion uses it.
- Smarter right-click menus: variables grouped by what they hold, the fitting group picked for you, recently used first.
- Autocomplete for variables and classes in the Bricks CSS editor.
- New Light/Dark Toggle element for Bricks.

## 0.20.0

- CSS hot reload: after you save, every open tab (front end, Bricks canvas, block editor) updates its TokenForge CSS in place.
- Remote Bricks templates keep their own classes when one of your classes has the same name.

## 0.19.0

- Content width system: --content-width, a safe width with gutters, a width scale and width classes.
- Changing the content width also updates a stored content-width token.
- Switches for Bricks' built-in preview and keyboard options.

## 0.18.0

- The builder window can dock left, dock right or float. Drag it, snap it to an edge, resize it and minimise it. It remembers its place.
- Right-click menus in Bricks: insert a variable into any field, or add utility classes to an element.
- Fix: tokens such as space-s and text-l were filed under colours. They are repaired on update.

## 0.17.0

- Classes and variables you switch off are removed from Bricks too. Your own classes are never touched.
- Optional cascade layers, so theme or builder CSS can override TokenForge.
- Option to put the stylesheet inline.
- Fix: loading the stylesheet in the Bricks canvas works again.

## 0.16.0

- Content and prose styling, a light and dark switcher shortcode, an admin bar link and a clean uninstall.

## 0.15.0

- A dark dashboard with a side menu, used both in wp-admin and inside Bricks.

## 0.14.0

- Header height variable, tied to sticky elements and scrolling to anchors.
- Builder options: Pro Mode and overflow detection.

## 0.13.0

- A full grid system: grid-1 to grid-12, ratio grids, auto grids and a content grid.
- Defaults and utilities: reset, smooth scrolling, reduced motion, focus styles, skip link, sticky and screen reader classes.

## 0.12.0

- One-click settings importer that brings in variables from a generated file.

## 0.11.0

- Builder extras (off until you turn them on): stacked Add Elements panel, container outlines, a grid and flex visualiser and a taller code editor.

## 0.10.1

- Fix: the stylesheet no longer styles page builders' own editor screens.
- In the block editor the styles now apply to the content only.

## 0.10.0

- Radius scale, borders and dividers, a shadow scale and an icon system.

## 0.9.0

- The stylesheet loads on any WordPress site, front end and block editor. Bricks features only run when Bricks is active.

## 0.8.0

- Dark mode with light-dark(), background classes that also set readable text colours, and text colour classes.

## 0.7.0

- Fluid gutters, and a boxed or full-width layout.

## 0.6.0

- Base styles for headings, buttons, forms, cards and links, on plain HTML and Bricks elements.
- The stylesheet rebuilds itself when TokenForge is updated.

## 0.5.0

- Fluid type and spacing scales made with clamp(), and grid variables and classes.

## 0.4.1

- Fix: the dashboard was blank in 0.4.0.

## 0.4.0

- The full dashboard inside the Bricks builder.
- Colour picker and one-click colour roles, each turned into a full set of shades.

## 0.3.1

- The BEM button sits in each Structure row, next to Bricks' own icons.

## 0.3.0

- BEM generator: builds class names for an element and everything inside it, and creates them as Bricks global classes.

## 0.2.0

- Colour engine with shades, utility classes made from tokens, a tabbed dashboard and a variable picker in the builder.

## 0.1.0

- First version: edit tokens, generate the CSS, sync with Bricks, BEM generator.
