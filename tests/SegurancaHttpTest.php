<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use SiteAuth\Request;
use SiteAuth\Response;
use SiteAuth\Seguranca;

final class SegurancaHttpTest extends AppBancoTestCase
{
    private const string CSP_ESPERADA = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'";

    private function conferirCabecalhos(Response $r, string $contexto): void
    {
        self::assertSame(self::CSP_ESPERADA, $r->cabecalho('Content-Security-Policy'), $contexto);
        self::assertSame('nosniff', $r->cabecalho('X-Content-Type-Options'), $contexto);
        self::assertNotNull($r->cabecalho('Referrer-Policy'), $contexto);
        self::assertStringContainsString('camera=()', (string) $r->cabecalho('Permissions-Policy'), $contexto);
        self::assertStringContainsString('geolocation=()', (string) $r->cabecalho('Permissions-Policy'), $contexto);
        self::assertSame('no-store', $r->cabecalho('Cache-Control'), $contexto);
        self::assertSame('DENY', $r->cabecalho('X-Frame-Options'), $contexto);
    }

    public function testTodasAsRespostasTemOsCabecalhosDeSeguranca(): void
    {
        $this->criarUsuario('maria@example.com');
        $n = $this->navegador();

        $this->conferirCabecalhos($n->get('/entrar'), 'GET /entrar');
        $this->conferirCabecalhos($n->get('/cadastrar'), 'GET /cadastrar');
        $this->conferirCabecalhos($n->get('/esqueci-senha'), 'GET /esqueci-senha');
        $this->conferirCabecalhos($n->get('/nao-existe'), '404');
        $this->conferirCabecalhos($n->post('/', ['csrf' => 'x']), '405');
        $this->conferirCabecalhos($n->get('/redefinir-senha', ['token' => 'ruim']), 'token inválido');
        $this->conferirCabecalhos($n->post('/entrar', ['email' => 'maria@example.com', 'senha' => 'senha-de-teste-1']), 'redirecionamento do login');

        $this->conferirCabecalhos($n->get('/conta'), 'GET /conta autenticada');
    }

    public function testPaginasComTokenNaUrlNaoVazamReferrer(): void
    {
        $r = $this->navegador()->get('/redefinir-senha', ['token' => 'ruim']);

        self::assertSame('no-referrer', $r->cabecalho('Referrer-Policy'));
        self::assertSame('strict-origin-when-cross-origin', $this->navegador()->get('/entrar')->cabecalho('Referrer-Policy'));
    }

    public function testHstsSoEmHttps(): void
    {
        $http = $this->navegador()->get('/entrar');
        $https = new Navegador($this->app(), '203.0.113.10', true);

        self::assertNull($http->cabecalho('Strict-Transport-Security'));
        self::assertStringStartsWith('max-age=', (string) $https->get('/entrar')->cabecalho('Strict-Transport-Security'));
    }

    public function testErro500EhGenericoETemCabecalhos(): void
    {
        $this->db->pdo()->exec('ALTER TABLE sessoes RENAME TO sessoes_x');
        try {
            $r = $this->app()->handle(new Request('GET', '/entrar', cookies: ['sid' => str_repeat('a', 43)], remoteAddr: '203.0.113.9'));
        } finally {
            $this->db->pdo()->exec('ALTER TABLE sessoes_x RENAME TO sessoes');
        }

        self::assertSame(500, $r->status);
        $this->conferirCabecalhos($r, '500');
        foreach (['SQLSTATE', 'sessoes', 'PDO', 'Stack', '127.0.0.1', '55432', 'postgres'] as $vazamento) {
            self::assertStringNotContainsString($vazamento, $r->body);
        }
        self::assertStringContainsString('Não foi possível concluir agora. Tente novamente.', $r->body);
        self::assertStringContainsString('SQLSTATE', $this->conteudoDoLogDeErros(), 'o detalhe fica só no log do servidor');
    }

    public function testCookieDeSessaoEmHttpsEhHostSeguro(): void
    {
        $n = new Navegador($this->app(), '203.0.113.10', true);
        $r = $n->get('/entrar');

        self::assertCount(1, $r->cookies);
        self::assertStringStartsWith('__Host-sid=', $r->cookies[0]);
        self::assertStringContainsString('; Secure', $r->cookies[0]);
        self::assertStringContainsString('; HttpOnly', $r->cookies[0]);
        self::assertStringContainsString('; SameSite=Lax', $r->cookies[0]);
        self::assertStringContainsString('; Path=/', $r->cookies[0]);
    }

    public function testCabecalhosJaDefinidosNaoSaoSobrescritos(): void
    {
        $r = new Response(200, ['Referrer-Policy' => 'no-referrer']);

        self::assertSame('no-referrer', Seguranca::aplicar($r, new Request('GET', '/'))->cabecalho('Referrer-Policy'));
    }
}
