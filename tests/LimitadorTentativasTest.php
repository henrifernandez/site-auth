<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use PHPUnit\Framework\TestCase;
use SiteAuth\LimitadorTentativas;

final class LimitadorTentativasTest extends TestCase
{
    private TentativasMemoria $repo;

    private LimitadorTentativas $limitador;

    protected function setUp(): void
    {
        $this->repo = new TentativasMemoria();
        $this->limitador = new LimitadorTentativas($this->repo, static fn(): bool => false);
    }

    public function testLoginBloqueiaDepoisDe5FalhasPorEmailEIp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($this->limitador->loginBloqueado('a@example.com', '198.51.100.1'), 'antes da falha ' . ($i + 1));
            $this->limitador->registrarFalhaDeLogin('a@example.com', '198.51.100.1');
        }

        self::assertTrue($this->limitador->loginBloqueado('a@example.com', '198.51.100.1'));
        self::assertFalse($this->limitador->loginBloqueado('b@example.com', '198.51.100.1'), 'outro e-mail no mesmo IP segue livre');
        self::assertFalse($this->limitador->loginBloqueado('a@example.com', '198.51.100.2'), 'mesmo e-mail em outro IP segue livre');
    }

    public function testLoginBloqueiaIpCom30FalhasEmEmailsDiferentes(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->limitador->registrarFalhaDeLogin("u{$i}@example.com", '198.51.100.9');
        }

        self::assertTrue($this->limitador->loginBloqueado('novo@example.com', '198.51.100.9'));
        self::assertFalse($this->limitador->loginBloqueado('novo@example.com', '198.51.100.10'));
    }

    public function testBloqueioDeLoginExpiraDepoisDe15Minutos(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limitador->registrarFalhaDeLogin('a@example.com', '198.51.100.1');
        }
        self::assertTrue($this->limitador->loginBloqueado('a@example.com', '198.51.100.1'));

        $this->repo->avancar(899);
        self::assertTrue($this->limitador->loginBloqueado('a@example.com', '198.51.100.1'));

        $this->repo->avancar(2);
        self::assertFalse($this->limitador->loginBloqueado('a@example.com', '198.51.100.1'));
    }

    public function testLoginCorretoLimpaAsFalhasDaChave(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->limitador->registrarFalhaDeLogin('a@example.com', '198.51.100.1');
        }
        $this->limitador->limparFalhasDeLogin('a@example.com', '198.51.100.1');
        $this->limitador->registrarFalhaDeLogin('a@example.com', '198.51.100.1');

        self::assertFalse($this->limitador->loginBloqueado('a@example.com', '198.51.100.1'));
    }

    public function testRecuperacaoLimita3PorEmailE10PorIpPorHora(): void
    {
        for ($i = 0; $i < 3; $i++) {
            self::assertFalse($this->limitador->recuperacaoExcedida('a@example.com', '198.51.100.1'));
            $this->limitador->registrarRecuperacao('a@example.com', '198.51.100.' . (10 + $i));
        }
        self::assertTrue($this->limitador->recuperacaoExcedida('a@example.com', '198.51.100.99'), 'o limite por e-mail vale em qualquer IP');

        for ($i = 0; $i < 10; $i++) {
            $this->limitador->registrarRecuperacao("x{$i}@example.com", '198.51.100.50');
        }
        self::assertTrue($this->limitador->recuperacaoExcedida('livre@example.com', '198.51.100.50'));

        $this->repo->avancar(3601);
        self::assertFalse($this->limitador->recuperacaoExcedida('a@example.com', '198.51.100.50'));
    }

    public function testCadastroVerificacaoESenhaAtual(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->limitador->registrarCadastro('198.51.100.7');
        }
        self::assertTrue($this->limitador->cadastroExcedido('198.51.100.7'));
        self::assertFalse($this->limitador->cadastroExcedido('198.51.100.8'));

        for ($i = 0; $i < 3; $i++) {
            $this->limitador->registrarVerificacao(7, '198.51.100.1');
        }
        self::assertTrue($this->limitador->verificacaoExcedida(7, '198.51.100.2'));
        self::assertFalse($this->limitador->verificacaoExcedida(8, '198.51.100.2'));

        for ($i = 0; $i < 5; $i++) {
            $this->limitador->registrarSenhaAtualErrada(7, '198.51.100.1');
        }
        self::assertTrue($this->limitador->senhaAtualBloqueada(7));
        self::assertFalse($this->limitador->senhaAtualBloqueada(8));
    }

    public function testChavesNaoGuardamEmailEmTexto(): void
    {
        self::assertSame(64, strlen(LimitadorTentativas::chave('a@example.com', '198.51.100.1')));
        self::assertStringNotContainsString('example', LimitadorTentativas::chave('a@example.com'));
    }

    public function testLimpezaOportunistaRodaQuandoSorteada(): void
    {
        $sempre = new LimitadorTentativas($this->repo, static fn(): bool => true);
        $sempre->registrarFalhaDeLogin('a@example.com', '198.51.100.1');

        self::assertSame(1, $this->repo->purgas);

        $this->limitador->registrarFalhaDeLogin('a@example.com', '198.51.100.1');
        self::assertSame(1, $this->repo->purgas, 'sem sorteio não limpa');
    }
}
