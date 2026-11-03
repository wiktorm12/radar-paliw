<?php

namespace App\Modifiers;

use Statamic\Modifiers\Modifier;

/**
 * Zamienia zewnętrzne <script src> w kodzie reklam (AdSense, sieci afiliacyjne) na nieaktywne
 * placeholdery <script type="text/plain" data-deferred-src>. Ładuje je dopiero
 * `modules/deferred-scripts.js` po pierwszej interakcji użytkownika — dzięki temu skrypty reklam
 * nie konkurują z pierwszym renderem i nie przesuwają układu w trakcie wczytywania strony.
 * Skrypty inline (np. `adsbygoogle.push({})`) zostają bez zmian, bo tylko kolejkują reklamy.
 */
class DeferScripts extends Modifier
{
    public function index($value)
    {
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        if (! is_string($value) || $value === '') {
            return $value;
        }

        return preg_replace_callback(
            '/<script\b([^>]*?)\bsrc\s*=\s*(["\'])(.*?)\2([^>]*)>\s*<\/script>/is',
            function (array $match) {
                $attributes = preg_replace('/\s(?:async|defer|type\s*=\s*(["\']).*?\1)(?=[\s>]|$)/i', '', $match[1].$match[4]);

                return '<script type="text/plain" data-deferred-src="'.e($match[3]).'"'.rtrim($attributes).'></script>';
            },
            $value
        );
    }
}
