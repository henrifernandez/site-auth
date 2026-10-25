<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use SiteAuth\TentativasRepositorio;

/**
 * Repositório falso, em memória, com relógio controlável.
 */
final class TentativasMemoria implements TentativasRepositorio
{
    public int $agora = 1_000_000;

    public int $purgas = 0;

    /** @var list<array{tipo: string, chave: string, ip: string, em: int}> */
    private array $linhas = [];

    public function avancar(int $segundos): void
    {
        $this->agora += $segundos;
    }

    public function registrar(string $tipo, string $chave, string $ip): void
    {
        $this->linhas[] = ['tipo' => $tipo, 'chave' => $chave, 'ip' => $ip, 'em' => $this->agora];
    }

    public function contar(string $tipo, ?string $chave, ?string $ip, int $janelaSegundos): int
    {
        $n = 0;
        foreach ($this->linhas as $l) {
            if ($l['tipo'] === $tipo
                && $l['em'] > $this->agora - $janelaSegundos
                && ($chave === null || $l['chave'] === $chave)
                && ($ip === null || $l['ip'] === $ip)) {
                $n++;
            }
        }

        return $n;
    }

    public function limpar(string $tipo, string $chave): void
    {
        $this->linhas = array_values(array_filter(
            $this->linhas,
            static fn(array $l): bool => !($l['tipo'] === $tipo && $l['chave'] === $chave),
        ));
    }

    public function purgar(int $maisAntigasQueSegundos): int
    {
        $this->purgas++;
        $antes = count($this->linhas);
        $this->linhas = array_values(array_filter(
            $this->linhas,
            fn(array $l): bool => $l['em'] >= $this->agora - $maisAntigasQueSegundos,
        ));

        return $antes - count($this->linhas);
    }
}
