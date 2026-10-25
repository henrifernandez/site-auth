<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SiteAuth\Request;
use SiteAuth\Response;
use SiteAuth\Router;

final class RouterTest extends TestCase
{
    private function roteador(): Router
    {
        $r = new Router();
        $r->adicionar('GET', '/ola', static fn(Request $q): Response => Response::texto('ola'));
        $r->adicionar('POST', '/ola', static fn(Request $q): Response => Response::texto('postado', 201));

        return $r;
    }

    public function testRotaConhecida(): void
    {
        $resposta = $this->roteador()->despachar(new Request('GET', '/ola'));

        self::assertSame(200, $resposta->status);
        self::assertSame('ola', $resposta->body);
    }

    public function testBarraFinalEhIgnorada(): void
    {
        self::assertSame(200, $this->roteador()->despachar(new Request('GET', '/ola/'))->status);
    }

    public function testRotaDesconhecidaDa404(): void
    {
        self::assertSame(404, $this->roteador()->despachar(new Request('GET', '/nada'))->status);
    }

    #[DataProvider('caminhosInternos')]
    public function testArquivosDeCodigoDao404(string $caminho): void
    {
        self::assertSame(404, $this->roteador()->despachar(new Request('GET', $caminho))->status);
    }

    /** @return array<string, list<string>> */
    public static function caminhosInternos(): array
    {
        return [
            'index.php' => ['/index.php'],
            'api.php' => ['/api.php'],
            'src' => ['/src/App.php'],
            'entrada' => ['/api/index.php'],
        ];
    }

    public function testMetodoNaoPermitidoDa405ComAllow(): void
    {
        $resposta = $this->roteador()->despachar(new Request('DELETE', '/ola'));

        self::assertSame(405, $resposta->status);
        self::assertSame('GET, POST, HEAD', $resposta->cabecalho('Allow'));
    }

    public function testHeadUsaOHandlerDoGet(): void
    {
        self::assertSame(200, $this->roteador()->despachar(new Request('HEAD', '/ola'))->status);
    }

    public function testRequestFromGlobalsDecodificaCaminhoECabecalhos(): void
    {
        $r = Request::fromGlobals(
            ['REQUEST_METHOD' => 'post', 'REQUEST_URI' => '/a%20b?x=1', 'HTTP_X_VERCEL_FORWARDED_FOR' => '203.0.113.9', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            ['x' => '1'],
            [],
            [],
        );

        self::assertSame('POST', $r->method);
        self::assertSame('/a b', $r->path);
        self::assertTrue($r->https);
        self::assertSame('203.0.113.9', $r->ip());
        self::assertSame('10.0.0.1', $r->ip(false));
    }

    public function testIpInvalidoNoCabecalhoCaiParaRemoteAddr(): void
    {
        $r = new Request('GET', '/', headers: ['x-vercel-forwarded-for' => 'lixo'], remoteAddr: '10.0.0.2');

        self::assertSame('10.0.0.2', $r->ip());
    }
}
