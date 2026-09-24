<?php
require __DIR__ . '/db.php';

echo "1) Extensão pdo_pgsql: " . (extension_loaded('pdo_pgsql') ? "OK" : "FALTANDO") . PHP_EOL;

carregar_env(__DIR__ . '/.env');
$url = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? '');
echo "2) DATABASE_URL no .env: " . ($url ? "encontrada" : "NÃO encontrada") . PHP_EOL;
$p = parse_url($url);
echo "   host: " . ($p['host'] ?? '(inválido)') . PHP_EOL;
if (empty($p['host'])) exit;

$s = @fsockopen($p['host'], $p['port'] ?? 5432, $errno, $errstr, 8);
echo "3) Rede até o Neon: " . ($s ? "OK" : "FALHOU ($errstr)") . PHP_EOL;

try {
    $r = db()->query('SELECT now() AS agora, current_database() AS banco')->fetch();
    echo "4) Conexão: OK ({$r['banco']}, {$r['agora']})" . PHP_EOL;
} catch (Throwable $e) {
    echo "4) Conexão: ERRO -> " . $e->getMessage() . PHP_EOL;
}