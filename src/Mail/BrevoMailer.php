<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

/**
 * Envio pela API transacional do Brevo (https://developers.brevo.com/reference/sendtransacemail).
 */
final class BrevoMailer extends MailerHttp
{
    public const string URL = 'https://api.brevo.com/v3/smtp/email';

    public function montarRequisicao(Email $email): array
    {
        $remetente = ['email' => $this->remetente->email];
        if ($this->remetente->nome !== null) {
            $remetente['name'] = $this->remetente->nome;
        }

        return [
            'url' => self::URL,
            'cabecalhos' => [
                'api-key: ' . $this->chaveApi,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: site-auth/1.0',
            ],
            'corpo' => [
                'sender' => $remetente,
                'to' => [['email' => $email->para]],
                'subject' => $email->assunto,
                'htmlContent' => $email->html,
                'textContent' => $email->texto,
            ],
        ];
    }

    protected function nome(): string
    {
        return 'Brevo';
    }
}
