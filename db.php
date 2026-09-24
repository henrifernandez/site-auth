<?php
declare(strict_types=1);

function carregar_env(string $arquivo): void
{
    if (!is_file($arquivo)) return;
    foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) continue;
        [$chave, $valor] = explode('=', $linha, 2);
        $_ENV[trim($chave)] = trim($valor, " \t\"'");
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    carregar_env(__DIR__ . '/.env');
    $url = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? '');
    $p = parse_url($url);
    if (!$p || empty($p['host'])) {
        throw new RuntimeException('DATABASE_URL ausente ou inválida.');
    }
    parse_str($p['query'] ?? '', $q);

    // Conexão direta (sem "-pooler") e ID do endpoint enviado manualmente
    $host = str_replace('-pooler', '', $p['host']);
    $endpoint = explode('.', $host)[0];

    $dsn = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;options='endpoint=%s'",
        $host, $p['port'] ?? 5432, ltrim($p['path'] ?? '/neondb', '/'), $q['sslmode'] ?? 'require', $endpoint
    );
    $pdo = new PDO($dsn, urldecode($p['user'] ?? ''), urldecode($p['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}