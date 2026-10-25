<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SiteAuth\Config;
use SiteAuth\Mail\BrevoMailer;
use SiteAuth\Mail\Email;
use SiteAuth\Mail\Emails;
use SiteAuth\Mail\LogMailer;
use SiteAuth\Mail\Mailer;
use SiteAuth\Mail\MailerFabrica;
use SiteAuth\Mail\MailerIndisponivel;
use SiteAuth\Mail\MailException;
use SiteAuth\Mail\Notificador;
use SiteAuth\Mail\Remetente;
use SiteAuth\Mail\ResendMailer;
use SiteAuth\Mail\TransporteHttp;

final class EmailTest extends TestCase
{
    private const string CHAVE = 'chave-falsa-so-para-teste-123';

    private const string LINK = 'https://exemplo.test/redefinir-senha?token=abc_DEF-123';

    private function email(): Email
    {
        return Emails::recuperarSenha('Maria <b>', 'maria@example.com', self::LINK);
    }

    public function testModeloDeRecuperacaoTemTextoHtmlELinkEscapado(): void
    {
        $e = $this->email();

        self::assertSame('Redefinição de senha', $e->assunto);
        self::assertSame('maria@example.com', $e->para);
        self::assertStringContainsString(self::LINK, $e->texto);
        self::assertStringContainsString('30 minutos', $e->texto);
        self::assertStringContainsString('href="' . self::LINK . '"', $e->html);
        self::assertStringContainsString('Maria &lt;b&gt;', $e->html, 'nome escapado no HTML');
        self::assertStringNotContainsString('<b>', $e->html);
        self::assertStringContainsString('Maria <b>', $e->texto, 'o texto simples não usa HTML');
    }

    public function testModelosEmPortuguesComAssuntoEPrazo(): void
    {
        $v = Emails::verificarEmail('Ana', 'ana@example.com', self::LINK);
        $a = Emails::senhaAlterada('Ana', 'ana@example.com', '24/10/2026 às 10:00', 'https://exemplo.test/esqueci-senha');
        $a2 = Emails::senhaAlterada('Ana', 'ana@example.com', '24/10/2026 às 10:00', null);
        $x = Emails::contaExcluida('Ana', 'ana@example.com', '24/10/2026 às 10:00');

        self::assertSame('Confirme seu e-mail', $v->assunto);
        self::assertStringContainsString('24 horas', $v->texto);
        self::assertSame('Sua senha foi alterada', $a->assunto);
        self::assertStringContainsString('24/10/2026 às 10:00', $a->texto);
        self::assertStringContainsString('https://exemplo.test/esqueci-senha', $a->texto);
        self::assertStringNotContainsString('http', $a2->texto);
        self::assertSame('Sua conta foi excluída', $x->assunto);
        foreach ([$v, $a, $a2, $x] as $e) {
            self::assertNotSame('', trim($e->texto));
            self::assertStringStartsWith('<p>', $e->html);
        }
    }

    public function testRemetenteAceitaNomeEEndereco(): void
    {
        $a = Remetente::analisar('Site Auth <contato@example.com>');
        $b = Remetente::analisar('contato@example.com');

        self::assertSame('contato@example.com', $a->email);
        self::assertSame('Site Auth', $a->nome);
        self::assertSame('Site Auth <contato@example.com>', $a->formatado());
        self::assertNull($b->nome);
        self::assertSame('contato@example.com', $b->formatado());
    }

    public function testRemetenteInvalidoLancaExcecao(): void
    {
        $this->expectException(MailException::class);

        Remetente::analisar('isso nao e email');
    }

    public function testResendMontaARequisicaoCerta(): void
    {
        $m = new ResendMailer(self::CHAVE, Remetente::analisar('Site <no-reply@example.com>'));
        $r = $m->montarRequisicao($this->email());

        self::assertSame('https://api.resend.com/emails', $r['url']);
        self::assertContains('Authorization: Bearer ' . self::CHAVE, $r['cabecalhos']);
        self::assertContains('Content-Type: application/json', $r['cabecalhos']);
        self::assertSame('Site <no-reply@example.com>', $r['corpo']['from']);
        self::assertSame(['maria@example.com'], $r['corpo']['to']);
        self::assertSame('Redefinição de senha', $r['corpo']['subject']);
        self::assertArrayHasKey('html', $r['corpo']);
        self::assertArrayHasKey('text', $r['corpo']);
    }

    public function testBrevoMontaARequisicaoCerta(): void
    {
        $m = new BrevoMailer(self::CHAVE, Remetente::analisar('Site <no-reply@example.com>'));
        $r = $m->montarRequisicao($this->email());

        self::assertSame('https://api.brevo.com/v3/smtp/email', $r['url']);
        self::assertContains('api-key: ' . self::CHAVE, $r['cabecalhos']);
        self::assertSame(['email' => 'no-reply@example.com', 'name' => 'Site'], $r['corpo']['sender']);
        self::assertSame([['email' => 'maria@example.com']], $r['corpo']['to']);
        self::assertArrayHasKey('htmlContent', $r['corpo']);
        self::assertArrayHasKey('textContent', $r['corpo']);
    }

    public function testEnvioChamaOTransporteComJsonUtf8(): void
    {
        $visto = [];
        $transporte = static function (string $url, array $cabecalhos, string $corpo) use (&$visto): array {
            $visto = ['url' => $url, 'corpo' => $corpo];

            return [200, '{"id":"x"}'];
        };
        new ResendMailer(self::CHAVE, Remetente::analisar('a@example.com'), $transporte)->enviar($this->email());

        self::assertSame('https://api.resend.com/emails', $visto['url']);
        $json = json_decode($visto['corpo'], true);
        self::assertIsArray($json);
        self::assertSame('Redefinição de senha', $json['subject']);
        self::assertStringContainsString('Redefinição', $visto['corpo'], 'UTF-8 sem escapar');
    }

    public function testRespostaDeErroLancaExcecaoSemAChave(): void
    {
        $transporte = static fn(string $u, array $h, string $c): array => [401, '{"message":"API key is invalid"}'];
        $m = new BrevoMailer(self::CHAVE, Remetente::analisar('a@example.com'), $transporte);

        try {
            $m->enviar($this->email());
            self::fail('deveria falhar');
        } catch (MailException $erro) {
            self::assertStringContainsString('HTTP 401', $erro->getMessage());
            self::assertStringNotContainsString(self::CHAVE, $erro->getMessage());
        }
    }

    public function testNotificadorNaoLancaERegistraSemAChave(): void
    {
        $log = sys_get_temp_dir() . '/siteauth-mail-' . bin2hex(random_bytes(4)) . '.log';
        $anterior = ini_set('error_log', $log);
        $quebra = new class implements Mailer {
            public function enviar(Email $email): void
            {
                throw new MailException('falha com a chave ' . EmailTest::chaveDeTeste());
            }
        };

        try {
            $ok = new Notificador($quebra, self::CHAVE)->enviar($this->email());
        } finally {
            ini_set('error_log', $anterior === false ? '' : $anterior);
        }

        $conteudo = (string) file_get_contents($log);
        unlink($log);
        self::assertFalse($ok);
        self::assertStringContainsString('Falha ao enviar e-mail', $conteudo);
        self::assertStringContainsString('[oculto]', $conteudo);
        self::assertStringNotContainsString(self::CHAVE, $conteudo);
    }

    public static function chaveDeTeste(): string
    {
        return self::CHAVE;
    }

    public function testNotificadorDevolveVerdadeiroQuandoEnvia(): void
    {
        $mailer = new LogMailer(false, static function (string $linha): void {});

        self::assertTrue(new Notificador($mailer)->enviar($this->email()));
        self::assertCount(1, $mailer->enviados);
    }

    public function testLogMailerEmProducaoNaoGravaOLink(): void
    {
        $linhas = [];
        $saida = static function (string $linha) use (&$linhas): void {
            $linhas[] = $linha;
        };

        new LogMailer(true, $saida)->enviar($this->email());
        new LogMailer(false, $saida)->enviar($this->email());

        self::assertStringNotContainsString('token=', $linhas[0]);
        self::assertStringContainsString('m***@example.com', $linhas[0]);
        self::assertStringNotContainsString('maria@example.com', $linhas[0]);
        self::assertStringContainsString(self::LINK, $linhas[1], 'em desenvolvimento o link aparece no log');
    }

    /** @return array<string, array{Config, class-string<Mailer>}> */
    public static function configuracoes(): array
    {
        $com = static fn(string $provedor, string $chave = 'k', string $de = 'a@example.com'): Config => new Config(mailProvider: $provedor, mailApiKey: $chave, mailFrom: $de);

        return [
            'padrão' => [new Config(), LogMailer::class],
            'log' => [$com('log'), LogMailer::class],
            'resend' => [$com('resend'), ResendMailer::class],
            'brevo' => [$com('brevo', 'k', 'Site <a@example.com>'), BrevoMailer::class],
            'sem chave' => [$com('resend', ''), MailerIndisponivel::class],
            'remetente ruim' => [$com('brevo', 'k', 'ruim'), MailerIndisponivel::class],
            'provedor desconhecido' => [$com('sendgrid'), MailerIndisponivel::class],
        ];
    }

    #[DataProvider('configuracoes')]
    public function testFabricaEscolhePeloProvedor(Config $config, string $classe): void
    {
        self::assertInstanceOf($classe, MailerFabrica::criar($config));
    }

    public function testMailerIndisponivelFalhaComMotivoClaro(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('MAIL_API_KEY');

        MailerFabrica::criar(new Config(mailProvider: 'resend'))->enviar($this->email());
    }

    public function testTransporteSoAceitaHttps(): void
    {
        $this->expectException(MailException::class);

        TransporteHttp::postarJson('http://exemplo.test/', [], '{}');
    }
}
