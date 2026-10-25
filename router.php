<?php

declare(strict_types=1);

/**
 * Roteador para desenvolvimento local: php -S localhost:8081 router.php
 *
 * Emula o vercel.json: arquivos de public/ são servidos como estáticos, caminhos internos dão 404
 * e todo o resto vai para api/index.php.
 */

$caminho = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$bloqueado = '#^/(?:(?:src|templates|migrations|bin|tests|vendor|docs|api|\.github|\.git)(?:/|$)|'
    . '(?:composer\.(?:json|lock)|phpunit\.xml\.dist|phpstan\.neon\.dist|\.php-cs-fixer\.dist\.php|router\.php|init\.sql|'
    . '\.env[^/]*|\.gitignore|\.gitattributes|\.vercelignore|package(?:-lock)?\.json)$)#';

if (preg_match($bloqueado, $caminho) === 1) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";

    return true;
}

// Pasta com index.html (a prévia em /previa/) abre o índice, como um servidor de arquivos faria.
$indice = $caminho !== '/' && str_ends_with($caminho, '/') ? realpath(__DIR__ . '/public' . $caminho . 'index.html') : false;
$arquivo = $caminho === '/' ? false : ($indice !== false ? $indice : realpath(__DIR__ . '/public' . $caminho));
$raizPublica = realpath(__DIR__ . '/public');
$extensao = $arquivo === false ? '' : strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));

if (
    $arquivo !== false
    && $raizPublica !== false
    && str_starts_with($arquivo, $raizPublica . DIRECTORY_SEPARATOR)
    && is_file($arquivo)
    && $extensao !== 'php'
) {
    $tipos = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
    ];
    header('Content-Type: ' . ($tipos[$extensao] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    readfile($arquivo);

    return true;
}

$_SERVER['SCRIPT_NAME'] = '/api/index.php';
require __DIR__ . '/api/index.php';

return true;
