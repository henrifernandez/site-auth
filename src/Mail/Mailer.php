<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

interface Mailer
{
    /** @throws MailException se o envio falhar */
    public function enviar(Email $email): void;
}
