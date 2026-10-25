<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use PHPUnit\Framework\TestCase;
use SiteAuth\Db;
use SiteAuth\Migrador;

/**
 * Base dos testes de integração. Sem TEST_DATABASE_URL os testes são ignorados (skipped).
 *
 * O banco de teste é descartável: o esquema é recriado do zero. Por segurança, a classe se recusa a
 * rodar contra qualquer servidor que não seja local.
 */
abstract class BancoTestCase extends TestCase
{
    protected Db $db;

    private static bool $esquemaPronto = false;

    protected function setUp(): void
    {
        $url = getenv('TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('TEST_DATABASE_URL não definida; teste de integração ignorado.');
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            self::fail('TEST_DATABASE_URL deve apontar para um Postgres local e descartável.');
        }

        $this->db = new Db($url);
        if (!self::$esquemaPronto) {
            self::recriarEsquema($this->db);
            self::$esquemaPronto = true;
        }

        $this->db->executar('TRUNCATE usuarios, sessoes, tokens_conta, tentativas_acesso RESTART IDENTITY CASCADE');
    }

    public static function recriarEsquema(Db $db): void
    {
        $db->pdo()->exec(
            'DROP TABLE IF EXISTS tentativas_acesso, tokens_conta, sessoes, usuarios, schema_migrations CASCADE',
        );
        new Migrador($db, dirname(__DIR__) . '/migrations')->subir();
    }

    /** Cria um usuário diretamente no banco e devolve o id. */
    protected function criarUsuario(string $email, string $senha = 'senha-de-teste-1', string $nome = 'Maria Teste'): int
    {
        $linha = $this->db->um(
            'INSERT INTO usuarios (nome, email, senha_hash) VALUES (:n, :e, :s) RETURNING id',
            ['n' => $nome, 'e' => $email, 's' => password_hash($senha, PASSWORD_BCRYPT, ['cost' => 4])],
        );

        return (int) ($linha['id'] ?? 0);
    }
}
