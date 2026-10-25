<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

/**
 * Usado quando MAIL_PROVIDER está mal configurado: todo envio falha com um motivo claro no log,
 * sem derrubar o site e sem fingir que o e-mail saiu.
 */
final class MailerIndisponivel implements Mailer
{
    public function __construct(private readonly string $motivo) {}

    public function enviar(Email $email): void
    {
        throw new MailException('E-mail indisponível: ' . $this->motivo);
    }
}
