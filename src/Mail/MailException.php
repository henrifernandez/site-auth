<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

use RuntimeException;

/**
 * Falha de envio. A mensagem nunca deve conter chaves de API; ela só vai para o log do servidor.
 */
final class MailException extends RuntimeException {}
