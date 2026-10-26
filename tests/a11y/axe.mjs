// Verifica as páginas com axe-core (WCAG 2.0, 2.1 e 2.2, níveis A e AA) em vários estados e confere o
// comportamento de foco. Precisa do site rodando com um Postgres migrado (veja o job do CI).
// Uso: BASE_URL=http://localhost:8080 node axe.mjs
import { randomBytes } from 'node:crypto';
import AxeBuilder from '@axe-core/playwright';
import { chromium } from 'playwright';

const BASE_URL = process.env.BASE_URL ?? 'http://localhost:8080';
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'];

// Conta descartável gerada a cada execução; nada disso é guardado em arquivo.
const email = `a11y-${Date.now()}@example.com`;
const senha = `${randomBytes(12).toString('base64url')}aA1`;
const nome = 'Pessoa de Teste';

// SwiftShader dá WebGL por software no CI; sem ele a cena cai no SVG estático (também verificado abaixo).
const navegador = await chromium.launch({
    args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'],
});
let falhas = 0;

function falhar(cenario, mensagem) {
    falhas += 1;
    console.error(`FALHOU: ${cenario}\n  ${mensagem}`);
}

async function analisar(pagina, cenario) {
    const resultado = await new AxeBuilder({ page: pagina }).withTags(TAGS).analyze();
    if (resultado.violations.length === 0) {
        console.log(`OK: ${cenario} (${resultado.passes.length} regras aprovadas)`);
        return;
    }
    const linhas = [];
    for (const violacao of resultado.violations) {
        linhas.push(`${violacao.id} (${violacao.impact}): ${violacao.help}`);
        for (const no of violacao.nodes) {
            linhas.push(`    ${no.target.join(' ')}`);
        }
    }
    falhar(cenario, linhas.join('\n  '));
}

async function exigirFoco(pagina, cenario, id) {
    try {
        await pagina.waitForFunction((alvo) => document.activeElement?.id === alvo, id, { timeout: 5000 });
    } catch {
        const atual = await pagina.evaluate(() => document.activeElement?.id || document.activeElement?.tagName);
        falhar(cenario, `o foco deveria estar em #${id}, mas está em ${atual}`);
    }
}

// Com WebGL, o botão de pausa aparece, funciona pelo teclado e libera o canvas; sem WebGL, o SVG fica.
async function verificarCenaDoLogin(pagina) {
    const pausar = pagina.locator('[data-pausar-cena]');
    try {
        await pausar.waitFor({ state: 'visible', timeout: 6000 });
    } catch {
        console.log('AVISO: sem animação (WebGL indisponível neste navegador); conferindo o SVG de reserva');
        if (!(await pagina.locator('#cena svg').isVisible())) {
            falhar('cena do login', 'sem WebGL o SVG estático deveria estar visível');
        }

        return;
    }
    if ((await pagina.locator('#cena canvas').count()) !== 1) {
        falhar('cena do login', 'deveria haver um canvas quando o botão de pausa está visível');
    }
    await analisar(pagina, 'entrar com a animação rodando');

    await pausar.focus();
    await pagina.keyboard.press('Enter');
    if ((await pausar.textContent())?.trim() !== 'Retomar animação' || (await pagina.locator('#cena canvas').count()) !== 0) {
        falhar('cena do login', 'Enter no botão deveria pausar e liberar o canvas');
    }
    if (!(await pagina.locator('#cena svg').isVisible())) {
        falhar('cena do login', 'pausada, o SVG estático deveria aparecer');
    }
    await analisar(pagina, 'entrar com a animação pausada');
    await pagina.keyboard.press('Enter');
    await pagina.locator('#cena canvas').waitFor({ timeout: 6000 }).catch(() => falhar('cena do login', 'Retomar deveria recriar o canvas'));
}

async function abrir(pagina, caminho) {
    await pagina.goto(`${BASE_URL}${caminho}`, { waitUntil: 'networkidle' });
}

// Desktop: fluxo completo.
{
    const contexto = await navegador.newContext({ viewport: { width: 1280, height: 800 } });
    const pagina = await contexto.newPage();

    await abrir(pagina, '/entrar');
    await analisar(pagina, 'entrar');
    await verificarCenaDoLogin(pagina);

    // "Mostrar senha" alterna o tipo do campo e o texto do botão.
    await pagina.getByRole('button', { name: 'Mostrar senha' }).click();
    if ((await pagina.locator('#senha').getAttribute('type')) !== 'text') {
        falhar('entrar: mostrar senha', 'o campo deveria virar texto');
    }
    await pagina.getByRole('button', { name: 'Ocultar senha' }).click();

    await pagina.getByRole('button', { name: 'Entrar', exact: true }).click();
    await exigirFoco(pagina, 'entrar com erro: foco no resumo', 'resumo-erros');
    await analisar(pagina, 'entrar com erro');

    await abrir(pagina, '/cadastrar');
    await analisar(pagina, 'cadastrar');
    await pagina.getByRole('button', { name: 'Criar conta', exact: true }).click();
    await exigirFoco(pagina, 'cadastrar com erros: foco no resumo', 'resumo-erros');
    await analisar(pagina, 'cadastrar com erros');

    await abrir(pagina, '/cadastrar');
    await pagina.locator('#nome').fill(nome);
    await pagina.locator('#email').fill(email);
    await pagina.locator('#senha').fill(senha);
    await pagina.getByRole('button', { name: 'Criar conta', exact: true }).click();
    await pagina.getByRole('dialog').waitFor();
    try {
        await pagina.waitForFunction(() => document.activeElement?.hasAttribute('data-fechar-popup'), null, { timeout: 5000 });
    } catch {
        falhar('modal de cadastro: foco', 'o foco deveria estar no botão Continuar');
    }
    await analisar(pagina, 'modal de sucesso do cadastro');
    await pagina.keyboard.press('Escape');
    if ((await pagina.getByRole('dialog').count()) !== 0) {
        falhar('modal: Esc', 'o modal deveria fechar com Esc');
    }

    await abrir(pagina, '/esqueci-senha');
    await analisar(pagina, 'esqueci minha senha');
    await pagina.locator('#email').fill(email);
    await pagina.getByRole('button', { name: 'Enviar link' }).click();
    await exigirFoco(pagina, 'esqueci: foco na confirmação', 'confirmacao');
    await analisar(pagina, 'esqueci minha senha, depois de enviar');

    await abrir(pagina, '/redefinir-senha?token=invalido');
    await analisar(pagina, 'link de redefinição inválido');

    await abrir(pagina, '/entrar');
    await pagina.locator('#email').fill(email);
    await pagina.locator('#senha').fill(senha);
    await pagina.getByRole('button', { name: 'Entrar', exact: true }).click();
    await pagina.getByRole('dialog').waitFor();
    await pagina.keyboard.press('Escape');
    await pagina.waitForURL(`${BASE_URL}/conta`);
    // Primeira carga depois do login: o sinal de desbloqueio veio do servidor (classe do body) e a cena,
    // a mesma da coluna de marca, é decorativa. O botão de pausa fica escondido (a abertura dura poucos segundos).
    if ((await pagina.locator('body.conta.desbloqueio #cena[aria-hidden="true"]').count()) !== 1) {
        falhar('conta: desbloqueio', 'a primeira carga depois do login deveria trazer body.desbloqueio e #cena decorativa');
    }
    if (!(await pagina.locator('[data-pausar-cena]').isHidden())) {
        falhar('conta: desbloqueio', 'o botão de pausa deveria ficar escondido na conta');
    }
    await analisar(pagina, 'área da conta (primeira carga, durante o desbloqueio)');
    await pagina.waitForFunction(() => !document.querySelector('#cena canvas'), null, { timeout: 6000 }).catch(() => {
        falhar('conta: desbloqueio', 'o canvas deveria sair depois de cerca de 3,5 s');
    });
    if (!(await pagina.locator('#cena svg.aneis-svg-aberto').isVisible())) {
        falhar('conta: desbloqueio', 'depois da abertura o SVG da porta aberta deveria ficar visível');
    }
    await analisar(pagina, 'área da conta (estado calmo, mesma página)');

    // A coluna de marca fica fixa ao rolar a conta (a partir de 821 px).
    await pagina.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    if ((await pagina.locator('.marca').evaluate((el) => Math.round(el.getBoundingClientRect().top))) !== 0) {
        falhar('conta: coluna fixa', 'rolada a página, a coluna de marca deveria continuar no topo da janela');
    }
    await pagina.evaluate(() => window.scrollTo(0, 0));

    // Recarregar: o sinal é de uso único, então só o estado calmo (SVG), sem animação.
    await pagina.reload({ waitUntil: 'networkidle' });
    await pagina.waitForTimeout(1500);
    if ((await pagina.locator('body.desbloqueio').count()) !== 0 || (await pagina.locator('#cena canvas').count()) !== 0) {
        falhar('conta: recarregar', 'o desbloqueio não deveria tocar de novo');
    }
    await analisar(pagina, 'área da conta (recarregada)');

    await pagina.locator('#senha-atual').fill('senha-errada');
    await pagina.locator('#senha-nova').fill('1');
    await pagina.getByRole('button', { name: 'Alterar senha' }).click();
    await exigirFoco(pagina, 'conta com erro: foco no resumo', 'resumo-erros-senha');
    await analisar(pagina, 'área da conta com erro de senha');

    await pagina.goto(`${BASE_URL}/pagina-que-nao-existe`);
    await analisar(pagina, 'página 404');

    await contexto.close();
}

// Movimento reduzido: nada de three.js, só o SVG estático, e o botão de pausa fica escondido.
{
    const contexto = await navegador.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' });
    const pagina = await contexto.newPage();
    const baixados = [];
    pagina.on('request', (requisicao) => baixados.push(requisicao.url()));

    await abrir(pagina, '/entrar');
    await pagina.waitForTimeout(1500);
    if (baixados.some((url) => url.includes('/vendor/three/'))) {
        falhar('movimento reduzido', 'o three.js não deveria ser baixado');
    }
    if ((await pagina.locator('#cena canvas').count()) !== 0 || !(await pagina.locator('[data-pausar-cena]').isHidden())) {
        falhar('movimento reduzido', 'não deveria haver canvas nem botão de pausa');
    }
    if (!(await pagina.locator('#cena svg').isVisible())) {
        falhar('movimento reduzido', 'o SVG estático deveria aparecer');
    }
    await analisar(pagina, 'movimento reduzido: entrar');

    // Na conta, depois do login, também nada de three.js: a coluna fica com o SVG da porta aberta.
    await pagina.locator('#email').fill(email);
    await pagina.locator('#senha').fill(senha);
    await pagina.getByRole('button', { name: 'Entrar', exact: true }).click();
    await pagina.getByRole('dialog').waitFor();
    await pagina.keyboard.press('Escape');
    await pagina.waitForURL(`${BASE_URL}/conta`);
    await pagina.waitForTimeout(1500);
    if (baixados.some((url) => url.includes('/vendor/three/'))) {
        falhar('movimento reduzido: conta', 'o three.js não deveria ser baixado');
    }
    if ((await pagina.locator('#cena canvas').count()) !== 0 || !(await pagina.locator('#cena svg.aneis-svg-aberto').isVisible())) {
        falhar('movimento reduzido: conta', 'deveria haver só o SVG da porta aberta, sem canvas');
    }
    await analisar(pagina, 'movimento reduzido: conta');

    await contexto.close();
}

// Celular.
{
    const contexto = await navegador.newContext({ viewport: { width: 375, height: 812 }, isMobile: true });
    const pagina = await contexto.newPage();

    await abrir(pagina, '/entrar');
    await analisar(pagina, 'celular: entrar');
    await abrir(pagina, '/cadastrar');
    await analisar(pagina, 'celular: cadastrar');

    await contexto.close();
}

await navegador.close();

if (falhas > 0) {
    console.error(`\n${falhas} problema(s) de acessibilidade encontrados.`);
    process.exit(1);
}

console.log('\nNenhuma violação de acessibilidade encontrada.');
