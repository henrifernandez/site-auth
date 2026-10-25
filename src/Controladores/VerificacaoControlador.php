<?php

declare(strict_types=1);

namespace SiteAuth\Controladores;

use SiteAuth\LimitadorTentativas;
use SiteAuth\Response;
use SiteAuth\Tokens;
use SiteAuth\TokensConta;
use SiteAuth\Validador;

/**
 * Verificação de e-mail: página de confirmação e reenvio do link.
 */
final class VerificacaoControlador extends Controlador
{
    /**
     * O link do e-mail só mostra o botão de confirmar. Quem consome o token é o POST, para que
     * leitores de e-mail e antivírus que abrem links não gastem o token sem querer.
     */
    public function confirmarForm(): Response
    {
        $token = $this->req->parametro('token');
        if ($this->usuarioDoToken($token) === null) {
            return $this->tokenInvalido();
        }

        return $this->pagina('verificar', 'Confirmar e-mail', ['token' => $token])
            ->comCabecalho('Referrer-Policy', 'no-referrer');
    }

    public function confirmar(): Response
    {
        if (!$this->csrfValido()) {
            return $this->erroSessaoExpirada();
        }

        $token = $this->req->campo('token');
        if (!Tokens::formatoValido($token)) {
            return $this->tokenInvalido();
        }

        $usuarioId = $this->s->tokens->consumir(TokensConta::VERIFICAR_EMAIL, Tokens::hash($token));
        if ($usuarioId === null) {
            return $this->tokenInvalido();
        }
        $this->s->usuarios->marcarEmailVerificado($usuarioId);

        return $this->pagina('verificar_ok', 'E-mail confirmado', [
            'logado' => $this->sessao->usuarioId() !== null,
        ]);
    }

    public function reenviar(): Response
    {
        $usuario = $this->usuarioAtual();
        if ($usuario === null) {
            return $this->redirecionar('/entrar');
        }
        if (!$this->csrfValido()) {
            return $this->erroSessaoExpirada();
        }

        if ($usuario->emailVerificado()) {
            $this->sessao->definir('aviso', 'Seu e-mail já está confirmado.');

            return $this->redirecionar('/conta');
        }

        $ip = $this->ip();
        if ($this->s->limitador->verificacaoExcedida($usuario->id, $ip)) {
            return $this->paginaDeErro(429, 'Muitas tentativas', Validador::ERRO_BLOQUEIO)
                ->comCabecalho('Retry-After', (string) LimitadorTentativas::JANELA_HORA);
        }
        $this->s->limitador->registrarVerificacao($usuario->id, $ip);

        $enviado = $this->s->verificacao->enviar($usuario->id, $usuario->nome, $usuario->email);
        $this->sessao->definir('aviso', $enviado
            ? 'Enviamos um novo e-mail de verificação. Ele vale por 24 horas.'
            : 'Não foi possível enviar o e-mail agora. Tente novamente em alguns minutos.');

        return $this->redirecionar('/conta');
    }

    private function usuarioDoToken(string $token): ?int
    {
        if (!Tokens::formatoValido($token)) {
            return null;
        }

        return $this->s->tokens->verificar(TokensConta::VERIFICAR_EMAIL, Tokens::hash($token));
    }

    private function tokenInvalido(): Response
    {
        return $this->pagina('verificar_invalido', 'Link inválido', [
            'logado' => $this->sessao->usuarioId() !== null,
        ], 400)->comCabecalho('Referrer-Policy', 'no-referrer');
    }
}
