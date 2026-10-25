<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Representação mínima de uma requisição HTTP, independente de servidor.
 */
final readonly class Request
{
    public string $method;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers nomes em minúsculas
     * @param array<string, string> $cookies
     */
    public function __construct(
        string $method,
        public string $path,
        public array $query = [],
        public array $post = [],
        public array $headers = [],
        public array $cookies = [],
        public string $remoteAddr = '',
        public bool $https = false,
    ) {
        $this->method = strtoupper($method);
    }

    /**
     * Monta a requisição a partir das superglobais do PHP.
     *
     * @param array<string, mixed>|null $server
     * @param array<string, mixed>|null $get
     * @param array<string, mixed>|null $post
     * @param array<string, mixed>|null $cookie
     */
    public static function fromGlobals(?array $server = null, ?array $get = null, ?array $post = null, ?array $cookie = null): self
    {
        $server ??= $_SERVER;
        $get ??= $_GET;
        $post ??= $_POST;
        $cookie ??= $_COOKIE;

        $method = is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET';
        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';

        // Remove query string e fragmento e decodifica o caminho.
        $caminho = rawurldecode(substr($uri, 0, strcspn($uri, '?#')));

        $headers = [];
        foreach ($server as $chave => $valor) {
            if (is_string($valor) && str_starts_with($chave, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($chave, 5)))] = $valor;
            }
        }

        $cookies = [];
        foreach ($cookie as $nome => $valor) {
            if (is_string($valor)) {
                $cookies[(string) $nome] = $valor;
            }
        }

        $https = (isset($server['HTTPS']) && is_string($server['HTTPS']) && strtolower($server['HTTPS']) !== 'off' && $server['HTTPS'] !== '')
            || str_starts_with(strtolower($headers['x-forwarded-proto'] ?? ''), 'https');

        return new self(
            $method,
            $caminho === '' ? '/' : $caminho,
            $get,
            $post,
            $headers,
            $cookies,
            is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '',
            $https,
        );
    }

    public function header(string $nome): ?string
    {
        return $this->headers[strtolower($nome)] ?? null;
    }

    /** Valor de um campo de formulário, sempre como string (arrays e ausentes viram vazio). */
    public function campo(string $nome): string
    {
        $valor = $this->post[$nome] ?? '';

        return is_string($valor) ? $valor : '';
    }

    /** Valor de um parâmetro da URL, sempre como string. */
    public function parametro(string $nome): string
    {
        $valor = $this->query[$nome] ?? '';

        return is_string($valor) ? $valor : '';
    }

    /**
     * IP do cliente. Na Vercel o cabeçalho x-vercel-forwarded-for é definido pela plataforma;
     * fora dela (ou com $confiarProxy = false) vale o REMOTE_ADDR.
     */
    public function ip(bool $confiarProxy = true): string
    {
        if ($confiarProxy) {
            $encaminhado = trim(explode(',', $this->header('x-vercel-forwarded-for') ?? '')[0]);
            if ($encaminhado !== '' && filter_var($encaminhado, FILTER_VALIDATE_IP) !== false) {
                return $encaminhado;
            }
        }

        return filter_var($this->remoteAddr, FILTER_VALIDATE_IP) !== false ? $this->remoteAddr : '0.0.0.0';
    }
}
