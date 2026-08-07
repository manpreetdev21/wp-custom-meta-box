# WP Custom Meta Box

Field groups, meta boxes and a developer-friendly field API for WordPress.

- **Author:** Manpreet Singh
- **Requires PHP:** 8.1
- **Requires WordPress:** 6.8
- **License:** GPL-2.0-or-later
- **Text Domain:** `wp-custom-meta-box`
- **Namespace:** `WPCMB`


## Build status

| Phase | Scope | Status |
|-------|-------------------|--------|
| 1 | Core architecture | ✅ Done |
| 2 | Admin UI | ✅ Done |
| 3 | Database | ✅ Done |
| 4 | Field API | ✅ Done |
| 5 | Field types | ✅ Done |
| 6 | Repeater | ✅ Done |
| 7 | Flexible content | ✅ Done |
| 8 | Frontend forms | ✅ Done |
| 9 | REST API | ✅ Done |
| 10 | Blocks | ✅ Done |
| 11 | Integrations | ✅ Done |
| 12 | Testing | ✅ Done |

## Architecture

Four moving parts, nothing more:

```
wp-custom-meta-box.php   Bootstrap. The only procedural file.
  └─ Container          Lazy service container, shared instances, auto-wiring.
  └─ Plugin             Singleton. Owns the container, collects and boots modules.
      └─ Module         Abstract base. One per feature. boot() registers hooks.
          └─ Bootable   The contract every module implements.
  └─ Installer          Activation / deactivation. Static — runs outside the lifecycle.
```

**Request flow**

1. WordPress loads `wp-custom-meta-box.php`, which defines constants and registers an autoloader (Composer's if `vendor/` exists, a PSR-4 fallback otherwise).
2. `wpcmb()->init()` hooks `Plugin::boot()` to `plugins_loaded` at priority 5 — early enough that other plugins can act on `wpcmb/booted`.
3. `boot()` runs the module list through the `wpcmb/modules` filter, resolves each class from the container, skips any whose `is_enabled()` returns false, and calls `boot()` on the rest.
4. `wpcmb/booted` fires. Add-ons build on top from here.

**Why a container.** Modules never construct their collaborators, they pull them from the container, so a field type in phase 5 can be swapped in tests or replaced by a site without editing plugin code. Auto-wiring keeps registration to zero for the common case: any `Module` subclass is constructed with the container automatically.

**Why one singleton.** `Plugin` only. WordPress gives one plugin lifecycle per request and hook callbacks need a stable target. Everything else is a plain object with an explicit dependency.

**Why `is_enabled()`.** Admin-only modules should not cost anything on front-end requests. The gate runs before `boot()`, so a skipped module registers no hooks and loads no assets.

## Installation (development)

```bash
composer install          # dev tooling + autoloader
composer dump-autoload -o # autoloader only
```

The plugin runs without Composer via the fallback autoloader in the bootstrap.

## Where field groups live

Field groups are a private post type (`wpcmb_field_group`), not a custom table. WordPress already supplies the list table, search, pagination, sorting, revisions, autosave, trash, capabilities and WXR export for a post type; a bespoke schema would reimplement all of that and add a migration surface for no gain at field-group scale.

The whole group configuration — fields, location rules, display settings — is one JSON value in `_wpcmb_config` post meta. One meta value means one read, and it is registered through `wp_post_revision_meta_keys` so revisions cover the group as a unit.

Two derived rules follow from this:

- **Active = published.** A draft group is inactive. There is no second "active" flag to fall out of sync with the post status.
- **Every capability maps to `manage_options`** (filter: `wpcmb/capability`). No role is modified, so nothing needs granting on activation or cleaning up on uninstall.

## Where field values live

Also in native storage: post, term, user and comment meta, or prefixed options for options pages and widgets. There is no value table. A value is therefore already visible to `WP_Query`'s `meta_query`, the core REST meta endpoints, WP-CLI, migration tools and a plain database export — with or without this plugin installed.

A value is stored under the field's own name as **one row**. Structured values (repeater rows, groups, flexible content) are stored whole rather than flattened into a row per sub value: one row means one read for a repeater of any size, and deleting it cannot orphan sub values. The trade-off is that sub values are not individually queryable by `meta_query`; if that is ever needed, mirror those sub fields into flat companion keys rather than flattening canonical storage.

Two details that are easy to get wrong and are covered by `tests/values-check.php`:

- **Slashing.** `update_metadata()` runs `wp_unslash()` on whatever it is given, so `Values` slashes on the way in. Written raw, a value like `C:\Users\test` loses its backslashes. Options are not unslashed and so are written as-is.
- **Unstored vs. empty.** Reads distinguish "never saved" from "saved as an empty string", so a field's default applies only until it is first saved — not every time it is cleared.

Field names resolve to field definitions through an index over the cached groups, so no companion `_key` meta row is written and values stay readable when a group's location no longer matches.

### Object references

Every entry point accepts loose identifiers and resolves them once through `ObjectRef`:

| Identifier | Resolves to |
|---|---|
| *(nothing)* | the current post |
| `42`, `'42'`, `'post_42'`, `WP_Post` | post 42 |
| `'term_5'`, `WP_Term` | term 5 |
| `'user_2'`, `WP_User` | user 2 |
| `'comment_9'`, `WP_Comment` | comment 9 |
| `'options'`, `'options_theme_settings'` | an options page |

Anything unrecognised resolves to an invalid reference that reads and writes nothing, rather than silently addressing post 0. The `wpcmb/object_ref` filter lets a custom location claim its own scheme.

## Template functions

```php
wpcmb_get_field( 'byline' );                    // current post
wpcmb_get_field( 'byline', $post_id );
wpcmb_get_field( 'colour', 'term_5' );
wpcmb_get_field( 'api_key', 'options' );
wpcmb_the_field( 'byline' );                    // echoed, escaped
wpcmb_get_fields( $post_id );                   // every applicable value
wpcmb_has_field( 'byline', $post_id );          // stored? ignores defaults
wpcmb_update_field( 'byline', 'Manpreet', $post_id );
wpcmb_delete_field( 'byline', $post_id );
wpcmb_get_field_object( 'byline' );             // the definition
wpcmb_get_field_groups( $post_id );             // groups on this object
wpcmb_validate_values( $values, $post_id );     // errors keyed by field name
```

These are the only global functions the plugin defines — thin wrappers over container services, so themes get a short procedural API and the implementation stays swappable.

### Registering groups in code

```php
add_action( 'wpcmb/register_field_groups', function () {
	wpcmb_register_field_group( array(
		'key'      => 'group_page_details',
		'title'    => 'Page Details',
		'fields'   => array(
			array( 'name' => 'byline', 'label' => 'Byline', 'type' => 'text', 'default' => 'Staff' ),
		),
		'location' => array(
			array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ),
		),
	) );
} );
```

Registered groups are never written to the database, so they can live in version control. A **stored** group with the same key wins — which is what makes "export to PHP, keep editing in the admin" work. The hook fires lazily on the first group read, not on `init`, so a field read before `init` still sees them and a request that reads nothing pays nothing.

## Location rules

Rules are an OR list of AND groups: the group shows when every rule in at least one rule group matches. Three deliberate behaviours:

- **A group with no rules matches nothing.** An unconfigured group stays out of the way instead of appearing site-wide.
- **List-valued parameters mean "is one of".** A user's roles, the taxonomies a post has terms in. `!=` then means "is none of".
- **An inapplicable parameter never matches, negated or not.** Otherwise "post type is not page" would start matching users, terms and comments.

`Context` computes parameter values on demand and memoises them. A typical rule set tests one or two parameters, and several of the others cost a term or template query — building a full description of the screen up front would run those queries to answer questions nobody asked.

## Field types

**50 types, 9 classes.** Types are grouped by behaviour, not by name. Thirty single-line inputs differ by an HTML `type` attribute and a sanitizer — thirty classes would be thirty places to fix the same bug.

| Class | Types |
|---|---|
| `Input` | text, number, email, url, password, hidden, range, color, date, time, datetime, phone, slug, uuid, currency |
| `Textarea` | textarea |
| `Choice` | select, checkbox, radio, toggle, button_group, rating, country, state |
| `Media` | file, image, gallery, video, audio |
| `Relationship` | post_object, page_link, relationship, taxonomy, user |
| `Editor` | wysiwyg, code, json, html |
| `Link` | link |
| `Enhanced` | icon, map, signature, qr, barcode, embed, address |
| `Structure` | message, tab, accordion, group |

Add one with `wpcmb/register_field_types`; a custom type gets the wrapper, conditional logic, validation display, layout and a settings UI for free, because field types own only their control.

### What is deliberately not bundled

Date, time, colour and number pickers are the browser's. Rich text is `wp_editor()`; code editing is `wp_enqueue_code_editor()`. Media is the WordPress media library, which already does upload, drag-drop, multi-select, alt text and image editing — a second one would not share the user's uploads. Country and state lists are short stubs that defer to WooCommerce or to `wpcmb/field/choices`, rather than carrying a second ISO table. Map tiles, QR and barcode encoders need a provider, so those fields render their stored text until a site supplies one through `wpcmb/field/enhanced_config` — better than a key-less map that silently shows nothing.

### Rules that hold across every type

- **Only offered values are stored.** Choice and relationship fields intersect the submission with the options actually rendered, so a hand-built request cannot store a value the form never contained.
- **Sanitizers are idempotent.** A value is cleaned on the edit screen to validate it and again in the value pipeline on write. Non-idempotent sanitizers would progressively strip stored values.
- **Validation runs before sanitizing.** A sanitizer discards what it cannot make safe; validating afterwards would see a malformed email as an empty optional field and report nothing, so the user would lose the value with no explanation.
- **Structural fields store nothing.** Tabs, accordions and messages never create an empty meta row and are never validated.
- **Only native controls' formats are accepted.** A date field stores an ISO date or nothing — never free text from a crafted request. A signature stores a PNG data URI or nothing, so it cannot smuggle a scriptable SVG into a `src`.
- **Escaping differs by type, on purpose.** `wysiwyg` is `wp_kses_post()`; `html` is verbatim only for users with `unfiltered_html`; `code` and `json` are stored raw because this plugin never renders them as markup.

## Repeater

Rows are one indexed array under the repeater's own name — the same whole-value storage everything structured uses. A repeater of any size is **one meta row and one read**.

**Nesting needs no code.** Sub fields render through the shared renderer, which owns input naming, so `repeater → repeater → group` produces correctly nested names without any type knowing how deep it is. Sanitizing recurses the same way.

**New rows come from the server, not from a cloned template.** A cloned `wp_editor()` is a dead editor: the markup is only half of what that control is. The `wpcmb_repeater_row` endpoint renders a fresh row exactly like the ones already on the page. Duplicating an existing row is the one case that clones, and it re-runs field initialisation afterwards.

**Row order survives gaps.** The browser posts rows keyed by their on-screen index, which has gaps once rows are removed. Keys are sorted numerically then discarded — a string sort would put row 100 before row 12.

**Every row has every declared sub field**, stored as null when absent, so a template can index into a row without checking each key first. Undeclared columns are dropped.

**Reordering is keyboard-accessible.** Move up/down buttons are the baseline; drag-to-reorder is the enhancement layered on top. Both call the same renumbering code.

### CSV

Per-repeater import and export, opt-in via a field setting.

- Export takes what is **on screen**, including unsaved edits, and only columns with an honest single-cell representation — a nested repeater or gallery is skipped rather than flattened into something that cannot be imported back.
- Cells beginning `=`, `+`, `-` or `@` are prefixed with a quote, so an exported `=HYPERLINK(...)` is text in a spreadsheet rather than a live formula.
- Import **appends** and matches columns **by header name**, dropping unknown ones. Nothing is written until you save the screen — an import that stored directly would be an unreviewable, unundoable change.

### The ceiling

Every row renders. Pagination is not bolted onto a single form post, because a paginated repeater must save per row — a per-row AJAX save endpoint and a concurrency story. Rows past `collapse_after` (default 10) start collapsed, which keeps the screen usable well past where the DOM alone would be the problem. Marked with a `ponytail:` comment.

## Flexible content

`Flexible extends Repeater`, because that is what it is: a repeater whose rows differ in shape. Order, gaps, limits, nesting, row chrome, reordering and the server-rendered new row are inherited, not rewritten.

Each row records its layout under `_layout`, so a layout can be renamed, have its fields reordered, or gain a sibling without touching stored rows.

```php
foreach ( wpcmb_get_field( 'sections' ) as $row ) {
	switch ( $row['_layout'] ) {
		case 'hero_banner':
			echo esc_html( $row['heading'] );
			break;
		case 'cards':
			foreach ( $row['cards'] as $card ) { /* … */ }
			break;
	}
}
```

Layouts carry an **icon**, a **category** (both used to group the picker) and an optional **max uses**, enforced on save rather than only in the picker.

**Two rules about rows whose layout has gone:**

- On screen, the row still renders, marked *Unknown layout*, with its values preserved in hidden inputs — so saving the page does not destroy data the user has not seen yet.
- On save, the row is **dropped rather than coerced** into another layout. Re-interpreting it would scramble its values into fields that were never meant to hold them.

The layout picker is a keyboard-reachable menu grouped by category, closing on Escape and on outside click. Collapsed rows show a live preview — the first non-empty text value — because a column of rows all saying "Row 3" is unnavigable.

## Front-end forms

```
[wpcmb_form group="group_contact0001"]
[wpcmb_form group="group_contact0001" action="post" post_type="post" guests="1" redirect="/thanks/"]
```

```php
wpcmb_form( array( 'group' => 'group_contact0001', 'action' => 'post' ) );
wpcmb_form_shortcode( 'group_contact0001' );   // the shortcode string
```

The editor's Display Settings box shows each group's shortcode, ready to copy.

Fields are drawn by the **same renderer the admin uses**, so a field type behaves identically on both sides and nothing is implemented twice. The form is a real `<form>` with a real action: with JavaScript blocked it posts, redirects and reports its result normally. The script only adds submitting without a reload and showing errors in place.

### The security model

The form's configuration — which group, what it creates, what status, where it redirects, who gets notified — **travels with the submission in a signed field**. Without that, any visitor could edit the markup and post back `post_status=publish` or their own notification address.

- `wp_hash()` signs the payload against the site's own salts, so nothing needs storing and a signature is worthless on another site.
- After verification the payload is intersected with the known defaults, so a correctly signed payload still cannot introduce settings the plugin does not define.
- **A public form never publishes.** `draft` is the default and anything beyond `pending` is refused unless the submitter can `publish_posts`.
- **Guests are refused unless the form opts in** with `guests="1"`.
- **Uploads require `upload_files`.** A public upload endpoint is a different security problem from a public form and should be a deliberate decision — override with `wpcmb/form/allow_uploads`.
- Redirects go through `wp_validate_redirect()`, so a form cannot bounce a visitor off-site.

### Spam protection

No captcha, nothing for a person to prove:

- A **honeypot** field, moved off-screen with CSS rather than `hidden` — form-filling scripts skip fields the browser reports as hidden. `aria-hidden` and `tabindex="-1"` keep it away from screen readers and keyboard users.
- A **signed timestamp**. Submissions faster than `MIN_SECONDS` are refused, and the signature means the timestamp cannot simply be back-dated.

A rejected submission gets the same wording as a genuine failure. Telling a bot which check it failed is free help for whoever wrote it.

### Template override

Copy `templates/form.php` to `wp-custom-meta-box/form.php` in your theme, or filter `wpcmb/form/template`.

## REST API

**Most of it is core's.** Fields are registered as typed meta with a JSON Schema, so values appear on the endpoints they belong to:

```
GET  /wp/v2/pages/12?context=edit     → { "meta": { "byline": "Manpreet", … } }
POST /wp/v2/pages/12                  → { "meta": { "byline": "New" } }
```

That buys authentication, per-object permissions, request validation, type casting and self-documenting schema output — all maintained by WordPress. A bespoke value endpoint would reimplement every one of those and still not appear alongside the post it describes.

Which object types a group registers against comes from **its own location rules**, so a group targeting pages does not put its fields on posts or users.

Two routes remain, for the things core cannot know about:

| Route | Method | Permission |
|---|---|---|
| `/wpcmb/v1/field-groups` | GET | `manage_options` |
| `/wpcmb/v1/field-groups/{key}` | GET | `manage_options` |
| `/wpcmb/v1/values/{type}/{id}` | GET | can read that object |
| `/wpcmb/v1/values/{type}/{id}` | POST | can edit that object |

`{type}` is `post`, `term`, `user`, `comment` or `option` — the last covering options pages, which have no core object to hang meta off.

### Permissions

`Fields\Permissions` is the single answer to "who may read or write these values", used by the edit screens, the front-end forms and REST alike. **Values inherit the permissions of the object they belong to** — a field on a post is readable by whoever may read that post and writable by whoever may edit it. The plugin never invents a looser rule.

### Schema

Schemas are **derived from field definitions**, not hand-written, so adding a sub field to a repeater updates the endpoint's contract with it. `OPTIONS` on any route returns the full description — that is the documentation, and unlike a hand-maintained route list it cannot go stale.

- Types map through: `number`→number, `toggle`→boolean, `image`→integer, `checkbox`/`gallery`→array, `link`/`address`/`group`→object.
- Formats are declared (`email`, `uri`, `date-time`, `hex-color`), so core rejects malformed values before plugin code runs.
- Choice fields declare an `enum` — including the empty string, or an optional field could never be cleared.
- Repeaters describe their rows all the way down.
- **Flexible content deliberately does not** merge every layout's properties into one shape: that would let a row of one layout claim another's fields and still validate. The item schema constrains `_layout` to the known names and leaves the rest open.
- Structural fields (message, tab, accordion) are never exposed — reads and writes see exactly the same set.

## Blocks

Tick **Register this group as a block** in the field group editor and give it a name. The group becomes a Gutenberg block in a "Custom Meta Box" category, with an icon, description, keywords, chosen supports and optional inner blocks.

**Values live in the block's own attributes, not in post meta.** That is what makes a block portable: copy it to another post, another site or a synced pattern and its content travels with it. Meta would tie it to the object it was first placed on.

**No build step.** `blocks.js` is written against `wp.element` directly rather than JSX, so what ships is what runs and a bug is debuggable in the browser without a source map.

**The editor UI is the same PHP-rendered form the admin uses**, fetched over AJAX and injected into the block. A field type works in a block the moment it works anywhere else — repeaters, flexible content and conditional logic included — rather than needing a second implementation of fifty field types in React.

### Rendering

`render_callback` only; nothing is saved into post content, so a template change takes effect on posts that already exist instead of invalidating them.

Template resolution, first match wins:

1. `wp-custom-meta-box/blocks/{block-name}.php` in the theme
2. `wp-custom-meta-box/block.php` in the theme
3. `templates/blocks/{block-name}.php` shipped by a plugin
4. `templates/block.php` — the generic fallback

So a new block renders something immediately rather than an empty region that looks broken. Or filter `wpcmb/block/template`.

Inside a block template, `wpcmb_get_field( 'heading' )` returns **that block's** value — the `wpcmb/value/pre_get` filter serves the block's attributes for the duration of the render, so a block template reads like a theme template.

### Two decisions worth knowing

- **All values sit under one `data` attribute**, not one attribute per field. Adding or renaming a field then does not invalidate blocks already saved in content.
- **A block name is never derived from the group title.** The name is part of saved content; deriving it would mean renaming a group silently orphans every block already placed.

Attributes arrive from the editor and are **cleaned by their own field types before anything is output** — the same sanitizers the edit screens, forms and REST use, so a block cannot become a way to store what the other three would refuse.

## Integrations

Every integration is **dormant unless its host plugin is present** — no host, no hooks, no loaded code.

### Shortcodes

```
[wpcmb_field name="byline"]
[wpcmb_field name="byline" object="post_12" fallback="—"]
[wpcmb_field name="hero_link" key="url"]
[wpcmb_field name="tags" separator=" | "]
```

Output is escaped, and the visitor's permission to read the object is checked — a shortcode in a comment cannot leak a draft's values. Structured values (repeaters, groups) print the `fallback` rather than a serialised array, because there is no sensible one-line form and printing JSON into a page reads as a bug.

### WooCommerce

Products and coupons are post types, so they already work through ordinary post-type location rules. Two things needed code:

- The `wc_product_type` and `wc_order_status` location rules the builder already offered.
- **Orders under HPOS.** With High-Performance Order Storage an order's meta lives in WooCommerce's own tables, not `wp_postmeta`. Three new filters — `wpcmb/storage/read`, `/write`, `/delete` — let the integration claim those reads and writes and route them through the order CRUD. `wc_get_order()` returning false for a product is exactly the test that keeps products on post meta.

Field groups render in a **Custom Fields** product data tab and on the order screen.

### Elementor and Bricks

Both builders can already read these values, because they are stored in native meta — the Phase 3 decision paying off. What they cannot do is apply a field type's formatting, so an image field hands them an attachment id and a link field hands them a serialised array. Both integrations expose the **formatted** value instead.

- **Bricks** — `{wpcmb_fieldname}` dynamic data, registered through plain filters. No class of theirs is extended, so an internal signature change cannot break it.
- **Elementor** — a dynamic tag in a "Custom Meta Box" group, offered for text and URL bindings. The tag class must extend an Elementor base class, so it lives in `elementor-tag.php` — a filename that deliberately does not match its class name, excluded from Composer's classmap, and loaded by hand only once Elementor has registered. On a site without Elementor the autoloader cannot reach it, so mentioning the class name is a missing feature rather than a fatal error.

### WP-CLI

```bash
wp wpcmb groups [--inactive] [--format=json]
wp wpcmb get byline 12 [--raw]
wp wpcmb update byline 12 "Manpreet Singh"
wp wpcmb update rows 12 '[{"title":"One"},{"title":"Two"}]'
wp wpcmb delete byline 12
wp wpcmb export [<key>...] [--file=groups.json]
wp wpcmb import groups.json [--activate]
```

Writes are validated and sanitized exactly as from an edit screen, so a script cannot store what the admin would refuse. Imports create new groups **inactive** unless `--activate` is passed.

The command surface is a separate class from the module that registers it: WP-CLI turns every public method of a command object into a subcommand, so registering the module itself would publish `wp wpcmb boot` and `wp wpcmb is_enabled`.

## Admin UI isolation

The plugin must never restyle WordPress or another plugin. That is a promise
no amount of care keeps on its own, so `tests/ui-check.php` enforces it and
runs with the rest of the suite:

| Rule | Enforced |
|---|---|
| Every CSS selector starts with a `wpcmb-` class or `#wpcmb-` id | ✅ |
| No core class restyled (`.button`, `.notice`, `.postbox`, `.wrap`, `.form-table`, `.widefat`, `.submit`, `.wp-core-ui`, `.dashicons`) | ✅ |
| No bare element selectors | ✅ |
| Every data attribute prefixed `data-wpcmb-` | ✅ |
| Scripts add nothing global but `window.wpcmb` | ✅ |
| Motion respects `prefers-reduced-motion` | ✅ |
| Logical properties only, so RTL needs no second stylesheet | ✅ |

**One deliberate deviation from the brief.** It asks that every selector begin
with `.wpcmb-admin` or `#wpcmb-app`. That is right for the plugin's own screens
and wrong for the field styles, which render inside the post, term, user and
comment editors where the body carries no class of ours — requiring it there
would mean fields render unstyled. Any `wpcmb-` class in the first compound is
equally airtight, since it cannot match markup the plugin did not write, so
that is what the checker enforces.

**RTL** needs no separate stylesheet: layout uses `margin-inline-*`,
`padding-inline-*`, `border-inline-*` and `inset-inline-*` throughout, which
mirror automatically under `dir="rtl"`.

**Colour** comes from `--wp-admin-theme-color`, so the plugin adopts whichever
admin colour scheme the user picked rather than imposing its own.
## Conditional logic and validation

Both run on the server. The browser copy exists for fast feedback, not to decide what may be stored.

- **Fields hidden by conditional logic are not validated.** A required field the user was never shown must not block a save.
- **`0` is an answer, not emptiness.** `empty()` is the wrong test and is not used for values.
- **`>` and `<` are numeric.** PHP's string comparison would make "greater than 10" true for `"9"`.
- **A malformed validation pattern is reported, not silently passed.** Silently passing is the failure mode that makes a broken rule look like it works.

Custom rules hook `wpcmb/validate`, `wpcmb/validate/type={$type}` or `wpcmb/validate/name={$name}`; return a non-empty string to reject.

### Revisions and autosave

WordPress 6.4+ already stores, restores, diffs and autosaves any meta key named by `wp_post_revision_meta_keys`. Declaring the plugin's value keys there buys revision history, revision restore and autosave recovery in one filter — nothing is reimplemented.

## Hooks so far

| Hook | Type | Description |
|------|------|-------------|
| `wpcmb/modules` | filter | Module class names to boot. Receives `array $classes, Container $container`. |
| `wpcmb/booted` | action | All modules booted. Receives `Plugin $plugin`. |
| `wpcmb/activate` | action | Plugin activated, core options written. |
| `wpcmb/deactivate` | action | Plugin deactivated. |
| `wpcmb/capability` | filter | Capability required to manage field groups. |
| `wpcmb/location/params` | filter | Available location rule parameters. |
| `wpcmb/location/choices` | filter | Selectable values per location parameter. |
| `wpcmb/admin/field_types` | filter | Field types offered in the editor. |
| `wpcmb/admin/overview` | action | End of the Overview screen. |
| `wpcmb/field_group/pre_save` | filter | Sanitized config, immediately before storage. |
| `wpcmb/field_group/saved` | action | After a field group is stored. |
| `wpcmb/object_ref` | filter | Claim an object identifier the built-in forms reject. |
| `wpcmb/value/load` | filter | A value as it is read. Field types format here. |
| `wpcmb/value/save` | filter | A value before storage. Field types cast here. |
| `wpcmb/value/updated` | action | After a value is written. |
| `wpcmb/value/deleted` | action | After a value is deleted. |
| `wpcmb/register_field_groups` | action | Register groups from code. |
| `wpcmb/groups` | filter | Groups resolved for a context. |
| `wpcmb/location/value` | filter | Value of a custom rule parameter. |
| `wpcmb/location/match_rule` | filter | Outcome of a single rule. |
| `wpcmb/conditional/visible` | filter | Whether conditional logic shows a field. |
| `wpcmb/validate` | filter | Validation result. Return a message to reject. |
| `wpcmb/validate/type={$type}` | filter | Same, for one field type. |
| `wpcmb/validate/name={$name}` | filter | Same, for one field name. |
| `wpcmb/register_field_types` | action | Register a custom field type. |
| `wpcmb/field/choices` | filter | Options a choice field offers. |
| `wpcmb/field/relationship_query` | filter | Objects a relationship field offers. |
| `wpcmb/field/enhanced_config` | filter | Config for a script-enhanced field. |
| `wpcmb/field/sub_renderer` | filter | Renderer used for a composite type's sub fields. |
| `wpcmb/field/sub_sanitizer` | filter | Sanitizer used for a composite type's sub values. |
| `wpcmb/field/sub_formatter` | filter | Formatter used for a composite type's sub values. |
| `wpcmb/admin/sub_field_types` | filter | Which types get a nested field list in the editor. |
| `wpcmb/admin/layout_field_types` | filter | Which types are edited as a set of layouts. |
| `wpcmb/values/saved` | action | After an edit screen stores its values. |
| `wpcmb/form/template` | filter | Template used for a front-end form. |
| `wpcmb/form/allow_uploads` | filter | Whether a front-end form may accept uploads. |
| `wpcmb/form/notification` | filter | The notification email. |
| `wpcmb/form/submitted` | action | After a front-end submission is stored. |
| `wpcmb/permissions/read` | filter | Whether values may be read. |
| `wpcmb/permissions/edit` | filter | Whether values may be written. |
| `wpcmb/rest/field_schema` | filter | JSON Schema for a field's value. |
| `wpcmb/rest/meta_targets` | filter | Object types a group registers meta against. |
| `wpcmb/value/pre_get` | filter | Short-circuit a value read. |
| `wpcmb/block/template` | filter | Template used to render a block. |
| `wpcmb/storage/read` | filter | Claim a value read (HPOS orders). |
| `wpcmb/storage/write` | filter | Claim a value write. |
| `wpcmb/storage/delete` | filter | Claim a value delete. |

### Registering your own module

```php
add_filter( 'wpcmb/modules', function ( array $classes ): array {
	$classes[] = My\Plugin\Addon::class; // extends WPCMB\Abstracts\Module
	return $classes;
} );
```

## Data on uninstall

Deleting the plugin removes nothing unless `wpcmb_delete_data_on_uninstall` is set. Reinstalling should not cost a site its field groups.

## Checks

```bash
composer test        # all 11 PHP checks, one process each
npm test             # 70 browser tests against the real scripts
php tests/integration.php   # real WordPress, non-destructive
composer lint        # WordPress Coding Standards
```

### Three layers, each covering what the others cannot

**PHP checks** (`tests/*-check.php`) run without WordPress, against shims. Fast, and they run anywhere. Their weakness is the shims: twice a shim was *more lenient* than WordPress, so a check passed on behaviour production did not have. Both times the gap was found by a live run, and both times the shim was corrected rather than the assertion relaxed.

**Integration checks** (`tests/integration.php`) run against a real install with the real functions, covering exactly the places where being wrong about WordPress matters — slashing, tag stripping, URL sanitizing, location matching, and REST permission boundaries. Deliberately **non-destructive**: it creates its own fixtures, tracks them, and removes only those. It never empties a database, unlike the WordPress test suite, which wipes whatever it is pointed at.

**Browser tests** (`tests/js/*.test.js`) run the five shipped scripts in jsdom against **real rendered markup**. The fixtures are generated by `php tests/js/build-fixtures.php`, which renders them through the actual PHP renderer — so a change to how input names are built fails a test rather than silently losing data. A test written against hand-written markup would pass while the real page was broken.

Individual PHP checks, if you want to run one:

```bash
php tests/bootstrap-check.php   # container + module boot pipeline
php tests/sanitize-check.php    # FieldGroup::sanitize(), the trust boundary
php tests/values-check.php      # object references + value storage
php tests/api-check.php         # location matching, conditional logic, validation
php tests/types-check.php       # field type sanitizing and formatting
php tests/repeater-check.php    # row order, limits, nesting
php tests/flexible-check.php    # layouts, orphan rows, per-layout limits
php tests/form-check.php        # form config signing, guests, targets
php tests/rest-check.php        # JSON Schema generation
php tests/blocks-check.php      # block settings and naming
php tests/integrations-check.php # integrations stay dormant without their hosts
```

None of these need WordPress.

## Directory layout

Directories are created as their phase lands, not up front. Current tree:

```
wp-custom-meta-box/
├── assets/
│   ├── css/admin.css             plugin screens
│   ├── css/fields.css            edit screens
│   ├── js/builder.js             field + location + logic builder
│   ├── js/fields.js              live logic, media picker, tabs
│   ├── js/repeater.js            rows, reorder, duplicate, CSV
│   ├── js/form.js                front-end AJAX submit
│   └── js/blocks.js              block editor, no build step
├── includes/
│   ├── Abstracts/
│   │   ├── FieldType.php         the field type contract
│   │   └── Module.php
│   ├── Admin/
│   │   ├── Ajax.php              repeater rows + CSV endpoints
│   │   ├── Assets.php            screen-gated asset loading
│   │   ├── DashboardWidget.php
│   │   ├── FieldGroupEditor.php  builder screen + the single save path
│   │   ├── FieldGroupList.php    columns, duplicate, cache invalidation
│   │   ├── Menu.php              menu + overview screen
│   │   ├── MetaBoxes.php         fields on post/term/user/comment/media
│   │   ├── SettingsPage.php
│   │   └── ToolsPage.php         JSON import/export, PHP export
│   ├── FieldTypes/               11 classes, 52 types
│   ├── API/functions.php         the public template functions
│   ├── Database/Revisions.php    revisioned meta keys (revisions + autosave)
│   ├── Fields/
│   │   ├── Conditional.php       server-side conditional logic
│   │   ├── Context.php           lazy description of the matched object
│   │   ├── FieldGroup.php        model + sanitizer
│   │   ├── Locations.php         rule catalogue, matching, summaries
│   │   ├── ObjectRef.php         loose identifier → (type, id)
│   │   ├── Permissions.php       one answer to who may read/write
│   │   ├── Registry.php          type registry + the value pipeline seam
│   │   ├── Renderer.php          wrappers, labels, naming, nesting
│   │   ├── Repository.php        reads, writes, duplication, caching, name index
│   │   ├── Resolver.php          which groups apply to a context
│   │   ├── Validator.php         server-side validation
│   │   └── Values.php            value get/update/delete/has
│   ├── Blocks/BlockRegistry.php  groups as blocks, PHP render
│   ├── CLI/                      wp wpcmb command
│   ├── Integrations/             shortcodes, Woo, Elementor, Bricks
│   ├── Frontend/
│   │   ├── Form.php              shortcode, signing, template location
│   │   └── Submission.php        one checked path for POST and AJAX
│   ├── Interfaces/Bootable.php
│   ├── REST/
│   │   ├── Controller.php        field-group + options-page routes
│   │   ├── MetaRegistrar.php     values on core's own endpoints
│   │   └── Schema.php            field definition → JSON Schema
│   ├── PostTypes/FieldGroupPostType.php
│   ├── Container.php
│   ├── Installer.php
│   └── Plugin.php
├── tests/
│   ├── *-check.php               11 standalone PHP checks
│   ├── integration.php          real WordPress, non-destructive
│   ├── run.php                  runs every PHP check
│   ├── shims.php
│   └── js/                      70 browser tests + generated fixtures
├── vendor/
├── composer.json
├── phpcs.xml.dist
├── README.md
├── uninstall.php
└── wp-custom-meta-box.php
```
