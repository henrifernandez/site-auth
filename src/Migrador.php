<?php

declare(strict_types=1);

namespace SiteAuth;

use RuntimeException;

/**
 * Migrações reversíveis em SQL puro: migrations/NNNN_nome.up.sql e NNNN_nome.down.sql.
 * Cada migração roda em uma transação (o DDL do PostgreSQL é transacional) e é registrada em schema_migrations.
 */
final class Migrador
{
    private const int TRAVA = 727274;

    public function __construct(private readonly Db $db, private readonly string $pasta) {}

    /**
     * @return list<array{versao: string, nome: string, aplicada_em: string|null}>
     */
    public function status(): array
    {
        $this->garantirTabela();
        $aplicadas = [];
        foreach ($this->db->consultar('SELECT versao, aplicada_em FROM schema_migrations') as $linha) {
            $aplicadas[(string) $linha['versao']] = (string) $linha['aplicada_em'];
        }

        $resultado = [];
        foreach ($this->descobrir() as $versao => $nome) {
            $resultado[] = ['versao' => $versao, 'nome' => $nome, 'aplicada_em' => $aplicadas[$versao] ?? null];
        }

        return $resultado;
    }

    /**
     * Aplica as migrações pendentes, em ordem.
     *
     * @return list<string> versões aplicadas
     */
    public function subir(): array
    {
        return $this->comTrava(function (): array {
            $aplicadas = [];
            foreach ($this->status() as $migracao) {
                if ($migracao['aplicada_em'] !== null) {
                    continue;
                }
                $sql = $this->ler($migracao['versao'], $migracao['nome'], 'up');
                $this->db->transacao(function (Db $db) use ($sql, $migracao): void {
                    $db->pdo()->exec($sql);
                    $db->executar('INSERT INTO schema_migrations (versao) VALUES (:v)', ['v' => $migracao['versao']]);
                });
                $aplicadas[] = $migracao['versao'];
            }

            return $aplicadas;
        });
    }

    /**
     * Desfaz as últimas $passos migrações aplicadas, da mais nova para a mais antiga.
     *
     * @return list<string> versões desfeitas
     */
    public function descer(int $passos = 1): array
    {
        return $this->comTrava(function () use ($passos): array {
            $desfeitas = [];
            $aplicadas = array_values(array_filter(
                $this->status(),
                static fn(array $m): bool => $m['aplicada_em'] !== null,
            ));
            foreach (array_slice(array_reverse($aplicadas), 0, max(0, $passos)) as $migracao) {
                $sql = $this->ler($migracao['versao'], $migracao['nome'], 'down');
                $this->db->transacao(function (Db $db) use ($sql, $migracao): void {
                    $db->pdo()->exec($sql);
                    $db->executar('DELETE FROM schema_migrations WHERE versao = :v', ['v' => $migracao['versao']]);
                });
                $desfeitas[] = $migracao['versao'];
            }

            return $desfeitas;
        });
    }

    private function garantirTabela(): void
    {
        $this->db->executar(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                versao TEXT PRIMARY KEY,
                aplicada_em TIMESTAMPTZ NOT NULL DEFAULT now()
            )',
        );
    }

    /**
     * @template T
     * @param callable(): T $acao
     * @return T
     */
    private function comTrava(callable $acao): mixed
    {
        $this->db->executar('SELECT pg_advisory_lock(:t)', ['t' => self::TRAVA]);
        try {
            return $acao();
        } finally {
            $this->db->executar('SELECT pg_advisory_unlock(:t)', ['t' => self::TRAVA]);
        }
    }

    /** @return array<string, string> versão => nome, em ordem crescente */
    private function descobrir(): array
    {
        $arquivos = glob($this->pasta . '/*.up.sql');
        if ($arquivos === false) {
            return [];
        }
        sort($arquivos);

        $migracoes = [];
        foreach ($arquivos as $arquivo) {
            if (preg_match('/^(\d{4})_(.+)\.up\.sql$/', basename($arquivo), $m) === 1) {
                $migracoes[$m[1]] = $m[2];
            }
        }

        return $migracoes;
    }

    private function ler(string $versao, string $nome, string $sentido): string
    {
        $caminho = sprintf('%s/%s_%s.%s.sql', $this->pasta, $versao, $nome, $sentido);
        $sql = is_file($caminho) ? file_get_contents($caminho) : false;
        if ($sql === false) {
            throw new RuntimeException(sprintf('Arquivo de migração ausente: %s_%s.%s.sql', $versao, $nome, $sentido));
        }

        return $sql;
    }
}
