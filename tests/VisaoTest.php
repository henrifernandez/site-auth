<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SiteAuth\Visao;

final class VisaoTest extends TestCase
{
    private const string HOSTIL = '"><script>alert(1)</script>';

    private function visao(): Visao
    {
        return new Visao(dirname(__DIR__) . '/templates');
    }

    public function testEscapaAspasETags(): void
    {
        self::assertSame('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $this->visao()->e(self::HOSTIL));
        self::assertSame('&#039;', $this->visao()->e("'"));
        self::assertSame('', $this->visao()->e(null));
    }

    public function testCampoEscapaValorERotuloEErro(): void
    {
        $html = $this->visao()->campo('email', self::HOSTIL, ['valor' => self::HOSTIL, 'erro' => self::HOSTIL, 'dica' => self::HOSTIL]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;', $html);
    }

    public function testCampoLigaRotuloDicaEErro(): void
    {
        $html = $this->visao()->campo('senha', 'Senha', ['tipo' => 'password', 'dica' => 'Dica', 'erro' => 'Falhou', 'autocomplete' => 'new-password']);

        self::assertStringContainsString('<label for="senha">Senha</label>', $html);
        self::assertStringContainsString('aria-describedby="senha-dica senha-erro"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('autocomplete="new-password"', $html);
        self::assertStringNotContainsString('role="alert"', $html, 'role=alert fica só no resumo');
        self::assertStringContainsString('aria-controls="senha"', $html);
        self::assertStringNotContainsString(' value=', $html, 'senha nunca volta preenchida');
    }

    public function testResumoDeErrosTemRoleAlertELinksParaOsCampos(): void
    {
        $html = $this->visao()->resumoErros('resumo-erros', ['email' => 'Informe um e-mail válido.'], null);

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('href="#email"', $html);
        self::assertStringContainsString('tabindex="-1"', $html);
        self::assertSame('', $this->visao()->resumoErros('r', [], null));
    }

    public function testTemplateInvalidoEhRecusado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->visao()->renderizar('../src/App');
    }

    /** @return list<string> */
    private function paginasDeExemplo(): array
    {
        $v = $this->visao();
        $layout = ['titulo' => self::HOSTIL, 'aviso' => self::HOSTIL, 'popup' => [self::HOSTIL, self::HOSTIL, '/entrar']];

        return [
            $v->pagina('entrar', ['csrf' => 'abc', 'email' => self::HOSTIL, 'erros' => ['email' => self::HOSTIL], 'geral' => self::HOSTIL], $layout),
            $v->pagina('cadastrar', ['csrf' => 'abc', 'nome' => self::HOSTIL, 'email' => self::HOSTIL, 'erros' => ['nome' => self::HOSTIL], 'geral' => null], $layout),
            $v->pagina('erro', ['titulo' => self::HOSTIL, 'mensagem' => self::HOSTIL], $layout),
        ];
    }

    public function testPaginasEscapamTudoQueVemDoUsuario(): void
    {
        foreach ($this->paginasDeExemplo() as $html) {
            self::assertStringNotContainsString(self::HOSTIL, $html);
            self::assertStringNotContainsString('<script>alert', $html);
        }
    }

    public function testPaginasNaoTemCssNemJavascriptInline(): void
    {
        foreach ($this->paginasDeExemplo() as $html) {
            self::assertDoesNotMatchRegularExpression('/<style\b/i', $html);
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html);
            self::assertDoesNotMatchRegularExpression('/javascript:/i', $html);
        }
    }

    public function testLayoutTemLangLandmarksUmH1EGanchoDaCena(): void
    {
        $html = $this->paginasDeExemplo()[0];

        self::assertStringContainsString('<html lang="pt-BR">', $html);
        self::assertSame(1, preg_match_all('/<h1[\s>]/', $html));
        self::assertStringContainsString('<main', $html);
        self::assertStringContainsString('<nav class="abas" aria-label="Acesso">', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringNotContainsString('role="tablist"', $html);
        self::assertStringContainsString('<div id="cena" aria-hidden="true">', $html);
        self::assertStringContainsString('aneis-svg-repouso', $html, 'as páginas de acesso trazem o SVG de reserva em repouso');
        self::assertStringNotContainsString('aneis-svg-aberto', $html);
    }

    public function testParcialDaCenaTemUmaVarianteDeCadaVezSemCssNemScriptInline(): void
    {
        $v = $this->visao();

        foreach (['repouso' => 'aberto', 'aberto' => 'repouso'] as $variante => $outra) {
            $html = $v->renderizar('cena_svg', ['variante' => $variante]);

            self::assertSame(1, preg_match_all('/<svg[ >]/', $html), $variante);
            self::assertStringContainsString('aneis-svg-' . $variante, $html);
            self::assertStringNotContainsString('aneis-svg-' . $outra, $html);
            self::assertStringContainsString('viewBox="-345 -345 690 690"', $html);
            self::assertDoesNotMatchRegularExpression('/<style\b|\sstyle\s*=|\son[a-z]+\s*=|<script|javascript:|\shidden\b/i', $html, $variante);
        }
    }

    public function testLayoutEscolheAVarianteAbertaSoNaConta(): void
    {
        $v = $this->visao();
        $dados = ['titulo' => 'Minha conta'];

        foreach (['conta', 'conta desbloqueio'] as $classe) {
            $html = $v->renderizar('layout', $dados + ['popup' => null, 'aviso' => null, 'classe' => $classe, 'conteudo' => '']);
            self::assertStringContainsString('aneis-svg-aberto', $html, $classe);
            self::assertStringNotContainsString('aneis-svg-repouso', $html, $classe);
        }
        foreach (['', 'outra'] as $classe) {
            $html = $v->renderizar('layout', $dados + ['popup' => null, 'aviso' => null, 'classe' => $classe, 'conteudo' => '']);
            self::assertStringContainsString('aneis-svg-repouso', $html, $classe);
            self::assertStringNotContainsString('aneis-svg-aberto', $html, $classe);
        }
    }
}
