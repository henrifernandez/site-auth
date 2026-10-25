<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Leitura de variáveis de ambiente. O arquivo .env existe só para desenvolvimento local,
 * fica fora do git e nunca sobrescreve uma variável já definida no ambiente.
 */
final class Env
{
    public static function carregarArquivo(string $caminho): void
    {
        if (!is_file($caminho)) {
            return;
        }

        $linhas = file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($linhas === false) {
            return;
        }

        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) {
                continue;
            }
            [$chave, $valor] = explode('=', $linha, 2);
            $chave = trim($chave);
            if ($chave === '' || self::obter($chave) !== null) {
                continue;
            }
            $valor = trim($valor, " \t\"'");
            putenv($chave . '=' . $valor);
            $_ENV[$chave] = $valor;
        }
    }

    public static function obter(string $nome, ?string $padrao = null): ?string
    {
        $valor = getenv($nome);
        if (is_string($valor) && $valor !== '') {
            return $valor;
        }
        $doEnv = $_ENV[$nome] ?? null;

        return is_string($doEnv) && $doEnv !== '' ? $doEnv : $padrao;
    }
}
