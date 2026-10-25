<?php

declare(strict_types=1);

namespace SiteAuth;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Conexão com o PostgreSQL (Neon em produção). A URL vem de DATABASE_URL, nunca de arquivo versionado.
 */
final class Db
{
    private ?PDO $pdo = null;

    public function __construct(private readonly string $url) {}

    public static function doAmbiente(string $variavel = 'DATABASE_URL'): self
    {
        $url = Env::obter($variavel);
        if ($url === null) {
            throw new InvalidArgumentException($variavel . ' ausente.');
        }

        return new self($url);
    }

    /**
     * Converte a URL de conexão em DSN, usuário e senha.
     *
     * No Neon a conexão é direta (sem "-pooler") e o ID do endpoint segue em options, para clientes
     * sem SNI. Em outros servidores (como o Postgres local) essa opção não é enviada.
     *
     * @return array{dsn: string, usuario: string, senha: string}
     */
    public static function parametros(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['host']) || $p['host'] === '') {
            throw new InvalidArgumentException('DATABASE_URL inválida.');
        }
        parse_str($p['query'] ?? '', $q);

        $host = str_replace('-pooler', '', $p['host']);
        $neon = str_ends_with($host, '.neon.tech');
        $sslmode = is_string($q['sslmode'] ?? null) ? $q['sslmode'] : ($neon ? 'require' : 'prefer');

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $host,
            $p['port'] ?? 5432,
            ltrim($p['path'] ?? '/neondb', '/'),
            $sslmode,
        );
        if ($neon) {
            $dsn .= sprintf(";options='endpoint=%s'", explode('.', $host)[0]);
        }

        return [
            'dsn' => $dsn,
            'usuario' => urldecode($p['user'] ?? ''),
            'senha' => urldecode($p['pass'] ?? ''),
        ];
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = self::parametros($this->url);
            $this->pdo = new PDO($c['dsn'], $c['usuario'], $c['senha'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ]);
        }

        return $this->pdo;
    }

    /**
     * @param array<string, mixed>|list<mixed> $parametros
     * @return list<array<string, mixed>>
     */
    public function consultar(string $sql, array $parametros = []): array
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($parametros);

        return array_values($st->fetchAll());
    }

    /**
     * @param array<string, mixed>|list<mixed> $parametros
     * @return array<string, mixed>|null
     */
    public function um(string $sql, array $parametros = []): ?array
    {
        $linhas = $this->consultar($sql, $parametros);

        return $linhas[0] ?? null;
    }

    /**
     * Executa um comando e devolve o número de linhas afetadas.
     *
     * @param array<string, mixed>|list<mixed> $parametros
     */
    public function executar(string $sql, array $parametros = []): int
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($parametros);

        return $st->rowCount();
    }

    /**
     * @template T
     * @param Closure(self): T $acao
     * @return T
     */
    public function transacao(Closure $acao): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $acao($this);
        }

        $pdo->beginTransaction();
        try {
            $resultado = $acao($this);
            $pdo->commit();

            return $resultado;
        } catch (Throwable $erro) {
            try {
                $pdo->rollBack();
            } catch (PDOException) {
                // A transação já foi encerrada pelo servidor; mantém o erro original.
            }
            throw $erro;
        }
    }
}
