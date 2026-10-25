<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Armazenamento chave-valor da sessão, separado da classe Sessao para permitir testes sem banco.
 */
interface Armazem
{
    public function obter(string $chave): mixed;

    public function definir(string $chave, mixed $valor): void;
}
