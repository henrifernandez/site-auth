<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

use Closure;

/**
 * Base dos envios por API HTTPS (Resend e Brevo). Cada provedor só monta a requisição.
 */
abstract class MailerHttp implements Mailer
{
    /** @var Closure(string, list<string>, string): array{0: int, 1: string} */
    private readonly Closure $transporte;

    /**
     * @param (Closure(string, list<string>, string): array{0: int, 1: string})|null $transporte troca o cURL em testes
     */
    public function __construct(
        protected readonly string $chaveApi,
        protected readonly Remetente $remetente,
        ?Closure $transporte = null,
    ) {
        $this->transporte = $transporte ?? TransporteHttp::postarJson(...);
    }

    /**
     * @return array{url: string, cabecalhos: list<string>, corpo: array<string, mixed>}
     */
    abstract public function montarRequisicao(Email $email): array;

    abstract protected function nome(): string;

    public function enviar(Email $email): void
    {
        $requisicao = $this->montarRequisicao($email);
        [$status, $corpoResposta] = ($this->transporte)(
            $requisicao['url'],
            $requisicao['cabecalhos'],
            json_encode($requisicao['corpo'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        if ($status < 200 || $status >= 300) {
            throw new MailException(sprintf('%s respondeu HTTP %d: %s', $this->nome(), $status, mb_substr($corpoResposta, 0, 200)));
        }
    }
}
