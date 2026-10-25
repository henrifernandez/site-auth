<?php

declare(strict_types=1);

namespace SiteAuth\Tests;

use DOMDocument;
use DOMElement;
use DOMXPath;
use SiteAuth\Tokens;
use SiteAuth\TokensConta;

/**
 * Verificações estruturais de acessibilidade em todas as páginas (rótulos, ids, landmarks, h1, autocomplete).
 * Não substituem o axe-core no navegador (tests/a11y), mas rodam em qualquer máquina.
 */
final class AcessibilidadeEstruturaTest extends AppBancoTestCase
{
    private const array AUTOCOMPLETE_VALIDOS = ['username', 'current-password', 'new-password', 'email', 'name', 'off'];

    /** @return array<string, string> nome da página => HTML */
    private function paginas(): array
    {
        $id = $this->criarUsuario('maria@example.com', 'senha-atual-1');
        $gerado = Tokens::gerar();
        $tokens = new TokensConta($this->db);
        $tokens->criar($id, TokensConta::RECUPERAR_SENHA, $gerado['hash'], 1800);

        $anonimo = $this->navegador('198.51.100.1');
        $logado = $this->logar('maria@example.com', 'senha-atual-1', '198.51.100.2');
        $csrf = $logado->csrfDe('/conta');

        return [
            'entrar' => $anonimo->get('/entrar')->body,
            'entrar com erro' => $anonimo->post('/entrar', ['email' => 'x@example.com', 'senha' => 'errada-errada'])->body,
            'entrar vazio' => $anonimo->post('/entrar', ['email' => '', 'senha' => ''])->body,
            'cadastrar' => $anonimo->get('/cadastrar')->body,
            'cadastrar com erros' => $anonimo->post('/cadastrar', ['nome' => 'A', 'email' => 'ruim', 'senha' => '123'])->body,
            'esqueci' => $anonimo->get('/esqueci-senha')->body,
            'esqueci com erro' => $anonimo->post('/esqueci-senha', ['email' => 'ruim'])->body,
            'esqueci enviado' => $anonimo->post('/esqueci-senha', ['email' => 'qualquer@example.com'])->body,
            'redefinir' => $anonimo->get('/redefinir-senha', ['token' => $gerado['token']])->body,
            'redefinir link inválido' => $anonimo->get('/redefinir-senha', ['token' => 'ruim'])->body,
            'verificar link inválido' => $anonimo->get('/verificar-email', ['token' => 'ruim'])->body,
            'conta' => $logado->get('/conta')->body,
            'conta com erro de nome' => $logado->post('/conta/nome', ['csrf' => $csrf, 'nome' => 'A'])->body,
            'conta com erro de senha' => $logado->post('/conta/senha', ['csrf' => $csrf, 'senha_atual' => 'x', 'senha_nova' => '1'])->body,
            'conta com erro ao excluir' => $logado->post('/conta/excluir', ['csrf' => $csrf, 'senha_excluir' => 'x', 'confirmacao' => 'x'])->body,
            'página 404' => $anonimo->get('/nao-existe')->body,
        ];
    }

    private function documento(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return $doc;
    }

    public function testEstruturaAcessivelEmTodasAsPaginas(): void
    {
        foreach ($this->paginas() as $nome => $html) {
            $doc = $this->documento($html);
            $xp = new DOMXPath($doc);

            self::assertSame('pt-BR', $doc->documentElement?->getAttribute('lang'), "{$nome}: lang");
            self::assertNotSame('', trim((string) $xp->evaluate('string(//title)')), "{$nome}: title");
            self::assertSame(1, (int) $xp->evaluate('count(//h1)'), "{$nome}: exatamente um h1");
            self::assertSame(1, (int) $xp->evaluate('count(//main)'), "{$nome}: um main");
            self::assertSame(1, (int) $xp->evaluate('count(//aside[@aria-label])'), "{$nome}: aside nomeado");
            self::assertSame(0, (int) $xp->evaluate('count(//*[@role="tablist" or @role="tab"])'), "{$nome}: sem abas ARIA em links");
            self::assertSame(0, (int) $xp->evaluate('count(//*[@tabindex and number(@tabindex) > 0])'), "{$nome}: sem tabindex positivo");
            self::assertSame(0, (int) $xp->evaluate('count(//img[not(@alt)])'), "{$nome}: imagens com alt");
            self::assertSame(1, (int) $xp->evaluate('count(//*[@id="cena" and @aria-hidden="true"])'), "{$nome}: gancho da cena");

            $this->conferirIds($doc, $xp, $nome);
            $this->conferirCampos($xp, $nome);
            $this->conferirResumoDeErros($xp, $nome);
        }
    }

    private function conferirIds(DOMDocument $doc, DOMXPath $xp, string $nome): void
    {
        $ids = [];
        foreach ($xp->query('//*[@id]') ?: [] as $el) {
            $id = $el instanceof DOMElement ? $el->getAttribute('id') : '';
            self::assertArrayNotHasKey($id, $ids, "{$nome}: id duplicado {$id}");
            $ids[$id] = true;
        }

        foreach ($xp->query('//*[@aria-describedby or @aria-labelledby or @aria-controls]') ?: [] as $el) {
            self::assertInstanceOf(DOMElement::class, $el);
            foreach (['aria-describedby', 'aria-labelledby', 'aria-controls'] as $atributo) {
                foreach (array_filter(explode(' ', $el->getAttribute($atributo))) as $alvo) {
                    self::assertArrayHasKey($alvo, $ids, "{$nome}: {$atributo} aponta para #{$alvo} inexistente");
                }
            }
        }

        foreach ($xp->query('//a[starts-with(@href, "#")]') ?: [] as $el) {
            self::assertInstanceOf(DOMElement::class, $el);
            self::assertArrayHasKey(substr($el->getAttribute('href'), 1), $ids, "{$nome}: link interno quebrado");
        }
    }

    private function conferirCampos(DOMXPath $xp, string $nome): void
    {
        foreach ($xp->query('//input[not(@type="hidden")]') ?: [] as $input) {
            self::assertInstanceOf(DOMElement::class, $input);
            $id = $input->getAttribute('id');
            self::assertNotSame('', $id, "{$nome}: campo sem id");
            self::assertSame(1, (int) $xp->evaluate('count(//label[@for="' . $id . '"])'), "{$nome}: #{$id} sem rótulo");

            $autocomplete = $input->getAttribute('autocomplete');
            self::assertContains($autocomplete, self::AUTOCOMPLETE_VALIDOS, "{$nome}: #{$id} sem autocomplete correto");
            self::assertFalse($input->hasAttribute('onpaste'), "{$nome}: colar deve ser permitido");

            if ($input->getAttribute('type') === 'password') {
                self::assertContains($autocomplete, ['current-password', 'new-password'], "{$nome}: #{$id}");
                self::assertSame(1, (int) $xp->evaluate('count(//button[@aria-controls="' . $id . '"])'), "{$nome}: #{$id} sem botão mostrar senha");
            }
            if ($input->getAttribute('aria-invalid') === 'true') {
                self::assertStringContainsString($id . '-erro', $input->getAttribute('aria-describedby'), "{$nome}: #{$id} com erro precisa de aria-describedby");
            }
        }

        foreach ($xp->query('//form') ?: [] as $form) {
            self::assertInstanceOf(DOMElement::class, $form);
            self::assertSame('post', strtolower($form->getAttribute('method')), "{$nome}: formulário POST");
        }
    }

    private function conferirResumoDeErros(DOMXPath $xp, string $nome): void
    {
        $alertas = $xp->query('//*[@role="alert"]') ?: [];
        foreach ($alertas as $alerta) {
            self::assertInstanceOf(DOMElement::class, $alerta);
            self::assertSame('-1', $alerta->getAttribute('tabindex'), "{$nome}: o resumo precisa receber foco");
            self::assertTrue($alerta->hasAttribute('data-foco-erro'), "{$nome}: o resumo precisa do gancho de foco");
            self::assertStringStartsWith('resumo-erros', $alerta->getAttribute('id'), "{$nome}: id do resumo");
        }
        self::assertLessThanOrEqual(1, $alertas->length, "{$nome}: um resumo por página");
        self::assertSame(0, (int) $xp->evaluate('count(//*[contains(@class,"erro-campo")][@role])'), "{$nome}: mensagens de campo sem role=alert");
    }

    public function testPaginasDeAcessoTemNavComAriaCurrent(): void
    {
        $anonimo = $this->navegador();

        foreach (['/entrar' => 'Entrar', '/cadastrar' => 'Criar conta'] as $rota => $rotulo) {
            $xp = new DOMXPath($this->documento($anonimo->get($rota)->body));
            $atual = $xp->query('//nav[@aria-label="Acesso"]//a[@aria-current="page"]');

            self::assertNotFalse($atual);
            self::assertSame(1, $atual->length, $rota);
            self::assertSame($rotulo, trim((string) $atual->item(0)?->textContent), $rota);
        }
    }

    public function testErroDeCampoLigaMensagemAoInputEAoResumo(): void
    {
        $html = $this->navegador()->post('/cadastrar', ['nome' => 'A', 'email' => 'ruim', 'senha' => '123'])->body;
        $xp = new DOMXPath($this->documento($html));

        foreach (['nome', 'email', 'senha'] as $campo) {
            self::assertSame(1, (int) $xp->evaluate('count(//input[@id="' . $campo . '"][@aria-invalid="true"][contains(@aria-describedby, "' . $campo . '-erro")])'), $campo);
            self::assertSame(1, (int) $xp->evaluate('count(//p[@id="' . $campo . '-erro"])'), $campo);
            self::assertSame(1, (int) $xp->evaluate('count(//*[@role="alert"]//a[@href="#' . $campo . '"])'), $campo);
        }
        self::assertStringContainsString('Erro: Criar conta', (string) $xp->evaluate('string(//title)'));
    }

    public function testModalDeSucessoTemRoleDialogEMarcadoresDeFoco(): void
    {
        $this->navegador()->get('/entrar');
        $n = $this->navegador();
        $n->post('/cadastrar', ['nome' => 'Maria Silva', 'email' => 'maria@example.com', 'senha' => 'uma-senha-boa-1']);
        $xp = new DOMXPath($this->documento($n->get('/entrar')->body));

        self::assertSame(1, (int) $xp->evaluate('count(//*[@role="dialog"][@aria-modal="true"][@aria-labelledby="popup-titulo"][@aria-describedby="popup-texto"])'));
        self::assertSame(1, (int) $xp->evaluate('count(//*[@data-fechar-popup])'));
        self::assertSame(1, (int) $xp->evaluate('count(//h1)'), 'o título do modal é h2, então o h1 segue único');
    }
}
