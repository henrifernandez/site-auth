<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use SiteAuth\Config;
use SiteAuth\Mail\Email;
use SiteAuth\Mail\Mailer;
use SiteAuth\Mail\MailException;
use SiteAuth\Tokens;
use SiteAuth\TokensConta;

final class RecuperacaoSenhaTest extends AppBancoTestCase
{
    private const string MENSAGEM = 'Se houver uma conta com este e-mail, enviaremos um link em alguns minutos.';

    private function pedirLink(Navegador $n, string $email): \SiteAuth\Response
    {
        return $n->post('/esqueci-senha', ['email' => $email]);
    }

    private function tokenPedido(string $email = 'maria@example.com'): string
    {
        $this->pedirLink($this->navegador(), $email);

        return $this->tokenDoEmail($this->ultimoEmail(), '/redefinir-senha');
    }

    public function testRespostaIdenticaParaEmailExistenteEInexistente(): void
    {
        $this->criarUsuario('existe@example.com');

        $a = $this->pedirLink($this->navegador(), 'existe@example.com');
        $b = $this->pedirLink($this->navegador('198.51.100.5'), 'nao-existe@example.com');

        self::assertSame(200, $a->status);
        self::assertSame($a->status, $b->status);
        self::assertStringContainsString(self::MENSAGEM, $a->body);
        self::assertSame(Navegador::semCsrf($a->body), Navegador::semCsrf($b->body), 'corpo idêntico');
        self::assertSame($a->cabecalho('Content-Type'), $b->cabecalho('Content-Type'));
        self::assertCount(1, $this->mailer->enviados, 'só a conta existente recebe e-mail');
        self::assertSame('existe@example.com', $this->mailer->enviados[0]->para);
    }

    public function testLinkUsaAppUrlEOBancoGuardaSoOHash(): void
    {
        $this->criarUsuario('maria@example.com');
        $token = $this->tokenPedido();

        self::assertStringContainsString('http://localhost:8081/redefinir-senha?token=' . $token, $this->ultimoEmail()->texto);
        self::assertSame(43, strlen($token));

        $linha = $this->db->um('SELECT hash_token, tipo, usado_em, (expira_em - criado_em) AS validade FROM tokens_conta');
        self::assertSame(hash('sha256', $token), $linha['hash_token'] ?? null);
        self::assertSame('recuperar_senha', $linha['tipo'] ?? null);
        self::assertNull($linha['usado_em']);
        self::assertSame('00:30:00', $linha['validade'] ?? null, 'expira em 30 minutos');
        self::assertSame(0, (int) ($this->db->um('SELECT count(*) AS n FROM tokens_conta WHERE hash_token = :t', ['t' => $token])['n'] ?? 1));
    }

    public function testEmailInvalidoNoPedidoEhRecusado(): void
    {
        $r = $this->pedirLink($this->navegador(), 'isso-nao-e-email');

        self::assertSame(422, $r->status);
        self::assertStringContainsString('Informe um e-mail válido.', $r->body);
        self::assertSame([], $this->mailer->enviados);
    }

    public function testRedefinicaoComTokenValido(): void
    {
        $this->criarUsuario('maria@example.com', 'senha-antiga-1');
        $token = $this->tokenPedido();
        $n = $this->navegador();

        $form = $n->get('/redefinir-senha', ['token' => $token]);
        self::assertSame(200, $form->status);
        self::assertStringContainsString('Crie uma nova senha', $form->body);
        self::assertStringContainsString('autocomplete="new-password"', $form->body);
        self::assertSame('no-referrer', $form->cabecalho('Referrer-Policy'));
        self::assertSame('no-store', $form->cabecalho('Cache-Control'));

    }

    public function testRedefinicaoCompletaTrocaASenha(): void
    {
        $this->criarUsuario('maria@example.com', 'senha-antiga-1');
        $token = $this->tokenPedido();
        $n = $this->navegador();
        $r = $this->redefinir($n, $token, 'senha-nova-123');

        self::assertSame(303, $r->status);
        self::assertSame('/entrar', $r->cabecalho('Location'));
        self::assertStringContainsString('Senha redefinida', $n->get('/entrar')->body);

        self::assertSame(422, $this->navegador('198.51.100.1')->post('/entrar', ['email' => 'maria@example.com', 'senha' => 'senha-antiga-1'])->status);
        self::assertSame(303, $this->navegador('198.51.100.2')->post('/entrar', ['email' => 'maria@example.com', 'senha' => 'senha-nova-123'])->status);
    }

    /** Lê o CSRF do formulário de redefinição e envia a nova senha. */
    private function redefinir(Navegador $n, string $token, string $senha): \SiteAuth\Response
    {
        $pagina = $n->get('/redefinir-senha', ['token' => $token])->body;
        $csrf = preg_match('/name="csrf" value="([0-9a-f]{64})"/', $pagina, $m) === 1 ? $m[1] : '';
        if ($csrf === '') {
            // Token inválido: não há formulário, então usa o CSRF de outra página da mesma sessão.
            $csrf = $n->csrfDe('/esqueci-senha');
        }

        return $n->post('/redefinir-senha', ['csrf' => $csrf, 'token' => $token, 'senha' => $senha]);
    }

    public function testTokenSoFuncionaUmaVez(): void
    {
        $this->criarUsuario('maria@example.com');
        $token = $this->tokenPedido();

        self::assertSame(303, $this->redefinir($this->navegador(), $token, 'primeira-senha-1')->status);

        $segunda = $this->redefinir($this->navegador('198.51.100.3'), $token, 'segunda-senha-2');
        self::assertSame(400, $segunda->status);
        self::assertStringContainsString('Link inválido ou expirado', $segunda->body);
        self::assertSame(400, $this->navegador()->get('/redefinir-senha', ['token' => $token])->status);

        $ok = $this->navegador('198.51.100.4')->post('/entrar', ['email' => 'maria@example.com', 'senha' => 'primeira-senha-1']);
        self::assertSame(303, $ok->status, 'continua valendo a primeira senha');
    }

    public function testTokenExpiradoEhRecusado(): void
    {
        $this->criarUsuario('maria@example.com');
        $token = $this->tokenPedido();
        $this->db->executar("UPDATE tokens_conta SET expira_em = now() - interval '1 second'");

        $n = $this->navegador();
        self::assertSame(400, $n->get('/redefinir-senha', ['token' => $token])->status);
        self::assertSame(400, $this->redefinir($n, $token, 'senha-nova-123')->status);
    }

    public function testTokensInventadosOuMalFormadosSaoRecusados(): void
    {
        $this->criarUsuario('maria@example.com');
        $this->tokenPedido();
        $n = $this->navegador();

        foreach (['', 'curto', str_repeat('a', 43), str_repeat('!', 43), "x' OR '1'='1", str_repeat('a', 500)] as $ruim) {
            self::assertSame(400, $n->get('/redefinir-senha', ['token' => $ruim])->status, $ruim);
            self::assertSame(400, $this->redefinir($n, $ruim, 'senha-nova-123')->status, $ruim);
        }
    }

    public function testSenhaFracaNaoConsomeOToken(): void
    {
        $this->criarUsuario('maria@example.com');
        $token = $this->tokenPedido();
        $n = $this->navegador();

        $curta = $this->redefinir($n, $token, '1234567');
        self::assertSame(422, $curta->status);
        self::assertStringContainsString('A senha precisa ter pelo menos 8 caracteres.', $curta->body);
        self::assertStringContainsString('name="token" value="' . $token . '"', $curta->body);

        $longa = $this->redefinir($n, $token, str_repeat('a', 73));
        self::assertSame(422, $longa->status);

        self::assertSame(303, $this->redefinir($n, $token, 'agora-sim-123')->status);
    }

    public function testPostSemCsrfNaoConsomeOToken(): void
    {
        $this->criarUsuario('maria@example.com');
        $token = $this->tokenPedido();

        $r = $this->navegador()->post('/redefinir-senha', ['csrf' => str_repeat('0', 64), 'token' => $token, 'senha' => 'senha-nova-123']);

        self::assertSame(403, $r->status);
        self::assertSame(303, $this->redefinir($this->navegador(), $token, 'senha-nova-123')->status);
    }

    public function testNovoPedidoInvalidaOLinkAnterior(): void
    {
        $this->criarUsuario('maria@example.com');
        $primeiro = $this->tokenPedido();
        $segundo = $this->tokenPedido();

        self::assertNotSame($primeiro, $segundo);
        self::assertSame(400, $this->navegador()->get('/redefinir-senha', ['token' => $primeiro])->status);
        self::assertSame(200, $this->navegador()->get('/redefinir-senha', ['token' => $segundo])->status);
    }

    public function testRedefinirEncerraTodasAsSessoesEAvisaPorEmail(): void
    {
        $id = $this->criarUsuario('maria@example.com', 'senha-antiga-1');
        $celular = $this->logar('maria@example.com', 'senha-antiga-1', '198.51.100.20');
        $notebook = $this->logar('maria@example.com', 'senha-antiga-1', '198.51.100.21');
        self::assertSame(2, (int) ($this->db->um('SELECT count(*) AS n FROM sessoes WHERE usuario_id = :u', ['u' => $id])['n'] ?? 0));
        self::assertSame(303, $celular->get('/entrar')->status);

        $token = $this->tokenPedido();
        $this->redefinir($this->navegador(), $token, 'senha-nova-123');

        self::assertSame(0, (int) ($this->db->um('SELECT count(*) AS n FROM sessoes WHERE usuario_id = :u', ['u' => $id])['n'] ?? 1));
        self::assertSame(200, $celular->get('/entrar')->status, 'a sessão antiga deixou de valer');
        self::assertSame(200, $notebook->get('/entrar')->status);

        $aviso = $this->ultimoEmail();
        self::assertSame('Sua senha foi alterada', $aviso->assunto);
        self::assertSame('maria@example.com', $aviso->para);
    }

    public function testRedefinirMarcaOEmailComoVerificado(): void
    {
        $id = $this->criarUsuario('maria@example.com');
        $this->redefinir($this->navegador(), $this->tokenPedido(), 'senha-nova-123');

        self::assertNotNull($this->db->um('SELECT email_verificado_em FROM usuarios WHERE id = :i', ['i' => $id])['email_verificado_em'] ?? null);
    }

    public function testLimiteDe3PedidosPorEmailPorHoraSemRevelarOBloqueio(): void
    {
        $this->criarUsuario('maria@example.com');
        $respostas = [];
        for ($i = 0; $i < 5; $i++) {
            $respostas[] = $this->pedirLink($this->navegador('198.51.100.' . (30 + $i)), 'maria@example.com');
        }

        self::assertCount(3, $this->mailer->enviados, 'só 3 e-mails por hora');
        foreach ($respostas as $r) {
            self::assertSame(200, $r->status);
            self::assertSame(Navegador::semCsrf($respostas[0]->body), Navegador::semCsrf($r->body), 'mesma resposta mesmo bloqueado');
        }
    }

    public function testLimiteDe10PedidosPorIpPorHora(): void
    {
        $n = $this->navegador('198.51.100.99');
        for ($i = 0; $i < 12; $i++) {
            $this->criarUsuario("u{$i}@example.com");
            $r = $this->pedirLink($n, "u{$i}@example.com");
            self::assertSame(200, $r->status);
        }

        self::assertCount(10, $this->mailer->enviados);
    }

    public function testSemAppUrlNaoEnviaEmailMasRespondeIgual(): void
    {
        $this->criarUsuario('maria@example.com');
        $app = $this->app(null, new Config(appUrl: null));
        $r = $this->pedirLink($this->navegador('203.0.113.10', $app), 'maria@example.com');

        self::assertSame(200, $r->status);
        self::assertStringContainsString(self::MENSAGEM, $r->body);
        self::assertSame([], $this->mailer->enviados);
        self::assertStringContainsString('APP_URL não definida', $this->conteudoDoLogDeErros());
    }

    public function testFalhaNoEnvioNaoQuebraARespostaNemVazaDetalhes(): void
    {
        $this->criarUsuario('maria@example.com');
        $quebrado = new class implements Mailer {
            public function enviar(Email $email): void
            {
                throw new MailException('HTTP 401 do provedor');
            }
        };
        $r = $this->pedirLink($this->navegador('203.0.113.10', $this->app($quebrado)), 'maria@example.com');

        self::assertSame(200, $r->status);
        self::assertStringContainsString(self::MENSAGEM, $r->body);
        self::assertStringNotContainsString('401', $r->body);
        self::assertStringContainsString('Falha ao enviar e-mail', $this->conteudoDoLogDeErros());
    }

    public function testConsumoDoTokenEhAtomico(): void
    {
        $id = $this->criarUsuario('maria@example.com');
        $gerado = Tokens::gerar();
        $tokens = new TokensConta($this->db);
        $tokens->criar($id, TokensConta::RECUPERAR_SENHA, $gerado['hash'], 1800);

        self::assertSame($id, $tokens->consumir(TokensConta::RECUPERAR_SENHA, $gerado['hash']));
        self::assertNull($tokens->consumir(TokensConta::RECUPERAR_SENHA, $gerado['hash']));
        self::assertNull($tokens->verificar(TokensConta::RECUPERAR_SENHA, $gerado['hash']));
    }

    public function testTokenDeVerificacaoNaoServeParaRecuperarSenha(): void
    {
        $id = $this->criarUsuario('maria@example.com');
        $gerado = Tokens::gerar();
        new TokensConta($this->db)->criar($id, TokensConta::VERIFICAR_EMAIL, $gerado['hash'], 86400);

        self::assertSame(400, $this->navegador()->get('/redefinir-senha', ['token' => $gerado['token']])->status);
    }
}
