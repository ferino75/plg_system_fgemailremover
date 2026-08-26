# Changelog

## joomla4-6/ v1.7.2 - 2026-08-09
# Fixes whitelist matching for a `mailto:` link listing multiple comma-separated recipients (RFC 6068 - e.g. `mailto:a@x.sk,b@y.sk`) - previously checked as one literal string against the whitelist, which essentially never matched even when every individual address was whitelisted, so such links always got replaced. `isWhitelisted()` now splits on "," (safe unconditionally - a single valid address can never itself contain a literal comma) and requires *every* individual address to be whitelisted for the whole link to be left untouched; if even one recipient isn't, the link is still replaced, since leaving it would expose that address
^ Verified the other suspected gaps from the same review - case sensitivity, a trailing "." after an address in prose, and `mailto:` URL-encoding (`%40` for "@") - were already handled correctly (case-folding, the email regex's own boundary, and the existing `rawurldecode()` call respectively); no changes needed there. A mailto: URI has no display-name concept to normalise (RFC 6068) - the visible link text is separate from the href value entirely

## v1.14.2 - 2026-08-09
Same fix as joomla4-6/ v1.7.2 above, ported to this build

## joomla4-6/ v1.7.1 - 2026-08-09
# Hardens `stripMailtoLinks()` against two remaining gaps: (1) unquoted `href` values - e.g. `<a href=mailto:info@x.sk>`, valid HTML5 - were not matched at all, leaving the address in the page's `href`; the matching regex now accepts both quoted and unquoted forms; (2) a mailto: address followed by extra headers - e.g. `<a href="mailto:info@x.sk?subject=Hello&body=...">` - was treated as if the whole `info@x.sk?subject=Hello&body=...` string were the address, which could defeat whitelist matching and leaked the query string into image-mode alt text; the query string is now stripped before the address is used for either purpose (detection/removal of the link itself was never affected either way - the whole `<a>` was always replaced)
^ Re-verified: a fake `href='mailto:...'` sitting inside an unrelated attribute's own quoted value (e.g. `data-note="href='mailto:fake@x'"`) is correctly *not* matched as a real href - confirmed this was already safe after the v1.6.0 tag-boundary hardening, no change needed there
^ Documented, deliberate limitation: matching stops at the *first* `</a>` after the opening tag - genuinely nested `<a>` elements (invalid HTML to begin with, and rendered inconsistently across browsers) are not specifically handled

## v1.14.1 - 2026-08-09
Same fixes as joomla4-6/ v1.7.1 above, ported to this build

## joomla4-6/ v1.7.0 - 2026-08-09
# Loosens the classic email.cloak `<span id="cloakHASH">` detection, which previously assumed one exact serialisation - `<span id="cloak` as a fixed literal substring (double quotes, "id" as the first/only attribute, no space around "="). While that's what Joomla core's own `email.cloak` helper always emits (verified against a real captured page), assuming one exact form was needlessly fragile. Now matches `id="cloakHASH"` (or `id='cloakHASH'`) anywhere within the span's attributes, in either quote style, regardless of what other attributes come before it - e.g. `<span class="contact" id="cloakABC">` or `<span id='cloakABC'>` are now both recognised
^ The early-exit check (`stripos($buffer, 'id="cloak')`) had the same rigidity - loosened to just `stripos($buffer, 'cloak')`, since a page whose *only* email content was one of the now-newly-recognised span variants would otherwise still have been skipped entirely before ever reaching the (already-fixed) detection itself

## v1.14.0 - 2026-08-09
Same fix as joomla4-6/ v1.7.0 above, ported to this build

## joomla4-6/ v1.6.0 - 2026-08-09
# Fixes two related tag-boundary weaknesses in the skip-block scanner (`findNextSkipBlock()`) and everywhere else the same naive patterns were used: (1) a plain `stripos($html, '<script')`/`'<style'` also matches the start of a longer tag/custom-element name that merely begins with the same letters - e.g. `<scripture>` or `<style-guide>` - which could then send the scanner looking for a matching `</script>`/`</style>` that never arrives, causing the *entire rest of the page* to be silently treated as an unprocessed skip block; (2) a plain search for the next `">"` to find an opening tag's end can land on a `">"` that's actually inside a quoted attribute value - e.g. `<script data-check="a > b">` - misreading the tag boundary
+ Adds two small, bounded, single-pass helper scans - `findTagNameStart()` (validates the character immediately after the matched tag name is a real boundary: whitespace, `>`, `/`, or end of string) and `findTagEnd()` (tracks quote state so a `">"` inside `"..."`/`'...'` is never mistaken for the tag's own end) - and applies them everywhere the plugin was previously doing a naive `stripos()`/`strpos()` for a tag name or its closing `">"`: `findNextSkipBlock()`, the classic-cloak span/script detection, the JSON-LD script-tag detection, and (Joomla 4-6 build only) the `<joomla-hidden-mail>` detection
^ The `mailtoOpenTagRegex` pattern had the same underlying weakness hidden inside a `[^>]*` regex fragment - upgraded to a quote-aware alternation, `(?:[^>"\']|"[^"]*"|'[^']*')*`, that treats a `">"` inside a quoted attribute as part of that attribute rather than the tag's end, while remaining a bounded, non-backtracking pattern

## v1.13.0 - 2026-08-09
Same fixes as joomla4-6/ v1.6.0 above, ported to this build

## joomla4-6/ v1.5.2 - 2026-08-09
# The real fix for the broken Save/Save & Close buttons (v1.5.1's fix addressed a real-but-secondary issue and wasn't sufficient) - the "Audit mode" field's *label* text (not its description) contained literal `<script>`/`<style>` substrings: "Audit mode (report addresses inside <script>/<style>)". Unlike the field description (rendered inside an HTML attribute, and correctly escaped there), Joomla 3.10 renders a field's label as raw, unescaped HTML content - so the browser parsed the literal `<script>` in the label as an actual opening script tag, swallowing an unpredictable amount of the subsequent page markup (very plausibly including the form's own hidden `task` input) as script content. Confirmed directly from a captured page source showing exactly that. Reworded the label to remove the angle brackets entirely, in both languages, in both builds

## v1.12.2 - 2026-08-09
Same fix as joomla4-6/ v1.5.2 above, ported to this build

## joomla4-6/ v1.5.1 - 2026-08-09
# Fixes the plugin's own admin Options form becoming unusable on Joomla 3.10 (Save/Save & Close buttons silently did nothing) - the "Audit mode" field's description text (added in v1.5.0/v1.12.0) contained literal embedded double-quote characters (`..."Scope of protection"...`). When rendered into an HTML attribute (e.g. a tooltip's `title="..."`), those quotes prematurely closed the attribute and corrupted the surrounding markup, breaking the page's JS enough to disable the toolbar buttons. Reworded to avoid embedded quotes entirely, in both languages, in both builds - nothing else about audit mode changed

## v1.12.1 - 2026-08-09
Same fix as joomla4-6/ v1.5.1 above, ported to this build

## joomla4-6/ v1.5.0 - 2026-08-09
+ `<script type="application/ld+json">` blocks are no longer skipped untouched like other `<script>` content - they're structured data, not executable code, so they're safely `json_decode()`d, cleaned of matching email addresses in any string value (respecting the whitelist), and `json_encode()`d back. Invalid/malformed JSON is left byte-for-byte unchanged rather than risk corrupting it. Replacement is always plain text (the "replacement_text" parameter) regardless of the configured mode, since an `<img>` tag has no meaning inside JSON
+ New optional "Audit mode" parameter - when enabled, logs a warning to `logs/plg_system_fgemailremover_audit.php` whenever an address is found inside an ordinary (non-JSON-LD) `<script>` or `<style>` block that the plugin deliberately leaves untouched, without ever modifying that content itself
^ README gets a new "Scope of protection" section spelling out plainly that literal addresses hard-coded into ordinary `<script>` JS or `<style>` CSS are *not* covered, and why - this was previously true but undocumented, which overstated what "emails never reach the page" actually covers

## v1.12.0 - 2026-08-09
Same two features (JSON-LD handling, audit mode) and the README documentation update, ported to this build

## joomla4-6/ v1.4.1 - 2026-08-09
^ Front-end-only guard tightened from `if ($app->isClient('administrator')) return;` to `if (!$app->isClient('site')) return;` - the previous denylist form only excluded the admin area, so any other application client Joomla might dispatch `onAfterRender` to (API, CLI, or future client types) would have been processed as if it were the public site. An explicit allowlist on "site" matches the plugin's own "front-end only" intent precisely instead of by exclusion. (The separate `$document->getType() !== 'html'` check, which already guards against non-HTML responses like JSON/XML/RSS regardless of client, was already in place and is unchanged.)

## v1.11.1 - 2026-08-09
Same change as joomla4-6/ v1.4.1 above, ported to this build

## joomla4-6/ v1.4.0 - 2026-08-08
+ "Replacement text" field is now hidden in Image mode (`showon="replacement_mode:text"`) - it was previously always visible even though it had nothing to do with Image mode
+ New "Image alt text" field, shown only in Image mode, replacing the previous double-duty use of "Replacement text" as both the Text-mode substitution and the Image-mode alt text - each mode's admin field is now shown only when it's actually relevant, instead of one field silently serving two different jobs depending on the mode
^ Existing installs: any previously-set "Replacement text" value used as alt text will need to be re-entered in the new "Image alt text" field - the two are no longer linked

## v1.11.0 - 2026-08-08
Same two changes as joomla4-6/ v1.4.0 above, ported to this build

## joomla4-6/ v1.3.1 - 2026-08-08
+ Displayed plugin name changed to "System - FG Email Remover" (was "System - Email Remover"), for consistency with the GitHub repo/README/banner branding - updated in the language files and in updates.xml
^ Removed the hard-coded "noshadow" default from the "Image CSS class" parameter (now defaults to empty) - it was specific to the maintainer's own site's shadow-removal need and had no business being a default for other installs; the internal `emailremover-img` class is still always added regardless

## v1.10.1 - 2026-08-08
Same two changes as joomla4-6/ v1.3.1 above, ported to this build

## joomla4-6/ v1.3.0 - 2026-08-08
# Fixes generated `<img>` tags (image mode) getting stretched/enlarged and re-centred on some sites' mobile views - a site's generic responsive-image CSS or a third-party lazy-loading library reacting to a class like `lazy` in the "Image CSS class" parameter can resize an image with no explicit dimensions to fill its container. The `<img>` tag now carries HTML `width`/`height` attributes and matching fixed-pixel `width`/`height` plus `max-width:none` in its inline style (dimensions read back from the actual generated PNG via `getimagesize()`), which reliably overrides that kind of low-specificity external CSS

## v1.10.0 - 2026-08-08
# Same `<img>` width/height fix as joomla4-6/ v1.3.0 above, ported to this build

## joomla4-6/ v1.2.0 - 2026-08-07
# Fixes emails going undetected when a page uses Joomla core's *classic* `email.cloak` construct (`<span id="cloakHASH">...</span>` + paired `<script>`) instead of, or alongside, the newer `<joomla-hidden-mail>` component - still possibly used on some Joomla 4/5 sites. The real address is assembled by JavaScript from numeric-HTML-entity-encoded string fragments, so no literal "@" ever appears (it's always the five-character `&#64;`), and the general script-skip logic that protects arbitrary third-party JS was also walling this one narrow, known pattern off from ever being decoded
+ Adds a dedicated pass (run on the raw buffer *before* the script/style skip-block split, since it specifically needs to look inside this one paired `<script>`) that locates the span+script pair, decodes the assembled address for whitelist checking, and replaces the whole construct
^ Early exit now also checks for `id="cloak` alongside the existing `@` and `<joomla-hidden-mail` checks

## v1.9.0 - 2026-08-07
# Same classic `email.cloak` fix as joomla4-6/ v1.2.0 above, ported to this build - this is in fact the primary case for it, since Joomla 3.10 only ever uses this classic mechanism (com_contact's own contact-detail email field uses it by default), never the newer `<joomla-hidden-mail>` component. Confirmed against a real cloaked address on urbarterchova.sk
^ Early exit now also checks for `id="cloak` alongside the existing `@` check

## joomla4-6/ v1.1.0 - 2026-08-07
# Fixes emails going completely undetected on Joomla 6 (and any Joomla 5/6 site using core's native email cloaking) - `com_contact` (and potentially other core views) now renders addresses via Joomla's own `<joomla-hidden-mail>` web component, which stores the address as base64 in `first`/`last`/`text` attributes rather than as literal text or a `mailto:` href, so none of the existing passes could ever find it
+ Adds a dedicated pass that finds `<joomla-hidden-mail is-email="1" ...>` elements (plain `strpos()`-based, same backtracking-safe approach as the rest of the plugin), decodes the `text` attribute to get the real address for whitelist checking, and replaces the whole element like any other match
# Fixes the "nothing to do" early exit incorrectly skipping pages whose only removable content is a `<joomla-hidden-mail>` element - base64 never contains "@", so the existing `strpos($buffer, '@') === false` check alone caused the plugin to return before ever processing such a page; now also checks for the literal `<joomla-hidden-mail` substring
# Fixes `getWhitelist()` caching its parsed result in a `static` local variable, which in PHP is shared across *every* instance of the class within one process rather than per-object - invisible in normal Joomla requests (one plugin instance per request) but incorrect regardless; now cached as a per-instance property

## joomla4-6/ v1.0.0 - 2026-08-07
+ New: native Joomla 4/5/6 build, in [`/joomla4-6`](joomla4-6) - `CMSPlugin` + `SubscriberInterface`, PSR-4 namespace `FG\Plugin\System\Fgemailremover`, `services/provider.php` DI registration - same element/identity (`fgemailremover`) and same feature set as the classic build, ported line-for-line (only the Joomla-API integration layer changed: `JFactory`/`JUri`/`JLog` -> `Factory`/`Uri`/`Log`, `onAfterRender()` -> `SubscriberInterface::getSubscribedEvents()`)
^ Reason: Joomla 4 removed native support for the classic build's `JPlugin`/`JFactory`/`JUri`/`JLog` classes; Joomla 5 only provides them via the "Behaviour - Backward Compatibility" plugin (on by default, but explicitly a removable/temporary crutch), and Joomla 6 removes that compatibility layer entirely - so the classic ZIP cannot run correctly on Joomla 4, 5 or 6
+ `updates.xml` now lists both builds as separate `<update>` entries under the same element, matched by `<targetplatform>` (`3\.[0-9]+\.[0-9]+` vs `[456]\.[0-9]+\.[0-9]+`, verified mutually exclusive) so Joomla's updater always offers the correct one for the site's own Joomla version
+ Versioned independently from the classic build (starts at 1.0.0) since it's a distinct codebase/package, despite sharing the same plugin identity and feature set

## v1.8.1 - 2026-08-07
# Fixes `getWhitelist()` caching its parsed result in a `static` local variable instead of a per-instance property (see joomla4-6/ v1.1.0 for details) - same fix ported here for consistency, though it's invisible in normal Joomla requests since only one plugin instance is ever created per request

## v1.8.0 - 2026-08-04
+ Renamed from `plg_system_emailremover` to `plg_system_fgemailremover` (FG series naming), published to GitHub
^ Behaviour is unchanged - element/folder/class name and language constants updated, cache folder moved to `images/fgemailremover_cache`; not switched to a PHP namespace (unlike other FG-series plugins), since namespaced/PSR-4 plugins require Joomla 4+ and this plugin specifically targets Joomla 3.10/PHP 7.4
+ Adds repo scaffolding: README, LICENSE (GPL-2.0), .gitignore, `updates.xml` update server, logo

## v1.7.1 - 2026-08-03
# Fixes HTML corruption when a plain-text address sits inside a tag's attribute value (e.g. a `<meta name="description" content="...">` auto-summary, or a `title="..."` tooltip) and "Replacement mode" is set to Image - inserting an `<img ...>` tag there prematurely closed the attribute's quotes and broke the surrounding markup, which browsers then rendered as stray visible text above the whole page
^ Adds a lightweight "am I inside an open tag" check before replacing a plain-text match; inside a tag's attribute, the address is now always removed as plain text (no markup ever injected there), regardless of the configured replacement mode - normal page text is unaffected and still gets the full text/image replacement

## v1.7.0 - 2026-08-03
+ Adds "TTF font path" and "Font size" parameters - renders the image with a real TrueType font (e.g. the template's own font, uploaded as .ttf/.otf) via `imagettftext()` instead of GD's built-in bitmap font
+ Falls back automatically to the built-in bitmap font if no TTF path is set, the file isn't found, GD lacks freetype support, or TTF rendering fails for any reason
+ Cache key now includes the font path/size, so changing the font automatically regenerates images instead of serving stale-styled ones from cache

## v1.6.0 - 2026-08-03
+ Adds an "Image CSS class" parameter (default: "noshadow") - CSS class(es) added to the generated `<img>` tag, replacing the previously hard-coded "noshadow"
+ Field only shows in the admin form when "Replacement mode" is set to Image (`showon`)

## v1.5.1 - 2026-08-03
# Fixes PHP header still showing v1.4.0 despite v1.5.0's changes (docblock only, no functional impact)
+ Adds "noshadow" to the generated `<img>` tag's class list, so site-wide default image shadow styling doesn't apply to it

## v1.5.0 - 2026-08-03
+ Adds a "Replacement mode" option: Text (unchanged behaviour) or Image (PNG) - renders the address as pixels using GD's built-in bitmap font, so no literal address text reaches the page source at all
+ Generated images are cached to `images/emailremover_cache/` (one PNG per unique address, keyed by hash) and reused on subsequent requests instead of regenerating every time
+ Falls back to text mode automatically if GD isn't available or image generation fails for any reason
+ The existing "Replacement text" field doubles as the image's alt text in Image mode

## v1.4.0 - 2026-08-03
# Fixes plain-text email addresses silently surviving on some pages even after v1.3.0 - confirmed via a live test where the plugin correctly replaced a mailto: link (via the strpos-based logic added in v1.3.0) but left a plain-text address in the same response untouched. Root cause: the plain-text pass still ran one `preg_replace_callback()` over the *entire* chunk (tens of KB); on hosts with a lower `pcre.backtrack_limit`/`pcre.recursion_limit` than PHP's default, this can silently fail and fall back to unmodified HTML
^ Rewrites the plain-text pass to jump to each "@" with `strpos()` and run the email regex only on a small ~200-byte window around it, never on the full chunk - verified correct even with `pcre.backtrack_limit`/`pcre.recursion_limit` artificially set to 1000 (1000x below PHP's default)

## v1.3.0 - 2026-08-03
# Fixes emails silently surviving on large/complex pages (many `<a>` tags and/or `<script>` blocks) - the previous mailto-link and script/style-skip regexes used a DOTALL lazy wildcard (`.*?`) to span arbitrary content, which could exceed PHP's PCRE backtrack limit on such pages; when that happened, `preg_replace_callback()`/`preg_split()` silently failed and the plugin left the whole page untouched with no visible error
^ Rewrites mailto-link removal and script/style block detection to use plain `strpos()`/`stripos()` boundary search instead of wildcard regex spanning - guaranteed linear time regardless of page size or tag count
^ `preg_replace_callback()` failures on the plain-text email pass are now handled explicitly (falls back to the mailto-stripped HTML instead of losing the chunk)

## v1.2.0 - 2026-08-03
+ Adds an optional "Log processing time" toggle - when enabled, writes elapsed time (ms), HTML size before/after, and the request URL to logs/plg_system_emailremover.php on every page load
+ Off by default, intended for temporary performance diagnostics on a specific page

## v1.1.1 - 2026-08-03
# Fixes "Replacement text" param stripping HTML (e.g. a link) - Joomla applied its default "string" input filter to the textarea field; now uses `filter="raw"` so HTML entered there is saved as-is

## v1.1.0 - 2026-08-03
+ Adds a "whitelist" parameter for exceptions - addresses or whole domains that should be kept untouched
+ Accepts either a specific address (`gdpr@example.sk`) or a whole-domain entry (`@example.sk`), one per line or comma-separated
+ Applies to both mailto links and plain-text addresses
^ Switches the strip logic from `preg_replace` to `preg_replace_callback` to allow the per-match whitelist check

## v1.0.0 - 2026-08-03
+ Initial release
+ System plugin (Joomla 3.10, `plg_system_emailremover`) that strips `mailto:` links and plain-text email addresses from the rendered front-end HTML output
+ Configurable replacement text (leave empty to remove with no replacement, or set custom text/HTML, e.g. a link to a contact form)
+ Skips `<script>` and `<style>` blocks so JS/CSS is never touched
+ Only runs on the public front-end; never touches the administrator area or outbound system mail
