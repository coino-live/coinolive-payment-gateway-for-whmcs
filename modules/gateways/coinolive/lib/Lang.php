<?php
/**
 * Module texts in the client's (or, in the admin area, the admin's) WHMCS language; English otherwise.
 */

namespace CoinoLive\Whmcs;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Lang
{
    /** @var array<string, array<string, string>> */
    private static $loaded = [];

    /** @var string|null Forced language (tests, cron). */
    public static $language = null;

    public static function t($key)
    {
        $lang = self::current();
        $texts = self::load($lang);
        if (isset($texts[$key])) {
            return $texts[$key];
        }
        $english = self::load('english');

        return isset($english[$key]) ? $english[$key] : $key;
    }

    /** WHMCS language name, e.g. "english", "farsi", "portuguese-br". */
    public static function current()
    {
        if (self::$language !== null) {
            return self::$language;
        }
        if (defined('ADMINAREA')) {
            if (class_exists('\AdminLang') && method_exists('\AdminLang', 'getName')) {
                return (string) \AdminLang::getName();
            }

            return isset($_SESSION['adminlang']) ? (string) $_SESSION['adminlang'] : 'english';
        }
        if (class_exists('\Lang') && method_exists('\Lang', 'getName')) {
            return (string) \Lang::getName();
        }
        if (!empty($_SESSION['Language'])) {
            return (string) $_SESSION['Language'];
        }

        return isset($GLOBALS['CONFIG']['Language']) ? (string) $GLOBALS['CONFIG']['Language'] : 'english';
    }

    private static function load($lang)
    {
        $lang = strtolower(preg_replace('/[^A-Za-z\-]/', '', (string) $lang));
        if (!isset(self::$loaded[$lang])) {
            $file = dirname(__DIR__) . '/lang/' . $lang . '.php';
            self::$loaded[$lang] = is_file($file) ? (array) include $file : [];
        }

        return self::$loaded[$lang];
    }
}
