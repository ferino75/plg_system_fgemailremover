<p align="center">
  <img src="assets/1786025835_optim.png" alt="plg_system_fgemailremover logo">
</p>

# Email Remover plugin for Joomla

![Version](https://img.shields.io/github/v/release/ferino75/plg_system_fgemailremover?label=version)
![License](https://img.shields.io/badge/license-GPL--2.0-blue)
![Joomla](https://img.shields.io/badge/Joomla-3.10%2B-orange?logo=joomla&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)
![Downloads](https://img.shields.io/github/downloads/ferino75/plg_system_fgemailremover/total?cacheSeconds=3600)

A Joomla **system plugin** that strips email addresses out of the public-facing HTML output of a site, so they can never be scraped by spam harvesters — because they simply never reach the page in the first place.

Unlike classic "email cloaking" plugins, this doesn't just obfuscate the address for bots while keeping it intact for real visitors — it can **remove it entirely**, replace it with configurable text/HTML, or render it as a **generated PNG image** (with an optional custom TrueType font) so no literal address text ever appears in the page source at all.

Built for **Joomla 3.10 / PHP 7.4** on purpose — no PHP namespaces, no PSR-4 autoloading, classic `JPlugin` style, so it keeps working on older installations that can't move to Joomla 4+.

## Features

- Strips both `mailto:` links and plain-text email addresses from the rendered front-end HTML — never touches the administrator area or outbound system mail
- Three replacement modes:
  - **Remove** (empty replacement)
  - **Text/HTML** — any custom text or markup (e.g. a link to a contact form)
  - **Image (PNG)** — renders the address as pixels via GD, with the built-in bitmap font or your own uploaded `.ttf`/`.otf` font; generated images are cached per unique address and reused on subsequent requests
- **Exceptions list** — keep specific addresses or whole domains untouched (e.g. a legally-required GDPR contact address), by exact address or `@domain.tld`
- **Attribute-safe** — automatically detects when a match sits inside an HTML tag's attribute value (e.g. a `<meta>` description or a `title="..."`) and always removes it as plain text there, regardless of the configured mode, so it can never corrupt the surrounding markup
- Linear-time, backtracking-safe HTML scanning throughout (`strpos`/`stripos`-based), so it stays fast and reliable even on large, complex pages and on hosts with a low `pcre.backtrack_limit`
- Optional processing-time logging for performance diagnostics (`logs/plg_system_fgemailremover.php`)

## Installation

1. Download the latest release ZIP from the [Releases](https://github.com/ferino75/plg_system_fgemailremover/releases) page.
2. Joomla admin → **Extensions → Manage → Install** → upload the ZIP.
3. **Extensions → Plugins** → enable **System - Email Remover**.
4. Configure replacement mode, exceptions, and (optionally) an image font under the plugin's Options tab.

Once installed, Joomla will offer future updates automatically via **Extensions → Manage → Update** (this repo's `updates.xml` is wired up as the plugin's update server).

## Configuration

| Setting | What it does |
|---|---|
| Replacement mode | Text or Image (PNG) |
| Replacement text | Text/HTML used in Text mode, and as the image `alt` text in Image mode |
| Image CSS class | Extra CSS class(es) on the generated `<img>` tag (default `noshadow`) |
| TTF font path | Path to a `.ttf`/`.otf` file to render the image with a real font instead of GD's built-in bitmap font |
| Font size | Point size for the TTF font |
| Exceptions | Addresses or `@domain.tld` entries to leave untouched |
| Log processing time | Writes per-request timing/size diagnostics to the Joomla log |

## Why remove instead of cloak?

Classic cloaking (splitting the address into DOM fragments reassembled by JS, or CSS-generated content) only stops the crudest scrapers doing plain-text regex matching over raw HTML — a scraper that reads the same DOM attributes or runs a headless browser sees the address just as easily as a real visitor. Full removal (or rendering as an image with no literal address text anywhere in the source) closes that gap entirely, at the cost of losing one-click `mailto:` convenience for visitors — which the plugin's text/image replacement can offset (e.g. by linking to a contact form instead).

## License

GPL-2.0 — see [LICENSE](LICENSE).
