<?php

declare(strict_types=1);

namespace SiteAuth;

use Throwable;

/**
 * Aplicação: monta as rotas e transforma uma Request em Response.
 */
final class App
{
    private readonly Router $roteador;

    public function __construct()
    {
        $this->roteador = new Router();
        $this->roteador->adicionar('GET', '/', static fn(Request $r): Response => Response::texto("Site Auth em reforma.\n"));
    }

    public function handle(Request $requisicao): Response
    {
        try {
            return $this->roteador->despachar($requisicao);
        } catch (Throwable $erro) {
            error_log(sprintf('Erro interno: %s em %s:%d', $erro->getMessage(), $erro->getFile(), $erro->getLine()));

            return Response::texto("Erro interno.\n", 500);
        }
    }
}
