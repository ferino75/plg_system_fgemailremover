# Changelog

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
