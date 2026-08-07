<?php

/**
 * @package     System.Fgemailremover
 * @subpackage  plg_system_fgemailremover
 * @version     1.1.0
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
     * Matches only the OPENING <a ... href="mailto:..."> tag, up to and
     * including the tag's closing ">". Deliberately bounded (cannot span
     * past the next ">" or the matching quote), unlike matching the whole
     * <a>...</a> element with a DOTALL lazy wildcard, which on a page with
     * many <a> tags can exceed PHP's PCRE backtrack limit and make
     * preg_replace_callback() silently fail. The corresponding closing
     * </a> is located separately with plain strpos() - see
     * stripMailtoLinks().
     *
     * @var string
     */
    private $mailtoOpenTagRegex = '#<a\b[^>]*\bhref\s*=\s*(["\'])mailto:([^"\']*)\1[^>]*>#i';

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

        // Only ever touch the public front-end - never the admin area,
        // so editors can still see/edit real addresses in the backend.
        if ($app->isClient('administrator')) {
            return;
        }

        $document = Factory::getDocument();

        if (!method_exists($document, 'getType') || $document->getType() !== 'html') {
            return;
        }

        $buffer = $app->getBody();

        // Cheap early exit - nothing to do if there is neither a plain
        // "@" (mailto: links, plain-text addresses) nor Joomla's own
        // <joomla-hidden-mail> web component (which encodes the address
        // as base64 - an alphabet that never contains "@" at all, so it
        // needs its own check here).
        if (strpos($buffer, '@') === false && stripos($buffer, '<joomla-hidden-mail') === false) {
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
        $replacement = (string) $this->params->get('replacement_text', '');
        $mode        = (string) $this->params->get('replacement_mode', 'text');
        $length      = strlen($html);
        $out         = '';
        $pos         = 0;

        while ($pos < $length) {
            $skip = $this->findNextSkipBlock($html, $pos);

            if ($skip === null) {
                $out .= $this->stripEmails(substr($html, $pos), $replacement, $mode);
                break;
            }

            [$skipStart, $skipEnd] = $skip;

            // Process (and strip emails from) everything before the skip block.
            $out .= $this->stripEmails(substr($html, $pos, $skipStart - $pos), $replacement, $mode);

            // Copy the <script>/<style> block itself untouched.
            $out .= substr($html, $skipStart, $skipEnd - $skipStart);

            $pos = $skipEnd;
        }

        return $out;
    }

    /**
     * Finds the next <script>...</script> or <style>...</style> block at
     * or after byte offset $from, using plain string search only - no
     * regex, so there is no backtracking cost regardless of how many such
     * blocks the page contains.
     *
     * @param   string  $html
     * @param   int     $from
     *
     * @return  array{0:int,1:int}|null  [startOffset, endOffsetExclusive], or null if none found.
     */
    private function findNextSkipBlock($html, $from)
    {
        $scriptStart = stripos($html, '<script', $from);
        $styleStart  = stripos($html, '<style', $from);

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

        $tagOpenEnd = strpos($html, '>', $start);

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
            $tagOpenEnd = strpos($html, '>', $tagStart);

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
     * <a ... href="mailto:..."> tag with a tightly bounded regex
     * (cannot span past the tag's closing ">"), then locates the
     * matching </a> with plain stripos() rather than a DOTALL lazy
     * wildcard - so a page with many <a> tags cannot blow the PCRE
     * backtrack limit.
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
            $mailAddr = rawurldecode($m[2][0]);

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
     * @param   string  $address      The real email address (for the image content).
     * @param   string  $replacement  The configured text/HTML replacement (also used as image alt text).
     * @param   string  $mode         "text" or "image".
     *
     * @return  string
     */
    private function buildReplacement($address, $replacement, $mode)
    {
        if ($mode !== 'image') {
            return $replacement;
        }

        $imageUrl = $this->getEmailImageUrl($address);

        if ($imageUrl === null) {
            return $replacement;
        }

        $alt      = $replacement !== '' ? $replacement : 'E-mailová adresa';
        $cssClass = trim((string) $this->params->get('image_css_class', 'noshadow'));
        $classes  = 'emailremover-img' . ($cssClass !== '' ? ' ' . $cssClass : '');

        return '<img src="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '"'
            . ' alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '"'
            . ' class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '" style="vertical-align:middle;">';
    }

    /**
     * Returns the public URL of a cached PNG image showing $email as
     * pixels (generated once per unique address and reused after that),
     * or null if GD isn't available or the image could not be created -
     * callers should fall back to the text replacement in that case.
     *
     * @param   string  $email
     *
     * @return  string|null
     */
    private function getEmailImageUrl($email)
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            return null;
        }

        $cacheKey = md5(
            mb_strtolower($email) . '|'
            . trim((string) $this->params->get('image_font_path', '')) . '|'
            . (string) $this->params->get('image_font_size', 14)
        );

        $relativeDir  = '/images/fgemailremover_cache';
        $relativePath = $relativeDir . '/' . $cacheKey . '.png';
        $absoluteDir  = JPATH_ROOT . $relativeDir;
        $absolutePath = JPATH_ROOT . $relativePath;

        if (is_file($absolutePath)) {
            return rtrim(Uri::root(true), '/') . $relativePath;
        }

        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            return null;
        }

        if (!$this->renderEmailImage($email, $absolutePath)) {
            return null;
        }

        return rtrim(Uri::root(true), '/') . $relativePath;
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
