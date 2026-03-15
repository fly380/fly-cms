<?php
/**
 * data/TwigFactory.php — Центральна ініціалізація Twig
 *
 * Єдине місце де налаштовується Twig:
 *  - шляхи до шаблонів (views/)
 *  - кешування (data/twig_cache/)
 *  - auto-escape (увімкнений за замовчуванням — весь {{ var }} безпечний)
 *  - кастомні фільтри: sanitize_html, sanitize_css, sanitize_js
 *  - кастомні функції: setting(), ts(), display_name(), tel()
 *
 * Використання:
 *   require_once __DIR__ . '/TwigFactory.php';
 *   $twig = TwigFactory::create();
 *   echo $twig->render('index.twig', ['title' => 'Привіт']);
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

// Підключаємо sanitize-функції (вони оголошені в page_wrapper, але тепер потрібні тут)
require_once __DIR__ . '/Sanitizer.php';

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigFactory
{
    private static ?Environment $instance = null;

    public static function create(): Environment
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $viewsPath = FLY_ROOT . '/views';
        $cachePath = FLY_ROOT . '/data/twig_cache';

        // Створюємо папку кешу якщо не існує
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        $loader = new FilesystemLoader($viewsPath);
        $twig   = new Environment($loader, [
            // auto-escape: весь {{ var }} автоматично htmlspecialchars
            // Для raw HTML використовуй {{ var|raw }}
            'autoescape' => 'html',
            // false в dev-режимі щоб бачити зміни без очищення кешу
            // true на production для продуктивності
            'cache'      => (getenv('APP_ENV') === 'production') ? $cachePath : false,
            'debug'      => (getenv('APP_ENV') !== 'production'),
        ]);

        // ── Кастомні фільтри ──────────────────────────────────────────

        // {{ content|sanitize_html }} — очищений HTML з TinyMCE (raw вивід)
        $twig->addFilter(new TwigFilter(
            'sanitize_html',
            fn(string $html): string => Sanitizer::html($html),
            ['is_safe' => ['html']]  // повертає безпечний HTML, не екранувати повторно
        ));

        // {{ css|sanitize_css }} — очищений CSS
        $twig->addFilter(new TwigFilter(
            'sanitize_css',
            fn(string $css): string => Sanitizer::css($css),
            ['is_safe' => ['html']]
        ));

        // {{ js|sanitize_js }} — очищений JS
        $twig->addFilter(new TwigFilter(
            'sanitize_js',
            fn(string $js): string => Sanitizer::js($js),
            ['is_safe' => ['html']]
        ));

        // {{ date_str|date_uk }} → "15.03.2025"
        $twig->addFilter(new TwigFilter(
            'date_uk',
            fn(?string $d): string => $d ? date('d.m.Y', strtotime($d)) : ''
        ));

        // {{ text|excerpt(120) }} → уривок без тегів
        $twig->addFilter(new TwigFilter(
            'excerpt',
            function (string $html, int $len = 120): string {
                $text = strip_tags($html);
                $text = preg_replace('/\s+/', ' ', trim($text));
                return mb_strlen($text) <= $len ? $text : mb_substr($text, 0, $len) . '…';
            }
        ));

        // {{ phone|tel }} → "+380671234567" (тільки цифри і +)
        $twig->addFilter(new TwigFilter(
            'tel',
            fn(string $p): string => preg_replace('/[^\d\+]/', '', $p)
        ));

        // ── Кастомні функції ─────────────────────────────────────────

        // {{ setting('site_title') }} → значення з таблиці settings
        $twig->addFunction(new TwigFunction(
            'setting',
            fn(string $key, string $table = 'settings') => get_setting($key, $table)
        ));

        // {{ ts('navbar_style', 'dark') }} → значення з theme_settings
        $twig->addFunction(new TwigFunction(
            'ts',
            fn(string $key, string $default = ''): string => ts($key, $default)
        ));

        // {{ display_name('john') }} → "Іван Коваль"
        $twig->addFunction(new TwigFunction(
            'display_name',
            fn(string $login): string => get_display_name($login)
        ));

        // {{ asset('css/style.css') }} → '/assets/css/style.css'
        $twig->addFunction(new TwigFunction(
            'asset',
            fn(string $path): string => '/assets/' . ltrim($path, '/')
        ));

        self::$instance = $twig;
        return $twig;
    }
}
