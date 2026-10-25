// Cena do cadeado em wireframe (three.js). Este módulo só monta e anima a cena; quem decide quando
// carregá-la, pausá-la ou trocá-la pelo SVG estático é cena.js.
//
// O tempo é sempre passado de fora (quadro(segundos)), o que deixa a animação determinística:
// quem chama controla o relógio.

const PI = Math.PI;

const TEMAS = {
    // Coluna de marca (fundo azul-marinho): linhas em tons claros do teal da identidade.
    escuro: { linha: 0x0f8b8d, destaque: 0x7cc9ca, particula: 0x7cc9ca, halo: 0x0f8b8d, erro: 0xff6b6b, aditivo: true },
    // Área da conta (fundo claro): teal escuro da identidade, sem mistura aditiva.
    claro: { linha: 0x0b6e70, destaque: 0x0f8b8d, particula: 0x0f8b8d, halo: 0x0f8b8d, erro: 0xc0392b, aditivo: false },
};

const ABERTURA_REPOUSO = 0.3; // argola levemente levantada enquanto ninguém digita a senha
const DUR_FECHAR = 0.5;
const DUR_SACUDIDA = 0.4;
const DUR_BRILHO_ERRO = 0.9;
const DUR_DESBLOQUEIO = 1.2;

const limitar = (x, a = 0, b = 1) => Math.min(b, Math.max(a, x));
const suave = (t) => {
    const u = limitar(t);

    return u * u * (3 - 2 * u);
};
const saidaComSobra = (t) => {
    const u = limitar(t);
    const c1 = 1.4;
    const c3 = c1 + 1;

    return 1 + c3 * (u - 1) ** 3 + c1 * (u - 1) ** 2;
};

function gerador(semente) {
    let s = semente;

    return () => {
        s = (s * 16807) % 2147483647;

        return (s - 1) / 2147483646;
    };
}

function formaArredondada(THREE, w, h, r) {
    const x = -w / 2;
    const y = -h / 2;
    const forma = new THREE.Shape();
    forma.moveTo(x + r, y);
    forma.lineTo(x + w - r, y);
    forma.absarc(x + w - r, y + r, r, -PI / 2, 0, false);
    forma.lineTo(x + w, y + h - r);
    forma.absarc(x + w - r, y + h - r, r, 0, PI / 2, false);
    forma.lineTo(x + r, y + h);
    forma.absarc(x + r, y + h - r, r, PI / 2, PI, false);
    forma.lineTo(x, y + r);
    forma.absarc(x + r, y + r, r, PI, PI * 1.5, false);

    return forma;
}

/** Corpo: dois contornos arredondados (frente e fundo) e arestas ligando-os. */
function corpoEmWireframe(THREE, material) {
    const pontos = formaArredondada(THREE, 2.2, 1.7, 0.34).getPoints(5);
    const profundidade = 0.46;
    const grupo = new THREE.Group();

    for (const z of [profundidade, -profundidade]) {
        const contorno = new THREE.BufferGeometry().setFromPoints(pontos.map((p) => new THREE.Vector3(p.x, p.y, z)));
        grupo.add(new THREE.LineLoop(contorno, material));
    }

    const ligacoes = [];
    pontos.forEach((p, i) => {
        if (i % 3 === 0) {
            ligacoes.push(new THREE.Vector3(p.x, p.y, profundidade), new THREE.Vector3(p.x, p.y, -profundidade));
        }
    });
    grupo.add(new THREE.LineSegments(new THREE.BufferGeometry().setFromPoints(ligacoes), material));

    return grupo;
}

function fechaduraEmLinhas(THREE, material) {
    const grupo = new THREE.Group();
    const circulo = new THREE.EllipseCurve(0, 0, 0.14, 0.14, 0, PI * 2).getPoints(20);
    const furo = new THREE.LineLoop(
        new THREE.BufferGeometry().setFromPoints(circulo.map((p) => new THREE.Vector3(p.x, p.y + 0.12, 0.47))),
        material,
    );
    const fenda = new THREE.Line(
        new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(0, -0.04, 0.47), new THREE.Vector3(0, -0.4, 0.47)]),
        material,
    );
    grupo.add(furo, fenda);

    return grupo;
}

/** Argola: meia-volta de torus e duas pernas, em arestas (sem as diagonais dos triângulos). */
function argolaEmWireframe(THREE, material, raio) {
    const grupo = new THREE.Group();
    const arco = new THREE.TorusGeometry(raio, 0.09, 6, 22, PI);
    grupo.add(new THREE.LineSegments(new THREE.EdgesGeometry(arco, 1), material));

    const comprimento = 0.6;
    for (const x of [-raio, raio]) {
        const perna = new THREE.CylinderGeometry(0.09, 0.09, comprimento, 6, 1, true);
        perna.translate(x, -comprimento / 2, 0);
        grupo.add(new THREE.LineSegments(new THREE.EdgesGeometry(perna, 1), material));
    }

    return grupo;
}

function texturaDoHalo(THREE) {
    const lado = 128;
    const tela = document.createElement('canvas');
    tela.width = lado;
    tela.height = lado;
    const ctx = tela.getContext('2d');
    const gradiente = ctx.createRadialGradient(lado / 2, lado / 2, 0, lado / 2, lado / 2, lado / 2);
    gradiente.addColorStop(0, 'rgba(255,255,255,0.55)');
    gradiente.addColorStop(0.45, 'rgba(255,255,255,0.18)');
    gradiente.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = gradiente;
    ctx.fillRect(0, 0, lado, lado);

    return new THREE.CanvasTexture(tela);
}

/**
 * Monta a cena dentro de `contenedor` e devolve o controle.
 *
 * @param THREE módulo do three.js
 * @param {HTMLElement} contenedor elemento que receberá o canvas
 * @param {{ tema?: 'escuro'|'claro', particulas?: number, raioParticulas?: number, parallax?: boolean, abertura?: number }} opcoes
 */
export function criarCadeado(THREE, contenedor, opcoes = {}) {
    // A cena foi desenhada com cores em hexadecimal "puras": sem gerenciamento de cor e saída linear.
    THREE.ColorManagement.enabled = false;
    const tema = TEMAS[opcoes.tema === 'claro' ? 'claro' : 'escuro'];
    const quantidade = Math.min(300, opcoes.particulas ?? 160);
    const raioParticulas = opcoes.raioParticulas ?? 1;
    const aberturaRepouso = opcoes.abertura ?? ABERTURA_REPOUSO;

    const renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true, powerPreference: 'low-power' });
    renderer.outputColorSpace = THREE.LinearSRGBColorSpace;
    renderer.setClearColor(0x000000, 0);
    const tela = renderer.domElement;
    contenedor.appendChild(tela);

    const cena = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(38, 1, 0.1, 60);
    const raioArgola = 0.62;

    const materialLinha = new THREE.LineBasicMaterial({ color: tema.linha, transparent: true, opacity: 0.95 });
    const materialDestaque = new THREE.LineBasicMaterial({ color: tema.destaque, transparent: true, opacity: 0.95 });
    const corLinha = new THREE.Color(tema.linha);
    const corDestaque = new THREE.Color(tema.destaque);
    const corErro = new THREE.Color(tema.erro);

    const cadeado = new THREE.Group();
    cadeado.add(corpoEmWireframe(THREE, materialLinha));
    cadeado.add(fechaduraEmLinhas(THREE, materialDestaque));

    // A argola gira em torno da perna direita, como num cadeado de verdade.
    const pivo = new THREE.Group();
    const argola = argolaEmWireframe(THREE, materialDestaque, raioArgola);
    argola.position.x = -raioArgola;
    pivo.add(argola);
    pivo.position.set(raioArgola, 0.85, 0);
    cadeado.add(pivo);
    cena.add(cadeado);

    const texturaHalo = texturaDoHalo(THREE);
    const materialHalo = new THREE.SpriteMaterial({
        map: texturaHalo,
        color: tema.halo,
        transparent: true,
        opacity: tema.aditivo ? 0.55 : 0.3,
        blending: tema.aditivo ? THREE.AdditiveBlending : THREE.NormalBlending,
        depthWrite: false,
    });
    const halo = new THREE.Sprite(materialHalo);
    halo.scale.set(5.2, 5.2, 1);
    halo.position.z = -0.8;
    cena.add(halo);

    const materialHaloErro = new THREE.SpriteMaterial({
        map: texturaHalo,
        color: tema.erro,
        transparent: true,
        opacity: 0,
        blending: tema.aditivo ? THREE.AdditiveBlending : THREE.NormalBlending,
        depthWrite: false,
    });
    const haloErro = new THREE.Sprite(materialHaloErro);
    haloErro.scale.set(4.2, 4.2, 1);
    haloErro.position.z = -0.7;
    cena.add(haloErro);

    // Partículas finas em uma casca ao redor do cadeado.
    const aleatorio = gerador(20251);
    const posicoes = new Float32Array(quantidade * 3);
    for (let i = 0; i < quantidade; i++) {
        const raio = (1.9 + aleatorio() * 1.5) * raioParticulas;
        const angulo = aleatorio() * PI * 2;
        const cos = aleatorio() * 2 - 1;
        const sen = Math.sqrt(1 - cos * cos);
        posicoes[i * 3] = raio * sen * Math.cos(angulo);
        posicoes[i * 3 + 1] = raio * cos * 0.8;
        posicoes[i * 3 + 2] = raio * sen * Math.sin(angulo) * 0.7;
    }
    const geometriaParticulas = new THREE.BufferGeometry();
    geometriaParticulas.setAttribute('position', new THREE.BufferAttribute(posicoes, 3));
    const materialParticulas = new THREE.PointsMaterial({
        color: tema.particula,
        size: 0.055 * raioParticulas + 0.01,
        sizeAttenuation: true,
        transparent: true,
        opacity: 0.6,
        depthWrite: false,
    });
    const particulas = new THREE.Points(geometriaParticulas, materialParticulas);
    cena.add(particulas);

    // Estado da animação, em segundos do relógio externo.
    let agora = 0;
    let abertura = aberturaRepouso;
    let aberturaAlvo = aberturaRepouso;
    let aberturaDe = aberturaRepouso;
    let inicioFechar = -1;
    let inicioSacudida = -1;
    let inicioDesbloqueio = -1;
    let aoTerminarDesbloqueio = null;
    let ponteiroX = 0;
    let ponteiroY = 0;
    let giroX = 0;
    let giroY = 0;
    let descartado = false;
    let ultimoAgora = null;

    function mudarAbertura(alvo) {
        aberturaDe = abertura;
        aberturaAlvo = alvo;
        inicioFechar = agora;
    }

    function aplicarAbertura(valor) {
        pivo.position.y = 0.85 + valor * 0.46;
        pivo.rotation.y = -valor * 1.25;
    }

    function redimensionar(largura, altura) {
        const l = Math.max(1, Math.floor(largura));
        const a = Math.max(1, Math.floor(altura));
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setSize(l, a, false);
        camera.aspect = l / a;
        const tg = Math.tan((camera.fov * PI) / 360);
        // Cabe a casca de partículas na largura e na altura.
        const meiaLargura = 3.3 * raioParticulas + 0.3;
        const meiaAltura = 2.7 * raioParticulas + 0.3;
        camera.position.set(0, 0, Math.max(meiaLargura / (tg * camera.aspect), meiaAltura / tg));
        camera.updateProjectionMatrix();
    }

    function quadro(segundos) {
        if (descartado) {
            return;
        }
        const dt = ultimoAgora === null ? 0 : Math.min(0.1, Math.max(0, segundos - ultimoAgora));
        ultimoAgora = segundos;
        agora = segundos;

        // Abertura da argola (fechar ao focar a senha, reabrir ao sair).
        if (inicioFechar >= 0) {
            const u = (agora - inicioFechar) / DUR_FECHAR;
            abertura = aberturaDe + (aberturaAlvo - aberturaDe) * suave(u);
            if (u >= 1) {
                abertura = aberturaAlvo;
                inicioFechar = -1;
            }
        }

        let envelopeErro = 0;
        let sacudida = 0;
        if (inicioSacudida >= 0) {
            const t = agora - inicioSacudida;
            if (t < DUR_SACUDIDA) {
                // Menos de 2 ciclos em 400 ms, amortecidos: um balanço curto, sem tremor rápido.
                sacudida = 0.13 * Math.sin((t / DUR_SACUDIDA) * PI * 3) * (1 - t / DUR_SACUDIDA);
            }
            if (t < DUR_BRILHO_ERRO) {
                envelopeErro = Math.sin((t / DUR_BRILHO_ERRO) * PI);
            } else {
                inicioSacudida = -1;
            }
        }

        let ondaParticulas = 0;
        let aberturaFinal = abertura;
        if (inicioDesbloqueio >= 0) {
            const t = agora - inicioDesbloqueio;
            aberturaFinal = saidaComSobra(t / 0.7);
            ondaParticulas = limitar((t - 0.1) / (DUR_DESBLOQUEIO - 0.1));
            if (t >= DUR_DESBLOQUEIO) {
                inicioDesbloqueio = -1;
                abertura = 1;
                aberturaAlvo = 1;
                const fim = aoTerminarDesbloqueio;
                aoTerminarDesbloqueio = null;
                if (fim) {
                    fim();
                }
            }
        }
        aplicarAbertura(aberturaFinal);

        // Flutuação lenta e parallax leve do ponteiro.
        giroX += (ponteiroY * -0.22 - giroX) * Math.min(1, dt * 4);
        giroY += (ponteiroX * 0.35 - giroY) * Math.min(1, dt * 4);
        cadeado.position.y = Math.sin(agora * 0.7) * 0.07;
        cadeado.rotation.y = giroY + Math.sin(agora * 0.35) * 0.2;
        cadeado.rotation.x = giroX + Math.sin(agora * 0.5) * 0.04;
        cadeado.rotation.z = sacudida;
        particulas.rotation.y = agora * 0.05;
        particulas.rotation.x = Math.sin(agora * 0.12) * 0.1;

        // Brilho vermelho suave, de baixa intensidade, que volta ao normal.
        materialHaloErro.opacity = 0.3 * envelopeErro;
        materialLinha.color.copy(corLinha).lerp(corErro, 0.55 * envelopeErro);
        materialDestaque.color.copy(corDestaque).lerp(corErro, 0.55 * envelopeErro);

        // Onda de partículas do desbloqueio: expande e se dissipa.
        const escala = 1 + ondaParticulas * 1.7;
        particulas.scale.setScalar(escala);
        materialParticulas.opacity = 0.6 * (1 - suave(ondaParticulas));
        materialHalo.opacity = (tema.aditivo ? 0.55 : 0.3) * (1 + 0.5 * Math.sin(ondaParticulas * PI));

        renderer.render(cena, camera);
    }

    function descartar() {
        if (descartado) {
            return;
        }
        descartado = true;
        cena.traverse((objeto) => {
            if (objeto.geometry) {
                objeto.geometry.dispose();
            }
            if (objeto.material) {
                objeto.material.dispose();
            }
        });
        texturaHalo.dispose();
        renderer.dispose();
        if (typeof renderer.forceContextLoss === 'function') {
            renderer.forceContextLoss();
        }
        tela.remove();
    }

    aplicarAbertura(abertura);

    return {
        canvas: tela,
        redimensionar,
        quadro,
        /** Argola desce e o cadeado se fecha (foco na senha). */
        fechar() {
            mudarAbertura(0);
        },
        /** Volta ao repouso (levemente aberto). */
        repousar() {
            mudarAbertura(aberturaRepouso);
        },
        /** Sacudida curta e brilho vermelho suave (erro de login ou de cadastro). */
        sacudir() {
            inicioSacudida = agora;
        },
        /** Argola sobe e abre, partículas em onda, e chama `aoTerminar`. Começa fechado. */
        desbloquear(aoTerminar) {
            abertura = 0;
            aberturaAlvo = 0;
            inicioFechar = -1;
            aoTerminarDesbloqueio = aoTerminar ?? null;
            inicioDesbloqueio = agora;
        },
        /** Posição do ponteiro de -1 a 1 (parallax). */
        apontar(x, y) {
            ponteiroX = limitar(x, -1, 1);
            ponteiroY = limitar(y, -1, 1);
        },
        descartar,
        duracaoDoDesbloqueio: DUR_DESBLOQUEIO,
    };
}
