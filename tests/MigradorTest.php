<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use SiteAuth\Migrador;
use Throwable;

final class MigradorTest extends BancoTestCase
{
    private function migrador(): Migrador
    {
        return new Migrador($this->db, dirname(__DIR__) . '/migrations');
    }

    private function tabelaExiste(string $nome): bool
    {
        $linha = $this->db->um('SELECT to_regclass(:n) AS t', ['n' => $nome]);

        return ($linha['t'] ?? null) !== null;
    }

    private function colunaExiste(string $tabela, string $coluna): bool
    {
        return $this->db->um(
            'SELECT 1 AS ok FROM information_schema.columns WHERE table_name = :t AND column_name = :c',
            ['t' => $tabela, 'c' => $coluna],
        ) !== null;
    }

    protected function tearDown(): void
    {
        // Deixa o esquema completo para os próximos testes.
        if (!isset($this->db)) {
            return;
        }
        BancoTestCase::recriarEsquema($this->db);
    }

    public function testSubirDescerESubirDeNovoSemErro(): void
    {
        BancoTestCase::recriarEsquema($this->db);
        $m = $this->migrador();

        self::assertSame(['0001', '0002'], array_column(array_filter($m->status(), static fn(array $x): bool => $x['aplicada_em'] !== null), 'versao'));

        self::assertSame(['0002', '0001'], $m->descer(PHP_INT_MAX));
        foreach (['usuarios', 'sessoes', 'tokens_conta', 'tentativas_acesso'] as $tabela) {
            self::assertFalse($this->tabelaExiste($tabela), $tabela . ' deveria ter sido removida');
        }
        self::assertTrue($this->tabelaExiste('schema_migrations'));

        self::assertSame(['0001', '0002'], $m->subir());
        self::assertTrue($this->tabelaExiste('usuarios'));
        self::assertTrue($this->colunaExiste('usuarios', 'email_verificado_em'));
        self::assertTrue($this->colunaExiste('usuarios', 'atualizado_em'));
        self::assertSame([], $m->subir(), 'segunda subida não deve ter nada a aplicar');
    }

    public function testDownDaPrimeiraNaoApagaUsuariosComDados(): void
    {
        BancoTestCase::recriarEsquema($this->db);
        $this->criarUsuario('guardar@example.com');

        $this->migrador()->descer(PHP_INT_MAX);

        self::assertTrue($this->tabelaExiste('usuarios'), 'a tabela com contas deve ser mantida');
        self::assertFalse($this->tabelaExiste('sessoes'));
        self::assertSame(1, $this->db->um('SELECT count(*)::int AS n FROM usuarios')['n'] ?? null);
    }

    public function testDownNaoApagaTabelaUsuariosQueJaExistiaAntes(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS tentativas_acesso, tokens_conta, sessoes, usuarios, schema_migrations CASCADE');
        $this->db->pdo()->exec(
            'CREATE TABLE usuarios (id SERIAL PRIMARY KEY, nome TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
                                    senha_hash TEXT NOT NULL, criado_em TIMESTAMPTZ NOT NULL DEFAULT now())',
        );
        $m = $this->migrador();
        $m->subir();

        $m->descer(PHP_INT_MAX);

        self::assertTrue($this->tabelaExiste('usuarios'), 'tabela pré-existente nunca é apagada');
    }

    public function testEmailsDuplicadosAbortamAMigracao0002(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS tentativas_acesso, tokens_conta, sessoes, usuarios, schema_migrations CASCADE');
        $pasta = sys_get_temp_dir() . '/siteauth-mig-' . bin2hex(random_bytes(4));
        mkdir($pasta);
        foreach (['0001_base.up.sql', '0001_base.down.sql'] as $arquivo) {
            copy(dirname(__DIR__) . '/migrations/' . $arquivo, $pasta . '/' . $arquivo);
        }

        try {
            new Migrador($this->db, $pasta)->subir();
            // Em bancos antigos o índice por lower(email) não existe, então duplicados por caixa são possíveis.
            $this->criarUsuario('Dup@Example.com');
            $this->criarUsuario('dup@example.com');

            try {
                $this->migrador()->subir();
                self::fail('A migração 0002 deveria abortar.');
            } catch (Throwable $erro) {
                self::assertStringContainsString('duplicados', $erro->getMessage());
                self::assertStringContainsString('dup@example.com', $erro->getMessage());
            }

            self::assertFalse($this->colunaExiste('usuarios', 'email_verificado_em'), 'nada deve ter sido alterado');
            self::assertSame(
                ['0001'],
                array_column(array_filter($this->migrador()->status(), static fn(array $x): bool => $x['aplicada_em'] !== null), 'versao'),
            );
        } finally {
            array_map('unlink', glob($pasta . '/*') ?: []);
            rmdir($pasta);
        }
    }

    public function testIndiceUnicoPorEmailSemDiferenciarCaixa(): void
    {
        BancoTestCase::recriarEsquema($this->db);
        $this->criarUsuario('caixa@example.com');

        $this->expectException(\PDOException::class);
        $this->criarUsuario('CAIXA@example.com');
    }
}
