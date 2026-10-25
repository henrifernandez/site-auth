<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

final class AuthFluxoTest extends AppBancoTestCase
{
    /** @return array{nome: string, email: string, senha: string} */
    private function dadosDeCadastro(string $email = 'maria@example.com'): array
    {
        return ['nome' => 'Maria Silva', 'email' => $email, 'senha' => 'uma-senha-boa-1'];
    }

    public function testCadastroCriaUsuarioComHashEMostraPopupNaProximaPagina(): void
    {
        $n = $this->navegador();
        $r = $n->post('/cadastrar', $this->dadosDeCadastro('Maria@Example.COM'));

        self::assertSame(303, $r->status);
        self::assertSame('/entrar', $r->cabecalho('Location'));

        $linha = $this->db->um('SELECT nome, email, senha_hash, email_verificado_em FROM usuarios');
        self::assertSame('Maria Silva', $linha['nome'] ?? null);
        self::assertSame('maria@example.com', $linha['email'] ?? null, 'e-mail guardado em minúsculas');
        self::assertNotSame('uma-senha-boa-1', $linha['senha_hash'] ?? null);
        self::assertTrue(password_verify('uma-senha-boa-1', (string) ($linha['senha_hash'] ?? '')));
        self::assertNull($linha['email_verificado_em']);

        $pagina = $n->get('/entrar');
        self::assertStringContainsString('Cadastrado com sucesso!', $pagina->body);
        self::assertStringContainsString('Sua conta foi criada, Maria. Agora é só entrar.', $pagina->body);
        self::assertStringNotContainsString('Cadastrado com sucesso!', $n->get('/entrar')->body, 'o popup aparece uma vez só');
    }

    public function testCadastroComDadosInvalidosMostraErrosPorCampo(): void
    {
        $n = $this->navegador();
        $r = $n->post('/cadastrar', ['nome' => 'A', 'email' => 'nao-e-email', 'senha' => '1234567']);

        self::assertSame(422, $r->status);
        foreach (['Informe seu nome completo.', 'Informe um e-mail válido.', 'A senha precisa ter pelo menos 8 caracteres.'] as $mensagem) {
            self::assertStringContainsString($mensagem, $r->body);
        }
        self::assertStringContainsString('aria-invalid="true"', $r->body);
        self::assertStringContainsString('aria-describedby="email-erro"', $r->body);
        self::assertSame(0, $this->db->um('SELECT count(*)::int AS n FROM usuarios')['n'] ?? null);
    }

    public function testSenhaComMaisDe72BytesEhRecusada(): void
    {
        $r = $this->navegador()->post('/cadastrar', ['nome' => 'Maria', 'email' => 'm@example.com', 'senha' => str_repeat('a', 73)]);

        self::assertSame(422, $r->status);
        self::assertStringContainsString('no máximo 72 bytes', $r->body);
    }

    public function testEmailJaCadastradoMantemAMensagemAtual(): void
    {
        $this->criarUsuario('maria@example.com');
        $r = $this->navegador()->post('/cadastrar', $this->dadosDeCadastro('MARIA@example.com'));

        self::assertSame(422, $r->status);
        self::assertStringContainsString('Este e-mail já está cadastrado. Tente entrar.', $r->body);
    }

    public function testLoginCorretoAutenticaERedireciona(): void
    {
        $id = $this->criarUsuario('maria@example.com', 'senha-correta-1');
        $n = $this->navegador();
        $r = $n->post('/entrar', ['email' => ' Maria@Example.com ', 'senha' => 'senha-correta-1']);

        self::assertSame(303, $r->status);
        self::assertSame('/conta', $r->cabecalho('Location'));
        self::assertSame($id, $this->db->um('SELECT usuario_id FROM sessoes')['usuario_id'] ?? null);
        self::assertSame('/conta', $n->get('/entrar')->cabecalho('Location'), 'logado, a tela de entrada redireciona');
    }

    public function testLoginTrocaOIdentificadorDeSessao(): void
    {
        $this->criarUsuario('maria@example.com', 'senha-correta-1');
        $n = $this->navegador();
        $n->get('/entrar');
        $antes = $n->valorDoCookie();
        $n->post('/entrar', ['email' => 'maria@example.com', 'senha' => 'senha-correta-1']);

        self::assertNotNull($antes);
        self::assertNotSame($antes, $n->valorDoCookie());
    }

    public function testSessaoFixadaPeloAtacanteNaoValeDepoisDoLogin(): void
    {
        $this->criarUsuario('maria@example.com', 'senha-correta-1');
        $atacante = $this->navegador('198.51.100.66');
        $atacante->get('/entrar');
        $fixado = (string) $atacante->valorDoCookie();

        $vitima = $this->navegador();
        $vitima->definirCookie($fixado);
        $vitima->post('/entrar', ['email' => 'maria@example.com', 'senha' => 'senha-correta-1']);

        $usa = $this->navegador('198.51.100.66');
        $usa->definirCookie($fixado);
        self::assertSame(200, $usa->get('/entrar')->status, 'o identificador fixado continua anônimo');
    }

    public function testLoginErradoEUsuarioInexistenteTemMesmaRespostaGenerica(): void
    {
        $this->criarUsuario('existe@example.com', 'senha-correta-1');

        $a = $this->navegador()->post('/entrar', ['email' => 'existe@example.com', 'senha' => 'errada-errada']);
        $b = $this->navegador()->post('/entrar', ['email' => 'nao-existe@example.com', 'senha' => 'errada-errada']);

        self::assertSame($a->status, $b->status);
        self::assertSame(422, $a->status);
        self::assertStringContainsString('E-mail ou senha incorretos.', $a->body);
        self::assertSame(
            Navegador::semCsrf(str_replace('existe@example.com', 'X', $a->body)),
            Navegador::semCsrf(str_replace('nao-existe@example.com', 'X', $b->body)),
        );
    }

    public function testCamposVaziosNoLogin(): void
    {
        $r = $this->navegador()->post('/entrar', ['email' => '', 'senha' => '']);

        self::assertSame(422, $r->status);
        self::assertStringContainsString('Informe e-mail e senha.', $r->body);
    }

    public function testSextaTentativaErradaDaBloqueioComRetryAfter(): void
    {
        $this->criarUsuario('maria@example.com', 'senha-correta-1');
        $n = $this->navegador();

        for ($i = 1; $i <= 5; $i++) {
            $r = $n->post('/entrar', ['email' => 'maria@example.com', 'senha' => 'errada-' . $i], '/entrar');
            self::assertSame(422, $r->status, "falha {$i} ainda é uma resposta normal");
        }

        $r = $n->post('/entrar', ['email' => 'maria@example.com', 'senha' => 'senha-correta-1'], '/entrar');
        self::assertSame(429, $r->status, 'a 6ª tentativa é bloqueada, mesmo com a senha certa');
        self::assertSame('900', $r->cabecalho('Retry-After'));
        self::assertStringContainsString('Muitas tentativas. Aguarde alguns minutos e tente de novo.', $r->body);

        $outroIp = $this->navegador('198.51.100.77')->post('/entrar', ['email' => 'maria@example.com', 'senha' => 'senha-correta-1']);
        self::assertSame(303, $outroIp->status, 'o bloqueio é por e-mail + IP');
    }

    public function testBloqueioPorIpComMuitasFalhasEmEmailsDiferentes(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->db->executar(
                "INSERT INTO tentativas_acesso (tipo, chave, ip) VALUES ('login_falha', :c, '203.0.113.10')",
                ['c' => hash('sha256', "x{$i}")],
            );
        }

        $r = $this->navegador('203.0.113.10')->post('/entrar', ['email' => 'qualquer@example.com', 'senha' => 'qualquer-coisa']);

        self::assertSame(429, $r->status);
    }

    public function testIpVemDoCabecalhoDaVercelQuandoPresente(): void
    {
        $app = $this->app();
        $n = new Navegador($app, '10.0.0.1');
        $n->get('/entrar');
        $csrf = $n->csrfDe('/entrar');
        $r = $app->handle(new \SiteAuth\Request(
            'POST',
            '/entrar',
            post: ['csrf' => $csrf, 'email' => 'a@example.com', 'senha' => 'x'],
            headers: ['x-vercel-forwarded-for' => '198.51.100.55'],
            cookies: ['sid' => (string) $n->valorDoCookie()],
            remoteAddr: '10.0.0.1',
        ));

        self::assertSame(422, $r->status);
        self::assertSame('198.51.100.55', $this->db->um('SELECT ip FROM tentativas_acesso')['ip'] ?? null);
    }

    public function testSenhaAntigaEhAtualizadaParaOHashPadraoNoLogin(): void
    {
        $id = $this->criarUsuario('maria@example.com', 'senha-correta-1');
        $antigo = (string) ($this->db->um('SELECT senha_hash FROM usuarios WHERE id = :i', ['i' => $id])['senha_hash'] ?? '');
        self::assertTrue(password_needs_rehash($antigo, PASSWORD_DEFAULT), 'o hash de teste usa custo baixo');

        $this->navegador()->post('/entrar', ['email' => 'maria@example.com', 'senha' => 'senha-correta-1']);

        $novo = (string) ($this->db->um('SELECT senha_hash FROM usuarios WHERE id = :i', ['i' => $id])['senha_hash'] ?? '');
        self::assertNotSame($antigo, $novo);
        self::assertFalse(password_needs_rehash($novo, PASSWORD_DEFAULT));
        self::assertTrue(password_verify('senha-correta-1', $novo));
    }

    public function testPostSemCsrfOuComCsrfErradoEhRecusado(): void
    {
        $n = $this->navegador();
        $n->get('/entrar');

        $sem = $n->post('/entrar', ['csrf' => '', 'email' => 'a@example.com', 'senha' => 'x']);
        $errado = $n->post('/cadastrar', ['csrf' => str_repeat('0', 64), 'nome' => 'Maria', 'email' => 'm@example.com', 'senha' => 'senha-boa-123']);
        $semSessao = $this->navegador()->post('/entrar', ['csrf' => str_repeat('a', 64), 'email' => 'a@example.com', 'senha' => 'x']);

        foreach ([$sem, $errado, $semSessao] as $r) {
            self::assertSame(403, $r->status);
            self::assertStringContainsString('Sessão expirada. Recarregue a página e tente de novo.', $r->body);
        }
        self::assertSame(0, $this->db->um('SELECT count(*)::int AS n FROM usuarios')['n'] ?? null);
    }

    public function testRotaDesconhecidaEMetodoErradoUsamPaginaGenerica(): void
    {
        $n = $this->navegador();

        $r404 = $n->get('/nao-existe');
        self::assertSame(404, $r404->status);
        self::assertStringContainsString('Página não encontrada', $r404->body);

        $r405 = $n->post('/', ['csrf' => 'x']);
        self::assertSame(405, $r405->status);
        self::assertNotNull($r405->cabecalho('Allow'));
    }

    public function testErroInternoNaoVazaDetalhes(): void
    {
        $log = sys_get_temp_dir() . '/siteauth-teste-' . bin2hex(random_bytes(4)) . '.log';
        $anterior = ini_set('error_log', $log);
        $this->db->pdo()->exec('ALTER TABLE usuarios RENAME TO usuarios_x');
        try {
            $r = $this->navegador()->post('/cadastrar', $this->dadosDeCadastro());
        } finally {
            $this->db->pdo()->exec('ALTER TABLE usuarios_x RENAME TO usuarios');
            ini_set('error_log', $anterior === false ? '' : $anterior);
        }

        self::assertStringContainsString('SQLSTATE', (string) file_get_contents($log), 'o detalhe vai para o log do servidor');
        unlink($log);
        self::assertSame(500, $r->status);
        self::assertStringContainsString('Algo deu errado', $r->body);
        foreach (['usuarios_x', 'SQLSTATE', 'PDOException', 'relation', '.php', '127.0.0.1'] as $segredo) {
            self::assertStringNotContainsString($segredo, $r->body);
        }
    }
}
