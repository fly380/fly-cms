<?php
/**
 * vendor/autoload.php — мінімальний autoloader для Twig
 * Замінює composer autoload для середовищ без Composer
 */
spl_autoload_register(function (string $class): void {
    // Twig namespace → vendor/twig/twig/src/
    if (strpos($class, 'Twig\\') === 0) {
        $relative = substr($class, 5); // відрізаємо 'Twig\'
        $file = __DIR__ . '/twig/twig/src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});
