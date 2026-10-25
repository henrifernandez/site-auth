<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Configuração da aplicação lida do ambiente. Nada aqui é lido de arquivo versionado.
 */
final readonly class Config
{
    /** Vida máxima de uma sessão autenticada (7 dias). */
    public const int SESSAO_ABSOLUTA = 604800;

    /** Tempo sem uso até a sessão autenticada expirar (2 horas). */
    public const int SESSAO_OCIOSA = 7200;

    /** Vida máxima de uma sessão anônima (1 hora). */
    public const int SESSAO_ANONIMA = 3600;

    public function __construct(
        public ?string $appUrl = null,
        public string $mailProvider = 'log',
        public string $mailApiKey = '',
        public string $mailFrom = '',
        public bool $producao = false,
        public bool $confiarIpDaVercel = true,
    ) {}

    public static function doAmbiente(): self
    {
        $url = Env::obter('APP_URL');

        return new self(
            $url === null ? null : rtrim($url, '/'),
            strtolower(Env::obter('MAIL_PROVIDER', 'log') ?? 'log'),
            Env::obter('MAIL_API_KEY', '') ?? '',
            Env::obter('MAIL_FROM', '') ?? '',
            Env::obter('VERCEL_ENV') === 'production' || Env::obter('APP_ENV') === 'production',
            Env::obter('TRUST_VERCEL_IP', '1') !== '0',
        );
    }

    /** Monta uma URL absoluta a partir de APP_URL; nunca usa o cabeçalho Host. */
    public function url(string $caminho): ?string
    {
        return $this->appUrl === null ? null : $this->appUrl . $caminho;
    }
}
