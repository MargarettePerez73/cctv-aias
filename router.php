<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in server:  php -S localhost:8000 router.php
 * Apache enforces the same rules with the .htaccess files in each folder.
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

if (preg_match('#^/(app|storage|database)(/|$)#', $path) || preg_match('#/\.#', $path) || $path === '/router.php') {
    http_response_code(404);
    echo 'Not found';
    return true;
}

// Let the built-in server serve existing files (pages, css, js) as usual.
return false;
