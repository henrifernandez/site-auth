<?php

declare(strict_types=1);

namespace SiteAuth\Controladores;

use DateTimeImmutable;
use SiteAuth\AgenteUsuario;
use SiteAuth\LimitadorTentativas;
use SiteAuth\Mail\Emails;
use SiteAuth\Response;
use SiteAuth\TokensConta;
use SiteAuth\Usuario;
use SiteAuth\Validador;

/**
 * Área da conta (exige sessão): dados, nome, senha, sessões ativas e exclusão.
 */
final class ContaControlador extends Controlador
{
    public function ver(): Response
    {
        $usuario = $this->usuarioAtual();
        if ($usuario === null) {
            return $this->redirecionar('/entrar');
        }

        return $this->paginaConta($usuario, desbloqueio: $this->sessao->pegarUmaVez('desbloqueio') !== null);
    }

    public function alterarNome(): Response
    {
        $usuario = $this->usuarioAtual();
        if ($usuario === null) {
            return $this->redirecionar('/entrar');
        }
        if (!$this->csrfValido()) {
            return $this->erroSessaoExpirada();
        }

        $nome = Validador::normalizarNome($this->req->campo('nome'));
        $erro = Validador::nome($nome);
        if ($erro !== null) {
            return $this->paginaConta($usuario, 'nome', ['conta-nome' => $erro], null, 422, ['nome' => $nome]);
        }

        $this->s->usuarios->atualizarNome($usuario->id, $nome);
        $this->sessao->definir('aviso', 'Nome atualizado.');

        return $this->redirecionar('/conta');
    }

    public function alterarSenha(): Response
    {
        $usuario = $this->usuarioAtual();
        if ($usuario === null) {
            return $this->redirecionar('/entrar');
        }
        if (!$this->csrfValido()) {
            return $this->erroSessaoExpirada();
        }

        $bloqueio = $this->bloqueioDeSenhaAtual($usuario, 'senha');
        if ($bloqueio !== null) {
            return $bloqueio;
        }

        $atual = $this->req->campo('senha_atual');
        $nova = $this->req->campo('senha_nova');

        $erros = [];
        if (!password_verify($atual, $usuario->senhaHash)) {
            $this->s->limitador->registrarSenhaAtualErrada($usuario->id, $this->ip());
            $erros['senha-atual'] = 'A senha atual está incorreta.';
        }
        $erroNova = Validador::senhaNova($nova);
        if ($erroNova !== null) {
            $erros['senha-nova'] = $erroNova;
        }
        if ($erros !== []) {
            return $this->paginaConta($usuario, 'senha', $erros, null, 422);
        }

        $this->s->usuarios->atualizarSenha($usuario->id, password_hash($nova, PASSWORD_DEFAULT));
        $this->s->tokens->invalidarPendentes($usuario->id, TokensConta::RECUPERAR_SENHA);
        // Quem tinha a senha antiga perde o acesso: só esta sessão continua, com identificador novo.
        $this->s->sessoes->encerrarTodas($usuario->id, $this->sessao->hashAtual());
        $this->sessao->regenerarId();

        $this->s->notificador->enviar(Emails::senhaAlterada(
            $usuario->nome,
            $usuario->email,
            Usuario::formatarData(new DateTimeImmutable()->format('c')),
            $this->s->config->url('/esqueci-senha'),
        ));
        $this->sessao->definir('aviso', 'Senha alterada. Os outros dispositivos foram desconectados.');

        return $this->redirecionar('/conta');
    }

    public function encerrarSessao(): Response
    {
        $usuario = $this->usuarioAtual();
        if ($usuario === null) {
            return $this->redirecionar('/entrar');
        }
        if (!$this->csrfValido()) {
            return $this->erroSessaoExpirada();
        }

        $alvo = $this->req->campo('sessao');
        if (hash_equals($this->sessao->hashAtual(), $alvo)) {
            $this->sessao->definir('aviso', 'Para encerrar esta sessão, use o botão Sair.');
        } elseif ($this->s->sessoes->encerrar($alvo, $usuario->id)) {
            $this->sessao->definir('aviso', 'Sessão encerrada.');
        } else {
            $this->sessao->definir('aviso', 'Essa sessão já não existe.');
        }

        return $this->redirecionar('/conta');
    }

    public function encerrarTodas(): Response
    {
        $usuario = $this->usuarioAtual();
        if ($usuario === null) {
            return $this->redirecionar('/entrar');
        }
        if (!$this->csrfValido()) {
            return $this->erroSessaoExpirada();
        }

        $this->s->sessoes->encerrarTodas($usuario->id);
        $this->sessao->destruir();
        $this->sessao->definir('aviso', 'Você saiu de todos os dispositivos.');

        return $this->redirecionar('/entrar');
    }

    public function excluir(): Response
    {
        $usuario = $this->usuarioAtual();
        if ($usuario === null) {
            return $this->redirecionar('/entrar');
        }
        if (!$this->csrfValido()) {
            return $this->erroSessaoExpirada();
        }

        $bloqueio = $this->bloqueioDeSenhaAtual($usuario, 'excluir');
        if ($bloqueio !== null) {
            return $bloqueio;
        }

        $erros = [];
        if (!password_verify($this->req->campo('senha_excluir'), $usuario->senhaHash)) {
            $this->s->limitador->registrarSenhaAtualErrada($usuario->id, $this->ip());
            $erros['excluir-senha'] = 'A senha está incorreta.';
        }
        if (trim($this->req->campo('confirmacao')) !== 'EXCLUIR') {
            $erros['excluir-confirmacao'] = 'Digite a palavra EXCLUIR, em maiúsculas, para confirmar.';
        }
        if ($erros !== []) {
            return $this->paginaConta($usuario, 'excluir', $erros, null, 422);
        }

        // Sessões e tokens saem em cascata com a conta.
        $this->s->usuarios->excluir($usuario->id);
        $this->s->notificador->enviar(Emails::contaExcluida(
            $usuario->nome,
            $usuario->email,
            Usuario::formatarData(new DateTimeImmutable()->format('c')),
        ));

        $this->sessao->destruir();
        $this->sessao->definir('aviso', 'Sua conta foi excluída.');

        return $this->redirecionar('/entrar');
    }

    private function bloqueioDeSenhaAtual(Usuario $usuario, string $form): ?Response
    {
        if (!$this->s->limitador->senhaAtualBloqueada($usuario->id)) {
            return null;
        }

        return $this->paginaConta($usuario, $form, [], Validador::ERRO_BLOQUEIO, 429)
            ->comCabecalho('Retry-After', (string) LimitadorTentativas::JANELA_LOGIN);
    }

    /**
     * @param array<string, string> $erros idDoCampo => mensagem, do formulário $formComErro
     * @param array{nome?: string} $valores
     */
    private function paginaConta(Usuario $usuario, string $formComErro = '', array $erros = [], ?string $geral = null, int $status = 200, array $valores = [], bool $desbloqueio = false): Response
    {
        $atual = $this->sessao->hashAtual();
        $sessoes = array_map(static fn(array $s): array => [
            'id_hash' => $s['id_hash'],
            'dispositivo' => AgenteUsuario::resumir($s['user_agent']),
            'ip' => $s['ip'],
            'criada_em' => $s['criada_em'],
            'ultimo_uso' => $s['ultimo_uso'],
            'atual' => hash_equals($atual, $s['id_hash']),
        ], $this->s->sessoes->listarPorUsuario($usuario->id));

        $titulo = ($erros !== [] || $geral !== null ? 'Erro: ' : '') . 'Minha conta';

        return $this->pagina('conta', $titulo, [
            'usuario' => $usuario,
            'sessoes' => $sessoes,
            'formComErro' => $formComErro,
            'erros' => $erros,
            'geral' => $geral,
            'nomeInformado' => $valores['nome'] ?? $usuario->nome,
            'desbloqueio' => $desbloqueio,
        ], $status, $desbloqueio ? 'conta desbloqueio' : 'conta');
    }
}
