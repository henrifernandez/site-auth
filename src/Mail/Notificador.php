<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

use Throwable;

/**
 * Envia e-mails sem nunca quebrar a resposta: qualquer falha vai para o log (sem a chave de API)
 * e o chamador só recebe verdadeiro ou falso.
 */
final class Notificador
{
    public function __construct(private readonly Mailer $mailer, private readonly string $chaveParaOcultar = '') {}

    public function enviar(Email $email): bool
    {
        try {
            $this->mailer->enviar($email);

            return true;
        } catch (Throwable $erro) {
            $mensagem = $erro->getMessage();
            if ($this->chaveParaOcultar !== '') {
                $mensagem = str_replace($this->chaveParaOcultar, '[oculto]', $mensagem);
            }
            error_log(sprintf('Falha ao enviar e-mail "%s": %s: %s', $email->assunto, $erro::class, $mensagem));

            return false;
        }
    }
}
