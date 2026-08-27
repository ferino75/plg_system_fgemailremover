<?php

/**
 * @package     System.Fgemailremover
 * @subpackage  plg_system_fgemailremover
 * @version     1.7.5
 *
 * @copyright   (C) 2026 Fero. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Plugin\System\Fgemailremover\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

/**
 * System plugin that strips email addresses (mailto: links and plain-text
 * occurrences) from the rendered front-end HTML output, so they can never
 * be harvested by scrapers - because they are simply never sent to the
 * browser in the first place.
 *
 * Only runs on the public front-end. Never touches the administrator
 * area, and never touches outbound system mail (registration, password
 * reset, etc.) - only the rendered HTML page buffer.
 *
 * Native Joomla 4/5/6 build - CMSPlugin + SubscriberInterface, PSR-4
 * autoloaded, no legacy J-prefixed classes. For Joomla 3.10/PHP 7.4 use
 * the classic plg_system_fgemailremover build instead (flat JPlugin
 * class, no namespace) - the two are separate install packages sharing
 * the same plugin identity, matched to the appropriate Joomla version by
 * the update site's <targetplatform> entries.
 */
class Fgemailremover extends CMSPlugin implements SubscriberInterface
{
    /**
     * Bumped whenever anything about the generated image's actual
     * appearance changes - text/background colour, padding, font
     * fallback logic, etc. Included in the cache key (see
     * getEmailImageInfo()) specifically so such a change can never
     * silently keep serving stale-looking cached PNGs generated under
     * the old rendering: bumping this one constant invalidates every
     * existing cache entry at once, without having to remember to also
     * enumerate every individual visual parameter into the key by hand.
     * None of those parameters are actually configurable today (colour,
     * padding etc. are hardcoded constants in renderEmailImageTtf()/
     * renderEmailImageBitmap()), so nothing about the current output
     * varies - this exists purely so a *future* change to those
     * constants (or new ones) can't accidentally reuse old images.
     *
     * @var int
     */
    const IMAGE_RENDERER_VERSION = 1;

    protected $autoloadLanguage = true;

    /**
     * Matches a plain-text email address. Deliberately simple (no nested
     * quantifiers, no unbounded lazy wildcard) so it stays fast and safe
     * on very large pages.
     *
     * @var string
     */
    private $emailRegex = '/[a-zA-Z0-9.\_%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/';

    /**
     * Matches only the OPENING <a ... href="mailto:..."> or
     * <a ... href=mailto:...> tag (both quoted and unquoted href values
     * are valid HTML5), up to and including the tag's own closing ">".
     * Deliberately bounded (cannot span past the tag's own end or the
     * matching quote), unlike matching the whole <a>...</a> element
     * with a DOTALL lazy wildcard, which on a page with many <a> tags
     * can exceed PHP's PCRE backtrack limit and make
     * preg_replace_callback() silently fail.
     *
     * Capture groups: 1 = quote character (quoted form only), 2 =
     * address (quoted form), 3 = address (unquoted form) - exactly one
     * of groups 2/3 is populated per match; see stripMailtoLinks().
     *
     * The alternation `(?:[^>"\']|"[^"]*"|'[^']*')*` (rather than a
     * plain `[^>]*`) between attributes correctly treats a ">" inside a
     * quoted attribute value - e.g. <a data-check="a > b" href="mailto:
     * ...">  - as part of that attribute, not the tag's own end, and
     * likewise means "href" appearing inside a different, unrelated
     * attribute's own quoted value - e.g. <a data-note="href=&#39;
     * mailto:fake@x&#39;">  - can never be mistaken for the tag's real
     * href (that whole quoted value is consumed as one atomic unit, so
     * the "href" text inside it is never exposed to the \bhref\b
     * match); each alternative consumes at least one character so this
     * stays a bounded, linear-time match, not a backtracking risk. The
     * corresponding closing </a> is located separately with plain
     * stripos() - see stripMailtoLinks().
     *
     * @var string
     */
    private $mailtoOpenTagRegex = '#<a\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*\bhref\s*=\s*(?:(["\'])mailto:([^"\']*)\1|mailto:([^\s>]+))(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i';

    /**
     * @return  array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRender' => 'onAfterRender',
        ];
    }

    /**
     * @param   Event  $event
     *
     * @return  void
     */
    public function onAfterRender(Event $event): void
    {
        $app = $this->getApplication();

        // Only ever touch the public front-end - never the admin area
        // (so editors can still see/edit real addresses in the
        // backend), and never any other application client (API, CLI,
        // or anything else Joomla may add) - an explicit allowlist on
        // "site" is more precise than merely excluding "administrator".
        if (!$app->isClient('site')) {
            return;
        }

        $document = Factory::getDocument();

        if (!method_exists($document, 'getType') || $document->getType() !== 'html') {
            return;
        }

        $buffer = $app->getBody();

        // Cheap early exit - nothing to do if there is no plain "@"
        // (mailto: links, plain-text addresses) AND neither of the two
        // known Joomla email-cloaking mechanisms that never contain a
        // literal "@" at all: the newer <joomla-hidden-mail> web
        // component (base64-encoded attributes) and the classic
        // email.cloak span+script (numeric-HTML-entity-encoded JS
        // string concatenation, where "@" is always the five-character
        // "&#64;", never the byte itself). Just "cloak" rather than a
        // specific "id=\"cloak" substring, since the real detection in
        // stripClassicCloakElements() is lenient about attribute order
        // and quote style - this early check only needs to avoid false
        // negatives, not be precise.
        if (
            strpos($buffer, '@') === false
            && stripos($buffer, '<joomla-hidden-mail') === false
            && stripos($buffer, 'cloak') === false
        ) {
            return;
        }

        $logTiming  = (bool) $this->params->get('log_timing', 0);
        $start      = $logTiming ? microtime(true) : null;
        $sizeBefore = $logTiming ? strlen($buffer) : null;

        $buffer = $this->processBuffer($buffer);

        if ($logTiming) {
            $this->logTiming($start, $sizeBefore, strlen($buffer));
        }

        $app->setBody($buffer);
    }

    /**
     * Writes one diagnostic line (elapsed time, buffer size before/after,
     * requested URL) to logs/plg_system_fgemailremover.php - only called
     * when the "log_timing" parameter is enabled.
     *
     * @param   float  $start       microtime(true) captured before processing.
     * @param   int    $sizeBefore  Buffer size in bytes before processing.
     * @param   int    $sizeAfter   Buffer size in bytes after processing.
     *
     * @return  void
     */
    private function logTiming($start, $sizeBefore, $sizeAfter)
    {
        Log::addLogger(
            ['text_file' => 'plg_system_fgemailremover.php'],
            Log::INFO,
            ['emailremover']
        );

        Log::add(
            sprintf(
                '%.2f ms | %d KB -> %d KB | %s',
                (microtime(true) - $start) * 1000,
                round($sizeBefore / 1024),
                round($sizeAfter / 1024),
                Uri::getInstance()->toString()
            ),
            Log::INFO,
            'emailremover'
        );
    }

    /**
     * Strips any embedded email-address-looking pattern out of an
     * admin-configured replacement string ("Replacement text" or
     * "Image alt text") before it's used - a defensive backstop against
     * an administrator accidentally typing a real address into the very
     * field meant to replace a removed one (e.g. "Write to
     * support@example.com instead"), which would otherwise reach the
     * page untouched, since it's substituted in *after* the plugin's
     * own scanning pass has already run over the surrounding HTML.
     * Safe to run unconditionally here - these are short,
     * administrator-configured strings, not page-sized input, so a
     * plain preg_replace() with the plugin's existing bounded email
     * pattern carries none of the backtracking risk the rest of the
     * plugin is otherwise careful to avoid.
     *
     * @param   string  $text
     *
     * @return  string
     */
    private function stripSelfEmails($text)
    {
        if ($text === '' || strpos($text, '@') === false) {
            return $text;
        }

        $cleaned = preg_replace($this->emailRegex, '', $text);

        return $cleaned === null ? $text : $cleaned;
    }

    /**
     * Processes the full page buffer, leaving <script>/<style> blocks
     * untouched and stripping emails from everything else. Walks the
     * buffer with plain strpos()/stripos() to locate skip-block
     * boundaries (linear time, no regex backtracking involved), so
     * performance stays predictable regardless of page size or how many
     * <script>/<style> blocks it contains.
     *
     * @param   string  $html  The full rendered page HTML.
     *
     * @return  string
     */
    private function processBuffer($html)
    {
        $replacement = $this->stripSelfEmails((string) $this->params->get('replacement_text', ''));
        $mode        = (string) $this->params->get('replacement_mode', 'text');

        // Classic Joomla email.cloak spans (still possibly used on some
        // Joomla 4/5 sites alongside or instead of the newer
        // <joomla-hidden-mail> component) embed the real address inside
        // their own paired <script> block, which the general
        // script-skip logic below deliberately walls off untouched.
        // Handle it first, on the raw buffer, before anything gets
        // marked as an opaque skip block.
        $html = $this->stripClassicCloakElements($html, $replacement, $mode);

        $length = strlen($html);
        $out    = '';
        $pos    = 0;

        while ($pos < $length) {
            $skip = $this->findNextSkipBlock($html, $pos);

            if ($skip === null) {
                $out .= $this->stripEmails(substr($html, $pos), $replacement, $mode);
                break;
            }

            [$skipStart, $skipEnd] = $skip;

            // Process (and strip emails from) everything before the skip block.
            $out .= $this->stripEmails(substr($html, $pos, $skipStart - $pos), $replacement, $mode);

            // <script>/<style> content itself is not parsed as HTML - see
            // processSkipBlock() for the one safe exception (JSON-LD) and
            // the optional audit-only reporting for everything else.
            $out .= $this->processSkipBlock(substr($html, $skipStart, $skipEnd - $skipStart), $replacement);

            $pos = $skipEnd;
        }

        return $out;
    }

    /**
     * Finds the next <script>...</script> or <style>...</style> block at
     * or after byte offset $from. Uses findTagNameStart() (not a naive
     * substring search) so a custom-element tag that merely starts with
     * the same letters - e.g. <scripture> or <style-guide> - is never
     * mistaken for a real <script>/<style> tag, and findTagEnd() (not a
     * naive search for the next ">") so a ">" inside a quoted attribute
     * value - e.g. <script data-check="a > b"> - is never mistaken for
     * the tag's own closing ">". Both helpers are small, bounded,
     * single-pass scans - not a full HTML parser, but enough to avoid
     * misreading the tag boundary in either of those two ways. Getting
     * this wrong is not a cosmetic issue: it can make the rest of the
     * whole document look like it's still "inside" the skip block,
     * silently disabling email removal for everything after it.
     *
     * @param   string  $html
     * @param   int     $from
     *
     * @return  array{0:int,1:int}|null  [startOffset, endOffsetExclusive], or null if none found.
     */
    private function findNextSkipBlock($html, $from)
    {
        $scriptStart = $this->findTagNameStart($html, 'script', $from);
        $styleStart  = $this->findTagNameStart($html, 'style', $from);

        if ($scriptStart === false && $styleStart === false) {
            return null;
        }

        if ($styleStart === false || ($scriptStart !== false && $scriptStart < $styleStart)) {
            $tag   = 'script';
            $start = $scriptStart;
        } else {
            $tag   = 'style';
            $start = $styleStart;
        }

        $tagOpenEnd = $this->findTagEnd($html, $start);

        if ($tagOpenEnd === false) {
            // Malformed - no closing ">" for the opening tag itself;
            // treat the rest of the document as part of the skip block
            // rather than risk mangling it.
            return [$start, strlen($html)];
        }

        $closeTag = stripos($html, '</' . $tag . '>', $tagOpenEnd);

        if ($closeTag === false) {
            // No closing tag found - same reasoning as above.
            return [$start, strlen($html)];
        }

        return [$start, $closeTag + strlen('</' . $tag . '>')];
    }

    /**
     * Finds the next occurrence of an opening "<$tagName" at a genuine
     * tag-name boundary - i.e. immediately followed by whitespace, ">",
     * "/", or the end of the string - starting at or after byte offset
     * $from. A plain stripos() for "<script" would also match the start
     * of "<scripture>" or "<script-runner>" (a plausible custom-element
     * name); this rejects those and keeps searching past them.
     *
     * @param   string  $html
     * @param   string  $tagName  Lowercase, without "<".
     * @param   int     $from
     *
     * @return  int|false
     */
    private function findTagNameStart($html, $tagName, $from)
    {
        $needle    = '<' . $tagName;
        $needleLen = strlen($needle);
        $length    = strlen($html);
        $pos       = $from;

        while (($pos = stripos($html, $needle, $pos)) !== false) {
            $next     = $pos + $needleLen;
            $nextChar = $next < $length ? $html[$next] : '';

            if ($nextChar === '' || $nextChar === '>' || $nextChar === '/' || ctype_space($nextChar)) {
                return $pos;
            }

            // Not a real boundary (e.g. "<scripture", "<script-runner")
            // - keep searching from just past this false match.
            $pos++;
        }

        return false;
    }

    /**
     * Finds the byte offset of the ">" that actually terminates an
     * opening HTML tag starting at $start (which must point at the
     * tag's leading "<"), correctly skipping over any ">" that appears
     * inside a single- or double-quoted attribute value - e.g.
     * <div title="a > b"> - rather than naively stopping at the first
     * ">" found anywhere after $start. A small, bounded, single-pass
     * scan - not a full HTML parser, but enough to never misidentify an
     * attribute's own ">" as the tag boundary.
     *
     * @param   string  $html
     * @param   int     $start
     *
     * @return  int|false
     */
    private function findTagEnd($html, $start)
    {
        $length = strlen($html);
        $quote  = null;

        for ($i = $start; $i < $length; $i++) {
            $ch = $html[$i];

            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '>') {
                return $i;
            }
        }

        return false;
    }

    /**
     * Decides what happens to a <script>/<style> block that the general
     * HTML scanning in processBuffer() treats as opaque (never parsed as
     * HTML, to avoid ever risking corruption of arbitrary third-party JS
     * or CSS - see the README's "Scope of protection" section for why).
     *
     * One narrow, deliberate exception: a <script type="application/
     * ld+json"> block is structured data, not executable code - safe to
     * json_decode(), clean matching email addresses out of its string
     * values, and json_encode() back, with zero risk of corrupting page
     * behaviour. Any address inside is always replaced with plain text
     * (the "replacement_text" parameter) regardless of the configured
     * replacement mode - an <img> tag has no meaning inside JSON.
     *
     * Everything else (ordinary <script> JS, <style> CSS) is left
     * completely untouched - by design, not oversight. When the
     * "audit_mode" parameter is enabled, such blocks are only ever
     * inspected for logging purposes (a warning naming the page URL,
     * written to logs/plg_system_fgemailremover_audit.php) - never
     * modified.
     *
     * @param   string  $blockHtml    The full <script>...</script> or <style>...</style> block, tags included.
     * @param   string  $replacement  The configured Text-mode replacement.
     *
     * @return  string
     */
    private function processSkipBlock($blockHtml, $replacement)
    {
        if (stripos($blockHtml, '<script') === 0) {
            $openTagEnd = $this->findTagEnd($blockHtml, 0);

            if ($openTagEnd !== false) {
                $openTag = substr($blockHtml, 0, $openTagEnd + 1);

                if (preg_match('/\btype\s*=\s*(["\'])application\/ld\+json\1/i', $openTag)) {
                    $closeTagPos = stripos($blockHtml, '</script>', $openTagEnd);

                    if ($closeTagPos !== false) {
                        $jsonText    = substr($blockHtml, $openTagEnd + 1, $closeTagPos - $openTagEnd - 1);
                        $cleanedJson = $this->processJsonLdScript($jsonText, $replacement);

                        return $openTag . $cleanedJson . substr($blockHtml, $closeTagPos);
                    }
                }
            }
        }

        if ((bool) $this->params->get('audit_mode', 0)) {
            $this->auditSkipBlock($blockHtml);
        }

        return $blockHtml;
    }

    /**
     * Safely removes email addresses from a <script type="application/
     * ld+json"> block's JSON content - decode, walk every string value,
     * replace matching addresses (respecting the whitelist), re-encode.
     * If the content isn't valid JSON, it's returned byte-for-byte
     * unchanged rather than risk corrupting it.
     *
     * @param   string  $jsonText     The script's raw text content (no tags).
     * @param   string  $replacement  The configured Text-mode replacement.
     *
     * @return  string
     */
    private function processJsonLdScript($jsonText, $replacement)
    {
        $data = json_decode($jsonText, true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $jsonText;
        }

        $changed = false;
        $data    = $this->stripEmailsFromJsonValue($data, $replacement, $changed);

        if (!$changed) {
            return $jsonText;
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? $jsonText : $encoded;
    }

    /**
     * Recursively walks a decoded JSON value, replacing any email
     * address found in a string value (unless whitelisted). Sets
     * $changed to true if anything was actually replaced, so the caller
     * can skip re-encoding (and its side effects, like key reordering
     * or whitespace changes) when nothing needed to change.
     *
     * @param   mixed   $value
     * @param   string  $replacement
     * @param   bool    $changed  Passed by reference.
     *
     * @return  mixed
     */
    private function stripEmailsFromJsonValue($value, $replacement, &$changed)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->stripEmailsFromJsonValue($item, $replacement, $changed);
            }

            return $value;
        }

        if (is_string($value) && strpos($value, '@') !== false) {
            $new = preg_replace_callback($this->emailRegex, function ($m) use ($replacement, &$changed) {
                if ($this->isWhitelisted($m[0])) {
                    return $m[0];
                }

                $changed = true;

                return $replacement;
            }, $value);

            if ($new !== null) {
                return $new;
            }
        }

        return $value;
    }

    /**
     * Writes one warning line to logs/plg_system_fgemailremover_audit.php
     * when a <script>/<style> block that the plugin deliberately never
     * modifies appears to contain an email address - only called when
     * the "audit_mode" parameter is enabled. Purely informational: never
     * changes the block's content.
     *
     * @param   string  $blockHtml
     *
     * @return  void
     */
    private function auditSkipBlock($blockHtml)
    {
        if (strpos($blockHtml, '@') === false) {
            return;
        }

        $tag = stripos($blockHtml, '<style') === 0 ? 'style' : 'script';

        Log::addLogger(
            ['text_file' => 'plg_system_fgemailremover_audit.php'],
            Log::WARNING,
            ['emailremover_audit']
        );

        Log::add(
            sprintf(
                'Possible email address left untouched inside a <%s> block (not parsed by design - see README) on %s',
                $tag,
                Uri::getInstance()->toString()
            ),
            Log::WARNING,
            'emailremover_audit'
        );
    }

    /**
     * Removes mailto: links and any remaining plain-text email addresses
     * from a chunk of HTML (guaranteed to contain no <script>/<style>
     * blocks - those are filtered out by processBuffer() beforehand) -
     * except addresses/domains listed as exceptions in the "whitelist"
     * parameter, which are left untouched.
     *
     * The plain-text pass deliberately never runs the regex over the
     * whole (potentially very large) chunk in one call - some hosts
     * configure a much lower pcre.backtrack_limit than PHP's default,
     * and even a backtracking-safe pattern can still fail on a large
     * subject under a low limit. Instead it jumps to each "@" with
     * strpos() and matches only a small bounded window around it - see
     * stripPlainTextEmails().
     *
     * @param   string  $html
     * @param   string  $replacement
     * @param   string  $mode  "text" or "image".
     *
     * @return  string
     */
    private function stripEmails($html, $replacement, $mode)
    {
        $html = $this->stripHiddenMailElements($html, $replacement, $mode);
        $html = $this->stripMailtoLinks($html, $replacement, $mode);

        return $this->stripPlainTextEmails($html, $replacement, $mode);
    }

    /**
     * Removes Joomla's own native <joomla-hidden-mail> web component
     * (introduced in Joomla 5/6 as com_contact's built-in email cloaking
     * - see /media/system/js/joomla-hidden-mail.js). The real address is
     * never present as literal text or a mailto: href anywhere in the
     * raw HTML - it's split into base64-encoded first/last/text
     * attributes on this custom element and only reassembled client-side
     * by JavaScript, so none of our other passes can ever find it. This
     * pass finds the element with plain strpos() (bounded, safe on any
     * page size), decodes the "text" attribute to get the real address
     * for whitelist checking, and replaces the whole element like any
     * other match.
     *
     * @param   string  $html
     * @param   string  $replacement
     * @param   string  $mode
     *
     * @return  string
     */
    private function stripHiddenMailElements($html, $replacement, $mode)
    {
        $result = '';
        $offset = 0;
        $length = strlen($html);

        while (($tagStart = stripos($html, '<joomla-hidden-mail', $offset)) !== false) {
            $tagOpenEnd = $this->findTagEnd($html, $tagStart);

            if ($tagOpenEnd === false) {
                // Malformed - no closing ">" for the opening tag itself;
                // copy the rest as-is and stop.
                $result .= substr($html, $offset);
                $offset  = $length;
                break;
            }

            $openTag     = substr($html, $tagStart, $tagOpenEnd - $tagStart + 1);
            $closeTagPos = stripos($html, '</joomla-hidden-mail>', $tagOpenEnd);

            $result .= substr($html, $offset, $tagStart - $offset);

            if ($closeTagPos === false) {
                // No closing tag found - copy the opening tag as-is and
                // continue scanning after it, rather than risk mangling
                // the remainder of the document.
                $result .= $openTag;
                $offset  = $tagOpenEnd + 1;
                continue;
            }

            $matchEnd = $closeTagPos + strlen('</joomla-hidden-mail>');
            $address  = $this->decodeHiddenMailAddress($openTag);

            if ($address === null) {
                // Couldn't parse a real address out of it - leave
                // untouched rather than guess.
                $result .= substr($html, $tagStart, $matchEnd - $tagStart);
            } else {
                $result .= $this->isWhitelisted($address)
                    ? substr($html, $tagStart, $matchEnd - $tagStart)
                    : $this->buildReplacement($address, $replacement, $mode);
            }

            $offset = $matchEnd;
        }

        $result .= substr($html, $offset);

        return $result;
    }

    /**
     * Extracts and base64-decodes the real address from a
     * <joomla-hidden-mail ...> opening tag's "text" attribute - only
     * when the tag is actually marked as an email (is-email="1"), so we
     * never touch non-email uses of the same web component.
     *
     * @param   string  $openTag  The opening tag, e.g. '<joomla-hidden-mail is-email="1" text="...">'.
     *
     * @return  string|null
     */
    private function decodeHiddenMailAddress($openTag)
    {
        if (!preg_match('/\bis-email\s*=\s*"1"/i', $openTag)) {
            return null;
        }

        if (!preg_match('/\btext\s*=\s*"([^"]*)"/i', $openTag, $m)) {
            return null;
        }

        $decoded = base64_decode($m[1], true);

        if ($decoded === false || strpos($decoded, '@') === false) {
            return null;
        }

        return $decoded;
    }

    /**
     * Removes Joomla core's classic <span id="cloakHASH">...</span> +
     * <script> email-cloaking construct (the older `email.cloak` helper,
     * still possibly used on some Joomla 4/5 sites alongside or instead
     * of the newer <joomla-hidden-mail> component - e.g. com_contact's
     * own contact-detail email field). The real address is never
     * present as literal text anywhere in the raw HTML - it's assembled
     * by JavaScript from string-concatenated, numeric-HTML-entity-
     * encoded fragments (so not even a literal "@" appears - "@" is
     * always "&#64;", five characters, none of them the "@" byte
     * itself), meaning none of the plugin's other passes can ever find
     * it.
     *
     * The id="cloakHASH" match is deliberately lenient about
     * serialisation - it doesn't assume "id" is the span's first or
     * only attribute, or that the value is double-quoted - since the
     * whole point is matching Joomla core's own generated markup
     * reliably, not one specific way of writing it by hand. This pass
     * locates the span+script pair with plain strpos()/stripos() plus
     * the findTagNameStart()/findTagEnd() tag-boundary helpers (bounded,
     * safe on any page size), decodes the assembled address for
     * whitelist checking, and replaces the whole construct like any
     * other match.
     *
     * @param   string  $html
     * @param   string  $replacement
     * @param   string  $mode
     *
     * @return  string
     */
    private function stripClassicCloakElements($html, $replacement, $mode)
    {
        $result = '';
        $offset = 0;
        $length = strlen($html);

        while (($spanStart = $this->findTagNameStart($html, 'span', $offset)) !== false) {
            $spanOpenEnd = $this->findTagEnd($html, $spanStart);

            if ($spanOpenEnd === false) {
                $result .= substr($html, $offset);
                $offset  = $length;
                break;
            }

            $openTag = substr($html, $spanStart, $spanOpenEnd - $spanStart + 1);

            // "id" doesn't have to be the first/only attribute, and its
            // value can be single- or double-quoted - match either, and
            // look for it anywhere in the tag rather than assuming a
            // fixed attribute order.
            if (!preg_match('/\bid\s*=\s*(["\'])cloak([a-z0-9]+)\1/i', $openTag, $hashMatch)) {
                // Not actually a recognisable cloak span - copy just this
                // opening tag and keep scanning after it.
                $result .= substr($html, $offset, $spanOpenEnd + 1 - $offset);
                $offset  = $spanOpenEnd + 1;
                continue;
            }

            $hash         = $hashMatch[2];
            $spanClosePos = stripos($html, '</span>', $spanOpenEnd);

            if ($spanClosePos === false) {
                $result .= substr($html, $offset, $spanOpenEnd + 1 - $offset);
                $offset  = $spanOpenEnd + 1;
                continue;
            }

            $spanEnd = $spanClosePos + strlen('</span>');

            // The matching <script> block should follow shortly after
            // (allow a little slack for whitespace between them).
            $scriptStart = $this->findTagNameStart($html, 'script', $spanEnd);

            if ($scriptStart === false || $scriptStart - $spanEnd > 50) {
                $result .= substr($html, $offset, $spanEnd - $offset);
                $offset  = $spanEnd;
                continue;
            }

            $scriptOpenEnd  = $this->findTagEnd($html, $scriptStart);
            $scriptClosePos = $scriptOpenEnd !== false ? stripos($html, '</script>', $scriptOpenEnd) : false;

            if ($scriptOpenEnd === false || $scriptClosePos === false) {
                $result .= substr($html, $offset, $spanEnd - $offset);
                $offset  = $spanEnd;
                continue;
            }

            $scriptContent = substr($html, $scriptOpenEnd + 1, $scriptClosePos - $scriptOpenEnd - 1);

            // Confirm this script actually references our hash - i.e. it
            // really is the cloak script for this exact span, not some
            // unrelated <script> that merely happens to follow it.
            if (strpos($scriptContent, $hash) === false) {
                $result .= substr($html, $offset, $spanEnd - $offset);
                $offset  = $spanEnd;
                continue;
            }

            $matchEnd = $scriptClosePos + strlen('</script>');

            $result .= substr($html, $offset, $spanStart - $offset);

            $address = $this->decodeClassicCloak($scriptContent, $hash);

            if ($address === null) {
                // Couldn't decode a real address out of it - leave
                // untouched rather than guess.
                $result .= substr($html, $spanStart, $matchEnd - $spanStart);
            } else {
                $result .= $this->isWhitelisted($address)
                    ? substr($html, $spanStart, $matchEnd - $spanStart)
                    : $this->buildReplacement($address, $replacement, $mode);
            }

            $offset = $matchEnd;
        }

        $result .= substr($html, $offset);

        return $result;
    }

    /**
     * Decodes the real address out of a classic Joomla email.cloak
     * script's "addyHASH" variable - which is built as one or more
     * quoted-string-literal concatenations, e.g.
     * `var addyXYZ = 'a&#64;' + 'b';` possibly followed by
     * `addyXYZ = addyXYZ + '&#46;c';` continuation lines. Deliberately
     * matches the quoted-string-literal sequence itself (not "everything
     * up to the next semicolon"), because the numeric HTML entities
     * inside those literals (e.g. "&#97;") themselves contain a literal
     * ";" - naively scanning to the first ";" would cut the match short
     * partway through an entity.
     *
     * @param   string  $scriptContent
     * @param   string  $hash
     *
     * @return  string|null
     */
    private function decodeClassicCloak($scriptContent, $hash)
    {
        $varName = 'addy' . preg_quote($hash, '/');
        $strSeq  = '((?:\s*\'(?:[^\'\\\\]|\\\\.)*\'\s*\+?)+)';

        if (!preg_match('/\bvar\s+' . $varName . '\s*=\s*' . $strSeq . '\s*;/s', $scriptContent, $m)) {
            return null;
        }

        $assembled = $this->extractConcatenatedStringLiterals($m[1]);

        if (preg_match_all('/\b' . $varName . '\s*=\s*' . $varName . '\s*\+\s*' . $strSeq . '\s*;/s', $scriptContent, $mm)) {
            foreach ($mm[1] as $rhs) {
                $assembled .= $this->extractConcatenatedStringLiterals($rhs);
            }
        }

        $decoded = html_entity_decode($assembled, ENT_QUOTES | ENT_HTML401, 'UTF-8');

        return strpos($decoded, '@') !== false ? $decoded : null;
    }

    /**
     * Extracts and concatenates every single-quoted string literal found
     * in a JS expression fragment, e.g. `'a' + 'b' + 'c'` -> "abc".
     *
     * @param   string  $expr
     *
     * @return  string
     */
    private function extractConcatenatedStringLiterals($expr)
    {
        $result = '';

        if (preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'/', $expr, $parts)) {
            foreach ($parts[1] as $part) {
                $result .= stripslashes($part);
            }
        }

        return $result;
    }

    /**
     * Removes plain-text email addresses by jumping to each "@" with
     * strpos() and running the email regex only on a small bounded
     * window around it (not the whole chunk) - so a single preg_match()
     * call is always cheap and cannot hit any PCRE resource limit,
     * regardless of how large the overall page is.
     *
     * @param   string  $html
     * @param   string  $replacement
     * @param   string  $mode
     *
     * @return  string
     */
    private function stripPlainTextEmails($html, $replacement, $mode)
    {
        $windowRadius = 100;
        $length       = strlen($html);
        $result       = '';
        $offset       = 0;

        while (($atPos = strpos($html, '@', $offset)) !== false) {
            $windowStart   = max($offset, $atPos - $windowRadius);
            $windowEnd     = min($length, $atPos + $windowRadius);
            $window        = substr($html, $windowStart, $windowEnd - $windowStart);
            $atPosInWindow = $atPos - $windowStart;

            $matched = false;

            if (preg_match($this->emailRegex, $window, $m, PREG_OFFSET_CAPTURE)) {
                $matchStart = $m[0][1];
                $matchEnd   = $matchStart + strlen($m[0][0]);

                // Only accept it if the match actually covers the "@" we
                // jumped to (the window can contain other, unrelated "@"
                // occurrences too).
                if ($matchStart <= $atPosInWindow && $matchEnd > $atPosInWindow) {
                    $matchStartAbs = $windowStart + $matchStart;
                    $matchEndAbs   = $windowStart + $matchEnd;
                    $address       = $m[0][0];

                    $result .= substr($html, $offset, $matchStartAbs - $offset);

                    if ($this->isWhitelisted($address)) {
                        $result .= $address;
                    } elseif ($this->isInsideTagAttribute($html, $matchStartAbs)) {
                        // Inside a tag's attribute value (e.g. a <meta>
                        // description, a title="..." attribute) - never
                        // inject markup here, it would break the tag.
                        // Just remove the address as plain text, no
                        // matter what replacement/mode is configured.
                        $result .= '';
                    } else {
                        $result .= $this->buildReplacement($address, $replacement, $mode);
                    }

                    $offset  = $matchEndAbs;
                    $matched = true;
                }
            }

            if (!$matched) {
                // No valid email around this "@" - copy through it and
                // keep scanning for the next one.
                $result .= substr($html, $offset, $atPos - $offset + 1);
                $offset  = $atPos + 1;
            }
        }

        $result .= substr($html, $offset);

        return $result;
    }

    /**
     * Checks whether byte offset $position falls inside an open HTML
     * tag (i.e. between an unclosed "<" and its ">") rather than in the
     * normal visible text between tags - a cheap, non-parser heuristic
     * good enough to tell "am I inside some tag's attribute value"
     * (meta content, title=, alt=, data-*=, ...) apart from ordinary
     * page text, so markup is never injected into an attribute value.
     *
     * @param   string  $html
     * @param   int     $position
     *
     * @return  bool
     */
    private function isInsideTagAttribute($html, $position)
    {
        $before    = substr($html, 0, $position);
        $lastOpen  = strrpos($before, '<');
        $lastClose = strrpos($before, '>');

        return $lastOpen !== false && ($lastClose === false || $lastOpen > $lastClose);
    }

    /**
     * Removes mailto: links from a chunk of HTML. Finds each opening
     * <a ... href="mailto:..."> (or unquoted href=mailto:...) tag with
     * a tightly bounded regex (cannot span past the tag's closing ">"),
     * strips any mailto query string (?subject=..., ?body=..., etc.) so
     * only the real address is used for whitelist checks and as the
     * replacement's content, then locates the matching </a> with plain
     * stripos() rather than a DOTALL lazy wildcard - so a page with many
     * <a> tags cannot blow the PCRE backtrack limit.
     *
     * Known, deliberate limitation: this looks for the *first* </a>
     * after the opening tag. Real-world HTML from Joomla/browsers never
     * nests <a> elements (nesting anchors is invalid HTML and rendered
     * inconsistently across browsers anyway), so this is not expected
     * to matter in practice - but a page with genuinely malformed,
     * nested anchor markup could see the wrong closing tag matched.
     *
     * @param   string  $html
     * @param   string  $replacement
     * @param   string  $mode
     *
     * @return  string
     */
    private function stripMailtoLinks($html, $replacement, $mode)
    {
        $result = '';
        $offset = 0;
        $length = strlen($html);

        while ($offset < $length && preg_match($this->mailtoOpenTagRegex, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $tagStart = $m[0][1];
            $tagEnd   = $tagStart + strlen($m[0][0]);

            // Exactly one of the quoted (group 2) / unquoted (group 3)
            // forms participated - PHP drops a wholly-unmatched
            // trailing group from the array entirely, so check
            // isset() before indexing.
            $rawAddr = '';

            if (isset($m[2]) && $m[2][0] !== '') {
                $rawAddr = $m[2][0];
            } elseif (isset($m[3]) && $m[3][0] !== '') {
                $rawAddr = $m[3][0];
            }

            // A mailto: href can carry additional headers after the
            // address - e.g. "mailto:info@x.sk?subject=Hello&body=..."
            // - which are not part of the address itself and must not
            // be treated as such (for whitelist matching, or as the
            // visible/alt text of the replacement).
            $queryPos = strpos($rawAddr, '?');
            $rawAddr  = $queryPos !== false ? substr($rawAddr, 0, $queryPos) : $rawAddr;

            $mailAddr = rawurldecode($rawAddr);

            $result .= substr($html, $offset, $tagStart - $offset);

            $closeTagPos = stripos($html, '</a>', $tagEnd);

            if ($closeTagPos === false) {
                // No closing </a> found - copy the rest as-is and stop.
                $result .= substr($html, $tagStart);
                $offset  = $length;
                break;
            }

            $matchEnd = $closeTagPos + 4;

            $result .= $this->isWhitelisted($mailAddr)
                ? substr($html, $tagStart, $matchEnd - $tagStart)
                : $this->buildReplacement($mailAddr, $replacement, $mode);

            $offset = $matchEnd;
        }

        $result .= substr($html, $offset);

        return $result;
    }

    /**
     * Builds the replacement markup for a single removed address: either
     * the configured replacement text/HTML as-is, or (when $mode is
     * "image") an <img> tag pointing at a cached, generated PNG that
     * visually shows the address as pixels - so no literal address text
     * ever reaches the page source. Falls back to the text replacement
     * if image generation isn't available or fails for any reason.
     *
     * The <img> tag deliberately carries both the HTML width/height
     * attributes AND matching fixed-pixel width/height in its inline
     * style (plus max-width:none) - these are small text-rendering
     * images, not photos, and should never be stretched by a site's
     * generic "responsive image" CSS (img{max-width:100%;height:auto}),
     * template rules, or third-party lazy-loading libraries reacting to
     * a class like "lazy" - inline pixel dimensions reliably win over
     * that kind of low-specificity external CSS.
     *
     * Alt text comes from its own "image_alt_text" parameter (separate
     * from "replacement_text", which is Text-mode-only and hidden via
     * showon when in Image mode) - each mode's admin field is now shown
     * only when relevant, instead of one field silently double-booked
     * for two different jobs depending on the mode.
     *
     * @param   string  $address      The real email address (for the image content).
     * @param   string  $replacement  The configured Text-mode replacement (used as a fallback if image generation fails).
     * @param   string  $mode         "text" or "image".
     *
     * @return  string
     */
    private function buildReplacement($address, $replacement, $mode)
    {
        if ($mode !== 'image') {
            return $replacement;
        }

        $imageInfo = $this->getEmailImageInfo($address);

        if ($imageInfo === null) {
            return $replacement;
        }

        $altText  = $this->stripSelfEmails(trim((string) $this->params->get('image_alt_text', '')));
        $alt      = $altText !== '' ? $altText : 'E-mailová adresa';
        $cssClass = trim((string) $this->params->get('image_css_class', ''));
        $classes  = 'emailremover-img' . ($cssClass !== '' ? ' ' . $cssClass : '');
        $width    = (int) $imageInfo['width'];
        $height   = (int) $imageInfo['height'];

        return '<img src="' . htmlspecialchars($imageInfo['url'], ENT_QUOTES, 'UTF-8') . '"'
            . ' alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"'
            . ' width="' . $width . '" height="' . $height . '"'
            . ' class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"'
            . ' style="vertical-align:middle;max-width:none;width:' . $width . 'px;height:' . $height . 'px;">';
    }

    /**
     * Returns the public URL and pixel dimensions of a cached PNG image
     * showing $email as pixels (generated once per unique address and
     * reused after that), or null if GD isn't available or the image
     * could not be created/read - callers should fall back to the text
     * replacement in that case. Dimensions come from getimagesize() on
     * the actual file on disk, so they're always accurate regardless of
     * which rendering path (bitmap font or TTF) produced it.
     *
     * getimagesize() is checked even on what looks like a cache hit
     * (not just after generating): if a file exists at the cache path
     * but turns out to be missing/corrupt - e.g. left behind by an
     * interrupted write, or damaged some other way - this regenerates
     * it rather than permanently giving up on that address the moment
     * one bad file happens to exist there. See renderEmailImageAtomic()
     * for how the actual write avoids ever exposing a partially-written
     * file to a concurrent request in the first place.
     *
     * @param   string  $email
     *
     * @return  array{url: string, width: int, height: int}|null
     */
    private function getEmailImageInfo($email)
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            return null;
        }

        $cacheKey = md5(
            mb_strtolower($email) . '|'
            . trim((string) $this->params->get('image_font_path', '')) . '|'
            . (string) $this->params->get('image_font_size', 14) . '|'
            . 'r' . self::IMAGE_RENDERER_VERSION
        );

        $relativeDir  = '/images/fgemailremover_cache';
        $relativePath = $relativeDir . '/' . $cacheKey . '.png';
        $absoluteDir  = JPATH_ROOT . $relativeDir;
        $absolutePath = JPATH_ROOT . $relativePath;

        $size = is_file($absolutePath) ? @getimagesize($absolutePath) : false;

        if ($size === false) {
            if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
                return null;
            }

            $size = $this->renderEmailImageAtomic($email, $absoluteDir, $absolutePath);

            if ($size === false) {
                return null;
            }
        }

        return [
            'url'    => rtrim(Uri::root(true), '/') . $relativePath,
            'width'  => $size[0],
            'height' => $size[1],
        ];
    }

    /**
     * Renders $email to a uniquely-named temporary file inside
     * $absoluteDir, validates it with getimagesize(), and only once
     * confirmed valid atomically rename()s it onto $absolutePath - so a
     * concurrent request reading $absolutePath can never observe a
     * partially-written or corrupt file. rename() on the same
     * filesystem is atomic (POSIX): at every point in time the path
     * holds either the previous complete file (if any) or the fully
     * written new one, never something in between - unlike writing
     * imagepng() output directly to $absolutePath, where a second
     * request's is_file()/getimagesize() could otherwise land in the
     * middle of the first request's still-in-progress write.
     *
     * If two requests race to generate the same image, both produce
     * byte-identical output (same address, same font settings), so
     * whichever rename() ends up "winning" is immaterial - and
     * tempnam() guarantees each request writes to its own uniquely
     * named file, so they never collide with each other mid-write
     * either.
     *
     * @param   string  $email
     * @param   string  $absoluteDir   Must already exist and be writable.
     * @param   string  $absolutePath  Final cache file path.
     *
     * @return  array|false  getimagesize() result for the published file, or false on failure.
     */
    private function renderEmailImageAtomic($email, $absoluteDir, $absolutePath)
    {
        $tempPath = @tempnam($absoluteDir, 'tmp_');

        if ($tempPath === false) {
            return false;
        }

        if (!$this->renderEmailImage($email, $tempPath)) {
            @unlink($tempPath);

            return false;
        }

        $size = @getimagesize($tempPath);

        if ($size === false) {
            @unlink($tempPath);

            return false;
        }

        if (!@rename($tempPath, $absolutePath)) {
            @unlink($tempPath);

            return false;
        }

        return $size;
    }

    /**
     * Renders $email as pixels into a small transparent-background PNG.
     * Uses a TrueType font (imagettftext) if the "image_font_path"
     * parameter points at a readable .ttf/.otf file and GD has freetype
     * support - so the image can match the site's own font. Falls back
     * to GD's built-in bitmap font (no external file needed) otherwise,
     * which always works on any host with plain GD.
     *
     * @param   string  $email
     * @param   string  $absolutePath  Full filesystem path to write the PNG to.
     *
     * @return  bool
     */
    private function renderEmailImage($email, $absolutePath)
    {
        $fontPath = trim((string) $this->params->get('image_font_path', ''));

        if ($fontPath !== '' && function_exists('imagettftext') && function_exists('imagettfbbox')) {
            $absoluteFontPath = $this->resolveFontPath($fontPath);

            if ($absoluteFontPath !== null) {
                $fontSize = (float) $this->params->get('image_font_size', 14);

                if ($this->renderEmailImageTtf($email, $absolutePath, $absoluteFontPath, $fontSize)) {
                    return true;
                }
                // TTF rendering failed for some reason - fall through to
                // the bitmap font rather than leaving no image at all.
            }
        }

        return $this->renderEmailImageBitmap($email, $absolutePath);
    }

    /**
     * Resolves the "image_font_path" parameter to an actual readable
     * file - accepts either an absolute filesystem path or one relative
     * to the Joomla root (e.g. "/media/fonts/Poppins-Regular.ttf").
     *
     * @param   string  $fontPath
     *
     * @return  string|null
     */
    private function resolveFontPath($fontPath)
    {
        if (is_file($fontPath)) {
            return $fontPath;
        }

        $candidate = JPATH_ROOT . '/' . ltrim($fontPath, '/');

        return is_file($candidate) ? $candidate : null;
    }

    /**
     * Renders $email using a TrueType font.
     *
     * @param   string  $email
     * @param   string  $absolutePath
     * @param   string  $fontFile
     * @param   float   $fontSize
     *
     * @return  bool
     */
    private function renderEmailImageTtf($email, $absolutePath, $fontFile, $fontSize)
    {
        $bbox = @imagettfbbox($fontSize, 0, $fontFile, $email);

        if ($bbox === false) {
            return false;
        }

        $minX = min($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
        $maxX = max($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
        $minY = min($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
        $maxY = max($bbox[1], $bbox[3], $bbox[5], $bbox[7]);

        $paddingX = 6;
        $paddingY = 6;
        $width    = ($maxX - $minX) + $paddingX * 2;
        $height   = ($maxY - $minY) + $paddingY * 2;

        $image = @imagecreatetruecolor((int) $width, (int) $height);

        if ($image === false) {
            return false;
        }

        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        $textColor = imagecolorallocate($image, 51, 51, 51);
        // imagettftext()'s y coordinate is the text BASELINE, not the top
        // - offset by -minX/-minY so the glyphs' own bounding box lands
        // at (paddingX, paddingY) regardless of the font's own metrics.
        $x = $paddingX - $minX;
        $y = $paddingY - $minY;

        $written = @imagettftext($image, $fontSize, 0, (int) $x, (int) $y, $textColor, $fontFile, $email)
            && @imagepng($image, $absolutePath);

        imagedestroy($image);

        return (bool) $written;
    }

    /**
     * Renders $email using GD's built-in bitmap font - no external font
     * file needed, works on any host with plain GD (no freetype
     * required). Used when no TTF font is configured, or as a fallback
     * if TTF rendering fails.
     *
     * @param   string  $email
     * @param   string  $absolutePath
     *
     * @return  bool
     */
    private function renderEmailImageBitmap($email, $absolutePath)
    {
        $font       = 5;
        $paddingX   = 6;
        $paddingY   = 4;
        $textWidth  = imagefontwidth($font) * strlen($email);
        $textHeight = imagefontheight($font);
        $width      = $textWidth + $paddingX * 2;
        $height     = $textHeight + $paddingY * 2;

        $image = @imagecreatetruecolor($width, $height);

        if ($image === false) {
            return false;
        }

        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        $textColor = imagecolorallocate($image, 51, 51, 51);
        imagestring($image, $font, $paddingX, $paddingY, $email, $textColor);

        $written = @imagepng($image, $absolutePath);
        imagedestroy($image);

        return $written;
    }

    /**
     * Checks whether an email address is exempt from removal, either as an
     * exact address match or via a whole-domain entry (e.g. "@example.sk").
     *
     * A mailto: URI can list multiple recipients separated by commas
     * per RFC 6068 - e.g. "mailto:a@x.sk,b@y.sk" - and a single valid
     * email address can never itself contain a literal comma, so
     * splitting on "," is always safe. When $email contains one or
     * more commas, every individual address must be whitelisted for
     * the whole thing to count as whitelisted - if even one recipient
     * isn't, the link is still replaced (leaving it untouched would
     * expose that address).
     *
     * @param   string  $email
     *
     * @return  bool
     */
    private function isWhitelisted($email)
    {
        $whitelist = $this->getWhitelist();

        if (!$whitelist) {
            return false;
        }

        if (strpos($email, ',') !== false) {
            foreach (explode(',', $email) as $part) {
                if (!$this->isWhitelisted(trim($part))) {
                    return false;
                }
            }

            return true;
        }

        $email  = mb_strtolower(trim($email));
        $atPos  = strrpos($email, '@');
        $domain = $atPos !== false ? substr($email, $atPos + 1) : '';

        foreach ($whitelist as $entry) {
            if ($entry === $email) {
                return true;
            }

            if ($domain !== '' && $entry !== '' && $entry[0] === '@' && substr($entry, 1) === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cached, parsed whitelist entries - see getWhitelist().
     *
     * @var array|null
     */
    private $whitelistCache;

    /**
     * Parses the "whitelist" parameter (one entry per line, or
     * comma-separated) into a lower-cased array, cached per plugin
     * instance for the request.
     *
     * @return  array
     */
    private function getWhitelist()
    {
        if ($this->whitelistCache === null) {
            $this->whitelistCache = [];
            $raw                  = (string) $this->params->get('whitelist', '');

            foreach (preg_split('/[\r\n,]+/', $raw) as $line) {
                $line = mb_strtolower(trim($line));

                if ($line !== '') {
                    $this->whitelistCache[] = $line;
                }
            }
        }

        return $this->whitelistCache;
    }
}
