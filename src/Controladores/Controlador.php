<?php

declare(strict_types=1);

namespace SiteAuth\Controladores;

use SiteAuth\Csrf;
use SiteAuth\Request;
use SiteAuth\Response;
use SiteAuth\Servicos;
use SiteAuth\Sessao;
use SiteAuth\Usuario;
use SiteAuth\Validador;

/**
 * Base dos controladores: renderização, CSRF e usuário da sessão.
 */
abstract class Controlador
{
    private bool $usuarioCarregado = false;

    private ?Usuario $usuario = null;

    public function __construct(
        protected readonly Servicos $s,
        protected readonly Request $req,
        protected readonly Sessao $sessao,
    ) {}

    protected function ip(): string
    {
        return $this->req->ip($this->s->config->confiarIpDaVercel);
    }

    protected function usuarioAtual(): ?Usuario
    {
        if (!$this->usuarioCarregado) {
            $id = $this->sessao->usuarioId();
            $this->usuario = $id === null ? null : $this->s->usuarios->buscarPorId($id);
            $this->usuarioCarregado = true;
        }

        return $this->usuario;
    }

    protected function csrfValido(): bool
    {
        return Csrf::valido($this->sessao, $this->req->campo(Csrf::CAMPO));
    }

    /**
     * Renderiza uma página completa. Páginas com sessão e token nunca ficam em cache.
     *
     * @param array<string, mixed> $dados
     */
    protected function pagina(string $template, string $titulo, array $dados = [], int $status = 200, string $classe = ''): Response
    {
        $dados += ['csrf' => Csrf::token($this->sessao)];
        $popup = $this->sessao->pegarUmaVez('popup');
        $aviso = $this->sessao->pegarUmaVez('aviso');

        $html = $this->s->visao->pagina($template, $dados, [
            'titulo' => $titulo,
            'popup' => is_array($popup) && count($popup) === 3 ? [(string) $popup[0], (string) $popup[1], (string) $popup[2]] : null,
            'aviso' => is_string($aviso) ? $aviso : null,
            'classe' => $classe,
        ]);

        return Response::html($html, $status)->comCabecalho('Cache-Control', 'no-store');
    }

    protected function erroSessaoExpirada(): Response
    {
        return $this->paginaDeErro(403, 'Sessão expirada', Validador::ERRO_CSRF);
    }

    protected function paginaDeErro(int $status, string $titulo, string $mensagem): Response
    {
        return $this->pagina('erro', $titulo, ['titulo' => $titulo, 'mensagem' => $mensagem], $status);
    }

    protected function redirecionar(string $destino): Response
    {
        return Response::redirecionar($destino)->comCabecalho('Cache-Control', 'no-store');
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function popup(string $titulo, string $texto, string $destino): array
    {
        return [$titulo, $texto, $destino];
    }
}
