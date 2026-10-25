<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SiteAuth\Validador;

final class ValidadorTest extends TestCase
{
    public function testNormalizaEmailParaMinusculasSemEspacos(): void
    {
        self::assertSame('ana@example.com', Validador::normalizarEmail("  Ana@Example.COM \n"));
    }

    public function testNormalizaNomeColapsandoEspacos(): void
    {
        self::assertSame('Ana Maria Silva', Validador::normalizarNome("  Ana   Maria\tSilva "));
    }

    #[DataProvider('nomes')]
    public function testNome(string $nome, bool $valido): void
    {
        $esperado = $valido ? null : 'Informe seu nome completo.';
        self::assertSame($esperado, Validador::nome(Validador::normalizarNome($nome)));
    }

    /** @return array<string, array{string, bool}> */
    public static function nomes(): array
    {
        return [
            'vazio' => ['', false],
            'uma letra' => ['A', false],
            'dois caracteres' => ['Jo', true],
            'com acento' => ['João', true],
            'só espaços' => ['   ', false],
            'longo demais' => [str_repeat('a', 101), false],
            'controle' => ["Ana\x07Maria", false],
        ];
    }

    #[DataProvider('emails')]
    public function testEmail(string $email, bool $valido): void
    {
        $esperado = $valido ? null : 'Informe um e-mail válido.';
        self::assertSame($esperado, Validador::email(Validador::normalizarEmail($email)));
    }

    /** @return array<string, array{string, bool}> */
    public static function emails(): array
    {
        return [
            'comum' => ['ana@example.com', true],
            'maiúsculas' => ['ANA@EXAMPLE.COM', true],
            'com mais' => ['ana+teste@example.com.br', true],
            'sem arroba' => ['ana.example.com', false],
            'sem domínio' => ['ana@', false],
            'vazio' => ['', false],
            'com espaço' => ['ana maria@example.com', false],
            'longo demais' => [str_repeat('a', 250) . '@example.com', false],
        ];
    }

    #[DataProvider('senhas')]
    public function testSenhaDe8A72Bytes(string $senha, ?string $erro): void
    {
        self::assertSame($erro, Validador::senhaNova($senha));
    }

    /** @return array<string, array{string, string|null}> */
    public static function senhas(): array
    {
        $curta = 'A senha precisa ter pelo menos 8 caracteres.';
        $longa = 'A senha pode ter no máximo 72 bytes (acentos e símbolos contam como mais de um).';

        return [
            'vazia' => ['', $curta],
            '7 bytes' => [str_repeat('a', 7), $curta],
            '8 bytes' => [str_repeat('a', 8), null],
            '72 bytes' => [str_repeat('a', 72), null],
            '73 bytes' => [str_repeat('a', 73), $longa],
            '36 caracteres de 2 bytes = 72 bytes' => [str_repeat('é', 36), null],
            '37 caracteres de 2 bytes = 74 bytes' => [str_repeat('é', 37), $longa],
            'espaços contam' => ['        ', null],
        ];
    }
}
