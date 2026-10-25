<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Tokens de uso único enviados por e-mail. O link leva o token; o banco guarda só o sha256.
 */
final class Tokens
{
    /**
     * @return array{token: string, hash: string}
     */
    public static function gerar(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** 32 bytes em base64url sem preenchimento dão exatamente 43 caracteres. */
    public static function formatoValido(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }
}
