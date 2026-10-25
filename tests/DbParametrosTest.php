<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SiteAuth\Db;

final class DbParametrosTest extends TestCase
{
    public function testNeonRemovePoolerEEnviaEndpoint(): void
    {
        $p = Db::parametros('postgresql://usuario:s%40nha@ep-exemplo-123-pooler.sa-east-1.aws.neon.tech/meubanco?sslmode=require&channel_binding=require');

        self::assertSame(
            "pgsql:host=ep-exemplo-123.sa-east-1.aws.neon.tech;port=5432;dbname=meubanco;sslmode=require;options='endpoint=ep-exemplo-123'",
            $p['dsn'],
        );
        self::assertSame('usuario', $p['usuario']);
        self::assertSame('s@nha', $p['senha']);
    }

    public function testNeonSemSslmodeExigeSsl(): void
    {
        $p = Db::parametros('postgresql://u:p@ep-a.neon.tech/neondb');

        self::assertStringContainsString('sslmode=require', $p['dsn']);
    }

    public function testPostgresLocalNaoEnviaOptionsDoNeon(): void
    {
        $p = Db::parametros('postgresql://postgres:x@127.0.0.1:55432/siteauth_test');

        self::assertSame('pgsql:host=127.0.0.1;port=55432;dbname=siteauth_test;sslmode=prefer', $p['dsn']);
    }

    public function testUrlSemSenhaParaOBancoDescartavelDoCi(): void
    {
        $p = Db::parametros('postgresql://postgres@localhost:5432/siteauth_test');

        self::assertSame('postgres', $p['usuario']);
        self::assertSame('', $p['senha']);
        self::assertSame('pgsql:host=localhost;port=5432;dbname=siteauth_test;sslmode=prefer', $p['dsn']);
    }

    public function testUrlInvalida(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Db::parametros('isto nao e uma url');
    }
}
