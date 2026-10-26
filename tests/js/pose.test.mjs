// Testes da pose da cena de anéis. Sem dependências: node --test tests/js
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { CONST, criarLinhaDoTempo, diferencaAngular } from '../../public/assets/js/aneis-pose.js';

const raiz = fileURLToPath(new URL('../../public/assets/js/', import.meta.url));
const PI = Math.PI;

/** Vetor plano de todos os números contínuos da pose, com o período angular de cada um (0 = linear). */
function achatar(p) {
    const campos = [];
    for (const a of p.aneis) {
        // z (afastamento axial da vista explodida, v2) também precisa casar no fim e não saltar.
        campos.push([a.fenda, 2 * PI], [a.inclX, 0], [a.inclY, 0], [a.escala, 0], [a.alinhado, 0], [a.vermelho, 0], [a.z, 0]);
    }
    // nucleo.pulso fica de fora: é função só do relógio (não do estado) e entra no teste 5.
    campos.push([p.nucleo.giro, PI / 3], [p.nucleo.brilho, 0], [p.nucleo.pinoAmbar, 0], [p.moldura.acesa, 0]);

    return campos;
}

/** Maior diferença entre duas poses (ângulos comparados pelo caminho mais curto). */
function distancia(a, b) {
    const ca = achatar(a);
    const cb = achatar(b);

    return Math.max(
        ...ca.map(([x, periodo], k) => {
            const d = x - cb[k][0];

            return periodo ? Math.abs(d - periodo * Math.round(d / periodo)) : Math.abs(d);
        }),
    );
}

function roteiro(tl) {
    tl.mudar('identidade', 0.5);
    tl.mudar('segredo', 1.2);
    tl.mudar('verificando', 2);
    tl.mudar('erro', 3);
    tl.mudar('liberado', 5.5);

    return tl;
}

test('1. as mesmas chamadas dão poses iguais', () => {
    const a = roteiro(criarLinhaDoTempo('repouso', 0));
    const b = roteiro(criarLinhaDoTempo('repouso', 0));
    for (const t of [0, 0.37, 1.0, 2.6, 3.4, 4.9, 6.1, 9]) {
        assert.deepStrictEqual(a.pose(t), b.pose(t), `t=${t}`);
    }
    // Chamar pose não altera nada: o resultado de t não depende de quem foi consultado antes.
    const c = roteiro(criarLinhaDoTempo('repouso', 0));
    c.pose(8);
    c.pose(0.1);
    assert.deepStrictEqual(c.pose(2.6), a.pose(2.6));
});

test('2. o fim de liberado é igual à pose aberto', () => {
    const lib = criarLinhaDoTempo('liberado', 0);
    const aberto = criarLinhaDoTempo('aberto', 0);
    const fim = lib.pose(CONST.DUR_LIBERADO);
    const alvo = aberto.pose(0);

    assert.equal(fim.terminou, 'liberado');
    assert.ok(distancia(fim, alvo) < 1e-9, `distância ${distancia(fim, alvo)}`);
    assert.deepStrictEqual(fim.ficha, alvo.ficha);
    assert.deepStrictEqual(fim.moldura, alvo.moldura);
    assert.equal(fim.fragmentos.ativo, false);

    // O mesmo vale quando liberado começa no meio de outro estado.
    const tl = criarLinhaDoTempo('segredo', 0);
    tl.mudar('liberado', 3.3);
    assert.ok(distancia(tl.pose(3.3 + CONST.DUR_LIBERADO + 0.5), alvo) < 1e-9);
});

test('3. em liberado, aos 1.0 s as fendas estão em TETA', () => {
    const lib = criarLinhaDoTempo('liberado', 0).pose(1.0);
    for (const anel of lib.aneis) {
        assert.ok(Math.abs(diferencaAngular(anel.fenda, CONST.TETA)) < 1e-3, `fenda ${anel.fenda}`);
        assert.ok(Math.abs(anel.inclX) < 1e-3 && Math.abs(anel.inclY) < 1e-3);
    }

    const tl = criarLinhaDoTempo('repouso', 0);
    tl.mudar('liberado', 4);
    for (const anel of tl.pose(5.0).aneis) {
        assert.ok(Math.abs(diferencaAngular(anel.fenda, CONST.TETA)) < 1e-3);
    }
});

test('4. trocar de estado no meio de uma transição não dá salto', () => {
    const sequencias = [
        ['repouso', 'identidade', 'segredo'],
        ['identidade', 'verificando', 'repouso'],
        ['segredo', 'erro', 'liberado'],
        ['verificando', 'erro', 'segredo'],
        ['erro', 'repouso', 'identidade'],
        ['liberado', 'repouso', 'verificando'],
        ['aberto', 'repouso', 'liberado'],
    ];
    for (const [a, b, c] of sequencias) {
        for (const atraso of [0.05, 0.2, 0.37, 0.6, 1.1, 1.6, 2.2]) {
            const tl = criarLinhaDoTempo('repouso', 0);
            tl.mudar(a, 1);
            tl.mudar(b, 1 + atraso);
            // Segunda troca no meio da mistura da primeira (ou do estado que ela iniciou).
            const t = 1 + atraso + 0.3;
            const antes = tl.pose(t);
            tl.mudar(c, t);
            const depois = tl.pose(t);
            assert.ok(distancia(antes, depois) < 1e-9, `${a}>${b}>${c} atraso ${atraso}: ${distancia(antes, depois)}`);

            // E logo depois do instante da troca a pose anda pouco: não há salto escondido.
            const seguinte = tl.pose(t + 1 / 240);
            assert.ok(distancia(depois, seguinte) < 0.05, `${a}>${b}>${c} atraso ${atraso}: salto ${distancia(depois, seguinte)}`);
        }
    }
});

/** Número de picos (máximos locais com altura relevante) de uma série, por janela deslizante de 1 s. */
function maximoPicosPorSegundo(serie, passo, saliencia) {
    const picos = [];
    for (let i = 1; i < serie.length - 1; i++) {
        if (serie[i] > serie[i - 1] && serie[i] >= serie[i + 1]) {
            // Saliência: queda dos dois lados dentro de 0,5 s.
            const raio = Math.round(0.5 / passo);
            const janela = serie.slice(Math.max(0, i - raio), i + raio + 1);
            if (serie[i] - Math.min(...janela) >= saliencia) {
                picos.push(i * passo);
            }
        }
    }
    let maximo = 0;
    for (const p of picos) {
        maximo = Math.max(maximo, picos.filter((q) => q >= p && q < p + 1).length);
    }

    return maximo;
}

test('5. no máximo 3 pulsos de luminância por segundo', () => {
    const passo = 1 / 120;
    // Uma sessão agitada: cada estado dura pouco, inclusive o erro e o desbloqueio.
    const tl = criarLinhaDoTempo('repouso', 0);
    const agenda = [
        [0.4, 'identidade'],
        [0.9, 'segredo'],
        [1.4, 'verificando'],
        [2.2, 'erro'],
        [3.0, 'verificando'],
        [3.8, 'liberado'],
        [7.0, 'repouso'],
        [7.3, 'identidade'],
        [7.6, 'segredo'],
    ];
    let k = 0;
    const brilho = [];
    const vermelho = [];
    const nucleo = [];
    const pulso = [];
    for (let t = 0; t < 10; t += passo) {
        while (k < agenda.length && agenda[k][0] <= t) {
            tl.mudar(agenda[k][1], agenda[k][0]);
            k++;
        }
        const p = tl.pose(t);
        brilho.push(p.nucleo.brilho);
        vermelho.push(Math.max(...p.aneis.map((a) => a.vermelho)));
        nucleo.push(p.nucleo.brilho + p.nucleo.pinoAmbar * 0.3);
        pulso.push(p.nucleo.pulso);
    }
    // A respiração da luz interna do núcleo (v2) também conta como pulso de luminância.
    assert.ok(maximoPicosPorSegundo(pulso, passo, 0.1) <= 3, 'respiração do núcleo');
    assert.ok(maximoPicosPorSegundo(brilho, passo, 0.1) <= 3, 'brilho do núcleo');
    assert.ok(maximoPicosPorSegundo(vermelho, passo, 0.1) <= 3, 'anel vermelho');
    assert.ok(maximoPicosPorSegundo(nucleo, passo, 0.1) <= 3, 'núcleo com o ponto');
    // A varredura da moldura é uma volta a cada 1,6 s: menos de 1 pulso por segundo.
    assert.ok(1 / CONST.VARREDURA_PERIODO <= 3);
    assert.ok(brilho.every(Number.isFinite));
});

test('6. o código dos dois módulos não usa Math.random, Date.now nem performance.now', () => {
    for (const arquivo of ['aneis-pose.js', 'aneis.js'].filter((a) => existsSync(raiz + a))) {
        const codigo = readFileSync(raiz + arquivo, 'utf8');
        assert.doesNotMatch(codigo, /Math\.random|Date\.now|performance\.now/, arquivo);
    }
    const pose = readFileSync(raiz + 'aneis-pose.js', 'utf8');
    assert.doesNotMatch(pose, /\bTHREE\b|\bdocument\b|\bwindow\b/, 'aneis-pose.js não conhece three nem DOM');
});
