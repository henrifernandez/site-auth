<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use SiteAuth\Tokens;
use SiteAuth\TokensConta;

final class VerificacaoEmailTest extends AppBancoTestCase
{
    /**
     * Cria o usuário, gera um token de verificação direto no banco e devolve [id, token].
     *
     * @return array{0: int, 1: string}
     */
    private function comTokenDeVerificacao(string $email = 'maria@example.com'): array
    {
        $id = $this->criarUsuario($email);
        $gerado = Tokens::gerar();
        new TokensConta($this->db)->criar($id, TokensConta::VERIFICAR_EMAIL, $gerado['hash'], TokensConta::VALIDADE_VERIFICAR);

        return [$id, $gerado['token']];
    }

    private function confirmar(Navegador $n, string $token): \SiteAuth\Response
    {
        $csrf = $n->csrfDe('/verificar-email', ['token' => $token]);
        if ($csrf === '') {
            $csrf = $n->csrfDe('/esqueci-senha');
        }

        return $n->post('/verificar-email', ['csrf' => $csrf, 'token' => $token]);
    }

    private function verificadoEm(int $id): mixed
    {
        $linha = $this->db->um('SELECT email_verificado_em FROM usuarios WHERE id = :i', ['i' => $id]);
        self::assertNotNull($linha, 'usuário deveria existir');

        return $linha['email_verificado_em'];
    }

    public function testCadastroEnviaEmailDeVerificacaoDe24Horas(): void
    {
        $n = $this->navegador();
        $n->post('/cadastrar', ['nome' => 'Maria Silva', 'email' => 'maria@example.com', 'senha' => 'uma-senha-boa-1']);

        $email = $this->ultimoEmail();
        self::assertSame('Confirme seu e-mail', $email->assunto);
        self::assertSame('maria@example.com', $email->para);

        $token = $this->tokenDoEmail($email, '/verificar-email');
        $linha = $this->db->um('SELECT hash_token, tipo, EXTRACT(EPOCH FROM (expira_em - criado_em))::int AS validade FROM tokens_conta');
        self::assertSame(hash('sha256', $token), $linha['hash_token'] ?? null);
        self::assertSame('verificar_email', $linha['tipo'] ?? null);
        self::assertSame(86400, $linha['validade'] ?? null);
    }

    public function testAbrirOLinkNaoConsomeOTokenSoOBotaoConsome(): void
    {
        [$id, $token] = $this->comTokenDeVerificacao();
        $n = $this->navegador();

        $pagina = $n->get('/verificar-email', ['token' => $token]);
        self::assertSame(200, $pagina->status);
        self::assertStringContainsString('Confirmar meu e-mail', $pagina->body);
        self::assertNull($this->verificadoEm($id));
        self::assertSame(200, $this->navegador()->get('/verificar-email', ['token' => $token])->status, 'abrir de novo continua válido');

        $r = $this->confirmar($n, $token);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('E-mail confirmado', $r->body);
        self::assertNotNull($this->verificadoEm($id));
    }

    public function testTokenDeVerificacaoTambemEhDeUsoUnico(): void
    {
        [, $token] = $this->comTokenDeVerificacao();

        self::assertSame(200, $this->confirmar($this->navegador(), $token)->status);

        $segunda = $this->confirmar($this->navegador('198.51.100.8'), $token);
        self::assertSame(400, $segunda->status);
        self::assertStringContainsString('Link inválido ou expirado', $segunda->body);
        self::assertSame(400, $this->navegador()->get('/verificar-email', ['token' => $token])->status);
    }

    public function testTokenExpiradoOuInventadoEhRecusado(): void
    {
        [$id, $token] = $this->comTokenDeVerificacao();
        $this->db->executar("UPDATE tokens_conta SET expira_em = now() - interval '1 second'");

        self::assertSame(400, $this->navegador()->get('/verificar-email', ['token' => $token])->status);
        self::assertSame(400, $this->confirmar($this->navegador(), $token)->status);
        self::assertSame(400, $this->navegador()->get('/verificar-email', ['token' => str_repeat('a', 43)])->status);
        self::assertSame(400, $this->navegador()->get('/verificar-email')->status);
        self::assertNull($this->verificadoEm($id));
    }

    public function testConfirmarExigeCsrf(): void
    {
        [$id, $token] = $this->comTokenDeVerificacao();

        $r = $this->navegador()->post('/verificar-email', ['csrf' => str_repeat('0', 64), 'token' => $token]);

        self::assertSame(403, $r->status);
        self::assertNull($this->verificadoEm($id));
    }

    public function testTokenDeRecuperacaoNaoServeParaVerificar(): void
    {
        $id = $this->criarUsuario('maria@example.com');
        $gerado = Tokens::gerar();
        new TokensConta($this->db)->criar($id, TokensConta::RECUPERAR_SENHA, $gerado['hash'], 1800);

        self::assertSame(400, $this->navegador()->get('/verificar-email', ['token' => $gerado['token']])->status);
    }

    public function testContaAntigaSemVerificacaoContinuaEntrando(): void
    {
        $id = $this->criarUsuario('antiga@example.com', 'senha-de-teste-1');

        $r = $this->navegador()->post('/entrar', ['email' => 'antiga@example.com', 'senha' => 'senha-de-teste-1']);

        self::assertSame(303, $r->status);
        self::assertSame('/conta', $r->cabecalho('Location'));
        self::assertNull($this->verificadoEm($id));
    }

    public function testReenvioExigeLogin(): void
    {
        $r = $this->navegador()->post('/reenviar-verificacao', ['csrf' => 'x']);

        self::assertSame(303, $r->status);
        self::assertSame('/entrar', $r->cabecalho('Location'));
        self::assertSame([], $this->mailer->enviados);
    }

    public function testReenvioEnviaNovoLinkEInvalidaOAnterior(): void
    {
        [$id, $antigo] = $this->comTokenDeVerificacao();
        $n = $this->logar('maria@example.com');
        $csrf = $n->csrfDe('/verificar-email', ['token' => $antigo]);

        $r = $n->post('/reenviar-verificacao', ['csrf' => $csrf]);

        self::assertSame(303, $r->status);
        self::assertSame('/conta', $r->cabecalho('Location'));
        $novo = $this->tokenDoEmail($this->ultimoEmail(), '/verificar-email');
        self::assertNotSame($antigo, $novo);
        self::assertSame(400, $this->navegador()->get('/verificar-email', ['token' => $antigo])->status);
        self::assertSame(200, $this->navegador()->get('/verificar-email', ['token' => $novo])->status);
        self::assertNull($this->verificadoEm($id));
    }

    public function testReenvioTemLimiteDe3PorHora(): void
    {
        [, $token] = $this->comTokenDeVerificacao();
        $n = $this->logar('maria@example.com');
        $csrf = $n->csrfDe('/verificar-email', ['token' => $token]);

        for ($i = 1; $i <= 3; $i++) {
            self::assertSame(303, $n->post('/reenviar-verificacao', ['csrf' => $csrf])->status, "reenvio {$i}");
        }
        $quarto = $n->post('/reenviar-verificacao', ['csrf' => $csrf]);

        self::assertSame(429, $quarto->status);
        self::assertSame('3600', $quarto->cabecalho('Retry-After'));
        self::assertCount(3, $this->mailer->enviados);
    }

    public function testReenvioDeContaJaVerificadaNaoEnviaNada(): void
    {
        [$id, $token] = $this->comTokenDeVerificacao();
        $n = $this->logar('maria@example.com');
        $csrf = $n->csrfDe('/verificar-email', ['token' => $token]);
        $this->db->executar('UPDATE usuarios SET email_verificado_em = now() WHERE id = :i', ['i' => $id]);

        $r = $n->post('/reenviar-verificacao', ['csrf' => $csrf]);

        self::assertSame(303, $r->status);
        self::assertSame([], $this->mailer->enviados);
    }
}
