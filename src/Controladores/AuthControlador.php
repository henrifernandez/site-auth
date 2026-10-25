<?php

declare(strict_types=1);

namespace SiteAuth\Controladores;

use SiteAuth\LimitadorTentativas;
use SiteAuth\Response;
use SiteAuth\Validador;

/**
 * Entrar, criar conta e sair.
 */
final class AuthControlador extends Controlador
{
    /**
     * Hash de uma senha descartável, no custo padrão atual. Quando o e-mail não existe, o password_verify
     * roda contra ele para a resposta demorar o mesmo que a de um e-mail existente.
     */
    private const string HASH_FALSO = '$2y$12$CWJrVu/zKBJFo9w0CFPIT.rE5Bl7ZzVklC7qk9Djq4T02R.CKQFdK';

    public function inicio(): Response
    {
        return $this->redirecionar($this->usuarioAtual() !== null ? '/conta' : '/entrar');
    }

    public function entrarForm(): Response
    {
        if ($this->usuarioAtual() !== null) {
            return $this->redirecionar('/conta');
        }

        return $this->formEntrar('');
    }

    public function entrar(): Response
    {
        if (!$this->csrfValido()) {
            return $this->formEntrar($this->req->campo('email'), [], Validador::ERRO_CSRF, 403);
        }

        $email = Validador::normalizarEmail($this->req->campo('email'));
        $senha = $this->req->campo('senha');
        if ($email === '' || $senha === '') {
            return $this->formEntrar($email, [], 'Informe e-mail e senha.', 422);
        }

        $ip = $this->ip();
        if ($this->s->limitador->loginBloqueado($email, $ip)) {
            return $this->formEntrar($email, [], Validador::ERRO_BLOQUEIO, 429)
                ->comCabecalho('Retry-After', (string) LimitadorTentativas::JANELA_LOGIN);
        }

        $usuario = $this->s->usuarios->buscarPorEmail($email);
        $senhaCorreta = password_verify($senha, $usuario->senhaHash ?? self::HASH_FALSO);

        if ($usuario === null || !$senhaCorreta) {
            $this->s->limitador->registrarFalhaDeLogin($email, $ip);

            return $this->formEntrar($email, [], Validador::ERRO_LOGIN, 422);
        }

        if (password_needs_rehash($usuario->senhaHash, PASSWORD_DEFAULT)) {
            $this->s->usuarios->atualizarSenha($usuario->id, password_hash($senha, PASSWORD_DEFAULT));
        }

        $this->s->limitador->limparFalhasDeLogin($email, $ip);
        $this->sessao->autenticar($usuario->id);
        $this->sessao->definir('popup', $this->popup('Login realizado com sucesso!', 'Que bom ver você, ' . $usuario->primeiroNome() . '.', '/conta'));

        return $this->redirecionar('/conta');
    }

    public function cadastrarForm(): Response
    {
        if ($this->usuarioAtual() !== null) {
            return $this->redirecionar('/conta');
        }

        return $this->formCadastrar('', '');
    }

    public function cadastrar(): Response
    {
        if (!$this->csrfValido()) {
            return $this->formCadastrar($this->req->campo('nome'), $this->req->campo('email'), [], Validador::ERRO_CSRF, 403);
        }

        $nome = Validador::normalizarNome($this->req->campo('nome'));
        $email = Validador::normalizarEmail($this->req->campo('email'));
        $senha = $this->req->campo('senha');

        $ip = $this->ip();
        if ($this->s->limitador->cadastroExcedido($ip)) {
            return $this->formCadastrar($nome, $email, [], Validador::ERRO_BLOQUEIO, 429)
                ->comCabecalho('Retry-After', (string) LimitadorTentativas::JANELA_HORA);
        }
        $this->s->limitador->registrarCadastro($ip);

        $erros = array_filter([
            'nome' => Validador::nome($nome),
            'email' => Validador::email($email),
            'senha' => Validador::senhaNova($senha),
        ]);
        if ($erros !== []) {
            return $this->formCadastrar($nome, $email, $erros, null, 422);
        }

        $id = $this->s->usuarios->criar($nome, $email, password_hash($senha, PASSWORD_DEFAULT));
        if ($id === null) {
            return $this->formCadastrar($nome, $email, ['email' => Validador::ERRO_EMAIL_EXISTE], null, 422);
        }

        $this->s->verificacao->enviar($id, $nome, $email);

        $primeiro = explode(' ', $nome)[0];
        $this->sessao->definir('popup', $this->popup('Cadastrado com sucesso!', 'Sua conta foi criada, ' . $primeiro . '. Agora é só entrar.', '/entrar'));

        return $this->redirecionar('/entrar');
    }

    public function sair(): Response
    {
        if (!$this->csrfValido()) {
            return $this->erroSessaoExpirada();
        }

        $this->sessao->destruir();
        $this->sessao->definir('aviso', 'Você saiu da conta.');

        return $this->redirecionar('/entrar');
    }

    /** @param array<string, string> $erros */
    private function formEntrar(string $email, array $erros = [], ?string $geral = null, int $status = 200): Response
    {
        return $this->pagina('entrar', $this->tituloComErro('Entrar', $erros, $geral), [
            'email' => $email,
            'erros' => $erros,
            'geral' => $geral,
        ], $status);
    }

    /** @param array<string, string> $erros */
    private function formCadastrar(string $nome, string $email, array $erros = [], ?string $geral = null, int $status = 200): Response
    {
        return $this->pagina('cadastrar', $this->tituloComErro('Criar conta', $erros, $geral), [
            'nome' => $nome,
            'email' => $email,
            'erros' => $erros,
            'geral' => $geral,
        ], $status);
    }

    /** @param array<string, string> $erros */
    private function tituloComErro(string $titulo, array $erros, ?string $geral): string
    {
        return ($erros !== [] || $geral !== null ? 'Erro: ' : '') . $titulo;
    }
}
