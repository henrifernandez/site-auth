<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Token CSRF por sessão, conferido em todo POST.
 */
final class Csrf
{
    public const string CAMPO = 'csrf';

    public static function token(Armazem $sessao): string
    {
        $atual = $sessao->obter('csrf');
        if (is_string($atual) && $atual !== '') {
            return $atual;
        }

        $novo = bin2hex(random_bytes(32));
        $sessao->definir('csrf', $novo);

        return $novo;
    }

    public static function valido(Armazem $sessao, string $enviado): bool
    {
        $esperado = $sessao->obter('csrf');

        return is_string($esperado) && $esperado !== '' && $enviado !== '' && hash_equals($esperado, $enviado);
    }
}
