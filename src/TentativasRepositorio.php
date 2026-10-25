<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Armazenamento das tentativas de acesso usadas pelo limitador.
 */
interface TentativasRepositorio
{
    public function registrar(string $tipo, string $chave, string $ip): void;

    /**
     * Conta tentativas dentro da janela, filtrando por chave e/ou IP quando informados.
     */
    public function contar(string $tipo, ?string $chave, ?string $ip, int $janelaSegundos): int;

    /** Apaga as tentativas de uma chave (por exemplo, depois de um login correto). */
    public function limpar(string $tipo, string $chave): void;

    /** Apaga tentativas mais antigas que o limite. Devolve quantas. */
    public function purgar(int $maisAntigasQueSegundos): int;
}
