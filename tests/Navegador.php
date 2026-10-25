<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use SiteAuth\App;
use SiteAuth\Request;
use SiteAuth\Response;

/**
 * Cliente de teste: chama App::handle() mantendo o cookie de sessão entre requisições.
 */
final class Navegador
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(
        private readonly App $app,
        private readonly string $ip = '203.0.113.10',
        private readonly bool $https = false,
        private readonly string $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0',
    ) {}

    /** @param array<string, string> $query */
    public function get(string $caminho, array $query = []): Response
    {
        return $this->enviar(new Request('GET', $caminho, $query, [], $this->cabecalhos(), $this->cookies, $this->ip, $this->https));
    }

    /**
     * POST com CSRF. O token é lido do formulário em $paginaDoFormulario (por padrão, a própria rota),
     * a menos que $campos já traga 'csrf'.
     *
     * @param array<string, string> $campos
     * @param array<string, string> $query
     */
    public function post(string $caminho, array $campos = [], ?string $paginaDoFormulario = null, array $query = []): Response
    {
        if (!array_key_exists('csrf', $campos)) {
            $campos['csrf'] = $this->csrfDe($paginaDoFormulario ?? $caminho);
        }

        return $this->enviar(new Request('POST', $caminho, $query, $campos, $this->cabecalhos(), $this->cookies, $this->ip, $this->https));
    }

    /**
     * Lê o token CSRF do primeiro formulário da página.
     *
     * @param array<string, string> $query
     */
    public function csrfDe(string $caminho, array $query = []): string
    {
        $html = $this->get($caminho, $query)->body;

        return preg_match('/name="csrf" value="([0-9a-f]+)"/', $html, $m) === 1 ? $m[1] : '';
    }

    public function valorDoCookie(): ?string
    {
        return $this->cookies['sid'] ?? $this->cookies['__Host-sid'] ?? null;
    }

    public function definirCookie(string $valor): void
    {
        $this->cookies[$this->https ? '__Host-sid' : 'sid'] = $valor;
    }

    public function esquecerCookies(): void
    {
        $this->cookies = [];
    }

    /** Remove o token CSRF do corpo para comparar páginas de sessões diferentes. */
    public static function semCsrf(string $html): string
    {
        return (string) preg_replace('/name="csrf" value="[0-9a-f]+"/', 'name="csrf" value="X"', $html);
    }

    /** @return array<string, string> */
    private function cabecalhos(): array
    {
        return ['user-agent' => $this->userAgent];
    }

    private function enviar(Request $requisicao): Response
    {
        $resposta = $this->app->handle($requisicao);
        foreach ($resposta->cookies as $linha) {
            if (preg_match('/^([^=]+)=([^;]*)/', $linha, $m) !== 1) {
                continue;
            }
            if ($m[2] === '' || str_contains($linha, 'Max-Age=0')) {
                unset($this->cookies[$m[1]]);
            } else {
                $this->cookies[$m[1]] = $m[2];
            }
        }

        return $resposta;
    }
}
