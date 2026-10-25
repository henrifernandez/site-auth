<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

/**
 * Remetente a partir de MAIL_FROM: "Nome <endereco@dominio>" ou só "endereco@dominio".
 */
final readonly class Remetente
{
    public function __construct(public string $email, public ?string $nome = null) {}

    /** @throws MailException se o valor não tiver um endereço válido */
    public static function analisar(string $valor): self
    {
        $valor = trim($valor);
        $nome = null;
        if (preg_match('/^(.*)<([^<>]+)>$/', $valor, $m) === 1) {
            $nome = trim($m[1], " \t\"'");
            $valor = trim($m[2]);
        }

        if (filter_var($valor, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException('MAIL_FROM inválido: informe "Nome <endereco@dominio>" ou "endereco@dominio".');
        }

        return new self($valor, $nome === '' ? null : $nome);
    }

    /** Forma "Nome <endereco>" aceita pelo Resend. */
    public function formatado(): string
    {
        return $this->nome === null ? $this->email : $this->nome . ' <' . $this->email . '>';
    }
}
