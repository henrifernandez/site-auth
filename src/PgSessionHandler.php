<?php

declare(strict_types=1);

namespace SiteAuth;

use SessionHandlerInterface;

/**
 * Sessões no PostgreSQL. Cada requisição serverless é isolada, então o estado não pode ficar em
 * arquivos locais. O banco guarda só o hash (sha256) do identificador; o valor do cookie nunca é gravado.
 *
 * Implementa SessionHandlerInterface (pode ser registrado com session_set_save_handler) e acrescenta
 * operações próprias usadas pela classe Sessao: vínculo com o usuário, listagem e encerramento.
 */
final class PgSessionHandler implements SessionHandlerInterface
{
    private string $ip = '';

    private string $userAgent = '';

    public function __construct(
        private readonly Db $db,
        private readonly int $absoluta = Config::SESSAO_ABSOLUTA,
        private readonly int $ociosa = Config::SESSAO_OCIOSA,
        private readonly int $anonima = Config::SESSAO_ANONIMA,
    ) {}

    public static function hash(string $id): string
    {
        return hash('sha256', $id);
    }

    public function definirContexto(string $ip, string $userAgent): void
    {
        $this->ip = $ip;
        $this->userAgent = mb_substr($userAgent, 0, 300);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        return $this->carregar($id)['dados'] ?? '';
    }

    public function write(string $id, string $data): bool
    {
        $this->salvar($id, $data, null);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->executar('DELETE FROM sessoes WHERE id_hash = :h', ['h' => self::hash($id)]);

        return true;
    }

    public function gc(int $max_lifetime): int
    {
        return $this->coletarLixo();
    }

    /**
     * Sessão válida (nem expirada de forma absoluta, nem ociosa demais).
     *
     * @return array{dados: string, usuario_id: int|null, idade_ultimo_uso: int}|null
     */
    public function carregar(string $id): ?array
    {
        $linha = $this->db->um(
            'SELECT dados, usuario_id, EXTRACT(EPOCH FROM (now() - ultimo_uso))::int AS idade
               FROM sessoes
              WHERE id_hash = :h
                AND expira_em > now()
                AND ultimo_uso > now() - make_interval(secs => :ociosa)',
            ['h' => self::hash($id), 'ociosa' => $this->ociosa],
        );
        if ($linha === null) {
            return null;
        }

        return [
            'dados' => (string) $linha['dados'],
            'usuario_id' => $linha['usuario_id'] === null ? null : (int) $linha['usuario_id'],
            'idade_ultimo_uso' => (int) $linha['idade'],
        ];
    }

    /**
     * Cria ou atualiza a sessão. Com $usuarioId nulo o vínculo atual é mantido. Ao vincular um usuário
     * pela primeira vez, a expiração absoluta passa a contar a partir de agora.
     */
    public function salvar(string $id, string $dados, ?int $usuarioId): void
    {
        $this->db->executar(
            'INSERT INTO sessoes (id_hash, usuario_id, dados, ip, user_agent, expira_em)
             VALUES (:h, :u, :d, :ip, :ua, now() + make_interval(secs => :ttl))
             ON CONFLICT (id_hash) DO UPDATE SET
                dados = EXCLUDED.dados,
                ultimo_uso = now(),
                criada_em = CASE WHEN sessoes.usuario_id IS NULL AND EXCLUDED.usuario_id IS NOT NULL
                                 THEN now() ELSE sessoes.criada_em END,
                expira_em = CASE WHEN sessoes.usuario_id IS NULL AND EXCLUDED.usuario_id IS NOT NULL
                                 THEN EXCLUDED.expira_em ELSE sessoes.expira_em END,
                usuario_id = COALESCE(EXCLUDED.usuario_id, sessoes.usuario_id)',
            [
                'h' => self::hash($id),
                'u' => $usuarioId,
                'd' => $dados,
                'ip' => $this->ip,
                'ua' => $this->userAgent,
                'ttl' => $usuarioId === null ? $this->anonima : $this->absoluta,
            ],
        );
    }

    /** Troca o identificador mantendo os dados (usado no login, contra fixação de sessão). */
    public function regenerar(string $antigo, string $novo): bool
    {
        return $this->db->executar(
            'UPDATE sessoes SET id_hash = :novo WHERE id_hash = :antigo',
            ['novo' => self::hash($novo), 'antigo' => self::hash($antigo)],
        ) > 0;
    }

    /** Apenas o toque de "último uso", para renovar o prazo de inatividade. */
    public function tocar(string $id): void
    {
        $this->db->executar('UPDATE sessoes SET ultimo_uso = now() WHERE id_hash = :h', ['h' => self::hash($id)]);
    }

    /**
     * Sessões ativas do usuário, da mais recente para a mais antiga.
     *
     * @return list<array{id_hash: string, ip: string, user_agent: string, criada_em: string, ultimo_uso: string}>
     */
    public function listarPorUsuario(int $usuarioId): array
    {
        $linhas = $this->db->consultar(
            'SELECT id_hash, ip, user_agent, criada_em, ultimo_uso
               FROM sessoes
              WHERE usuario_id = :u
                AND expira_em > now()
                AND ultimo_uso > now() - make_interval(secs => :ociosa)
              ORDER BY ultimo_uso DESC',
            ['u' => $usuarioId, 'ociosa' => $this->ociosa],
        );

        return array_map(static fn(array $l): array => [
            'id_hash' => (string) $l['id_hash'],
            'ip' => (string) $l['ip'],
            'user_agent' => (string) $l['user_agent'],
            'criada_em' => (string) $l['criada_em'],
            'ultimo_uso' => (string) $l['ultimo_uso'],
        ], $linhas);
    }

    /** Encerra uma sessão do usuário pelo hash. Devolve se algo foi removido. */
    public function encerrar(string $idHash, int $usuarioId): bool
    {
        return $this->db->executar(
            'DELETE FROM sessoes WHERE id_hash = :h AND usuario_id = :u',
            ['h' => $idHash, 'u' => $usuarioId],
        ) > 0;
    }

    /** Encerra todas as sessões do usuário, exceto a indicada (hash) quando informada. */
    public function encerrarTodas(int $usuarioId, ?string $exceto = null): int
    {
        if ($exceto === null) {
            return $this->db->executar('DELETE FROM sessoes WHERE usuario_id = :u', ['u' => $usuarioId]);
        }

        return $this->db->executar(
            'DELETE FROM sessoes WHERE usuario_id = :u AND id_hash <> :h',
            ['u' => $usuarioId, 'h' => $exceto],
        );
    }

    /** Remove sessões vencidas (absoluta ou por inatividade). */
    public function coletarLixo(): int
    {
        return $this->db->executar(
            'DELETE FROM sessoes
              WHERE expira_em <= now() OR ultimo_uso <= now() - make_interval(secs => :ociosa)',
            ['ociosa' => $this->ociosa],
        );
    }
}
