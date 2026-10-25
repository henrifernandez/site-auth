<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

use SiteAuth\Config;

/**
 * Escolhe o Mailer por MAIL_PROVIDER: log (padrão), resend ou brevo.
 */
final class MailerFabrica
{
    public static function criar(Config $config): Mailer
    {
        switch ($config->mailProvider) {
            case 'log':
            case '':
                return new LogMailer($config->producao);
            case 'resend':
            case 'brevo':
                if ($config->mailApiKey === '') {
                    return new MailerIndisponivel('MAIL_API_KEY não definida para ' . $config->mailProvider . '.');
                }
                try {
                    $remetente = Remetente::analisar($config->mailFrom);
                } catch (MailException $erro) {
                    return new MailerIndisponivel($erro->getMessage());
                }

                return $config->mailProvider === 'resend'
                    ? new ResendMailer($config->mailApiKey, $remetente)
                    : new BrevoMailer($config->mailApiKey, $remetente);
            default:
                return new MailerIndisponivel('MAIL_PROVIDER desconhecido (use log, resend ou brevo).');
        }
    }
}
