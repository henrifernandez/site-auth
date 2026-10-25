<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

use Closure;

/**
 * Não envia nada: registra o e-mail no log do servidor (stderr no `php -S`) e guarda em memória.
 * É o padrão em desenvolvimento e nos testes. Em produção ($redigir = true) o log traz só destinatário
 * mascarado e assunto, para links de recuperação não irem parar nos logs da hospedagem.
 */
final class LogMailer implements Mailer
{
    /** @var list<Email> */
    public array $enviados = [];

    /** @var Closure(string): void */
    private Closure $saida;

    /** @param (Closure(string): void)|null $saida destino da linha de log (padrão: error_log) */
    public function __construct(private readonly bool $redigir = false, ?Closure $saida = null)
    {
        $this->saida = $saida ?? static function (string $linha): void {
            error_log($linha);
        };
    }

    public function enviar(Email $email): void
    {
        $this->enviados[] = $email;

        if ($this->redigir) {
            ($this->saida)(sprintf('[mail] para=%s assunto="%s" (conteúdo omitido)', self::mascarar($email->para), $email->assunto));

            return;
        }

        ($this->saida)(sprintf("[mail] para=%s assunto=\"%s\"\n%s", $email->para, $email->assunto, $email->texto));
    }

    public static function mascarar(string $endereco): string
    {
        $partes = explode('@', $endereco, 2);
        if (count($partes) !== 2) {
            return '***';
        }

        return mb_substr($partes[0], 0, 1) . '***@' . $partes[1];
    }
}
