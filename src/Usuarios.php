<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Acesso à tabela usuarios. Toda consulta usa parâmetros; e-mails são comparados em minúsculas.
 */
final class Usuarios
{
    private const string COLUNAS = 'id, nome, email, senha_hash, criado_em, email_verificado_em';

    public function __construct(private readonly Db $db) {}

    public function buscarPorEmail(string $emailNormalizado): ?Usuario
    {
        $linha = $this->db->um(
            'SELECT ' . self::COLUNAS . ' FROM usuarios WHERE lower(email) = :e',
            ['e' => $emailNormalizado],
        );

        return $linha === null ? null : Usuario::deLinha($linha);
    }

    public function buscarPorId(int $id): ?Usuario
    {
        $linha = $this->db->um('SELECT ' . self::COLUNAS . ' FROM usuarios WHERE id = :i', ['i' => $id]);

        return $linha === null ? null : Usuario::deLinha($linha);
    }

    /** Devolve o id criado, ou null se o e-mail já estiver cadastrado. */
    public function criar(string $nome, string $emailNormalizado, string $senhaHash): ?int
    {
        $linha = $this->db->um(
            'INSERT INTO usuarios (nome, email, senha_hash) VALUES (:n, :e, :s)
             ON CONFLICT DO NOTHING RETURNING id',
            ['n' => $nome, 'e' => $emailNormalizado, 's' => $senhaHash],
        );

        return $linha === null ? null : (int) $linha['id'];
    }

    public function atualizarSenha(int $id, string $senhaHash): void
    {
        $this->db->executar(
            'UPDATE usuarios SET senha_hash = :s, atualizado_em = now() WHERE id = :i',
            ['s' => $senhaHash, 'i' => $id],
        );
    }

    public function atualizarNome(int $id, string $nome): void
    {
        $this->db->executar(
            'UPDATE usuarios SET nome = :n, atualizado_em = now() WHERE id = :i',
            ['n' => $nome, 'i' => $id],
        );
    }

    public function marcarEmailVerificado(int $id): void
    {
        $this->db->executar(
            'UPDATE usuarios SET email_verificado_em = COALESCE(email_verificado_em, now()), atualizado_em = now()
              WHERE id = :i',
            ['i' => $id],
        );
    }

    /** Exclui a conta; sessões e tokens saem em cascata. */
    public function excluir(int $id): bool
    {
        return $this->db->executar('DELETE FROM usuarios WHERE id = :i', ['i' => $id]) > 0;
    }
}
