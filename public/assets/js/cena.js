// Orquestra as cenas de cadeado: decide se há animação, carrega o three.js só quando vale a pena
// (WebGL disponível, sem prefers-reduced-motion e com espaço) e cuida do ciclo de vida.
//
// Sem JavaScript, sem WebGL ou com movimento reduzido, o SVG estático que já vem no HTML continua
// visível. Nada aqui mexe no foco do teclado nem anuncia mensagens: o texto da página faz isso.

const URL_THREE = '../vendor/three/three.module.min.js';
const URL_CADEADO = './cadeado.js';
const ALTURA_MINIMA = 200; // abaixo disso (celular, janela baixa) a coluna de marca fica como era
const FOLGA_DO_TEXTO = 24; // distância entre a cena e o texto da coluna de marca

const movimentoReduzido = window.matchMedia('(prefers-reduced-motion: reduce)');
const ponteiroFino = window.matchMedia('(hover: hover) and (pointer: fine)');

let marca = null; // estado da cena do login

function temWebGL() {
    try {
        const tela = document.createElement('canvas');
        const contexto = tela.getContext('webgl2') || tela.getContext('webgl');
        if (!contexto) {
            return false;
        }
        const extensao = contexto.getExtension('WEBGL_lose_context');
        if (extensao) {
            extensao.loseContext();
        }

        return true;
    } catch {
        return false;
    }
}

function quandoOcioso(funcao) {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(funcao, { timeout: 1500 });
    } else {
        window.setTimeout(funcao, 200);
    }
}

/** Espaço livre acima do texto da coluna de marca, em pixels. */
function espacoLivre(elemento) {
    const coluna = elemento.parentElement;
    const texto = coluna?.querySelector('.marca-texto');
    if (!coluna || !texto) {
        return 0;
    }

    return texto.getBoundingClientRect().top - coluna.getBoundingClientRect().top - FOLGA_DO_TEXTO;
}

function ajustarArea(elemento) {
    const livre = espacoLivre(elemento);
    elemento.style.setProperty('bottom', 'auto');
    elemento.style.setProperty('height', `${Math.max(0, Math.floor(livre))}px`);

    return livre;
}

// Cena do login: cadeado na coluna de marca

async function ligarCenaDoLogin(elemento) {
    const botao = document.querySelector('[data-pausar-cena]');
    const estado = {
        elemento,
        botao,
        cadeado: null,
        quadro: 0,
        pausadoPeloUsuario: false,
        abaOculta: document.hidden,
        carregando: false,
        inicioDoRelogio: null,
        observador: null,
        ouvintes: [],
    };
    marca = estado;

    if (movimentoReduzido.matches || !temWebGL() || ajustarArea(elemento) < ALTURA_MINIMA) {
        mostrarEstatico(estado);

        return;
    }

    await iniciar(estado);

    movimentoReduzido.addEventListener('change', () => {
        if (movimentoReduzido.matches) {
            parar(estado);
        }
    });
    document.addEventListener('visibilitychange', () => {
        estado.abaOculta = document.hidden;
        atualizarLoop(estado);
    });
    window.addEventListener('pagehide', () => descartar(estado));
    botao?.addEventListener('click', () => {
        estado.pausadoPeloUsuario = !estado.pausadoPeloUsuario;
        if (estado.pausadoPeloUsuario) {
            descartar(estado);
            botao.textContent = 'Retomar animação';
            botao.dataset.estado = 'pausada';
        } else {
            botao.textContent = 'Pausar animação';
            botao.dataset.estado = 'rodando';
            iniciar(estado);
        }
    });
}

function mostrarEstatico(estado) {
    estado.elemento.classList.remove('cena-3d');
    if (!estado.pausadoPeloUsuario && estado.botao) {
        estado.botao.hidden = true;
    }
}

async function iniciar(estado) {
    if (estado.cadeado || estado.carregando) {
        return;
    }
    estado.carregando = true;
    try {
        const [THREE, modulo] = await Promise.all([import(URL_THREE), import(URL_CADEADO)]);
        if (estado.pausadoPeloUsuario || movimentoReduzido.matches) {
            return;
        }

        const cadeado = modulo.criarCadeado(THREE, estado.elemento, { tema: 'escuro', particulas: 160 });
        cadeado.canvas.addEventListener('webglcontextlost', (evento) => {
            evento.preventDefault();
            descartar(estado);
            mostrarEstatico(estado);
        });
        estado.cadeado = cadeado;
        dimensionar(estado);
        estado.elemento.classList.add('cena-3d');
        if (estado.botao) {
            estado.botao.hidden = false;
        }
        ligarEntradas(estado);

        // A página recarregou com erro: uma sacudida curta, sem mexer no foco nem no texto.
        if (document.querySelector('[data-foco-erro]')) {
            cadeado.quadro(0);
            cadeado.sacudir();
        }
        atualizarLoop(estado);
    } catch {
        // Qualquer falha (rede, WebGL, módulo) deixa o SVG estático e o formulário intactos.
        descartar(estado);
        mostrarEstatico(estado);
    } finally {
        estado.carregando = false;
    }
}

function dimensionar(estado) {
    const livre = ajustarArea(estado.elemento);
    if (livre < ALTURA_MINIMA) {
        descartar(estado);
        mostrarEstatico(estado);

        return;
    }
    estado.cadeado?.redimensionar(estado.elemento.clientWidth, estado.elemento.clientHeight);
}

function ligarEntradas(estado) {
    const aoFocar = (evento) => {
        if (evento.target instanceof HTMLInputElement && evento.target.type !== 'hidden' && evento.target.autocomplete.includes('password')) {
            estado.cadeado?.fechar();
        }
    };
    const aoSair = (evento) => {
        if (evento.target instanceof HTMLInputElement && evento.target.autocomplete.includes('password')) {
            estado.cadeado?.repousar();
        }
    };
    // Só foco e saída do campo: nada reage a teclas, para não revelar o tamanho da senha.
    const aoMoverPonteiro = (evento) => {
        if (!ponteiroFino.matches) {
            return;
        }
        estado.cadeado?.apontar((evento.clientX / window.innerWidth) * 2 - 1, (evento.clientY / window.innerHeight) * 2 - 1);
    };
    // O ponteiro com cadeado em foco e senha: o campo de senha pode já estar focado ao carregar.
    document.addEventListener('focusin', aoFocar);
    document.addEventListener('focusout', aoSair);
    window.addEventListener('pointermove', aoMoverPonteiro, { passive: true });
    estado.ouvintes = [
        ['focusin', document, aoFocar],
        ['focusout', document, aoSair],
        ['pointermove', window, aoMoverPonteiro],
    ];

    estado.observador = new ResizeObserver(() => dimensionar(estado));
    estado.observador.observe(estado.elemento.parentElement);
}

function atualizarLoop(estado) {
    const deveRodar = estado.cadeado && !estado.pausadoPeloUsuario && !estado.abaOculta && !movimentoReduzido.matches;
    if (!deveRodar) {
        if (estado.quadro) {
            window.cancelAnimationFrame(estado.quadro);
            estado.quadro = 0;
        }

        return;
    }
    if (estado.quadro) {
        return;
    }
    const passo = (agora) => {
        estado.quadro = window.requestAnimationFrame(passo);
        estado.inicioDoRelogio ??= agora;
        estado.cadeado?.quadro((agora - estado.inicioDoRelogio) / 1000);
    };
    estado.quadro = window.requestAnimationFrame(passo);
}

function descartar(estado) {
    if (estado.quadro) {
        window.cancelAnimationFrame(estado.quadro);
        estado.quadro = 0;
    }
    for (const [tipo, alvo, funcao] of estado.ouvintes) {
        alvo.removeEventListener(tipo, funcao);
    }
    estado.ouvintes = [];
    estado.observador?.disconnect();
    estado.observador = null;
    if (estado.cadeado) {
        estado.cadeado.descartar();
        estado.cadeado = null;
    }
    estado.inicioDoRelogio = null;
    estado.elemento.classList.remove('cena-3d');
}

function parar(estado) {
    descartar(estado);
    mostrarEstatico(estado);
}

// Desbloqueio na área da conta

function aguardarPopup() {
    if (!document.querySelector('[data-popup]')) {
        return Promise.resolve();
    }

    return new Promise((resolver) => {
        document.addEventListener('popup:fechado', () => resolver(), { once: true });
    });
}

async function ligarDesbloqueio(elemento) {
    // Só na primeira carga depois do login (o servidor marca com data-desbloqueio e consome o sinal).
    if (elemento.dataset.desbloqueio !== '1' || movimentoReduzido.matches || !temWebGL()) {
        return;
    }

    await aguardarPopup();

    let cadeado = null;
    let quadro = 0;
    const limpar = () => {
        window.cancelAnimationFrame(quadro);
        cadeado?.descartar();
        cadeado = null;
        elemento.classList.remove('cena-3d', 'cena-saindo');
    };

    try {
        const [THREE, modulo] = await Promise.all([import(URL_THREE), import(URL_CADEADO)]);
        if (document.hidden) {
            await new Promise((resolver) => document.addEventListener('visibilitychange', resolver, { once: true }));
        }
        cadeado = modulo.criarCadeado(THREE, elemento, { tema: 'claro', particulas: 120, raioParticulas: 0.55, abertura: 0 });
        cadeado.redimensionar(elemento.clientWidth, elemento.clientHeight);
        elemento.classList.add('cena-3d');

        let inicio = null;
        const passo = (agora) => {
            inicio ??= agora;
            cadeado.quadro((agora - inicio) / 1000);
            quadro = window.requestAnimationFrame(passo);
        };
        cadeado.quadro(0);
        cadeado.desbloquear(() => {
            // O SVG do cadeado aberto já está embaixo; o canvas some com uma transição curta.
            elemento.classList.add('cena-saindo');
            window.cancelAnimationFrame(quadro);
            window.setTimeout(limpar, 450);
        });
        quadro = window.requestAnimationFrame(passo);
        window.addEventListener('pagehide', limpar, { once: true });
    } catch {
        limpar();
    }
}

/** @param {HTMLElement | null} elemento contêiner #cena da coluna de marca */
export function iniciarCena(elemento) {
    const conta = document.getElementById('cena-conta');
    if (conta) {
        quandoOcioso(() => ligarDesbloqueio(conta));
    }
    if (elemento && !conta) {
        quandoOcioso(() => ligarCenaDoLogin(elemento));
    }
}

export function pararCena() {
    if (marca) {
        parar(marca);
    }
}
