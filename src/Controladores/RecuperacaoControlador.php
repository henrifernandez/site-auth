<?php

declare(strict_types=1);

namespace SiteAuth\Controladores;

use DateTimeImmutable;
use SiteAuth\Mail\Emails;
use SiteAuth\Response;
use SiteAuth\Tokens;
use SiteAuth\TokensConta;
use SiteAuth\Usuario;
use SiteAuth\Validador;

/**
 * Recuperação de senha: pedido do link e redefinição.
 */
final class RecuperacaoControlador extends Controlador
{
    public const string MENSAGEM_ENVIADO = 'Se houver uma conta com este e-mail, enviaremos um link em alguns minutos.';

    public function esqueciForm(): Response
    {
        if ($this->usuarioAtual() !== null) {
            return $this->redirecionar('/conta');
        }

        return $this->formEsqueci('');
    }

    /**
     * A resposta é sempre a mesma, exista a conta ou não, e também quando o limite de pedidos foi atingido.
     */
    public function esqueci(): Response
    {
        if (!$this->csrfValido()) {
            return $this->formEsqueci($this->req->campo('email'), [], Validador::ERRO_CSRF, 403);
        }

        $email = Validador::normalizarEmail($this->req->campo('email'));
        $erro = Validador::email($email);
        if ($erro !== null) {
            return $this->formEsqueci($email, ['email' => $erro], null, 422);
        }

        $ip = $this->ip();
        if (!$this->s->limitador->recuperacaoExcedida($email, $ip)) {
            $this->s->limitador->registrarRecuperacao($email, $ip);
            $usuario = $this->s->usuarios->buscarPorEmail($email);
            if ($usuario !== null) {
                $this->enviarLink($usuario);
            }
        }

        return $this->pagina('esqueci', 'Esqueci minha senha', [
            'enviado' => true,
            'mensagem' => self::MENSAGEM_ENVIADO,
            'email' => '',
            'erros' => [],
            'geral' => null,
        ]);
    }

    public function redefinirForm(): Response
    {
        $token = $this->req->parametro('token');
        if ($this->usuarioDoToken($token) === null) {
            return $this->tokenInvalido();
        }

        return $this->formRedefinir($token);
    }

    public function redefinir(): Response
    {
        $token = $this->req->campo('token');
        if (!$this->csrfValido()) {
            return $this->formRedefinir($token, [], Validador::ERRO_CSRF, 403);
        }

        // O token só é consumido depois que a senha nova passa na validação.
        if ($this->usuarioDoToken($token) === null) {
            return $this->tokenInvalido();
        }

        $senha = $this->req->campo('senha');
        $erro = Validador::senhaNova($senha);
        if ($erro !== null) {
            return $this->formRedefinir($token, ['senha' => $erro], null, 422);
        }

        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $usuarioId = $this->s->db->transacao(function () use ($token, $novoHash): ?int {
            $id = $this->s->tokens->consumir(TokensConta::RECUPERAR_SENHA, Tokens::hash($token));
            if ($id === null) {
                return null;
            }
            $this->s->usuarios->atualizarSenha($id, $novoHash);
            // Quem recebeu o link no e-mail comprovou que controla o endereço.
            $this->s->usuarios->marcarEmailVerificado($id);
            $this->s->tokens->invalidarPendentes($id, TokensConta::RECUPERAR_SENHA);
            $this->s->sessoes->encerrarTodas($id);

            return $id;
        });

        if ($usuarioId === null) {
            return $this->tokenInvalido();
        }

        $usuario = $this->s->usuarios->buscarPorId($usuarioId);
        if ($usuario !== null) {
            $this->avisarSenhaAlterada($usuario);
        }

        $this->sessao->definir('aviso', 'Senha redefinida. Entre com a nova senha.');

        return $this->redirecionar('/entrar');
    }

    private function usuarioDoToken(string $token): ?int
    {
        if (!Tokens::formatoValido($token)) {
            return null;
        }

        return $this->s->tokens->verificar(TokensConta::RECUPERAR_SENHA, Tokens::hash($token));
    }

    private function enviarLink(Usuario $usuario): void
    {
        $gerado = Tokens::gerar();
        $this->s->tokens->criar($usuario->id, TokensConta::RECUPERAR_SENHA, $gerado['hash'], TokensConta::VALIDADE_RECUPERAR);

        $link = $this->s->config->url('/redefinir-senha?token=' . $gerado['token']);
        if ($link === null) {
            error_log('APP_URL não definida: e-mail de recuperação de senha não enviado.');

            return;
        }

        $this->s->notificador->enviar(Emails::recuperarSenha($usuario->nome, $usuario->email, $link));
    }

    private function avisarSenhaAlterada(Usuario $usuario): void
    {
        $this->s->notificador->enviar(Emails::senhaAlterada(
            $usuario->nome,
            $usuario->email,
            Usuario::formatarData(new DateTimeImmutable()->format('c')),
            $this->s->config->url('/esqueci-senha'),
        ));
    }

    /** @param array<string, string> $erros */
    private function formEsqueci(string $email, array $erros = [], ?string $geral = null, int $status = 200): Response
    {
        return $this->pagina('esqueci', ($erros !== [] || $geral !== null ? 'Erro: ' : '') . 'Esqueci minha senha', [
            'enviado' => false,
            'mensagem' => self::MENSAGEM_ENVIADO,
            'email' => $email,
            'erros' => $erros,
            'geral' => $geral,
        ], $status);
    }

    /** @param array<string, string> $erros */
    private function formRedefinir(string $token, array $erros = [], ?string $geral = null, int $status = 200): Response
    {
        return $this->pagina('redefinir', ($erros !== [] || $geral !== null ? 'Erro: ' : '') . 'Redefinir senha', [
            'token' => $token,
            'erros' => $erros,
            'geral' => $geral,
        ], $status)->comCabecalho('Referrer-Policy', 'no-referrer');
    }

    private function tokenInvalido(): Response
    {
        return $this->pagina('redefinir_invalido', 'Link inválido', [], 400)
            ->comCabecalho('Referrer-Policy', 'no-referrer');
    }
}
