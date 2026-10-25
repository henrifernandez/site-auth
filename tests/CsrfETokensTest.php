<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use PHPUnit\Framework\TestCase;
use SiteAuth\Armazem;
use SiteAuth\Csrf;
use SiteAuth\Tokens;

final class CsrfETokensTest extends TestCase
{
    private function armazem(): Armazem
    {
        return new class implements Armazem {
            /** @var array<string, mixed> */
            private array $dados = [];

            public function obter(string $chave): mixed
            {
                return $this->dados[$chave] ?? null;
            }

            public function definir(string $chave, mixed $valor): void
            {
                $this->dados[$chave] = $valor;
            }
        };
    }

    public function testTokenCsrfEhCriadoUmaVezEReaproveitado(): void
    {
        $a = $this->armazem();
        $primeiro = Csrf::token($a);

        self::assertSame(64, strlen($primeiro));
        self::assertSame($primeiro, Csrf::token($a));
    }

    public function testValidacaoCsrf(): void
    {
        $a = $this->armazem();
        $token = Csrf::token($a);

        self::assertTrue(Csrf::valido($a, $token));
        self::assertFalse(Csrf::valido($a, ''));
        self::assertFalse(Csrf::valido($a, strrev($token)));
        self::assertFalse(Csrf::valido($this->armazem(), $token), 'sessão sem token nunca valida');
        self::assertFalse(Csrf::valido($this->armazem(), ''));
    }

    public function testTokenDeContaTem43CaracteresBase64urlEHashSha256(): void
    {
        $t = Tokens::gerar();

        self::assertTrue(Tokens::formatoValido($t['token']));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $t['token']);
        self::assertSame(hash('sha256', $t['token']), $t['hash']);
        self::assertSame(64, strlen($t['hash']));
        self::assertNotSame($t['token'], $t['hash']);
    }

    public function testTokensSaoUnicos(): void
    {
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $tokens[Tokens::gerar()['token']] = true;
        }

        self::assertCount(50, $tokens);
    }

    public function testFormatoInvalidoEhRejeitado(): void
    {
        foreach (['', 'curto', str_repeat('a', 42), str_repeat('a', 44), str_repeat('+', 43), str_repeat('a', 42) . '='] as $ruim) {
            self::assertFalse(Tokens::formatoValido($ruim), $ruim);
        }
    }
}
