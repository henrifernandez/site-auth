<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Regras de validação dos formulários. Cada método devolve a mensagem de erro, ou null se estiver válido.
 */
final class Validador
{
    public const int SENHA_MIN = 8;

    /** Limite do bcrypt: acima disso a senha seria truncada em silêncio. */
    public const int SENHA_MAX_BYTES = 72;

    public const string ERRO_CSRF = 'Sessão expirada. Recarregue a página e tente de novo.';

    public const string ERRO_LOGIN = 'E-mail ou senha incorretos.';

    public const string ERRO_BLOQUEIO = 'Muitas tentativas. Aguarde alguns minutos e tente de novo.';

    public const string ERRO_EMAIL_EXISTE = 'Este e-mail já está cadastrado. Tente entrar.';

    public const string ERRO_GENERICO = 'Não foi possível concluir agora. Tente novamente.';

    public static function normalizarEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Remove espaços nas pontas e repetidos no meio. */
    public static function normalizarNome(string $nome): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $nome));
    }

    public static function nome(string $nomeNormalizado): ?string
    {
        if (!mb_check_encoding($nomeNormalizado, 'UTF-8')
            || mb_strlen($nomeNormalizado) < 2
            || mb_strlen($nomeNormalizado) > 100
            || preg_match('/[\x00-\x1F\x7F]/', $nomeNormalizado) === 1) {
            return 'Informe seu nome completo.';
        }

        return null;
    }

    public static function email(string $emailNormalizado): ?string
    {
        if ($emailNormalizado === ''
            || strlen($emailNormalizado) > 254
            || !mb_check_encoding($emailNormalizado, 'UTF-8')
            || filter_var($emailNormalizado, FILTER_VALIDATE_EMAIL) === false) {
            return 'Informe um e-mail válido.';
        }

        return null;
    }

    /** A senha vale como veio (sem trim), de 8 a 72 BYTES. */
    public static function senhaNova(string $senha): ?string
    {
        $bytes = strlen($senha);
        if ($bytes < self::SENHA_MIN) {
            return 'A senha precisa ter pelo menos 8 caracteres.';
        }
        if ($bytes > self::SENHA_MAX_BYTES) {
            return 'A senha pode ter no máximo 72 bytes (acentos e símbolos contam como mais de um).';
        }

        return null;
    }
}
