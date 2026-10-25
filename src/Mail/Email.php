<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

/**
 * Um e-mail pronto para envio: texto simples e HTML básico.
 */
final readonly class Email
{
    public function __construct(
        public string $para,
        public string $assunto,
        public string $texto,
        public string $html,
    ) {}
}
