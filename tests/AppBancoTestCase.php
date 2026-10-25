<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use SiteAuth\App;
use SiteAuth\Config;
use SiteAuth\Mail\Email;
use SiteAuth\Mail\LogMailer;
use SiteAuth\Mail\Mailer;

/**
 * Base dos testes que exercitam a aplicação inteira (App::handle) contra o Postgres de teste,
 * com o LogMailer em memória no lugar do provedor de e-mail.
 */
abstract class AppBancoTestCase extends BancoTestCase
{
    protected LogMailer $mailer;

    private string $logDeErros = '';

    private string|false $logAnterior = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new LogMailer(false, static function (string $linha): void {});
        // Mantém o stderr limpo: erros esperados (como falha de envio) vão para um arquivo temporário.
        $this->logDeErros = sys_get_temp_dir() . '/siteauth-teste-' . bin2hex(random_bytes(4)) . '.log';
        $this->logAnterior = ini_set('error_log', $this->logDeErros);
    }

    protected function tearDown(): void
    {
        if ($this->logDeErros !== '') {
            ini_set('error_log', $this->logAnterior === false ? '' : $this->logAnterior);
            if (is_file($this->logDeErros)) {
                unlink($this->logDeErros);
            }
        }
        parent::tearDown();
    }

    protected function conteudoDoLogDeErros(): string
    {
        return is_file($this->logDeErros) ? (string) file_get_contents($this->logDeErros) : '';
    }

    protected function app(?Mailer $mailer = null, ?Config $config = null): App
    {
        return new App(
            $this->db,
            $config ?? new Config(appUrl: 'http://localhost:8081'),
            null,
            $mailer ?? $this->mailer,
        );
    }

    protected function navegador(string $ip = '203.0.113.10', ?App $app = null): Navegador
    {
        return new Navegador($app ?? $this->app(), $ip);
    }

    /** Faz login com um usuário criado e devolve o navegador autenticado. */
    protected function logar(string $email, string $senha = 'senha-de-teste-1', string $ip = '203.0.113.10'): Navegador
    {
        $n = $this->navegador($ip);
        $r = $n->post('/entrar', ['email' => $email, 'senha' => $senha]);
        self::assertSame(303, $r->status, 'login de teste deveria funcionar');

        return $n;
    }

    /**
     * Procura em um e-mail enviado o token do link de uma rota (por exemplo /redefinir-senha).
     */
    protected function tokenDoEmail(Email $email, string $rota): string
    {
        $achou = preg_match('#' . preg_quote($rota, '#') . '\?token=([A-Za-z0-9_-]{43})#', $email->texto, $m);
        self::assertSame(1, $achou, 'o e-mail deveria trazer o link de ' . $rota);

        return $m[1];
    }

    protected function ultimoEmail(): Email
    {
        $ultimo = $this->mailer->enviados[array_key_last($this->mailer->enviados)] ?? null;
        self::assertNotNull($ultimo, 'nenhum e-mail foi enviado');

        return $ultimo;
    }

    protected function contar(string $tabela): int
    {
        return (int) ($this->db->um('SELECT count(*) AS n FROM ' . $tabela)['n'] ?? 0);
    }
}
