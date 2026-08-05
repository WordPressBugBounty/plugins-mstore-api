<?php
/**
 * Shared icon helper utilities.
 *
 * @package MStore API
 */

defined('ABSPATH') or die('No script kiddies please!');

if (!class_exists('MStore_Icon_Helper')) {
    class MStore_Icon_Helper {
        /**
         * Convert icon class to Iconify SVG URL.
         *
         * @param string $icon_class Icon class like "fas fa-car", "mi bookmark_border", "icon-pin-2".
         * @return string SVG URL or empty string when conversion is not supported.
         */
        public static function convert_icon_class_to_url($icon_class) {
            if (empty($icon_class) || !is_string($icon_class)) {
                return '';
            }

            $icon_name = trim($icon_class);

            // Already a URL, return as-is.
            if (strpos($icon_name, 'http') === 0) {
                return $icon_name;
            }

            // MyListing Material Icons: "mi bookmark_border" -> "ic:bookmark-border".
            if (strpos($icon_name, 'mi ') === 0) {
                $name = trim(str_replace('mi ', '', $icon_name));
                $name = str_replace('_', '-', $name);
                if (!empty($name) && preg_match('/^[a-zA-Z0-9-]+$/', $name)) {
                    return "https://api.iconify.design/ic:{$name}.svg";
                }
            }

            // Custom theme icon fonts are not available via Iconify.
            if (strpos($icon_name, 'icon-') === 0) {
                return '';
            }

            $icon_map = array(
                'fa-brands fa-' => 'fa6-brands:',
                'fa-solid fa-' => 'fa6-solid:',
                'fa-regular fa-' => 'fa6-regular:',
                'fa-light fa-' => 'fa6-light:',
                'fab fa-' => 'fa6-brands:',
                'fas fa-' => 'fa6-solid:',
                'far fa-' => 'fa6-regular:',
                'fal fa-' => 'fa6-light:',
                'fa fa-' => 'fa6-solid:',
                'im im-icon-' => 'im:',
                'material-icons ' => 'material-symbols:',
            );

            foreach ($icon_map as $prefix => $collection) {
                if (strpos($icon_name, $prefix) === 0) {
                    $name = trim(str_replace($prefix, '', $icon_name));
                    if (empty($name) || preg_match('/[^a-zA-Z0-9-_]/', $name)) {
                        return '';
                    }

                    return "https://api.iconify.design/{$collection}{$name}.svg";
                }
            }

            return '';
        }
    }
}