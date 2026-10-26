// Orquestra a cena "fechadura de anéis": decide se há animação, carrega o three.js só quando vale a
// pena e liga a cena aos eventos da página (foco, saída do campo, envio, erro e desbloqueio).
//
// A cena em si está em aneis.js (visual) e aneis-pose.js (coreografia). Aqui só se escolhe o estado.
// Sem JavaScript, sem WebGL, com movimento reduzido ou com pouco espaço, o SVG de reserva que já
// vem no HTML continua visível. A cena é decorativa: nada aqui mexe no foco do teclado nem anuncia
// mensagens (o texto da página faz isso), e nada reage a teclas ou ao que se digita, para não
// revelar o tamanho da senha a quem olha a tela.

const URL_THREE = '../vendor/three/three.module.min.js';
const URL_ANEIS = './aneis.js';
const LARGURA_MINIMA = 300; // abaixo disso (celular, janela estreita ou baixa) fica só o SVG
const ALTURA_MINIMA = 260;
const ESPERA_SAIDA = 250; // ms entre sair de um campo e voltar ao repouso
const ESPERA_ABERTO = 400; // ms com a porta aberta antes de o canvas sair, na conta
const DURACAO_SAIDA = 450; // ms da transição que revela o SVG por baixo (a mesma do CSS)
const LIVRES = ['repouso', 'identidade', 'segredo']; // estados em que o foco dos campos manda

const movimentoReduzido = window.matchMedia('(prefers-reduced-motion: reduce)');
const ponteiroFino = window.matchMedia('(hover: hover) and (pointer: fine)');

let modulos = null;
let controle = null;

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

function quandoOcioso(funcao) {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(funcao, { timeout: 1500 });
    } else {
        window.setTimeout(funcao, 200);
    }
}

function espacoSuficiente(elemento) {
    return elemento.clientWidth >= LARGURA_MINIMA && elemento.clientHeight >= ALTURA_MINIMA;
}

/** O three.js e a cena só são baixados quando alguém vai mesmo usá-los. */
function carregarModulos() {
    modulos ??= Promise.all([import(URL_THREE), import(URL_ANEIS)]).catch((erro) => {
        modulos = null;
        throw erro;
    });

    return modulos;
}

// Estado do foco

function campoDaPagina(alvo) {
    return alvo instanceof HTMLInputElement && alvo.type !== 'hidden' && alvo.form !== null && alvo.closest('.cartao') !== null;
}

/** 'segredo' para senhas, 'identidade' para e-mail ou nome, null para qualquer outro campo. */
function estadoDoCampo(campo) {
    const tipos = campo.autocomplete.split(/\s+/);
    if (tipos.some((tipo) => tipo.includes('password'))) {
        return 'segredo';
    }
    if (campo.type === 'email' || tipos.some((tipo) => ['username', 'email', 'name'].includes(tipo))) {
        return 'identidade';
    }

    return null;
}

function estadoDoFoco() {
    const ativo = document.activeElement;

    return (campoDaPagina(ativo) ? estadoDoCampo(ativo) : null) ?? 'repouso';
}

// Controle da cena (um por página)

function criarControle(elemento) {
    return {
        elemento,
        botao: document.querySelector('[data-pausar-cena]'),
        emConta: document.body.classList.contains('conta'),
        cena: null,
        estado: 'repouso', // estado da fechadura (o de aneis-pose.js)
        geracao: 0, // invalida montagens assíncronas que ficaram para trás
        montando: false,
        erroPendente: document.querySelector('[data-foco-erro]') !== null,
        concluido: false, // na conta: a porta já abriu
        ligado: false, // o relógio pode andar (na conta, só depois de o modal fechar)
        agendado: false,
        falhou: false, // sem WebGL ou contexto perdido: só o SVG
        pausadoPeloUsuario: false,
        abaOculta: document.hidden,
        relogio: 0, // segundos de cena; só avança enquanto o laço roda
        deslocamento: null,
        quadro: 0,
        temporizadores: new Set(),
    };
}

function esperar(c, ms, funcao) {
    const id = window.setTimeout(() => {
        c.temporizadores.delete(id);
        funcao();
    }, ms);
    c.temporizadores.add(id);

    return id;
}

function permitida(c) {
    return !movimentoReduzido.matches && !c.falhou && !c.pausadoPeloUsuario;
}

function atualizarBotao(c) {
    if (!c.botao) {
        return;
    }
    // Na conta a animação dura poucos segundos: o botão fica escondido.
    const emUso = c.cena !== null || c.montando || c.pausadoPeloUsuario;
    c.botao.hidden = c.emConta || c.falhou || movimentoReduzido.matches || !espacoSuficiente(c.elemento) || !emUso;
}

/** Monta a cena em `estado`. O relógio fica parado em 0 até `rodar` ser chamado. */
async function montar(c, estado, { paralaxe = true } = {}) {
    if (c.cena || c.montando || !permitida(c) || !espacoSuficiente(c.elemento)) {
        return null;
    }
    c.montando = true;
    const minha = ++c.geracao;
    try {
        const [THREE, aneis] = await carregarModulos();
        if (minha !== c.geracao || !permitida(c) || !espacoSuficiente(c.elemento)) {
            return null;
        }

        const cena = aneis.criarAneis(THREE, c.elemento, { inicial: estado, parallax: paralaxe });
        cena.canvas.addEventListener('webglcontextlost', (evento) => {
            evento.preventDefault();
            if (c.cena === cena) {
                c.falhou = true;
                descartar(c);
            }
        });
        c.cena = cena;
        c.estado = estado;
        c.relogio = 0;
        c.deslocamento = null;
        cena.redimensionar(c.elemento.clientWidth, c.elemento.clientHeight);
        cena.quadro(0);
        c.elemento.classList.add('cena-3d');
        atualizarBotao(c);

        return cena;
    } catch {
        // Qualquer falha (rede, WebGL, módulo) deixa o SVG de reserva e o formulário intactos.
        c.falhou = true;
        descartar(c);

        return null;
    } finally {
        c.montando = false;
    }
}

function descartar(c) {
    c.geracao += 1;
    c.ligado = false;
    parar(c);
    for (const id of c.temporizadores) {
        window.clearTimeout(id);
    }
    c.temporizadores.clear();
    if (c.cena) {
        c.cena.descartar();
        c.cena = null;
    }
    c.elemento.classList.remove('cena-3d', 'cena-saindo');
    atualizarBotao(c);
}

// Relógio: o tempo sempre vem de fora, em segundos de cena, e continua de onde parou.

function rodar(c) {
    const deve = c.cena && c.ligado && !c.abaOculta && !c.pausadoPeloUsuario && !movimentoReduzido.matches;
    if (!deve) {
        parar(c);

        return;
    }
    if (c.quadro) {
        return;
    }
    const passo = (ms) => {
        c.quadro = window.requestAnimationFrame(passo);
        c.deslocamento ??= ms / 1000 - c.relogio;
        c.relogio = ms / 1000 - c.deslocamento;
        c.cena?.quadro(c.relogio);
    };
    c.deslocamento = null;
    c.quadro = window.requestAnimationFrame(passo);
}

function parar(c) {
    if (c.quadro) {
        window.cancelAnimationFrame(c.quadro);
        c.quadro = 0;
    }
    c.deslocamento = null;
}

function irPara(c, estado) {
    if (!c.cena) {
        return;
    }
    c.estado = estado;
    c.cena.definirEstado(estado, c.relogio);
}

// Login, cadastro e recuperação de senha

async function iniciarNaMarca(c) {
    if (!c.cena) {
        const inicial = c.erroPendente ? 'erro' : estadoDoFoco();
        const cena = await montar(c, inicial, { paralaxe: true });
        if (!cena) {
            return;
        }
        c.erroPendente = false;
        cena.aoTerminar((nome) => {
            // A página recarregou com erro: depois da animação, a fechadura volta ao que o foco pede.
            if (nome === 'erro') {
                irPara(c, estadoDoFoco());
            }
        });
    }
    c.ligado = true;
    rodar(c);
}

function agendar(c) {
    if (c.agendado) {
        return;
    }
    c.agendado = true;
    quandoOcioso(() => {
        c.agendado = false;
        iniciarNaMarca(c);
    });
}

function ligarEventosDosCampos(c) {
    let temporizador = 0;

    document.addEventListener('focusin', (evento) => {
        const alvo = evento.target;
        if (!c.cena || !campoDaPagina(alvo) || !LIVRES.includes(c.estado)) {
            return;
        }
        const estado = estadoDoCampo(alvo);
        if (estado) {
            irPara(c, estado);
        }
    });

    document.addEventListener('focusout', (evento) => {
        const alvo = evento.target;
        if (!campoDaPagina(alvo)) {
            return;
        }
        const formulario = alvo.form;
        window.clearTimeout(temporizador);
        temporizador = window.setTimeout(() => {
            // O foco pode ter ido para outro campo ou para o botão "Mostrar senha", no mesmo formulário.
            const ativo = document.activeElement;
            const noMesmoForm = ativo instanceof HTMLElement && formulario.contains(ativo);
            if (c.cena && !noMesmoForm && (c.estado === 'identidade' || c.estado === 'segredo')) {
                irPara(c, 'repouso');
            }
        }, ESPERA_SAIDA);
    });

    // Fase de captura e sem preventDefault: a cena só avisa que o envio começou, o formulário segue.
    document.addEventListener(
        'submit',
        (evento) => {
            if (c.cena && evento.target instanceof HTMLFormElement && evento.target.closest('.cartao')) {
                irPara(c, 'verificando');
            }
        },
        true,
    );

    window.addEventListener(
        'pointermove',
        (evento) => {
            if (c.cena && ponteiroFino.matches) {
                c.cena.apontar((evento.clientX / window.innerWidth) * 2 - 1, (evento.clientY / window.innerHeight) * 2 - 1);
            }
        },
        { passive: true },
    );
}

function ligarBotaoDePausa(c) {
    c.botao?.addEventListener('click', () => {
        c.pausadoPeloUsuario = !c.pausadoPeloUsuario;
        if (c.pausadoPeloUsuario) {
            descartar(c);
            c.botao.textContent = 'Retomar animação';
            c.botao.dataset.estado = 'pausada';
        } else {
            c.botao.textContent = 'Pausar animação';
            c.botao.dataset.estado = 'rodando';
            iniciarNaMarca(c);
        }
        atualizarBotao(c);
    });
}

// Área da conta: abertura da fechadura na primeira carga depois do login

function aguardarModal() {
    if (!document.querySelector('[data-popup]')) {
        return Promise.resolve();
    }

    return new Promise((resolver) => {
        document.addEventListener('popup:fechado', () => resolver(), { once: true });
    });
}

async function abrirNaConta(c) {
    // O servidor marca a primeira carga com body.desbloqueio e consome o sinal. Sem ele (recarregar,
    // voltar à página) a coluna fica com o SVG aberto, sem three.js.
    if (!document.body.classList.contains('desbloqueio') || c.concluido) {
        return;
    }
    const cena = await montar(c, 'liberado', { paralaxe: false });
    if (!cena) {
        return;
    }

    // O primeiro quadro (fechadura fechada) já está desenhado sob o modal; o relógio só liga depois dele.
    await aguardarModal();
    if (c.cena !== cena) {
        return;
    }
    cena.aoTerminar((nome) => {
        if (nome !== 'liberado') {
            return;
        }
        c.concluido = true;
        esperar(c, ESPERA_ABERTO, () => {
            // O SVG aberto já está por baixo: o canvas some e a cena é descartada por inteiro.
            c.elemento.classList.add('cena-saindo');
            esperar(c, DURACAO_SAIDA + 50, () => descartar(c));
        });
    });
    c.ligado = true;
    rodar(c);
}

// Ciclo de vida

function observarTamanho(c) {
    new ResizeObserver(() => {
        if (c.emConta) {
            if (c.cena && espacoSuficiente(c.elemento)) {
                c.cena.redimensionar(c.elemento.clientWidth, c.elemento.clientHeight);
            }

            return;
        }
        if (c.cena && !espacoSuficiente(c.elemento)) {
            descartar(c);
        } else if (c.cena) {
            c.cena.redimensionar(c.elemento.clientWidth, c.elemento.clientHeight);
        } else if (!c.montando && permitida(c) && espacoSuficiente(c.elemento)) {
            agendar(c);
        }
        atualizarBotao(c);
    }).observe(c.elemento);
}

function ligarCicloDeVida(c) {
    document.addEventListener('visibilitychange', () => {
        c.abaOculta = document.hidden;
        rodar(c);
    });
    movimentoReduzido.addEventListener('change', () => {
        if (movimentoReduzido.matches) {
            descartar(c);
        }
    });
    window.addEventListener('pagehide', () => descartar(c));
    // Voltar à página pelo histórico (cache de páginas): a cena foi descartada, então recomeça.
    window.addEventListener('pageshow', (evento) => {
        if (!evento.persisted) {
            return;
        }
        c.abaOculta = document.hidden;
        if (c.emConta) {
            abrirNaConta(c);
        } else {
            iniciarNaMarca(c);
        }
    });
}

/** @param {HTMLElement | null} elemento contêiner #cena da coluna de marca */
export function iniciarCena(elemento) {
    if (!elemento || controle) {
        return;
    }
    const c = criarControle(elemento);
    controle = c;
    // Com movimento reduzido ou sem WebGL nada é carregado: fica o SVG que já está no HTML.
    if (movimentoReduzido.matches) {
        return;
    }
    if (!temWebGL()) {
        c.falhou = true;

        return;
    }

    ligarCicloDeVida(c);
    observarTamanho(c);
    if (c.emConta) {
        // Na conta quem monta a cena é o sinal de desbloqueio; sem ele não se carrega o three.js.
        quandoOcioso(() => abrirNaConta(c));
    } else {
        ligarBotaoDePausa(c);
        ligarEventosDosCampos(c);
        // A primeira medida do ResizeObserver agenda a montagem, se #cena tiver o tamanho mínimo.
    }
}
