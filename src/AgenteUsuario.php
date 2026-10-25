<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Resumo legível do User-Agent para a lista de sessões ("Firefox em Windows").
 * É só uma pista visual para o usuário reconhecer o dispositivo, não uma identificação confiável.
 */
final class AgenteUsuario
{
    public static function resumir(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Dispositivo desconhecido';
        }

        $navegador = match (true) {
            str_contains($userAgent, 'Edg/') || str_contains($userAgent, 'EdgA/') || str_contains($userAgent, 'EdgiOS/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Navegador desconhecido',
        };

        $sistema = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') || str_contains($userAgent, 'iPod') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $sistema === null ? $navegador : $navegador . ' em ' . $sistema;
    }
}
