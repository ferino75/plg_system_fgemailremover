<p align="center">
  <img src="assets/logo.png" alt="plg_system_fgemailremover logo" width="128" height="128">
</p>

<h1 align="center">FG Email Remover plugin for Joomla</h1>

<p align="center">
  <img src="https://img.shields.io/github/v/release/ferino75/plg_system_fgemailremover?color=FF6B4A&label=release" alt="Latest release">
  <img src="https://img.shields.io/badge/Joomla-3.10%20--%206-5091CD.svg?logo=joomla&logoColor=white" alt="Joomla">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg?logo=php&logoColor=white" alt="PHP">
  <a href="https://extensions.joomla.org/extension/access-a-security/site-security/email-remover/"><img src="https://img.shields.io/badge/Joomla!%20Extensions%20Directory%E2%84%A2-EmailRemover-blue" alt="JED"></a>
  <img src="https://img.shields.io/badge/license-GPL--2.0-green.svg" alt="License">
  <img src="https://img.shields.io/github/downloads/ferino75/plg_system_fgemailremover/total?cacheSeconds=3600" alt="Downloads">
</p>

A Joomla **system plugin** that strips email addresses out of the public-facing HTML output of a site, so they can never be scraped by spam harvesters — because they simply never reach the page in the first place.

Unlike classic "email cloaking" plugins, this doesn't just obfuscate the address for bots while keeping it intact for real visitors — it can **remove it entirely**, replace it with configurable text/HTML, or render it as a **generated PNG image** (with an optional custom TrueType font) so no literal address text ever appears in the page source at all.

Ships as **two separate packages sharing the same plugin identity** (`fgemailremover`), so Joomla's own updater always offers the right one for your site:

| | Joomla | PHP | Location in this repo |
|---|---|---|---|
| **Classic build** | 3.10.x | 7.4+ | repo root (`fgemailremover.php`, flat `JPlugin` class, no namespace) |
| **Native build** | 4.x / 5.x / 6.x | 8.1+ | [`/joomla4-6`](joomla4-6) (`CMSPlugin` + `SubscriberInterface`, PSR-4 namespace `FG\Plugin\System\Fgemailremover`) |

The classic build deliberately avoids PHP namespaces and PSR-4 autoloading so it keeps working on Joomla 3.10 installs that can't move to Joomla 4+. Joomla 4 removed native support for the old `JPlugin`/`JFactory`/`JUri`/`JLog` classes the classic build relies on — Joomla 5 still provides them for now only via the "Behaviour - Backward Compatibility" plugin, and Joomla 6 removes that compatibility layer entirely — so the classic ZIP will not run correctly on Joomla 4, 5 or 6; install the native build there instead.

## Features

- Strips both `mailto:` links and plain-text email addresses from the rendered front-end HTML — never touches the administrator area or outbound system mail
- Also detects and cleans both of Joomla core's own email-cloaking mechanisms (the classic `email.cloak` span+script construct, and Joomla 5/6's newer `<joomla-hidden-mail>` web component) — neither ever contains a literal address in the raw HTML, so a generic scraper wouldn't normally need help there, but the plugin removes them too for consistency
- Three replacement modes:
  - **Remove** (empty replacement)
  - **Text/HTML** — any custom text or markup (e.g. a link to a contact form)
  - **Image (PNG)** — renders the address as pixels via GD, with the built-in bitmap font or your own uploaded `.ttf`/`.otf` font; generated images are cached per unique address and reused on subsequent requests
- **Exceptions list** — keep specific addresses or whole domains untouched (e.g. a legally-required GDPR contact address), by exact address or `@domain.tld`
- **Attribute-safe** — automatically detects when a match sits inside an HTML tag's attribute value (e.g. a `<meta>` description or a `title="..."`) and always removes it as plain text there, regardless of the configured mode, so it can never corrupt the surrounding markup
- **JSON-LD aware** — `<script type="application/ld+json">` structured-data blocks are decoded as JSON and cleaned safely, rather than skipped like other `<script>` content (see "Scope of protection" below)
- Linear-time, backtracking-safe HTML scanning throughout (`strpos`/`stripos`-based), so it stays fast and reliable even on large, complex pages and on hosts with a low `pcre.backtrack_limit`
- Optional processing-time logging for performance diagnostics (`logs/plg_system_fgemailremover.php`), and an optional audit mode that reports (without ever modifying) addresses left untouched inside `<script>`/`<style>` content (`logs/plg_system_fgemailremover_audit.php`)

## Installation

1. Download the release ZIP matching your Joomla version from the [Releases](https://github.com/ferino75/plg_system_fgemailremover/releases) page — the classic build's asset is named `plg_system_fgemailremover_vX.Y.Z.zip`, the native Joomla 4-6 build's is `plg_system_fgemailremover_j46_vX.Y.Z.zip`.
2. Joomla admin → **Extensions → Manage → Install** → upload the ZIP.
3. **Extensions → Plugins** → enable **System - FG Email Remover**.
4. Configure replacement mode, exceptions, and (optionally) an image font under the plugin's Options tab.

Once installed, Joomla will offer future updates automatically via **Extensions → Manage → Update** — this repo's `updates.xml` is wired up as the plugin's update server and lists both builds, so Joomla always offers the one matching the site's own Joomla version.

## Configuration

| Setting | What it does |
|---|---|
| Replacement mode | Text or Image (PNG) |
| Replacement text | Text/HTML used in Text mode (shown only in Text mode) |
| Image alt text | `alt` attribute text for the generated image (shown only in Image mode; defaults to a generic "email address" string if left empty) |
| Image CSS class | Extra CSS class(es) on the generated `<img>` tag, added alongside the always-present `emailremover-img` class (empty by default) |
| TTF font path | Path to a `.ttf`/`.otf` file to render the image with a real font instead of GD's built-in bitmap font |
| Font size | Point size for the TTF font |
| Exceptions | Addresses or `@domain.tld` entries to leave untouched |
| Log processing time | Writes per-request timing/size diagnostics to `logs/plg_system_fgemailremover.php` |
| Audit mode | Writes a warning to `logs/plg_system_fgemailremover_audit.php` whenever an address is left untouched inside `<script>`/`<style>` content - see "Scope of protection" below. Never modifies anything itself |

## Scope of protection

This plugin parses the page as HTML — it does not parse or execute JavaScript, and does not parse CSS. In practice that means:

- **Covered**: the visible HTML body, `mailto:` links, HTML attribute values (e.g. `<meta>` descriptions), both of Joomla core's own cloaking mechanisms, and `<script type="application/ld+json">` blocks (parsed and cleaned safely as JSON, not as arbitrary script content).
- **Not covered, by design**: a literal address inside ordinary `<script>` JavaScript (e.g. `const contact = 'info@example.com';`) or inside `<style>` CSS (e.g. `content: "info@example.com";`) is left completely untouched.

This is a deliberate trade-off, not an oversight: safely rewriting arbitrary third-party JavaScript or CSS without ever risking breaking it is not something a general-purpose regex/string-scanning plugin can guarantee — the risk of corrupting site behaviour would outweigh the benefit. If your site has an address hard-coded into a `<script>` or `<style>` block, remove it there directly, or enable **Audit mode** to have the plugin tell you (via the Joomla log) exactly where that's happening, without changing anything itself.

## Why remove instead of cloak?

Classic cloaking (splitting the address into DOM fragments reassembled by JS, or CSS-generated content) only stops the crudest scrapers doing plain-text regex matching over raw HTML — a scraper that reads the same DOM attributes or runs a headless browser sees the address just as easily as a real visitor. Full removal (or rendering as an image with no literal address text anywhere in the source) closes that gap entirely, at the cost of losing one-click `mailto:` convenience for visitors — which the plugin's text/image replacement can offset (e.g. by linking to a contact form instead).

## License

GPL-2.0 — see [LICENSE](LICENSE).
