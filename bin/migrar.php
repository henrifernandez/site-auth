<?php

declare(strict_types=1);

/**
 * Uso: php bin/migrar.php up | down [passos | --todas] | status
 * Lê DATABASE_URL do ambiente (em desenvolvimento, também do arquivo .env, que não é versionado).
 */

use SiteAuth\Db;
use SiteAuth\Env;
use SiteAuth\Migrador;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    exit;
}

$raiz = dirname(__DIR__);
if (is_file($raiz . '/vendor/autoload.php')) {
    require $raiz . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $classe) use ($raiz): void {
        if (str_starts_with($classe, 'SiteAuth\\')) {
            $arquivo = $raiz . '/src/' . str_replace('\\', '/', substr($classe, 9)) . '.php';
            if (is_file($arquivo)) {
                require $arquivo;
            }
        }
    });
}

Env::carregarArquivo($raiz . '/.env');

$comando = $argv[1] ?? '';
if (!in_array($comando, ['up', 'down', 'status'], true)) {
    fwrite(STDERR, "Uso: php bin/migrar.php up | down [passos | --todas] | status\n");

    exit(2);
}

try {
    $migrador = new Migrador(Db::doAmbiente(), $raiz . '/migrations');

    if ($comando === 'status') {
        foreach ($migrador->status() as $m) {
            printf("%s  %-28s %s\n", $m['versao'], $m['nome'], $m['aplicada_em'] === null ? 'pendente' : 'aplicada em ' . $m['aplicada_em']);
        }

        exit(0);
    }

    if ($comando === 'up') {
        $feitas = $migrador->subir();
        echo $feitas === [] ? "Nada a aplicar.\n" : 'Aplicadas: ' . implode(', ', $feitas) . "\n";

        exit(0);
    }

    $argumento = $argv[2] ?? '1';
    $passos = $argumento === '--todas' ? PHP_INT_MAX : max(1, (int) $argumento);
    $feitas = $migrador->descer($passos);
    echo $feitas === [] ? "Nada a desfazer.\n" : 'Desfeitas: ' . implode(', ', $feitas) . "\n";
} catch (Throwable $erro) {
    // A mensagem do banco ajuda a resolver (por exemplo, e-mails duplicados); a URL de conexão nunca é impressa.
    fwrite(STDERR, 'Falha na migração: ' . $erro->getMessage() . "\n");

    exit(1);
}
