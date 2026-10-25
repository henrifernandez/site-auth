<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Resposta HTTP: status, cabeçalhos, cookies (um Set-Cookie por item) e corpo.
 */
final readonly class Response
{
    /**
     * @param array<string, string> $headers
     * @param list<string> $cookies linhas completas de Set-Cookie
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
        public array $cookies = [],
    ) {}

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public static function texto(string $texto, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $texto);
    }

    public static function redirecionar(string $destino, int $status = 303): self
    {
        return new self($status, ['Location' => $destino]);
    }

    public function comCabecalho(string $nome, string $valor): self
    {
        return new self($this->status, [...$this->headers, $nome => $valor], $this->body, $this->cookies);
    }

    /**
     * Acrescenta cabeçalhos que ainda não existem (não sobrescreve os já definidos).
     *
     * @param array<string, string> $padrao
     */
    public function comCabecalhosPadrao(array $padrao): self
    {
        $existentes = array_change_key_case($this->headers);
        $novos = $this->headers;
        foreach ($padrao as $nome => $valor) {
            if (!array_key_exists(strtolower($nome), $existentes)) {
                $novos[$nome] = $valor;
            }
        }

        return new self($this->status, $novos, $this->body, $this->cookies);
    }

    public function comCookie(string $linhaSetCookie): self
    {
        return new self($this->status, $this->headers, $this->body, [...$this->cookies, $linhaSetCookie]);
    }

    public function cabecalho(string $nome): ?string
    {
        foreach ($this->headers as $chave => $valor) {
            if (strcasecmp($chave, $nome) === 0) {
                return $valor;
            }
        }

        return null;
    }

    public function enviar(bool $semCorpo = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $nome => $valor) {
            header($nome . ': ' . $valor);
        }
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        if (!$semCorpo) {
            echo $this->body;
        }
    }
}
