<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Tokens de conta no banco (recuperação de senha e verificação de e-mail). Só o hash é guardado.
 */
final class TokensConta
{
    public const string RECUPERAR_SENHA = 'recuperar_senha';

    public const string VERIFICAR_EMAIL = 'verificar_email';

    public const int VALIDADE_RECUPERAR = 1800;

    public const int VALIDADE_VERIFICAR = 86400;

    public function __construct(private readonly Db $db) {}

    /** Cria um token novo e invalida os anteriores ainda pendentes do mesmo tipo. */
    public function criar(int $usuarioId, string $tipo, string $hash, int $validadeSegundos): void
    {
        $this->db->transacao(function (Db $db) use ($usuarioId, $tipo, $hash, $validadeSegundos): void {
            $this->invalidarPendentes($usuarioId, $tipo);
            $db->executar(
                'INSERT INTO tokens_conta (usuario_id, tipo, hash_token, expira_em)
                 VALUES (:u, :t, :h, now() + make_interval(secs => :v))',
                ['u' => $usuarioId, 't' => $tipo, 'h' => $hash, 'v' => $validadeSegundos],
            );
        });
    }

    /** Consulta sem consumir: id do usuário se o token existe, não foi usado e não expirou. */
    public function verificar(string $tipo, string $hash): ?int
    {
        $linha = $this->db->um(
            'SELECT usuario_id FROM tokens_conta
              WHERE hash_token = :h AND tipo = :t AND usado_em IS NULL AND expira_em > now()',
            ['h' => $hash, 't' => $tipo],
        );

        return $linha === null ? null : (int) $linha['usuario_id'];
    }

    /** Consome o token de forma atômica: só uma requisição consegue usá-lo. */
    public function consumir(string $tipo, string $hash): ?int
    {
        $linha = $this->db->um(
            'UPDATE tokens_conta SET usado_em = now()
              WHERE hash_token = :h AND tipo = :t AND usado_em IS NULL AND expira_em > now()
          RETURNING usuario_id',
            ['h' => $hash, 't' => $tipo],
        );

        return $linha === null ? null : (int) $linha['usuario_id'];
    }

    public function invalidarPendentes(int $usuarioId, string $tipo): int
    {
        return $this->db->executar(
            'UPDATE tokens_conta SET usado_em = now() WHERE usuario_id = :u AND tipo = :t AND usado_em IS NULL',
            ['u' => $usuarioId, 't' => $tipo],
        );
    }
}
