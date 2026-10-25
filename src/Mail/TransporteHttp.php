<?php

declare(strict_types=1);

namespace SiteAuth\Mail;

/**
 * POST JSON por HTTPS usando cURL, com tempo limite. Devolve [status, corpo].
 * Os cabeçalhos (que levam a chave de API) nunca entram em mensagens de erro.
 */
final class TransporteHttp
{
    private const int TEMPO_TOTAL = 10;

    private const int TEMPO_CONEXAO = 5;

    /**
     * @param list<string> $cabecalhos
     * @return array{0: int, 1: string}
     * @throws MailException
     */
    public static function postarJson(string $url, array $cabecalhos, string $corpo): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new MailException('O provedor de e-mail só é chamado por HTTPS.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new MailException('Não foi possível iniciar o cURL.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corpo,
            CURLOPT_HTTPHEADER => $cabecalhos,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TEMPO_TOTAL,
            CURLOPT_CONNECTTIMEOUT => self::TEMPO_CONEXAO,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $resposta = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $erro = curl_errno($curl);
        curl_close($curl);

        if ($resposta === false || $erro !== 0) {
            throw new MailException('Falha de rede ao contatar o provedor de e-mail (cURL ' . $erro . ').');
        }

        return [$status, (string) $resposta];
    }
}
