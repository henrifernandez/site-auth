<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use SiteAuth\PgSessionHandler;
use SiteAuth\Request;
use SiteAuth\Response;
use SiteAuth\Sessao;

final class SessaoPgTest extends BancoTestCase
{
    private function requisicao(?string $cookie = null, bool $https = false): Request
    {
        $nome = Sessao::nomeDoCookie($https);

        return new Request('GET', '/', cookies: $cookie === null ? [] : [$nome => $cookie], remoteAddr: '203.0.113.7', https: $https, headers: ['user-agent' => 'PHPUnit']);
    }

    /** Extrai o valor do cookie de sessão de uma resposta. */
    private function valorDoCookie(Response $resposta, bool $https = false): ?string
    {
        foreach ($resposta->cookies as $linha) {
            if (preg_match('/^' . preg_quote(Sessao::nomeDoCookie($https), '/') . '=([^;]*)/', $linha, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function handler(): PgSessionHandler
    {
        return new PgSessionHandler($this->db);
    }

    public function testSessaoSemConteudoNaoGravaNemEnviaCookie(): void
    {
        $sessao = Sessao::iniciar($this->handler(), $this->requisicao());
        $resposta = $sessao->finalizar(new Response());

        self::assertSame([], $resposta->cookies);
        self::assertSame(0, $this->db->um('SELECT count(*)::int AS n FROM sessoes')['n'] ?? null);
    }

    public function testSessaoSobreviveEntreRequisicoesEGuardaSoOHash(): void
    {
        $h = $this->handler();
        $a = Sessao::iniciar($h, $this->requisicao());
        $a->definir('csrf', 'abc');
        $cookie = $this->valorDoCookie($a->finalizar(new Response()));
        self::assertNotNull($cookie);
        self::assertSame(43, strlen($cookie));

        $b = Sessao::iniciar($h, $this->requisicao($cookie));
        self::assertSame('abc', $b->obter('csrf'));

        $linha = $this->db->um('SELECT id_hash, ip, user_agent FROM sessoes');
        self::assertSame(hash('sha256', $cookie), $linha['id_hash'] ?? null);
        self::assertSame('203.0.113.7', $linha['ip'] ?? null);
        self::assertSame('PHPUnit', $linha['user_agent'] ?? null);
        self::assertSame(0, $this->db->um('SELECT count(*)::int AS n FROM sessoes WHERE id_hash = :c', ['c' => $cookie])['n'] ?? null);
    }

    public function testCookieInventadoPeloClienteNaoEhAdotado(): void
    {
        $h = $this->handler();
        $inventado = str_repeat('A', 43);
        $sessao = Sessao::iniciar($h, $this->requisicao($inventado));
        $sessao->definir('x', 1);
        $cookie = $this->valorDoCookie($sessao->finalizar(new Response()));

        self::assertNotNull($cookie);
        self::assertNotSame($inventado, $cookie);
    }

    public function testLoginTrocaOIdentificadorEVinculaOUsuario(): void
    {
        $h = $this->handler();
        $usuario = $this->criarUsuario('sessao@example.com');
        $anonima = Sessao::iniciar($h, $this->requisicao());
        $anonima->definir('csrf', 'antes');
        $antigo = $this->valorDoCookie($anonima->finalizar(new Response()));

        $sessao = Sessao::iniciar($h, $this->requisicao($antigo));
        $sessao->autenticar($usuario);
        $novo = $this->valorDoCookie($sessao->finalizar(new Response()));

        self::assertNotNull($novo);
        self::assertNotSame($antigo, $novo);
        self::assertNull(Sessao::iniciar($h, $this->requisicao($antigo))->usuarioId(), 'o identificador antigo deixa de valer');
        self::assertSame($usuario, Sessao::iniciar($h, $this->requisicao($novo))->usuarioId());
        self::assertSame(1, $this->db->um('SELECT count(*)::int AS n FROM sessoes')['n'] ?? null);
    }

    public function testExpiracaoPorInatividadeEAbsoluta(): void
    {
        $h = $this->handler();
        $usuario = $this->criarUsuario('expira@example.com');
        $s = Sessao::iniciar($h, $this->requisicao());
        $s->autenticar($usuario);
        $cookie = (string) $this->valorDoCookie($s->finalizar(new Response()));
        self::assertSame($usuario, Sessao::iniciar($h, $this->requisicao($cookie))->usuarioId());

        $this->db->executar("UPDATE sessoes SET ultimo_uso = now() - interval '3 hours'");
        self::assertNull(Sessao::iniciar($h, $this->requisicao($cookie))->usuarioId(), 'ociosa por mais de 2 h');

        $this->db->executar("UPDATE sessoes SET ultimo_uso = now(), expira_em = now() - interval '1 second'");
        self::assertNull(Sessao::iniciar($h, $this->requisicao($cookie))->usuarioId(), 'expirada de forma absoluta');

        self::assertSame(1, $h->coletarLixo());
        self::assertSame(0, $this->db->um('SELECT count(*)::int AS n FROM sessoes')['n'] ?? null);
    }

    public function testCookieEmHttpsUsaPrefixoHostESecure(): void
    {
        $s = Sessao::iniciar($this->handler(), $this->requisicao(https: true));
        $s->definir('a', 'b');
        $linha = $s->finalizar(new Response())->cookies[0] ?? '';

        self::assertStringStartsWith('__Host-sid=', $linha);
        foreach (['Path=/', 'HttpOnly', 'SameSite=Lax', 'Secure'] as $atributo) {
            self::assertStringContainsString($atributo, $linha);
        }
        self::assertStringNotContainsString('Domain', $linha);
    }

    public function testCookieEmHttpTemHttpOnlyELaxSemSecure(): void
    {
        $s = Sessao::iniciar($this->handler(), $this->requisicao());
        $s->definir('a', 'b');
        $linha = $s->finalizar(new Response())->cookies[0] ?? '';

        self::assertStringStartsWith('sid=', $linha);
        self::assertStringContainsString('HttpOnly', $linha);
        self::assertStringContainsString('SameSite=Lax', $linha);
        self::assertStringNotContainsString('Secure', $linha);
    }

    public function testDestruirApagaARowELimpaOCookie(): void
    {
        $h = $this->handler();
        $s = Sessao::iniciar($h, $this->requisicao());
        $s->definir('a', 'b');
        $cookie = (string) $this->valorDoCookie($s->finalizar(new Response()));

        $s2 = Sessao::iniciar($h, $this->requisicao($cookie));
        $s2->destruir();
        $resposta = $s2->finalizar(new Response());

        self::assertSame('', $this->valorDoCookie($resposta));
        self::assertStringContainsString('Max-Age=0', $resposta->cookies[0] ?? '');
        self::assertSame(0, $this->db->um('SELECT count(*)::int AS n FROM sessoes')['n'] ?? null);
    }

    public function testHandlerImplementaAInterfaceNativa(): void
    {
        $h = $this->handler();
        self::assertTrue($h->open('', ''));
        self::assertTrue($h->write('id-nativo', 'dados'));
        self::assertSame('dados', $h->read('id-nativo'));
        self::assertTrue($h->destroy('id-nativo'));
        self::assertSame('', $h->read('id-nativo'));
        self::assertTrue($h->close());
    }

    public function testEncerrarSessoesDoUsuario(): void
    {
        $h = $this->handler();
        $u = $this->criarUsuario('varias@example.com');
        $cookies = [];
        for ($i = 0; $i < 3; $i++) {
            $s = Sessao::iniciar($h, $this->requisicao());
            $s->autenticar($u);
            $cookies[] = (string) $this->valorDoCookie($s->finalizar(new Response()));
        }
        self::assertCount(3, $h->listarPorUsuario($u));

        self::assertTrue($h->encerrar(PgSessionHandler::hash($cookies[0]), $u));
        self::assertSame(1, $h->encerrarTodas($u, PgSessionHandler::hash($cookies[1])));
        self::assertSame($u, Sessao::iniciar($h, $this->requisicao($cookies[1]))->usuarioId());
        self::assertNull(Sessao::iniciar($h, $this->requisicao($cookies[2]))->usuarioId());
    }
}
