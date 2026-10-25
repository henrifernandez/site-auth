<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Cabeçalhos de segurança aplicados a toda resposta dinâmica (páginas, erros e redirecionamentos).
 *
 * A CSP não permite nada inline: scripts, estilos e fontes vêm de /assets, no mesmo domínio.
 */
final class Seguranca
{
    public const string CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'";

    public const string PERMISSIONS_POLICY = 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), '
        . 'microphone=(), payment=(), usb=(), interest-cohort=()';

    /**
     * Cabeçalhos que a resposta ainda não definiu. Referrer-Policy e Cache-Control específicos
     * (como no-referrer nas páginas com token na URL) têm prioridade.
     *
     * @return array<string, string>
     */
    public static function cabecalhos(bool $https): array
    {
        $cabecalhos = [
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
        ];
        if ($https) {
            $cabecalhos['Strict-Transport-Security'] = 'max-age=31536000';
        }

        return $cabecalhos;
    }

    public static function aplicar(Response $resposta, Request $requisicao): Response
    {
        return $resposta->comCabecalhosPadrao(self::cabecalhos($requisicao->https));
    }
}
