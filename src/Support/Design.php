<?php
/**
 * Copyright (c) WebDeviAnt Studios.
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at
 * https://opensource.org/licenses/AFL-3.0
 */

namespace NitroSearch\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

use NitroSearch\Settings;

/**
 * Resolves the merchant's appearance choices into widget design tokens.
 *
 * THE PRESET NAMES NEVER LEAVE THIS FILE. "Roomy", "compact", "dark" are
 * vocabulary for the settings screen; what the widget receives is `--ns-*` token
 * VALUES. That split is what lets one shared bundle serve every shop on every
 * platform without learning a single preset name — and it means adding a preset
 * here needs no widget release.
 *
 * WHAT IS EMITTED, STATED ACCURATELY: the four density tokens ALWAYS go, because
 * the look is a complete set and sending three of four would leave the fourth at
 * whatever the widget's default happens to be — a combination nobody chose.
 * Everything else is emitted only when it differs from the widget's own default,
 * so a shop on the defaults sends four short strings and nothing more.
 *
 * (The neighbouring WooCommerce implementation carries a comment claiming the
 * default case emits nothing at all. It does not, and never did — same loop,
 * same four tokens. Repeated here as a correction rather than copied, because a
 * comment that overstates what the code does is the kind of thing that gets
 * believed later.)
 */
final class Design
{
    /** Density tokens per look. Keys map to `--ns-*` custom properties. */
    private static $looks = array(
        // The default: two-line names and a thumbnail big enough to recognise.
        'roomy' => array('thumb' => '48px', 'rowPad' => '10px', 'nameLines' => '2', 'size' => '14px'),
        // ~40% more rows before scrolling, for long catalogues of short names.
        'compact' => array('thumb' => '36px', 'rowPad' => '6px', 'nameLines' => '1', 'size' => '13px'),
        // Image-led shops: a bigger picture inside the same row, no second layout.
        'images' => array('thumb' => '72px', 'rowPad' => '12px', 'nameLines' => '2', 'size' => '14px'),
        // No thumbnails at all — B2B, spares, or shops without good photography.
        'text' => array('thumb' => '0px', 'rowPad' => '8px', 'nameLines' => '2', 'size' => '14px'),
    );

    private static $corners = array('rounded' => '12px', 'soft' => '6px', 'square' => '0');

    /** Panel/text/chrome colours for the non-custom schemes. */
    private static $schemes = array(
        'dark' => array(
            'bg' => '#111827', 'text' => '#f9fafb', 'muted' => '#9ca3af',
            'border' => '#374151', 'chipBg' => '#1f2937', 'surface2' => '#1f2937',
        ),
    );

    /**
     * The `theme` half of the widget config.
     *
     * @return array<string, string|bool>
     */
    public static function theme()
    {
        $theme = array();

        $look = (string) Settings::get('DESIGN_LOOK', 'roomy');
        $tokens = isset(self::$looks[$look]) ? self::$looks[$look] : self::$looks['roomy'];
        foreach ($tokens as $token => $value) {
            $theme[$token] = $value;
        }

        $scheme = (string) Settings::get('DESIGN_SCHEME', 'light');
        if ($scheme === 'dark') {
            $theme = array_merge($theme, self::$schemes['dark']);
        } elseif ($scheme === 'auto') {
            // The widget carries the dark palette for this one case and applies it
            // behind prefers-color-scheme. Sending ten more tokens could not work:
            // the switch has to happen in CSS, on the shopper's own device, because
            // only the device knows which mode it is in at render time.
            $theme['autoDark'] = true;
        }

        $corner = (string) Settings::get('DESIGN_CORNERS', 'rounded');
        if ($corner !== 'rounded' && isset(self::$corners[$corner])) {
            $theme['radius'] = self::$corners[$corner];
        }

        $accent = self::hex((string) Settings::get('DESIGN_ACCENT', ''));
        if ($accent !== '') {
            $theme['accent'] = $accent;
            // Label text on the accent is decided HERE, not by the widget: the
            // widget would have to ship a colour-contrast routine to work it out,
            // and it is one boolean we already know the answer to.
            $theme['onAccent'] = self::isLight($accent) ? '#111827' : '#ffffff';
        }

        return $theme;
    }

    /**
     * The `layout` half: behaviour the widget decides in JS rather than CSS.
     *
     * @return array<string, string|int>
     */
    public static function layout()
    {
        $layout = array();

        $width = (string) Settings::get('DESIGN_WIDTH', 'auto');
        if (in_array($width, array('wide', 'match'), true)) {
            $layout['width'] = $width;
        }

        $perPage = (int) Settings::get('DESIGN_PER_PAGE', 8);
        if ($perPage > 0 && $perPage !== 8) {
            $layout['perPage'] = max(2, min(20, $perPage));
        }

        $filters = (string) Settings::get('DESIGN_FILTERS', 'auto');
        if (in_array($filters, array('top', 'off'), true)) {
            $layout['facets'] = $filters;
        }

        return $layout;
    }

    /**
     * Accept only a literal hex colour.
     *
     * These values are interpolated into CSS custom properties on a live
     * storefront, so anything that is not unambiguously a colour is dropped
     * rather than escaped — there is no legitimate merchant input here that
     * needs `url(`, a semicolon, or a closing brace.
     *
     * @param string $value
     *
     * @return string '' when it is not a colour
     */
    private static function hex($value)
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if ($value[0] !== '#') {
            $value = '#' . $value;
        }

        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) ? strtolower($value) : '';
    }

    /**
     * WCAG relative luminance, used only to choose black or white label text.
     *
     * @param string $hex
     *
     * @return bool
     */
    private static function isLight($hex)
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return true;
        }

        $channels = array();
        foreach (array(0, 2, 4) as $offset) {
            $c = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }

        $luminance = (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);

        return $luminance > 0.179;
    }
}
