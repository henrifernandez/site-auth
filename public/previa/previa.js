// Prévia navegável da cena de anéis (etapa 1). Não é parte do app.
//
// A ligação de eventos abaixo é a mesma que o futuro cena.js vai usar: só foco, saída do campo e
// envio do formulário. Nada reage a teclas nem ao que se digita. O resto é o painel de controle.

import { ESTADOS } from '/assets/js/aneis-pose.js';

const URL_THREE = '/assets/vendor/three/three.module.min.js';
const URL_ANEIS = '/assets/js/aneis.js';
const URL_CADEADO = '/assets/js/cadeado.js';
const ALTURA_MINIMA = 200; // abaixo disso a coluna de marca fica sem cena, como hoje
const FOLGA_DO_TEXTO = 24;
const ESPERA_SAIDA = 250; // ms entre sair de um campo e voltar ao repouso
const PASSO_QUADRO = 1 / 60;
const LIVRES = ['repouso', 'identidade', 'segredo']; // estados em que o foco dos campos manda

const pagina = document.querySelector('.pagina');
const cena = document.getElementById('cena');
const marca = document.querySelector('.marca');
const emConta = document.body.classList.contains('conta');
const parametros = new URLSearchParams(window.location.search);
const movimentoReduzido = window.matchMedia('(prefers-reduced-motion: reduce)');
const ponteiroFino = window.matchMedia('(hover: hover) and (pointer: fine)');
const colunaLado = window.matchMedia('(min-width: 821px)');

function estadoDaCarga() {
    // ?estado=erro abre direto num estado (útil para links e capturas).
    const pedido = parametros.get('estado');
    if (ESTADOS.includes(pedido)) {
        return pedido;
    }
    if (emConta) {
        return parametros.get('desbloqueio') === '1' ? 'liberado' : 'aberto';
    }

    return parametros.get('erro') === '1' || document.querySelector('[data-foco-erro]') ? 'erro' : 'repouso';
}

const S = {
    estado: estadoDaCarga(),
    versao: 'nova', // nova | anterior
    svg: movimentoReduzido.matches,
    modo: parametros.get('relogio') === 'manual' ? 'manual' : 'real', // ?relogio=manual abre com o relógio parado em 0
    T: 0, // tempo atual, em segundos
    tBase: 0, // no modo manual, o tempo em que o estado atual começou
    deslocamento: null, // modo real: relógio do navegador (s) menos T, definido no primeiro quadro
    quadro: 0,
    pausado: false,
    autoRepouso: false,
    cena3d: null,
    THREE: null,
    geracao: 0,
    largura: null,
    quadros: 0,
    inicioFps: null,
    fps: null,
    ultimaMedida: -1,
};

const ui = {};

// Utilidades

function no(tag, props = {}, ...filhos) {
    const elemento = document.createElement(tag);
    for (const [nome, valor] of Object.entries(props)) {
        if (nome === 'texto') {
            elemento.textContent = valor;
        } else {
            elemento.setAttribute(nome, valor);
        }
    }
    elemento.append(...filhos);

    return elemento;
}

function temWebGL() {
    try {
        const tela = document.createElement('canvas');
        const contexto = tela.getContext('webgl2') || tela.getContext('webgl');
        if (!contexto) {
            return false;
        }
        contexto.getExtension('WEBGL_lose_context')?.loseContext();

        return true;
    } catch {
        return false;
    }
}

// Área da cena: o espaço livre acima do texto da coluna de marca (como no cena.js atual).

function ajustarArea() {
    const livre = marca.querySelector('.marca-texto').getBoundingClientRect().top - marca.getBoundingClientRect().top - FOLGA_DO_TEXTO;
    cena.style.setProperty('bottom', 'auto');
    cena.style.setProperty('height', `${Math.max(0, Math.floor(livre))}px`);

    return livre;
}

function dimensionar() {
    const livre = ajustarArea();
    const pequena = livre < ALTURA_MINIMA;
    if (S.cena3d) {
        S.cena3d.canvas.hidden = pequena;
        if (!pequena) {
            S.cena3d.redimensionar(cena.clientWidth, cena.clientHeight);
        }
    }
    mostrarSvg();
}

// Reserva em SVG

function mostrarSvg() {
    const aberto = S.estado === 'liberado' || S.estado === 'aberto';
    const area = cena.clientHeight >= ALTURA_MINIMA;
    // SVG não tem a propriedade hidden: o atributo é que vale.
    cena.querySelector('.aneis-svg-repouso').toggleAttribute('hidden', !(S.svg && area && !aberto));
    cena.querySelector('.aneis-svg-aberto').toggleAttribute('hidden', !(S.svg && area && aberto));
}

// Cena (nova ou anterior)

function desmontar() {
    S.geracao++;
    if (S.cena3d) {
        S.cena3d.descartar();
        S.cena3d = null;
    }
    cena.classList.remove('cena-3d');
}

/** Cena anterior (cadeado.js): mapa aproximado dos sete estados para os gestos que ela conhece. */
function aplicarNaAnterior(c, estado) {
    switch (estado) {
        case 'segredo':
        case 'verificando':
            c.fechar();
            break;
        case 'erro':
            c.sacudir();
            break;
        case 'liberado':
        case 'aberto':
            c.desbloquear(() => {
                if (S.estado === 'liberado') {
                    S.estado = 'aberto';
                    atualizarPainel();
                }
            });
            break;
        default:
            c.repousar();
    }
}

async function montar() {
    desmontar();
    S.ultimaMedida = -1; // força a linha de medidas a se atualizar
    const minha = S.geracao;
    ui.aviso.textContent = '';
    if (S.svg) {
        mostrarSvg();
        atualizarMedidas(tempoAtual());

        return;
    }
    if (!temWebGL()) {
        S.svg = true;
        ui.aviso.textContent = 'Sem WebGL neste navegador: mostrando o SVG.';
        atualizarPainel();
        mostrarSvg();

        return;
    }
    try {
        S.THREE ??= await import(URL_THREE);
        const nova = S.versao === 'nova';
        const modulo = await import(nova ? URL_ANEIS : URL_CADEADO);
        if (minha !== S.geracao) {
            return;
        }
        const tempo = tempoAtual();
        let c;
        if (nova) {
            // Se o relógio já andou, a linha do tempo nasce em repouso e o estado atual entra em T.
            const recomecar = tempo > 0.05 && S.estado !== 'repouso';
            c = modulo.criarAneis(S.THREE, cena, { inicial: recomecar ? 'repouso' : S.estado, parallax: true });
            c.aoTerminar(aoTerminar);
            c.quadro(tempo);
            if (recomecar) {
                c.definirEstado(S.estado, tempo);
            }
        } else {
            c = modulo.criarCadeado(S.THREE, cena, { tema: 'escuro', particulas: 160 });
            c.quadro(tempo);
            aplicarNaAnterior(c, S.estado);
        }
        c.canvas.addEventListener('webglcontextlost', (evento) => {
            evento.preventDefault();
            if (S.cena3d !== c) {
                return; // contexto de uma cena já descartada de propósito
            }
            desmontar();
            S.svg = true;
            ui.aviso.textContent = 'Contexto WebGL perdido: mostrando o SVG.';
            atualizarPainel();
            mostrarSvg();
        });
        S.cena3d = c;
        cena.classList.add('cena-3d');
        mostrarSvg();
        dimensionar();
        desenhar(tempo);
    } catch (erro) {
        desmontar();
        S.svg = true;
        ui.aviso.textContent = `Falha ao montar a cena (${erro?.message ?? erro}): mostrando o SVG.`;
        atualizarPainel();
        mostrarSvg();
    }
}

function aoTerminar(nome) {
    if (nome === 'erro' && S.autoRepouso) {
        irPara('repouso');
    } else if (nome === 'liberado') {
        S.estado = 'aberto';
        atualizarPainel();
    }
}

// Relógio

function tempoAtual() {
    return S.modo === 'manual' ? S.tBase + Number(ui.deslizante.value) : S.T;
}

function desenhar(tempo) {
    S.T = tempo;
    S.cena3d?.quadro(tempo);
    atualizarMedidas(tempo);
}

function passo(ms) {
    S.quadro = window.requestAnimationFrame(passo);
    const segundos = ms / 1000;
    S.deslocamento ??= segundos - S.T;
    desenhar(segundos - S.deslocamento);

    S.inicioFps ??= ms;
    S.quadros++;
    if (ms - S.inicioFps >= 500) {
        S.fps = (S.quadros * 1000) / (ms - S.inicioFps);
        S.quadros = 0;
        S.inicioFps = ms;
    }
}

function atualizarLaco() {
    const deve = S.modo === 'real' && !S.pausado && !document.hidden;
    if (!deve) {
        if (S.quadro) {
            window.cancelAnimationFrame(S.quadro);
            S.quadro = 0;
        }
        S.deslocamento = null;
        S.inicioFps = null;
        S.quadros = 0;
        S.fps = null;

        return;
    }
    if (!S.quadro) {
        S.deslocamento = null;
        S.quadro = window.requestAnimationFrame(passo);
    }
}

function definirModo(modo) {
    if (modo === S.modo) {
        return;
    }
    if (modo === 'manual') {
        S.modo = 'manual';
        S.tBase = S.T;
        ui.deslizante.value = '0';
    } else {
        S.T = tempoAtual();
        S.modo = 'real';
    }
    atualizarLaco();
    atualizarPainel();
    if (S.modo === 'manual') {
        desenhar(tempoAtual());
    }
}

// Estados

function irPara(nome, { automatico = false } = {}) {
    if (!ESTADOS.includes(nome)) {
        return;
    }
    const tempo = tempoAtual();
    S.autoRepouso = automatico;
    if (S.modo === 'manual') {
        S.tBase = tempo;
        ui.deslizante.value = '0';
    }
    S.estado = nome;
    if (S.cena3d) {
        if (S.versao === 'nova') {
            S.cena3d.quadro(tempo);
            S.cena3d.definirEstado(nome, tempo);
        } else {
            S.cena3d.quadro(tempo);
            aplicarNaAnterior(S.cena3d, nome);
        }
    }
    atualizarPainel();
    mostrarSvg();
    desenhar(tempo);
}

// Ligação de eventos (a mesma do futuro cena.js)

let temporizadorSaida = 0;

function campoDaPagina(alvo) {
    return alvo instanceof HTMLInputElement && alvo.type !== 'hidden' && !alvo.closest('.painel') && alvo.form !== null;
}

function aoFocar(evento) {
    const alvo = evento.target;
    if (!campoDaPagina(alvo) || !LIVRES.includes(S.estado)) {
        return;
    }
    const tipos = alvo.autocomplete.split(/\s+/);
    if (tipos.some((t) => t.includes('password'))) {
        irPara('segredo');
    } else if (alvo.type === 'email' || tipos.some((t) => ['username', 'email', 'name'].includes(t))) {
        irPara('identidade');
    }
}

function aoSair(evento) {
    const alvo = evento.target;
    if (!campoDaPagina(alvo)) {
        return;
    }
    const formulario = alvo.form;
    window.clearTimeout(temporizadorSaida);
    temporizadorSaida = window.setTimeout(() => {
        const ativo = document.activeElement;
        const noPainel = ativo instanceof Element && ativo.closest('.painel');
        const noMesmoForm = ativo instanceof HTMLElement && formulario.contains(ativo);
        if (!noMesmoForm && !noPainel && (S.estado === 'identidade' || S.estado === 'segredo')) {
            irPara('repouso');
        }
    }, ESPERA_SAIDA);
}

function aoEnviar(evento) {
    // A prévia não tem servidor: nenhum formulário é enviado.
    evento.preventDefault();
    if (emConta || !LIVRES.includes(S.estado)) {
        return;
    }
    document.getElementById('resumo-erros')?.remove();
    irPara('verificando');
}

function aoMoverPonteiro(evento) {
    if (!ponteiroFino.matches || !S.cena3d) {
        return;
    }
    S.cena3d.apontar((evento.clientX / window.innerWidth) * 2 - 1, (evento.clientY / window.innerHeight) * 2 - 1);
}

function mostrarResumoDeErro() {
    const formulario = document.querySelector('.cartao form[action="/entrar"]');
    if (!formulario || document.getElementById('resumo-erros')) {
        return;
    }
    const resumo = no('div', { class: 'resumo-erros', id: 'resumo-erros', role: 'alert', tabindex: '-1', 'data-foco-erro': '' }, no('p', { texto: 'E-mail ou senha incorretos.' }));
    formulario.insertBefore(resumo, formulario.querySelector('.campo'));

    return resumo;
}

function ligarEventos() {
    document.addEventListener('focusin', aoFocar);
    document.addEventListener('focusout', aoSair);
    document.addEventListener('submit', aoEnviar, true);
    window.addEventListener('pointermove', aoMoverPonteiro, { passive: true });
    window.addEventListener('pagehide', desmontar);
    document.addEventListener('visibilitychange', atualizarLaco);

    // A prévia é estática: os links do app (outras páginas) não levam a lugar nenhum.
    document.addEventListener('click', (evento) => {
        const link = evento.target instanceof Element ? evento.target.closest('a[href^="/"]') : null;
        if (link && !link.getAttribute('href').startsWith('/previa/')) {
            evento.preventDefault();
        }
    });

    for (const botao of document.querySelectorAll('[data-mostrar-senha]')) {
        const campo = document.getElementById(botao.dataset.mostrarSenha);
        botao.hidden = false;
        botao.addEventListener('click', () => {
            const mostrando = campo.type === 'text';
            campo.type = mostrando ? 'password' : 'text';
            botao.textContent = mostrando ? 'Mostrar senha' : 'Ocultar senha';
        });
    }

    const pausar = document.querySelector('[data-pausar-cena]');
    pausar.hidden = false;
    pausar.addEventListener('click', () => {
        S.pausado = !S.pausado;
        pausar.textContent = S.pausado ? 'Retomar animação' : 'Pausar animação';
        pausar.dataset.estado = S.pausado ? 'pausada' : 'rodando';
        atualizarLaco();
    });

    new ResizeObserver(dimensionar).observe(marca);
    movimentoReduzido.addEventListener('change', () => {
        if (movimentoReduzido.matches) {
            S.svg = true;
            atualizarPainel();
            montar();
        }
    });
}

// Painel

function botao(texto, aoClicar, props = {}) {
    const b = no('button', { type: 'button', 'aria-pressed': 'false', ...props, texto });
    b.addEventListener('click', aoClicar);

    return b;
}

function linha(rotulo, ...filhos) {
    return no('div', { class: 'painel-linha' }, no('span', { class: 'painel-rotulo', texto: rotulo }), ...filhos);
}

function construirPainel() {
    const painel = no('section', { class: 'painel', 'aria-label': 'Painel da prévia' });
    ui.painel = painel;

    painel.append(no('div', { class: 'painel-titulo' }, no('span', { texto: 'Prévia: fechadura de anéis' }), (ui.aviso = no('span', { class: 'painel-aviso', 'aria-live': 'polite' }))));

    ui.estados = ESTADOS.map((nome) => botao(nome, () => irPara(nome), { 'data-estado': nome }));
    painel.append(linha('Estado', ...ui.estados));

    ui.real = botao('Real', () => definirModo('real'));
    ui.manual = botao('Manual', () => definirModo('manual'));
    ui.deslizante = no('input', { type: 'range', min: '0', max: '4', step: 'any', value: '0', 'aria-label': 'Tempo desde o início do estado, em segundos' });
    ui.deslizante.addEventListener('input', () => {
        if (S.modo !== 'manual') {
            definirModo('manual');
        }
        desenhar(tempoAtual());
        atualizarPainel();
    });
    ui.leitura = no('output', { 'aria-live': 'off' });
    ui.quadro = botao('1 quadro', () => {
        if (S.modo !== 'manual') {
            definirModo('manual');
        }
        ui.deslizante.value = String(Math.min(4, Number(ui.deslizante.value) + PASSO_QUADRO));
        desenhar(tempoAtual());
        atualizarPainel();
    });
    painel.append(linha('Relógio', ui.real, ui.manual, ui.deslizante, ui.leitura, ui.quadro));

    ui.larguras = [400, 520, 640].map((px) => botao(String(px), () => definirLargura(px), { 'aria-label': `Coluna de ${px} pixels` }));
    ui.nova = botao('Nova', () => definirVersao('nova'));
    ui.anterior = botao('Anterior', () => definirVersao('anterior'));
    ui.semWebgl = botao('Sem WebGL (SVG)', () => {
        S.svg = !S.svg;
        atualizarPainel();
        montar();
    });
    painel.append(linha('Coluna', ...ui.larguras, no('span', { class: 'painel-rotulo', texto: 'Cena' }), ui.nova, ui.anterior, ui.semWebgl));

    if (!emConta) {
        ui.erro = botao('resultado: erro', () => {
            const resumo = mostrarResumoDeErro();
            irPara('erro', { automatico: true });
            resumo?.focus();
        });
        ui.sucesso = botao('resultado: sucesso', () => {
            window.location.href = '/previa/conta.html?desbloqueio=1';
        });
        ui.fluxo = linha('Fluxo', ui.erro, ui.sucesso, no('span', { class: 'painel-medidas', texto: 'Digite nos campos e use Entrar.' }));
        painel.append(ui.fluxo);
    }

    ui.medidas = no('p', { class: 'painel-medidas', 'aria-live': 'off' });
    painel.append(no('div', { class: 'painel-linha' }, ui.medidas));
    document.body.append(painel);
}

function definirLargura(px) {
    S.largura = px;
    if (colunaLado.matches) {
        pagina.style.gridTemplateColumns = `${px}px 1fr`;
        document.documentElement.style.setProperty('--marca-largura', `${px}px`);
    }
    atualizarPainel();
}

function definirVersao(versao) {
    if (S.versao === versao) {
        return;
    }
    S.versao = versao;
    atualizarPainel();
    montar();
}

function marcar(b, ligado) {
    b.setAttribute('aria-pressed', ligado ? 'true' : 'false');
}

function atualizarPainel() {
    ui.estados.forEach((b) => marcar(b, b.dataset.estado === S.estado));
    marcar(ui.real, S.modo === 'real');
    marcar(ui.manual, S.modo === 'manual');
    ui.larguras.forEach((b, i) => marcar(b, S.largura === [400, 520, 640][i]));
    marcar(ui.nova, S.versao === 'nova');
    marcar(ui.anterior, S.versao === 'anterior');
    marcar(ui.semWebgl, S.svg);
    ui.deslizante.disabled = false;
    const decorrido = S.modo === 'manual' ? Number(ui.deslizante.value) : Math.max(0, S.T - S.tBase);
    ui.leitura.textContent = S.modo === 'manual' ? `+${decorrido.toFixed(2)} s no estado` : 'relógio real';
    if (ui.fluxo) {
        ui.fluxo.hidden = S.estado !== 'verificando';
    }
}

function atualizarMedidas(tempo) {
    // Só reescreve o texto uns quatro vezes por segundo.
    const chave = Math.floor(tempo * 4);
    if (chave === S.ultimaMedida) {
        return;
    }
    S.ultimaMedida = chave;
    const info = S.cena3d?.info?.();
    const fps = S.fps === null ? 'fps: relógio manual' : `fps: ${S.fps.toFixed(0)}`;
    const partes = [fps];
    if (info) {
        partes.push(`chamadas de desenho: ${info.chamadas}`, `triângulos: ${info.triangulos}`, `pontos: ${info.pontos}`);
    } else if (S.cena3d) {
        partes.push('contadores só na cena nova');
    } else {
        partes.push(S.svg ? 'SVG estático, sem WebGL' : 'cena não montada');
    }
    partes.push(`t = ${tempo.toFixed(2)} s`);
    ui.medidas.textContent = partes.join(' · ');
    if (S.modo === 'real') {
        atualizarPainel();
    }
}

// Início

construirPainel();
ligarEventos();
atualizarPainel();
if (S.estado === 'erro' && !emConta) {
    mostrarResumoDeErro();
    S.autoRepouso = true;
}
if (S.estado === 'erro' && !emConta && document.getElementById('resumo-erros')) {
    document.getElementById('resumo-erros').focus();
}
ajustarArea();
await montar();
atualizarLaco();
