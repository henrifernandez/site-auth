<?php

declare(strict_types=1);

namespace SiteAuth;

use DateTimeImmutable;
use DateTimeZone;

final readonly class Usuario
{
    public function __construct(
        public int $id,
        public string $nome,
        public string $email,
        public string $senhaHash,
        public string $criadoEm,
        public ?string $emailVerificadoEm,
    ) {}

    /** @param array<string, mixed> $linha */
    public static function deLinha(array $linha): self
    {
        return new self(
            (int) $linha['id'],
            (string) $linha['nome'],
            (string) $linha['email'],
            (string) $linha['senha_hash'],
            (string) $linha['criado_em'],
            isset($linha['email_verificado_em']) ? (string) $linha['email_verificado_em'] : null,
        );
    }

    public function primeiroNome(): string
    {
        return explode(' ', $this->nome)[0];
    }

    public function emailVerificado(): bool
    {
        return $this->emailVerificadoEm !== null;
    }

    /** Data de cadastro real, no fuso de Brasília. */
    public static function formatarData(string $timestamp): string
    {
        return new DateTimeImmutable($timestamp)
            ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
            ->format('d/m/Y \à\s H:i');
    }
}
