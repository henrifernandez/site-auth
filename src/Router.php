<?php

declare(strict_types=1);

namespace SiteAuth;

use Closure;

/**
 * Roteador por método e caminho exato. HEAD usa o handler do GET.
 */
final class Router
{
    /** @var array<string, array<string, Closure(Request): Response>> caminho => método => handler */
    private array $rotas = [];

    /** @var Closure(Request): Response */
    private Closure $naoEncontrado;

    /** @var Closure(Request, list<string>): Response */
    private Closure $metodoNaoPermitido;

    /**
     * @param (Closure(Request): Response)|null $naoEncontrado
     * @param (Closure(Request, list<string>): Response)|null $metodoNaoPermitido
     */
    public function __construct(?Closure $naoEncontrado = null, ?Closure $metodoNaoPermitido = null)
    {
        $this->naoEncontrado = $naoEncontrado
            ?? static fn(Request $r): Response => Response::texto("Não encontrado.\n", 404);
        $this->metodoNaoPermitido = $metodoNaoPermitido
            ?? static fn(Request $r, array $permitidos): Response => Response::texto("Método não permitido.\n", 405)
                ->comCabecalho('Allow', implode(', ', $permitidos));
    }

    /** @param Closure(Request): Response $handler */
    public function adicionar(string $metodo, string $caminho, Closure $handler): void
    {
        $this->rotas[self::normalizar($caminho)][strtoupper($metodo)] = $handler;
    }

    public function despachar(Request $requisicao): Response
    {
        $metodos = $this->rotas[self::normalizar($requisicao->path)] ?? null;
        if ($metodos === null) {
            return ($this->naoEncontrado)($requisicao);
        }

        $metodo = $requisicao->method;
        if ($metodo === 'HEAD' && !isset($metodos['HEAD'])) {
            $metodo = 'GET';
        }

        if (!isset($metodos[$metodo])) {
            $permitidos = array_keys($metodos);
            if (in_array('GET', $permitidos, true) && !in_array('HEAD', $permitidos, true)) {
                $permitidos[] = 'HEAD';
            }

            return ($this->metodoNaoPermitido)($requisicao, $permitidos);
        }

        return $metodos[$metodo]($requisicao);
    }

    private static function normalizar(string $caminho): string
    {
        return $caminho === '/' ? '/' : rtrim($caminho, '/');
    }
}
