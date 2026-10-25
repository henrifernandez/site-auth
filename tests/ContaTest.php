<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use SiteAuth\Response;
use SiteAuth\Tokens;
use SiteAuth\TokensConta;

final class ContaTest extends AppBancoTestCase
{
    private function csrf(Navegador $n): string
    {
        return $n->csrfDe('/conta');
    }

    /** @param array<string, string> $campos */
    private function postar(Navegador $n, string $rota, array $campos = []): Response
    {
        return $n->post($rota, $campos + ['csrf' => $this->csrf($n)]);
    }

    private function senhaHashDe(int $id): string
    {
        return (string) ($this->db->um('SELECT senha_hash FROM usuarios WHERE id = :i', ['i' => $id])['senha_hash'] ?? '');
    }

    public function testAreaDaContaExigeSessao(): void
    {
        $n = $this->navegador();

        $r = $n->get('/conta');
        self::assertSame(303, $r->status);
        self::assertSame('/entrar', $r->cabecalho('Location'));

        foreach (['/conta/nome', '/conta/senha', '/conta/sessoes/encerrar', '/conta/sessoes/encerrar-todas', '/conta/excluir'] as $rota) {
            $p = $n->post($rota, ['csrf' => $n->csrfDe('/entrar'), 'nome' => 'X']);
            self::assertSame(303, $p->status, $rota);
            self::assertSame('/entrar', $p->cabecalho('Location'), $rota);
        }
    }

    public function testMostraDadosEADataDeCadastroReal(): void
    {
        $id = $this->criarUsuario('maria@example.com', nome: 'Maria <b>Teste</b>');
        $this->db->executar("UPDATE usuarios SET criado_em = '2020-03-15 12:00:00+00' WHERE id = :i", ['i' => $id]);
        $n = $this->logar('maria@example.com');

        $r = $n->get('/conta');

        self::assertSame(200, $r->status);
        self::assertSame('no-store', $r->cabecalho('Cache-Control'));
        self::assertStringContainsString('Minha conta', $r->body);
        self::assertStringContainsString('15/03/2020 às 09:00', $r->body, 'data real de cadastro, no fuso de Brasília');
        self::assertStringContainsString('maria@example.com', $r->body);
        self::assertStringContainsString('Maria &lt;b&gt;Teste&lt;/b&gt;', $r->body);
        self::assertStringNotContainsString('<b>Teste</b>', $r->body);
        self::assertStringContainsString('Esta sessão', $r->body);
        self::assertStringContainsString('Login realizado com sucesso!', $r->body, 'o popup do login aparece na área da conta');
    }

    public function testAvisoDeEmailNaoConfirmadoSomeQuandoVerificado(): void
    {
        $id = $this->criarUsuario('maria@example.com');
        $n = $this->logar('maria@example.com');

        $antes = $n->get('/conta')->body;
        self::assertStringContainsString('Reenviar e-mail de verificação', $antes);
        self::assertStringContainsString('não confirmado', $antes);

        $this->db->executar('UPDATE usuarios SET email_verificado_em = now() WHERE id = :i', ['i' => $id]);
        $depois = $n->get('/conta')->body;
        self::assertStringNotContainsString('Reenviar e-mail de verificação', $depois);
        self::assertStringContainsString('(confirmado)', $depois);
    }

    public function testAlterarNome(): void
    {
        $id = $this->criarUsuario('maria@example.com');
        $n = $this->logar('maria@example.com');

        $r = $this->postar($n, '/conta/nome', ['nome' => '  Maria   da   Silva ']);

        self::assertSame(303, $r->status);
        self::assertSame('/conta', $r->cabecalho('Location'));
        self::assertSame('Maria da Silva', $this->db->um('SELECT nome FROM usuarios WHERE id = :i', ['i' => $id])['nome'] ?? null);
        self::assertStringContainsString('Nome atualizado.', $n->get('/conta')->body);
    }

    public function testAlterarNomeInvalidoMostraErro(): void
    {
        $this->criarUsuario('maria@example.com');
        $n = $this->logar('maria@example.com');

        $r = $this->postar($n, '/conta/nome', ['nome' => 'A']);

        self::assertSame(422, $r->status);
        self::assertStringContainsString('Informe seu nome completo.', $r->body);
        self::assertStringContainsString('href="#conta-nome"', $r->body);
    }

    public function testPostsDaContaExigemCsrf(): void
    {
        $id = $this->criarUsuario('maria@example.com');
        $n = $this->logar('maria@example.com');
        $falso = str_repeat('0', 64);

        $casos = [
            '/conta/nome' => ['nome' => 'Hacker'],
            '/conta/senha' => ['senha_atual' => 'senha-de-teste-1', 'senha_nova' => 'invadida-123'],
            '/conta/sessoes/encerrar-todas' => [],
            '/conta/excluir' => ['senha_excluir' => 'senha-de-teste-1', 'confirmacao' => 'EXCLUIR'],
            '/sair' => [],
        ];
        foreach ($casos as $rota => $campos) {
            self::assertSame(403, $n->post($rota, $campos + ['csrf' => $falso])->status, $rota);
        }

        self::assertSame(200, $n->get('/conta')->status, 'continua logada');
        self::assertSame('Maria Teste', $this->db->um('SELECT nome FROM usuarios WHERE id = :i', ['i' => $id])['nome'] ?? null);
    }

    public function testTrocarSenhaExigeASenhaAtual(): void
    {
        $id = $this->criarUsuario('maria@example.com', 'senha-atual-1');
        $n = $this->logar('maria@example.com', 'senha-atual-1');
        $antes = $this->senhaHashDe($id);

        $r = $this->postar($n, '/conta/senha', ['senha_atual' => 'errada-errada', 'senha_nova' => 'nova-senha-123']);

        self::assertSame(422, $r->status);
        self::assertStringContainsString('A senha atual está incorreta.', $r->body);
        self::assertSame($antes, $this->senhaHashDe($id));

        $curta = $this->postar($n, '/conta/senha', ['senha_atual' => 'senha-atual-1', 'senha_nova' => '1234567']);
        self::assertSame(422, $curta->status);
        self::assertStringContainsString('A senha precisa ter pelo menos 8 caracteres.', $curta->body);
        self::assertSame($antes, $this->senhaHashDe($id));
    }

    public function testTrocarSenhaEncerraAsOutrasSessoesEMantemEsta(): void
    {
        $id = $this->criarUsuario('maria@example.com', 'senha-atual-1');
        $celular = $this->logar('maria@example.com', 'senha-atual-1', '198.51.100.20');
        $notebook = $this->logar('maria@example.com', 'senha-atual-1', '198.51.100.21');
        $antes = (string) $notebook->valorDoCookie();

        $r = $this->postar($notebook, '/conta/senha', ['senha_atual' => 'senha-atual-1', 'senha_nova' => 'nova-senha-123']);

        self::assertSame(303, $r->status);
        self::assertSame(303, $celular->get('/conta')->status, 'a outra sessão foi encerrada');
        $depois = $notebook->get('/conta');
        self::assertSame(200, $depois->status, 'esta sessão continua');
        self::assertStringContainsString('Os outros dispositivos foram desconectados.', $depois->body);
        self::assertNotSame($antes, $notebook->valorDoCookie(), 'o identificador da sessão muda');
        self::assertSame(1, (int) ($this->db->um('SELECT count(*) AS n FROM sessoes WHERE usuario_id = :u', ['u' => $id])['n'] ?? 0));
        self::assertTrue(password_verify('nova-senha-123', $this->senhaHashDe($id)));
        self::assertSame('Sua senha foi alterada', $this->ultimoEmail()->assunto);
    }

    public function testTrocarSenhaInvalidaLinksDeRecuperacaoPendentes(): void
    {
        $id = $this->criarUsuario('maria@example.com', 'senha-atual-1');
        $gerado = Tokens::gerar();
        new TokensConta($this->db)->criar($id, TokensConta::RECUPERAR_SENHA, $gerado['hash'], 1800);
        $n = $this->logar('maria@example.com', 'senha-atual-1');

        $this->postar($n, '/conta/senha', ['senha_atual' => 'senha-atual-1', 'senha_nova' => 'nova-senha-123']);

        self::assertSame(400, $this->navegador()->get('/redefinir-senha', ['token' => $gerado['token']])->status);
    }

    public function testSenhaAtualErradaTemLimiteDeCincoTentativas(): void
    {
        $this->criarUsuario('maria@example.com', 'senha-atual-1');
        $n = $this->logar('maria@example.com', 'senha-atual-1');

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $this->postar($n, '/conta/senha', ['senha_atual' => 'errada-' . $i, 'senha_nova' => 'nova-senha-123'])->status);
        }
        $r = $this->postar($n, '/conta/senha', ['senha_atual' => 'senha-atual-1', 'senha_nova' => 'nova-senha-123']);

        self::assertSame(429, $r->status);
        self::assertStringContainsString('Muitas tentativas', $r->body);
        self::assertSame(429, $this->postar($n, '/conta/excluir', ['senha_excluir' => 'senha-atual-1', 'confirmacao' => 'EXCLUIR'])->status, 'o limite vale também para excluir');
    }

    public function testListaSessoesEEncerraUmaDelas(): void
    {
        $this->criarUsuario('maria@example.com');
        $celular = new Navegador($this->app(), '198.51.100.20', userAgent: 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36');
        $celular->post('/entrar', ['email' => 'maria@example.com', 'senha' => 'senha-de-teste-1']);
        $notebook = $this->logar('maria@example.com');

        $pagina = $notebook->get('/conta')->body;
        self::assertStringContainsString('Chrome em Android', $pagina);
        self::assertStringContainsString('Firefox em Linux', $pagina);
        self::assertStringContainsString('198.51.100.20', $pagina);
        self::assertSame(1, substr_count($pagina, 'Esta sessão</span>'));

        preg_match_all('/name="sessao" value="([0-9a-f]{64})"/', $pagina, $m);
        self::assertCount(1, $m[1], 'só as outras sessões têm botão encerrar');

        $r = $this->postar($notebook, '/conta/sessoes/encerrar', ['sessao' => $m[1][0]]);
        self::assertSame(303, $r->status);
        self::assertSame(303, $celular->get('/conta')->status, 'o celular perdeu o acesso');
        self::assertSame(200, $notebook->get('/conta')->status);
    }

    public function testNaoEncerraSessaoDeOutroUsuarioNemAAtual(): void
    {
        $this->criarUsuario('maria@example.com');
        $this->criarUsuario('joao@example.com');
        $joao = $this->logar('joao@example.com', 'senha-de-teste-1', '198.51.100.30');
        $maria = $this->logar('maria@example.com');
        $hashDoJoao = (string) ($this->db->um('SELECT id_hash FROM sessoes s JOIN usuarios u ON u.id = s.usuario_id WHERE u.email = :e', ['e' => 'joao@example.com'])['id_hash'] ?? '');

        $this->postar($maria, '/conta/sessoes/encerrar', ['sessao' => $hashDoJoao]);
        self::assertSame(200, $joao->get('/conta')->status, 'sessão de outro usuário não é afetada');

        $this->postar($maria, '/conta/sessoes/encerrar', ['sessao' => hash('sha256', (string) $maria->valorDoCookie())]);
        $pagina = $maria->get('/conta');
        self::assertSame(200, $pagina->status);
        self::assertStringContainsString('use o botão Sair', $pagina->body);
    }

    public function testSairDeTodosOsDispositivos(): void
    {
        $id = $this->criarUsuario('maria@example.com');
        $a = $this->logar('maria@example.com', 'senha-de-teste-1', '198.51.100.20');
        $b = $this->logar('maria@example.com');

        $r = $this->postar($b, '/conta/sessoes/encerrar-todas');

        self::assertSame(303, $r->status);
        self::assertSame('/entrar', $r->cabecalho('Location'));
        self::assertSame(0, (int) ($this->db->um('SELECT count(*) AS n FROM sessoes WHERE usuario_id = :u', ['u' => $id])['n'] ?? 1));
        self::assertSame(303, $a->get('/conta')->status);
        self::assertSame(303, $b->get('/conta')->status);
        self::assertStringContainsString('Você saiu de todos os dispositivos.', $b->get('/entrar')->body);
    }

    public function testSairPorPostEncerraASessao(): void
    {
        $id = $this->criarUsuario('maria@example.com');
        $n = $this->logar('maria@example.com');

        $r = $this->postar($n, '/sair');

        self::assertSame(303, $r->status);
        self::assertSame('/entrar', $r->cabecalho('Location'));
        self::assertSame(303, $n->get('/conta')->status);
        self::assertSame(0, (int) ($this->db->um('SELECT count(*) AS n FROM sessoes WHERE usuario_id = :u', ['u' => $id])['n'] ?? 1));
        self::assertSame(405, $n->get('/sair')->status, 'sair por GET não existe');
    }

    public function testExcluirContaExigeSenhaEAPalavraExcluir(): void
    {
        $id = $this->criarUsuario('maria@example.com', 'senha-atual-1');
        $n = $this->logar('maria@example.com', 'senha-atual-1');

        $semPalavra = $this->postar($n, '/conta/excluir', ['senha_excluir' => 'senha-atual-1', 'confirmacao' => 'excluir']);
        self::assertSame(422, $semPalavra->status);
        self::assertStringContainsString('Digite a palavra EXCLUIR', $semPalavra->body);

        $semSenha = $this->postar($n, '/conta/excluir', ['senha_excluir' => 'errada-errada', 'confirmacao' => 'EXCLUIR']);
        self::assertSame(422, $semSenha->status);
        self::assertStringContainsString('A senha está incorreta.', $semSenha->body);

        self::assertNotNull($this->db->um('SELECT 1 AS ok FROM usuarios WHERE id = :i', ['i' => $id]));
    }

    public function testExcluirContaApagaEmCascataEAvisaPorEmail(): void
    {
        $id = $this->criarUsuario('maria@example.com', 'senha-atual-1');
        $outroDispositivo = $this->logar('maria@example.com', 'senha-atual-1', '198.51.100.20');
        $n = $this->logar('maria@example.com', 'senha-atual-1');
        new TokensConta($this->db)->criar($id, TokensConta::VERIFICAR_EMAIL, Tokens::gerar()['hash'], 86400);
        self::assertSame(1, $this->contar('tokens_conta'));

        $r = $this->postar($n, '/conta/excluir', ['senha_excluir' => 'senha-atual-1', 'confirmacao' => 'EXCLUIR']);

        self::assertSame(303, $r->status);
        self::assertSame('/entrar', $r->cabecalho('Location'));
        self::assertSame(0, $this->contar('usuarios'));
        self::assertSame(0, $this->contar('tokens_conta'), 'tokens saem em cascata');
        self::assertSame(0, (int) ($this->db->um('SELECT count(*) AS n FROM sessoes WHERE usuario_id IS NOT NULL')['n'] ?? 1), 'sessões saem em cascata');
        self::assertSame(303, $outroDispositivo->get('/conta')->status);
        self::assertStringContainsString('Sua conta foi excluída.', $n->get('/entrar')->body);

        $aviso = $this->ultimoEmail();
        self::assertSame('Sua conta foi excluída', $aviso->assunto);
        self::assertSame('maria@example.com', $aviso->para);

        $login = $this->navegador('198.51.100.40')->post('/entrar', ['email' => 'maria@example.com', 'senha' => 'senha-atual-1']);
        self::assertSame(422, $login->status);
    }
}
