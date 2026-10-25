<?php

declare(strict_types=1);

namespace SiteAuth;

final class TentativasPg implements TentativasRepositorio
{
    public function __construct(private readonly Db $db) {}

    public function registrar(string $tipo, string $chave, string $ip): void
    {
        $this->db->executar(
            'INSERT INTO tentativas_acesso (tipo, chave, ip) VALUES (:t, :c, :ip)',
            ['t' => $tipo, 'c' => $chave, 'ip' => $ip],
        );
    }

    public function contar(string $tipo, ?string $chave, ?string $ip, int $janelaSegundos): int
    {
        $sql = 'SELECT count(*) AS n FROM tentativas_acesso
                 WHERE tipo = :t AND criado_em > now() - make_interval(secs => :j)';
        $parametros = ['t' => $tipo, 'j' => $janelaSegundos];
        if ($chave !== null) {
            $sql .= ' AND chave = :c';
            $parametros['c'] = $chave;
        }
        if ($ip !== null) {
            $sql .= ' AND ip = :ip';
            $parametros['ip'] = $ip;
        }

        return (int) ($this->db->um($sql, $parametros)['n'] ?? 0);
    }

    public function limpar(string $tipo, string $chave): void
    {
        $this->db->executar('DELETE FROM tentativas_acesso WHERE tipo = :t AND chave = :c', ['t' => $tipo, 'c' => $chave]);
    }

    public function purgar(int $maisAntigasQueSegundos): int
    {
        return $this->db->executar(
            'DELETE FROM tentativas_acesso WHERE criado_em < now() - make_interval(secs => :s)',
            ['s' => $maisAntigasQueSegundos],
        );
    }
}
