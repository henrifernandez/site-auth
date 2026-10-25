<?php

declare(strict_types=1);

namespace SiteAuth;

use Closure;

/**
 * Limites de tentativas guardados no Postgres (cada requisição serverless é isolada, então nada fica em memória).
 *
 * Chaves de e-mail são hashes: o banco não guarda endereços em texto nas tentativas.
 */
final class LimitadorTentativas
{
    public const string LOGIN = 'login_falha';

    public const string RECUPERACAO = 'recuperacao';

    public const string VERIFICACAO = 'verificacao';

    public const string CADASTRO = 'cadastro';

    public const string SENHA_ATUAL = 'senha_atual';

    public const int JANELA_LOGIN = 900;

    public const int LOGIN_POR_EMAIL_E_IP = 5;

    public const int LOGIN_POR_IP = 30;

    public const int JANELA_HORA = 3600;

    public const int RECUPERACAO_POR_EMAIL = 3;

    public const int RECUPERACAO_POR_IP = 10;

    public const int VERIFICACAO_POR_USUARIO = 3;

    public const int VERIFICACAO_POR_IP = 10;

    public const int CADASTRO_POR_IP = 20;

    public const int SENHA_ATUAL_POR_USUARIO = 5;

    /** Tentativas mais velhas que isso (2 h) já não contam em nenhuma janela e são apagadas. */
    private const int RETENCAO = 7200;

    /** @var Closure(): bool */
    private Closure $sorteio;

    /**
     * @param (Closure(): bool)|null $sorteio decide se a limpeza oportunista roda nesta chamada (padrão: 1 em 50)
     */
    public function __construct(private readonly TentativasRepositorio $repositorio, ?Closure $sorteio = null)
    {
        $this->sorteio = $sorteio ?? static fn(): bool => random_int(1, 50) === 1;
    }

    public static function chave(string ...$partes): string
    {
        return hash('sha256', implode('|', $partes));
    }

    // Login: 5 falhas por e-mail+IP e 30 por IP em 15 minutos.

    public function loginBloqueado(string $email, string $ip): bool
    {
        return $this->repositorio->contar(self::LOGIN, self::chave($email, $ip), null, self::JANELA_LOGIN) >= self::LOGIN_POR_EMAIL_E_IP
            || $this->repositorio->contar(self::LOGIN, null, $ip, self::JANELA_LOGIN) >= self::LOGIN_POR_IP;
    }

    public function registrarFalhaDeLogin(string $email, string $ip): void
    {
        $this->registrar(self::LOGIN, self::chave($email, $ip), $ip);
    }

    public function limparFalhasDeLogin(string $email, string $ip): void
    {
        $this->repositorio->limpar(self::LOGIN, self::chave($email, $ip));
    }

    // Recuperação de senha: 3 pedidos por e-mail e 10 por IP, por hora.

    public function recuperacaoExcedida(string $email, string $ip): bool
    {
        return $this->repositorio->contar(self::RECUPERACAO, self::chave($email), null, self::JANELA_HORA) >= self::RECUPERACAO_POR_EMAIL
            || $this->repositorio->contar(self::RECUPERACAO, null, $ip, self::JANELA_HORA) >= self::RECUPERACAO_POR_IP;
    }

    public function registrarRecuperacao(string $email, string $ip): void
    {
        $this->registrar(self::RECUPERACAO, self::chave($email), $ip);
    }

    // Reenvio da verificação de e-mail: 3 por usuário e 10 por IP, por hora.

    public function verificacaoExcedida(int $usuarioId, string $ip): bool
    {
        return $this->repositorio->contar(self::VERIFICACAO, self::chave('u', (string) $usuarioId), null, self::JANELA_HORA) >= self::VERIFICACAO_POR_USUARIO
            || $this->repositorio->contar(self::VERIFICACAO, null, $ip, self::JANELA_HORA) >= self::VERIFICACAO_POR_IP;
    }

    public function registrarVerificacao(int $usuarioId, string $ip): void
    {
        $this->registrar(self::VERIFICACAO, self::chave('u', (string) $usuarioId), $ip);
    }

    // Cadastro: 20 por IP, por hora.

    public function cadastroExcedido(string $ip): bool
    {
        return $this->repositorio->contar(self::CADASTRO, null, $ip, self::JANELA_HORA) >= self::CADASTRO_POR_IP;
    }

    public function registrarCadastro(string $ip): void
    {
        $this->registrar(self::CADASTRO, self::chave($ip), $ip);
    }

    // Senha atual errada na área da conta: 5 por usuário em 15 minutos.

    public function senhaAtualBloqueada(int $usuarioId): bool
    {
        return $this->repositorio->contar(self::SENHA_ATUAL, self::chave('u', (string) $usuarioId), null, self::JANELA_LOGIN) >= self::SENHA_ATUAL_POR_USUARIO;
    }

    public function registrarSenhaAtualErrada(int $usuarioId, string $ip): void
    {
        $this->registrar(self::SENHA_ATUAL, self::chave('u', (string) $usuarioId), $ip);
    }

    private function registrar(string $tipo, string $chave, string $ip): void
    {
        $this->repositorio->registrar($tipo, $chave, $ip);
        if (($this->sorteio)()) {
            $this->repositorio->purgar(self::RETENCAO);
        }
    }
}
