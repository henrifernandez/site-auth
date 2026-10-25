<?php

declare(strict_types=1);

namespace SiteAuth;

use SiteAuth\Mail\Emails;
use SiteAuth\Mail\Notificador;

/**
 * Cria o token de verificação (24 h) e envia o e-mail com o link.
 */
final class VerificacaoEmail
{
    public function __construct(
        private readonly TokensConta $tokens,
        private readonly Config $config,
        private readonly Notificador $notificador,
    ) {}

    /** Devolve se o e-mail foi entregue ao provedor. Falhas só vão para o log. */
    public function enviar(int $usuarioId, string $nome, string $email): bool
    {
        $link = $this->config->url('/verificar-email?token=');
        if ($link === null) {
            error_log('APP_URL não definida: e-mail de verificação não enviado.');

            return false;
        }

        $gerado = Tokens::gerar();
        $this->tokens->criar($usuarioId, TokensConta::VERIFICAR_EMAIL, $gerado['hash'], TokensConta::VALIDADE_VERIFICAR);

        return $this->notificador->enviar(Emails::verificarEmail($nome, $email, $link . $gerado['token']));
    }
}
