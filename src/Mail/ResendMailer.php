<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

/**
 * Envio pela API do Resend (https://resend.com/docs/api-reference/emails/send-email).
 */
final class ResendMailer extends MailerHttp
{
    public const string URL = 'https://api.resend.com/emails';

    public function montarRequisicao(Email $email): array
    {
        return [
            'url' => self::URL,
            'cabecalhos' => [
                'Authorization: Bearer ' . $this->chaveApi,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: site-auth/1.0',
            ],
            'corpo' => [
                'from' => $this->remetente->formatado(),
                'to' => [$email->para],
                'subject' => $email->assunto,
                'html' => $email->html,
                'text' => $email->texto,
            ],
        ];
    }

    protected function nome(): string
    {
        return 'Resend';
    }
}
