<?php

declare(strict_types=1);

/**
 * Entrada única da aplicação. Funciona com ou sem vendor/: o deploy na Vercel pode não ter o Composer.
 */

// Nunca exibe erros de PHP ao cliente; eles seguem para o log do servidor.
ini_set('display_errors', '0');

$raiz = dirname(__DIR__);

if (is_file($raiz . '/vendor/autoload.php')) {
    require $raiz . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $classe) use ($raiz): void {
        $prefixo = 'SiteAuth\\';
        if (str_starts_with($classe, $prefixo)) {
            $arquivo = $raiz . '/src/' . str_replace('\\', '/', substr($classe, strlen($prefixo))) . '.php';
            if (is_file($arquivo)) {
                require $arquivo;
            }
        }
    });
}

// O .env só existe em desenvolvimento e nunca sobrescreve variáveis do ambiente.
SiteAuth\Env::carregarArquivo($raiz . '/.env');

try {
    $requisicao = SiteAuth\Request::fromGlobals();
    $app = new SiteAuth\App(SiteAuth\Db::doAmbiente(), SiteAuth\Config::doAmbiente());
    $app->handle($requisicao)->enviar($requisicao->method === 'HEAD');
} catch (Throwable $erro) {
    error_log(sprintf('Falha ao iniciar: %s: %s', $erro::class, $erro->getMessage()));
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Erro interno.\n";
}
