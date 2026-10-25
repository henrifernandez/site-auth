<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SiteAuth\AgenteUsuario;

final class AgenteUsuarioTest extends TestCase
{
    #[DataProvider('agentes')]
    public function testResume(string $userAgent, string $esperado): void
    {
        self::assertSame($esperado, AgenteUsuario::resumir($userAgent));
    }

    /** @return array<string, array{string, string}> */
    public static function agentes(): array
    {
        return [
            'firefox linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox em Linux'],
            'chrome windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36', 'Chrome em Windows'],
            'edge windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0', 'Edge em Windows'],
            'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1', 'Safari em iOS'],
            'chrome android' => ['Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36', 'Chrome em Android'],
            'vazio' => ['', 'Dispositivo desconhecido'],
            'desconhecido' => ['curl/8.0', 'Navegador desconhecido'],
        ];
    }
}
