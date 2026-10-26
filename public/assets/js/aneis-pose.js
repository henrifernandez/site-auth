// Pose da cena "fechadura de anéis": constantes, easing e linha do tempo dos estados.
//
// Módulo puro: sem three.js e sem DOM. pose(t) devolve só números. O tempo (em segundos) vem
// sempre de fora, e a pose é uma função do histórico de mudar() e de t, então as mesmas chamadas
// dão sempre a mesma pose. Os estados são: repouso, identidade, segredo, verificando, erro,
// liberado e aberto.

const PI = Math.PI;
const DOIS_PI = PI * 2;

// Constantes do roteiro. Ajustes visuais entram só aqui.
const RAIOS = [1.15, 1.65, 2.15]; // do anel interno ao externo
const TUBO = [0.022, 0.02, 0.018];
const FENDA = 0.42; // abertura da fenda, em rad
const TETA = -0.5796; // ângulo do caminho da ficha e do alinhamento
const R_MOLDURA = 3.25;
const R_ENQUADRE = 3.45;
const ESCALA_ABERTO = [1.12, 1.18, 1.24];
const TORCAO_ABERTO = PI / 2;
const FENDA_ABERTO = TETA + TORCAO_ABERTO; // 0.9912 rad
const GIRO_ABERTO = PI / 6;
const BRILHO_ABERTO = 1.4;
// Na abertura os anéis também se afastam no eixo (vista explodida): o interno vem para a frente.
const Z_ABERTO = [0.3, 0.02, -0.26];
// Respiração da luz interna do núcleo (Hz): bem abaixo de 3 pulsos por segundo.
const PULSO_HZ = 0.22;

// Caminho da ficha: spline de Catmull-Rom (uniforme, com pontas refletidas) pelos pontos abaixo.
// Todos ficam na reta y = -0.6545x, para a ficha cruzar os anéis em TETA. A ficha vem da frente
// (z > 0) e entra no plano dos anéis antes do anel externo, para passar de fato pela fenda.
const CAMINHO = [
    [4.4, -2.88, 1.5],
    [2.9, -1.9, 0.22],
    [1.6, -1.05, 0],
    [0, 0, 0],
];

// Durações (s) da mistura de entrada em cada estado e dos estados com linha do tempo própria.
const MISTURA = { repouso: 1.2, identidade: 0.8, segredo: 0.7, verificando: 0.9, erro: 0.35, liberado: 0.5, aberto: 0.8 };
const DUR_ERRO = 1.9;
const DUR_LIBERADO = 2.6;
// Onda de luz que sai do núcleo quando ele acende (liberado): começa em 1.3 s e dura 0.8 s.
const ONDA_INICIO = 1.3;
const ONDA_DURACAO = 0.8;
const VARREDURA_PERIODO = 1.6;
const VARREDURA_JANELA = 0.5;

export const ESTADOS = ['repouso', 'identidade', 'segredo', 'verificando', 'erro', 'liberado', 'aberto'];

// Easing

const limitar = (x, a = 0, b = 1) => Math.min(b, Math.max(a, x));
export const inOutCubic = (u) => (u < 0.5 ? 4 * u * u * u : 1 - (-2 * u + 2) ** 3 / 2);
export const outCubic = (u) => 1 - (1 - u) ** 3;
export const inCubic = (u) => u * u * u;
export const outBack = (u, c = 1.2) => 1 + (c + 1) * (u - 1) ** 3 + c * (u - 1) ** 2;

/** Caminho mais curto entre ângulos: devolve a diferença no intervalo [-periodo/2, periodo/2]. */
const embrulhar = (d, periodo = DOIS_PI) => d - periodo * Math.round(d / periodo);
/** Diferença angular mínima, em rad, entre dois ângulos. */
export const diferencaAngular = (a, b) => Math.atan2(Math.sin(a - b), Math.cos(a - b));

// Caminho da ficha

const pontaRefletida = (i, j) => CAMINHO[i].map((v, k) => 2 * v - CAMINHO[j][k]);

/** Ponto [x, y, z] do caminho em s de 0 (lado do formulário) a 1 (núcleo). */
export function pontoDoCaminho(s) {
    const c = limitar(s) * (CAMINHO.length - 1);
    const k = Math.min(CAMINHO.length - 2, Math.floor(c));
    const f = c - k;
    const ponto = (i) => (i < 0 ? pontaRefletida(0, 1) : i > CAMINHO.length - 1 ? pontaRefletida(CAMINHO.length - 1, CAMINHO.length - 2) : CAMINHO[i]);
    const [p0, p1, p2, p3] = [ponto(k - 1), ponto(k), ponto(k + 1), ponto(k + 2)];

    return p1.map(
        (_, d) =>
            0.5 *
            (2 * p1[d] + (-p0[d] + p2[d]) * f + (2 * p0[d] - 5 * p1[d] + 4 * p2[d] - p3[d]) * f * f + (-p0[d] + 3 * p1[d] - 3 * p2[d] + p3[d]) * f * f * f),
    );
}

/** Distância ao centro, no plano da tela, do ponto do caminho em s. */
const raioDoCaminho = (s) => {
    const [x, y] = pontoDoCaminho(s);

    return Math.hypot(x, y);
};

/** s em que o caminho cruza a circunferência de raio r (bisseção; o raio decresce com s). */
function sNoRaio(r) {
    let a = 0;
    let b = 1;
    for (let i = 0; i < 60; i++) {
        const m = (a + b) / 2;
        if (raioDoCaminho(m) > r) {
            a = m;
        } else {
            b = m;
        }
    }

    return (a + b) / 2;
}

// A ficha para encostada na borda de fora do anel externo: meia largura do anel (0.15, v2) mais
// meia ficha (0.15), com folga.
const FOLGA_CONTATO = 0.32;
const S_RAIO = RAIOS.map(sNoRaio);
const S_CONTATO = sNoRaio(RAIOS[2] + FOLGA_CONTATO);

export const CONST = Object.freeze({
    RAIOS,
    TUBO,
    FENDA,
    TETA,
    R_MOLDURA,
    R_ENQUADRE,
    ESCALA_ABERTO,
    TORCAO_ABERTO,
    FENDA_ABERTO,
    GIRO_ABERTO,
    BRILHO_ABERTO,
    Z_ABERTO,
    PULSO_HZ,
    CAMINHO,
    S_RAIO,
    S_CONTATO,
    DUR_ERRO,
    DUR_LIBERADO,
    ONDA_INICIO,
    ONDA_DURACAO,
    VARREDURA_PERIODO,
    VARREDURA_JANELA,
    VIDA_FRAGMENTOS: 0.95,
});

// Campos misturáveis, num vetor plano: 7 por anel (fenda, inclX, inclY, escala, alinhado, vermelho,
// z), depois giro, brilho e pino do núcleo, e o quanto da moldura está acesa.
const POR_ANEL = 7;
const I_GIRO = 3 * POR_ANEL;
const I_BRILHO = I_GIRO + 1;
const I_PINO = I_GIRO + 2;
const I_ACESA = I_GIRO + 3;
const N_CAMPOS = I_GIRO + 4;
const PERIODOS = new Array(N_CAMPOS).fill(0);
for (let i = 0; i < 3; i++) {
    PERIODOS[i * POR_ANEL] = DOIS_PI;
}
PERIODOS[I_GIRO] = PI / 3; // o hexágono se repete a cada 60 graus

/**
 * Mistura A -> B com peso w. Ângulos seguem o caminho mais curto, escolhido uma vez a partir de
 * R (o alvo no início da mistura), então não há salto quando B se move durante a mistura.
 */
function misturar(A, B, w, R) {
    if (w >= 1) {
        return B.slice();
    }

    return A.map((a, k) => (PERIODOS[k] ? a + w * (embrulhar(R[k] - a, PERIODOS[k]) + B[k] - R[k]) : a + w * (B[k] - a)));
}

// Estados

function inclinacoesDoRepouso(t) {
    return [
        [0.95 + 0.07 * Math.sin(0.7 * t), 0.3 + 0.05 * Math.sin(0.53 * t)],
        [-0.55 + 0.06 * Math.sin(0.61 * t + 1), 0.75 + 0.05 * Math.sin(0.47 * t)],
        [0.38 + 0.05 * Math.sin(0.43 * t + 2), -0.62 + 0.06 * Math.sin(0.59 * t)],
    ];
}

function vetor(an, giro, brilho, pino, acesa) {
    const v = new Array(N_CAMPOS).fill(0);
    an.forEach((a, i) => {
        a.forEach((x, k) => {
            v[i * POR_ANEL + k] = x;
        });
    });
    v[I_GIRO] = giro;
    v[I_BRILHO] = brilho;
    v[I_PINO] = pino;
    v[I_ACESA] = acesa;

    return v;
}

function vetorDoRepouso(t) {
    const fendas = [TETA + 2.1 + 0.13 * t, TETA - 2.6 - 0.09 * t, TETA + 1.2 + 0.06 * t];
    const inc = inclinacoesDoRepouso(t);

    return vetor(
        fendas.map((f, i) => [f, inc[i][0], inc[i][1], 1, 0, 0, 0]),
        0.1 * t,
        1,
        0,
        0,
    );
}

const FICHA_OFF = { visivel: false, s: 0, glitch: 0, glitchY: 0, tremorX: 0, tremorY: 0 };
const FRAG_OFF = { ativo: false, u: 0 };

/** Alvo (sem mistura de entrada) de cada estado em u segundos depois do início, no relógio t. */
function alvoDe(estado, u, t, ctx) {
    const base = { ficha: FICHA_OFF, fragmentos: FRAG_OFF, varredura: null, onda: 0, terminou: null };
    const t0 = ctx.t0;

    switch (estado) {
        case 'identidade': {
            const v = vetorDoRepouso(t);
            v.splice(2 * POR_ANEL, POR_ANEL, TETA, 0, 0, 1, 1, 0, 0);
            v[I_BRILHO] = 1.15;

            return { ...base, v };
        }
        case 'segredo': {
            // Tudo para de derivar: a pose congela no instante em que o estado começa.
            const inc = inclinacoesDoRepouso(t0);
            const v = vetor(
                [
                    [TETA - 0.6, inc[0][0] * 0.3, inc[0][1] * 0.3, 1, 0.35, 0, 0],
                    [TETA + 0.35, inc[1][0] * 0.3, inc[1][1] * 0.3, 1, 0.6, 0, 0],
                    [TETA, 0, 0, 1, 1, 0, 0],
                ],
                0.1 * t0,
                1.3,
                0,
                0,
            );

            return { ...base, v };
        }
        case 'verificando': {
            const v = vetor(
                [
                    [TETA + 0.12, 0, 0, 1, 1, 0, 0],
                    [TETA - 0.08, 0, 0, 1, 1, 0, 0],
                    [TETA, 0, 0, 1, 1, 0, 0],
                ],
                0.1 * t0,
                1.3,
                0,
                0,
            );
            const s = u < 0.7 ? 0.62 * outCubic(u / 0.7) : 0.62 + 0.12 * (1 - Math.exp(-(u - 0.7) / 0.6));

            return {
                ...base,
                v,
                ficha: { ...FICHA_OFF, visivel: true, s },
                varredura: TETA + (DOIS_PI * u) / VARREDURA_PERIODO,
            };
        }
        case 'erro':
            return alvoDoErro(u, t, ctx, base);
        case 'liberado':
            return alvoDoLiberado(u, ctx, base);
        case 'aberto':
            return {
                ...base,
                v: vetor(
                    ESCALA_ABERTO.map((e, i) => [FENDA_ABERTO, 0, 0, e, 1, 0, Z_ABERTO[i]]),
                    GIRO_ABERTO,
                    BRILHO_ABERTO,
                    1,
                    1,
                ),
            };
        default:
            return { ...base, v: vetorDoRepouso(t) };
    }
}

function alvoDoErro(u, t, ctx, base) {
    // Os anéis 1 e 2 e o núcleo ficam como estavam; o externo fecha na frente da ficha.
    const parado = ctx.foto ?? vetorDoRepouso(ctx.t0);
    const e = Math.sin(PI * limitar((u - 0.35) / 0.9));
    const balanco = u >= 0.35 ? 0.08 * Math.exp(-4 * (u - 0.35)) * Math.sin(DOIS_PI * 2.2 * (u - 0.35)) : 0;
    const v = parado.slice();
    v.splice(2 * POR_ANEL, POR_ANEL, TETA + 2.4 + balanco, 0, 0, 1, 0, e, 0);
    v[I_GIRO] = parado[I_GIRO] + 0.1 * u;
    v[I_BRILHO] = parado[I_BRILHO] + (0.8 - parado[I_BRILHO]) * e;
    v[I_PINO] = 0;
    v[I_ACESA] = 0;

    let ficha = FICHA_OFF;
    let fragmentos = FRAG_OFF;
    if (u < 0.35) {
        ficha = { ...FICHA_OFF, visivel: true, s: S_CONTATO - 0.25 + 0.25 * outCubic(u / 0.35) };
    } else if (u < 0.65) {
        ficha = {
            visivel: true,
            s: S_CONTATO,
            glitch: 0.075 + 0.03 * Math.sin(23 * u),
            glitchY: 0.04 * Math.cos(31 * u),
            tremorX: 0.012 * Math.sin(DOIS_PI * 13 * u),
            tremorY: 0.012 * Math.cos(DOIS_PI * 17 * u),
        };
    } else {
        ficha = { ...FICHA_OFF, s: S_CONTATO };
        if (u - 0.65 < CONST.VIDA_FRAGMENTOS) {
            fragmentos = { ativo: true, u: u - 0.65 };
        }
    }

    // 1.25 a 1.9: volta ao repouso.
    let vFinal = v;
    if (u >= DUR_ERRO) {
        vFinal = vetorDoRepouso(t);
    } else if (u > 1.25) {
        const w = inOutCubic((u - 1.25) / (DUR_ERRO - 1.25));
        vFinal = misturar(v, vetorDoRepouso(t), w, vetorDoRepouso(ctx.t0 + 1.25));
    }

    return { ...base, v: vFinal, ficha, fragmentos, terminou: u >= DUR_ERRO ? 'erro' : null };
}

/** Pose de partida do liberado: inclinações a 30% do repouso e fendas a +-0.3 do alinhamento. */
const DESVIO_LIBERADO = [0.3, -0.3, 0.3];

function alvoDoLiberado(u, ctx, base) {
    const inc = inclinacoesDoRepouso(ctx.t0);
    const torcao = inOutCubic(limitar((u - 1.35) / 0.8));
    const an = RAIOS.map((_, i) => {
        // Os anéis alinham escalonados (0, 0.08 e 0.16 s), 0.74 s cada.
        const a = inOutCubic(limitar((u - 0.08 * i) / 0.74));
        const abre = outBack(limitar((u - 1.35 - 0.06 * i) / 0.8));
        const fenda = TETA + DESVIO_LIBERADO[i] * (1 - a) + TORCAO_ABERTO * torcao;

        return [fenda, 0.3 * inc[i][0] * (1 - a), 0.3 * inc[i][1] * (1 - a), 1 + (ESCALA_ABERTO[i] - 1) * abre, 0.4 + 0.6 * a, 0, Z_ABERTO[i] * abre];
    });

    // Um pulso de brilho só, entre 1.3 e 1.9, depois o núcleo fica em BRILHO_ABERTO.
    const brilho = u < 1.3 ? 1 + (BRILHO_ABERTO - 1) * inOutCubic(limitar(u / 0.9)) : u < 1.9 ? BRILHO_ABERTO + 1.2 * Math.sin((PI * (u - 1.3)) / 0.6) : BRILHO_ABERTO;
    const giro = GIRO_ABERTO * outCubic(limitar((u - 1.3) / 0.6));
    const pino = limitar((u - 1.3) / 0.2);
    const varre = limitar((u - 1.4) / 1.0);
    const v = vetor(an, giro, brilho, pino, varre);

    let ficha = FICHA_OFF;
    if (u < 1.3) {
        ficha = { ...FICHA_OFF, visivel: true, s: u < 0.8 ? 0.74 : 0.74 + 0.26 * inCubic(limitar((u - 0.8) / 0.5)) };
    }

    return {
        ...base,
        v,
        ficha,
        varredura: varre > 0 && varre < 1 ? TETA + DOIS_PI * varre : null,
        onda: u > ONDA_INICIO && u < ONDA_INICIO + ONDA_DURACAO ? (u - ONDA_INICIO) / ONDA_DURACAO : 0,
        terminou: u >= DUR_LIBERADO ? 'liberado' : null,
    };
}

// Linha do tempo

function paraPose(alvo, v, tempo) {
    const aneis = [0, 1, 2].map((i) => {
        const [fenda, inclX, inclY, escala, alinhado, vermelho, z] = v.slice(i * POR_ANEL, (i + 1) * POR_ANEL);

        return { fenda, inclX, inclY, escala, alinhado, vermelho, z };
    });
    // Respiração contínua da luz interna (0 a 1), só função do relógio: não tem salto entre estados.
    const pulso = 0.5 + 0.5 * Math.sin(DOIS_PI * PULSO_HZ * tempo);

    return {
        aneis,
        // onda: progresso (0 a 1) da onda de luz única do liberado; 0 = sem onda.
        nucleo: { giro: v[I_GIRO], brilho: v[I_BRILHO], pinoAmbar: v[I_PINO], pulso, onda: alvo.onda },
        ficha: { ...alvo.ficha },
        fragmentos: { ...alvo.fragmentos },
        moldura: { varredura: alvo.varredura, acesa: v[I_ACESA] },
        terminou: alvo.terminou,
    };
}

/**
 * Cria a máquina de estados.
 *
 * mudar(estado, t): tira uma foto da pose em t e mistura dela até a pose do novo estado, então
 * toda troca pode interromper outra sem salto. pose(t) é pura: não altera nada.
 */
export function criarLinhaDoTempo(inicial = 'repouso', t = 0) {
    let estado = ESTADOS.includes(inicial) ? inicial : 'repouso';
    let ctx = { t0: t, foto: null };
    let referencia = null; // alvo no início da mistura de entrada

    function calcular(tempo) {
        const u = Math.max(0, tempo - ctx.t0);
        const alvo = alvoDe(estado, u, tempo, ctx);
        const duracao = MISTURA[estado];
        if (ctx.foto && u < duracao) {
            return paraPose(alvo, misturar(ctx.foto, alvo.v, inOutCubic(u / duracao), referencia), tempo);
        }

        return paraPose(alvo, alvo.v, tempo);
    }

    function vetorEm(tempo) {
        const p = calcular(tempo);
        const v = [];
        for (const a of p.aneis) {
            v.push(a.fenda, a.inclX, a.inclY, a.escala, a.alinhado, a.vermelho, a.z);
        }
        v.push(p.nucleo.giro, p.nucleo.brilho, p.nucleo.pinoAmbar, p.moldura.acesa);

        return v;
    }

    return {
        mudar(novo, tempo) {
            if (!ESTADOS.includes(novo)) {
                return;
            }
            const foto = vetorEm(tempo);
            estado = novo;
            ctx = { t0: tempo, foto };
            referencia = alvoDe(novo, 0, tempo, ctx).v;
        },
        pose: calcular,
        get estado() {
            return estado;
        },
    };
}
