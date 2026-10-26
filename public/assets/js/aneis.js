// Cena "fechadura de anéis" em three.js: monta os objetos e aplica a pose de aneis-pose.js.
//
// Autenticar é alinhar as fendas de três anéis usinados: uma ficha âmbar sai do lado do
// formulário e só chega ao núcleo de cristal pelo canal alinhado. Este módulo não decide nada
// sobre a animação (isso é da pose) e não tem relógio próprio: o tempo, em segundos, vem sempre
// de quadro(segundos).
//
// Visual (v2): metal azul-petróleo com perfis chanfrados e brilho de torno (anisotropia), luz
// por ambiente gerado aqui mesmo (PMREM de uma sala de painéis, sem arquivo externo), recorte
// teal por trás, luz quente âmbar do lado do formulário, núcleo de cristal com luz interna, ficha
// que ilumina o metal por onde passa, poeira com profundidade e névoa.

import { CONST, criarLinhaDoTempo, pontoDoCaminho } from './aneis-pose.js';

const PI = Math.PI;
const DOIS_PI = PI * 2;
const { RAIOS, FENDA, TETA, R_MOLDURA, R_ENQUADRE, S_CONTATO, VARREDURA_JANELA, VIDA_FRAGMENTOS } = CONST;

const FOV = 28;
const FUNDO = 0x10233a; // mesma cor da coluna de marca
const TEAL = 0x0f8b8d;
const TEAL_ESCURO = 0x0b6e70;
const TRACO = 0x7cc9ca;
const CLARO = 0xe6edf4;
const AMBAR = 0xffb454;
const ERRO = 0xff4d4d;
// Refletância do metal: aço neutro e frio. O azul-petróleo vem do ambiente (sala petróleo escura) e
// do recorte teal; assim o reflexo âmbar sai âmbar, e não verde-oliva.
const METAL = 0x93abb8;
const METAL_ERRO = 0xd83a3a; // no erro o anel externo vira metal vermelho anodizado

// O conjunto inteiro fica em 3/4, de frente para o formulário (à direita), para a espessura e os
// chanfros aparecerem mesmo quando os anéis estão alinhados de frente.
const INCLINACAO = [0.2, 0.34];

// Seção de cada anel, do interno ao externo: meia largura radial, meia espessura e chanfros.
const SECOES = [
    { w: 0.085, h: 0.12, cf: 0.045, ct: 0.018 },
    { w: 0.07, h: 0.09, cf: 0.026, ct: 0.016 },
    { w: 0.15, h: 0.065, cf: 0.02, ct: 0.014, canaleta: [-0.04, 0.115, 0.022] },
];
const SEGMENTOS_ARCO = 150;
const N_DENTES = 64;
const N_CONTAS = 20;
const N_MOLDURA = 72;
const N_RASTRO = 28;
const N_ESTILHACOS = 22;
const N_FAISCAS = 36;
const N_POEIRA = 150;
const GRAVIDADE = -2.2;
const SEMENTE = 20261025;

const limitar = (x, a = 0, b = 1) => Math.min(b, Math.max(a, x));

/** Gerador determinístico (o mesmo da cena anterior): sorteios feitos uma vez, na montagem. */
function gerador(semente) {
    let s = semente;

    return () => {
        s = (s * 16807) % 2147483647;

        return (s - 1) / 2147483646;
    };
}

function texturaRadial(THREE, paradas, lado = 128) {
    const tela = document.createElement('canvas');
    tela.width = lado;
    tela.height = lado;
    const ctx = tela.getContext('2d');
    const gradiente = ctx.createRadialGradient(lado / 2, lado / 2, 0, lado / 2, lado / 2, lado / 2);
    for (const [p, c] of paradas) {
        gradiente.addColorStop(p, c);
    }
    ctx.fillStyle = gradiente;
    ctx.fillRect(0, 0, lado, lado);
    const textura = new THREE.CanvasTexture(tela);
    textura.colorSpace = THREE.SRGBColorSpace;

    return textura;
}

/** Perfil (r, z) de um retângulo chanfrado, em sentido anti-horário; chanfro da frente e de trás. */
function perfilChanfrado({ w, h, cf, ct, canaleta }) {
    const frente = [
        [w, h - cf],
        [w - cf, h],
    ];
    if (canaleta) {
        // Canaleta rasa na face da frente, onde a escala é gravada.
        const [g1, g2, d] = canaleta;
        frente.push([g2 + 0.008, h], [g2, h - d], [g1, h - d], [g1 - 0.008, h]);
    }
    frente.push([-w + cf, h], [-w, h - cf]);

    return [[-w + ct, -h], [w - ct, -h], [w, -h + ct], ...frente, [-w, -h + ct]];
}

/**
 * Anel por varredura de um perfil (r, z) ao longo de um arco, com quinas vivas entre as faces do
 * perfil (cada aresta do perfil tem as próprias normais) e tampas nas pontas da fenda. O UV corre
 * ao longo do arco, então a anisotropia do material desenha o brilho circular de torno.
 */
function anelUsinado(THREE, raio, perfil, inicio, comprimento, segmentos) {
    const pos = [];
    const nor = [];
    const uv = [];
    const indices = [];
    const n = perfil.length;
    for (let j = 0; j < n; j++) {
        const [r0, z0] = perfil[j];
        const [r1, z1] = perfil[(j + 1) % n];
        const er = r1 - r0;
        const ez = z1 - z0;
        const len = Math.hypot(er, ez);
        const nr = ez / len;
        const nz = -er / len;
        const base = pos.length / 3;
        for (let k = 0; k <= segmentos; k++) {
            const phi = inicio + (comprimento * k) / segmentos;
            const [c, s] = [Math.cos(phi), Math.sin(phi)];
            for (const [r, z, v] of [
                [r0, z0, 0],
                [r1, z1, 1],
            ]) {
                pos.push((raio + r) * c, (raio + r) * s, z);
                nor.push(nr * c, nr * s, nz);
                uv.push((phi * raio) / 0.5, j + v);
            }
        }
        for (let k = 0; k < segmentos; k++) {
            const a = base + k * 2;
            const b = a + 2;
            indices.push(a, b, a + 1, b, b + 1, a + 1);
        }
    }

    // Tampas: o polígono do perfil triangulado, com a normal tangente ao arco.
    const contorno = perfil.map(([r, z]) => new THREE.Vector2(r, z));
    const triangulos = THREE.ShapeUtils.triangulateShape(contorno, []);
    for (const [phi, sinal] of [
        [inicio, -1],
        [inicio + comprimento, 1],
    ]) {
        const [c, s] = [Math.cos(phi), Math.sin(phi)];
        const base = pos.length / 3;
        for (const [r, z] of perfil) {
            pos.push((raio + r) * c, (raio + r) * s, z);
            nor.push(-s * sinal, c * sinal, 0);
            uv.push(r * 4, z * 4);
        }
        for (const [a, b, d] of triangulos) {
            const [pa, pb, pd] = [perfil[a], perfil[b], perfil[d]];
            const area = (pb[0] - pa[0]) * (pd[1] - pa[1]) - (pd[0] - pa[0]) * (pb[1] - pa[1]);
            // Anti-horário em (r, z) olha para -phi; a tampa do fim olha para +phi.
            const direto = area > 0 === sinal < 0;
            indices.push(base + a, direto ? base + b : base + d, direto ? base + d : base + b);
        }
    }

    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    g.setAttribute('normal', new THREE.Float32BufferAttribute(nor, 3));
    g.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
    g.setIndex(indices);
    g.computeBoundingSphere();

    return g;
}

/** Ângulos de k marcas igualmente espaçadas, sem as que caem na fenda (centrada em 0). */
function angulosForaDaFenda(k, folga) {
    const lista = [];
    for (let i = 0; i < k; i++) {
        const a = (i * DOIS_PI) / k;
        if (Math.abs(Math.atan2(Math.sin(a), Math.cos(a))) > FENDA / 2 + folga) {
            lista.push(a);
        }
    }

    return lista;
}

/**
 * Ambiente de reflexo gerado por código: uma sala escura com painéis emissivos (softbox frio no
 * alto, faixa âmbar do lado do formulário, recortes teal atrás), filtrada pelo PMREM.
 */
function criarAmbiente(THREE, renderer) {
    const sala = new THREE.Scene();
    sala.background = new THREE.Color(0x0a2230);
    const descartaveis = [];
    const painel = (largura, altura, x, y, z, hex, intensidade) => {
        const g = new THREE.PlaneGeometry(largura, altura);
        const m = new THREE.MeshBasicMaterial({ color: hex, side: THREE.DoubleSide });
        m.color.multiplyScalar(intensidade);
        const p = new THREE.Mesh(g, m);
        p.position.set(x, y, z);
        p.lookAt(0, 0, 0);
        sala.add(p);
        descartaveis.push(g, m);
    };
    painel(7, 2.2, -0.5, 6, 2.5, 0xdcebf2, 2.2); // softbox frio no alto
    painel(0.3, 3, 6, 0.4, 0.5, AMBAR, 0.8); // filete quente do lado do formulário (só acento)
    painel(0.9, 7, -6, 1, -2.5, TEAL, 3.4); // recorte teal à esquerda, por trás
    painel(6, 0.8, 1, -2.5, -6, TRACO, 1.0); // linha teal baixa ao fundo
    painel(4, 3, 0, -6, 2, 0x0f4a5c, 1.4); // rebatimento petróleo do chão
    painel(3, 1.4, -4, -1, 5, 0x9fc4d0, 0.8); // preenchimento frontal suave
    painel(2, 2, 3, 3, 5, 0x2f7f8f, 0.9); // contraluz teal à direita, no alto

    const pmrem = new THREE.PMREMGenerator(renderer);
    const alvo = pmrem.fromScene(sala, 0.035);
    pmrem.dispose();
    for (const d of descartaveis) {
        d.dispose();
    }

    return alvo;
}

/**
 * Monta a cena dentro de `contenedor` e devolve o controle.
 *
 * @param THREE módulo do three.js
 * @param {HTMLElement} contenedor elemento que receberá o canvas
 * @param {{ inicial?: string, parallax?: boolean }} opcoes
 */
export function criarAneis(THREE, contenedor, { inicial = 'repouso', parallax = true } = {}) {
    // v2: gerenciamento de cor ligado e saída sRGB, para a iluminação física ficar correta. As
    // cores hexadecimais continuam valendo como sRGB (o fundo sai exatamente #10233a).
    THREE.ColorManagement.enabled = true;

    const renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'low-power' });
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.NeutralToneMapping;
    renderer.toneMappingExposure = 1.0;
    renderer.setClearColor(FUNDO, 1);
    const tela = renderer.domElement;
    contenedor.appendChild(tela);

    const cena = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(FOV, 1, 0.1, 80);
    let distancia = 14;
    cena.fog = new THREE.Fog(FUNDO, distancia - 1, distancia + 7);

    const ambiente = criarAmbiente(THREE, renderer);
    cena.environment = ambiente.texture;
    cena.environmentIntensity = 1.0;

    const cor = (hex) => new THREE.Color(hex);
    const cTraco = cor(TRACO);
    const cClaro = cor(CLARO);
    const cAmbar = cor(AMBAR);
    const cErro = cor(ERRO);
    const cMetal = cor(METAL);
    const cMetalErro = cor(METAL_ERRO);
    const cPreto = cor(0);
    const rascunho = cor(0);
    const rascunho2 = cor(0);
    const geometrias = [];
    const materiais = [];
    const texturas = [];
    const registrar = (g) => {
        geometrias.push(g);

        return g;
    };
    const material = (Classe, parametros) => {
        const m = new Classe(parametros);
        materiais.push(m);

        return m;
    };
    const brilhoTextura = texturaRadial(THREE, [
        [0, 'rgba(255,255,255,1)'],
        [0.18, 'rgba(255,255,255,0.55)'],
        [0.45, 'rgba(255,255,255,0.12)'],
        [1, 'rgba(255,255,255,0)'],
    ]);
    const poeiraTextura = texturaRadial(
        THREE,
        [
            [0, 'rgba(255,255,255,1)'],
            [0.35, 'rgba(255,255,255,0.5)'],
            [1, 'rgba(255,255,255,0)'],
        ],
        64,
    );
    const halo = texturaRadial(
        THREE,
        [
            [0, 'rgba(15,139,141,0.30)'],
            [0.35, 'rgba(15,139,141,0.12)'],
            [0.7, 'rgba(11,110,112,0.04)'],
            [1, 'rgba(11,110,112,0)'],
        ],
        256,
    );
    texturas.push(brilhoTextura, poeiraTextura, halo);
    const matriz = new THREE.Matrix4();
    const quat = new THREE.Quaternion();
    const vetor = new THREE.Vector3();
    const escalaV = new THREE.Vector3();
    const eixoZ = new THREE.Vector3(0, 0, 1);

    // Luzes. Principal fria do alto à esquerda, quente âmbar do lado do formulário, recorte teal
    // por trás. As luzes do núcleo e da ficha ficam dentro do conjunto.
    const principal = new THREE.DirectionalLight(CLARO, 1.5);
    principal.position.set(-3, 4.5, 5);
    // Luz pontual próxima, do lado do formulário: um acento localizado nas peças da direita. Uma
    // direcional âmbar espalhava o brilho de torno por arcos inteiros e o aço parecia latão.
    const quente = new THREE.PointLight(AMBAR, 5, 5.5, 1.6);
    quente.position.set(3.6, -1.0, 1.1);
    const recorte = new THREE.DirectionalLight(TRACO, 2.4);
    recorte.position.set(-4, 3, -5);
    cena.add(principal, quente, recorte);

    // Halo do fundo: um degradê teal muito suave atrás da fechadura, que some na cor da coluna.
    const haloM = material(THREE.MeshBasicMaterial, { map: halo, transparent: true, depthWrite: false, fog: false, toneMapped: false });
    const haloG = registrar(new THREE.PlaneGeometry(1, 1));
    const fundo = new THREE.Mesh(haloG, haloM);
    fundo.position.z = -3.5;
    fundo.scale.setScalar(12);
    fundo.renderOrder = -2;
    cena.add(fundo);

    // Conjunto inclinado (anéis, núcleo, moldura, ficha).
    const mundo = new THREE.Group();
    mundo.rotation.set(INCLINACAO[0], INCLINACAO[1], 0);
    cena.add(mundo);

    // Materiais do metal: corpo com brilho de torno; dentes e porca sem anisotropia (mais baratos).
    const corpoBase = {
        color: METAL,
        metalness: 1,
        roughness: 0.3,
        anisotropy: 0.35,
        clearcoat: 0.35,
        clearcoatRoughness: 0.2,
    };
    const pecaM = material(THREE.MeshStandardMaterial, { color: METAL, metalness: 1, roughness: 0.34 });

    // Anéis

    const arco = DOIS_PI - FENDA;
    const caixa = registrar(new THREE.BoxGeometry(1, 1, 1));
    const aneis = RAIOS.map((raio, i) => {
        const secao = SECOES[i];
        const grupo = new THREE.Group();

        const corpoG = registrar(anelUsinado(THREE, raio, perfilChanfrado(secao), FENDA / 2, arco, SEGMENTOS_ARCO));
        const corpoM = material(THREE.MeshPhysicalMaterial, { ...corpoBase, emissive: 0x000000 });
        grupo.add(new THREE.Mesh(corpoG, corpoM));

        // Detalhe de engenharia de cada anel: contas luminosas (interno), dentes (médio) e escala
        // gravada na canaleta (externo).
        const gravadoM = material(THREE.MeshBasicMaterial, { color: TRACO });
        let detalhe;
        if (i === 0) {
            const contaG = registrar(new THREE.CylinderGeometry(0.019, 0.019, 0.012, 12));
            contaG.rotateX(PI / 2);
            const angulos = angulosForaDaFenda(N_CONTAS, 0.08);
            detalhe = new THREE.InstancedMesh(contaG, gravadoM, angulos.length);
            angulos.forEach((a, k) => {
                matriz.makeTranslation(Math.cos(a) * raio, Math.sin(a) * raio, secao.h + 0.002);
                detalhe.setMatrixAt(k, matriz);
            });
        } else if (i === 1) {
            const angulos = angulosForaDaFenda(N_DENTES, 0.04);
            detalhe = new THREE.InstancedMesh(caixa, pecaM, angulos.length);
            angulos.forEach((a, k) => {
                vetor.set(Math.cos(a) * (raio + secao.w + 0.03), Math.sin(a) * (raio + secao.w + 0.03), 0);
                quat.setFromAxisAngle(eixoZ, a);
                escalaV.set(0.07, 0.05, 0.11);
                detalhe.setMatrixAt(k, matriz.compose(vetor, quat, escalaV));
            });
        } else {
            const [g1, g2, d] = secao.canaleta;
            const angulos = angulosForaDaFenda(120, 0.035);
            detalhe = new THREE.InstancedMesh(caixa, gravadoM, angulos.length);
            angulos.forEach((a, k) => {
                const maior = Math.round((a * 120) / DOIS_PI) % 5 === 0;
                const r0 = maior ? g1 + 0.012 : g2 - 0.07;
                const r1 = g2 - 0.008;
                const rm = raio + (r0 + r1) / 2;
                vetor.set(Math.cos(a) * rm, Math.sin(a) * rm, secao.h - d + 0.004);
                quat.setFromAxisAngle(eixoZ, a);
                escalaV.set(r1 - r0, maior ? 0.014 : 0.009, 0.008);
                detalhe.setMatrixAt(k, matriz.compose(vetor, quat, escalaV));
            });
        }
        grupo.add(detalhe);

        // Travas da fenda: placas luminosas nas duas pontas, que viram âmbar ao alinhar.
        const travaM = material(THREE.MeshBasicMaterial, { color: CLARO });
        const travas = new THREE.InstancedMesh(caixa, travaM, 2);
        [-1, 1].forEach((lado, k) => {
            const a = (lado * FENDA) / 2;
            const t = lado * 0.011; // meia espessura da placa, para dentro da fenda
            vetor.set(Math.cos(a) * raio - Math.sin(a) * t, Math.sin(a) * raio + Math.cos(a) * t, 0);
            quat.setFromAxisAngle(eixoZ, a);
            escalaV.set(secao.w * 2 + 0.05, 0.022, secao.h * 2 + 0.03);
            travas.setMatrixAt(k, matriz.compose(vetor, quat, escalaV));
        });
        grupo.add(travas);

        // Sem halo aditivo: vermelho somado ao azul-marinho vira roxo-rosado. O erro aparece no
        // próprio metal (cor e emissão) e na luz vermelha do ponto de impacto.
        mundo.add(grupo);

        return { grupo, corpoM, gravadoM, travaM };
    });

    // Núcleo: porca sextavada chanfrada, cristal hexagonal e uma luz interna que respira.

    const nucleo = new THREE.Group();
    const hexagono = (r, fase = PI / 6) => {
        const pontos = [];
        for (let k = 0; k < 6; k++) {
            const a = fase + (k * PI) / 3;
            pontos.push(new THREE.Vector2(Math.cos(a) * r, Math.sin(a) * r));
        }

        return pontos;
    };
    const porcaForma = new THREE.Shape(hexagono(0.5));
    porcaForma.holes.push(new THREE.Path(hexagono(0.37).reverse()));
    const porcaG = registrar(
        new THREE.ExtrudeGeometry(porcaForma, { depth: 0.12, bevelEnabled: true, bevelThickness: 0.03, bevelSize: 0.03, bevelSegments: 1, curveSegments: 1 }),
    );
    porcaG.translate(0, 0, -0.06);
    nucleo.add(new THREE.Mesh(porcaG, pecaM));

    const cristalG = registrar(
        new THREE.LatheGeometry(
            [
                [0, -0.3],
                [0.31, -0.12],
                [0.31, 0.12],
                [0, 0.32],
            ].map(([x, y]) => new THREE.Vector2(x, y)),
            6,
        ),
    );
    cristalG.rotateX(PI / 2);
    const cristalM = material(THREE.MeshPhysicalMaterial, {
        color: TEAL_ESCURO,
        emissive: TEAL,
        emissiveIntensity: 0.4,
        metalness: 0,
        roughness: 0.06,
        transparent: true,
        opacity: 0.62,
        depthWrite: false,
        flatShading: true,
        clearcoat: 1,
        clearcoatRoughness: 0.05,
        envMapIntensity: 1.6,
    });
    const cristal = new THREE.Mesh(cristalG, cristalM);
    nucleo.add(cristal);

    const centelhaG = registrar(new THREE.OctahedronGeometry(0.1, 0));
    const centelhaM = material(THREE.MeshBasicMaterial, { color: CLARO, transparent: true, depthWrite: false, fog: false });
    const centelha = new THREE.Mesh(centelhaG, centelhaM);
    centelha.renderOrder = 2;
    nucleo.add(centelha);
    mundo.add(nucleo);

    const luzNucleo = new THREE.PointLight(TRACO, 1.2, 3.2, 1.6);
    mundo.add(luzNucleo);

    const brilhoNucleoM = material(THREE.SpriteMaterial, {
        map: brilhoTextura,
        color: TRACO,
        transparent: true,
        opacity: 0.3,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        fog: false,
    });
    const brilhoNucleo = new THREE.Sprite(brilhoNucleoM);
    brilhoNucleo.scale.set(1.5, 1.5, 1);
    brilhoNucleo.position.z = 0.25; // na frente da porca, senão a porca tapa o miolo do brilho
    brilhoNucleo.renderOrder = 3;
    mundo.add(brilhoNucleo);

    // Onda: um quadrado com textura de anel de luz (degradê radial com pico perto da borda).
    const ondaTextura = texturaRadial(
        THREE,
        [
            [0, 'rgba(255,255,255,0)'],
            [0.72, 'rgba(255,255,255,0)'],
            [0.9, 'rgba(255,255,255,0.35)'],
            [0.965, 'rgba(255,255,255,1)'],
            [1, 'rgba(255,255,255,0)'],
        ],
        512,
    );
    texturas.push(ondaTextura);
    const ondaG = registrar(new THREE.PlaneGeometry(2, 2));
    const ondaM = material(THREE.MeshBasicMaterial, {
        map: ondaTextura,
        color: TRACO,
        transparent: true,
        opacity: 0,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        side: THREE.DoubleSide,
        fog: false,
    });
    const ondaMalha = new THREE.Mesh(ondaG, ondaM);
    ondaMalha.visible = false;
    ondaMalha.renderOrder = 3;
    mundo.add(ondaMalha);

    // Moldura: um mostrador de 72 traços gravados e 8 cardeais, com a varredura nas cores.

    const angulosMoldura = [];
    const tracosMolduraM = material(THREE.MeshBasicMaterial, { color: 0xffffff });
    const tracosMoldura = new THREE.InstancedMesh(caixa, tracosMolduraM, N_MOLDURA);
    for (let k = 0; k < N_MOLDURA; k++) {
        const a = (k * DOIS_PI) / N_MOLDURA;
        angulosMoldura.push(a);
        const comprido = k % 3 === 0;
        vetor.set(Math.cos(a) * R_MOLDURA, Math.sin(a) * R_MOLDURA, 0);
        quat.setFromAxisAngle(eixoZ, a);
        escalaV.set(comprido ? 0.11 : 0.06, 0.014, 0.01);
        tracosMoldura.setMatrixAt(k, matriz.compose(vetor, quat, escalaV));
        tracosMoldura.setColorAt(k, cPreto);
    }
    mundo.add(tracosMoldura);

    const cardeais = [];
    const cardinalG = registrar(new THREE.OctahedronGeometry(0.05, 0));
    const cardinalM = material(THREE.MeshBasicMaterial, { color: 0xffffff });
    const cardinais = new THREE.InstancedMesh(cardinalG, cardinalM, 8);
    for (let k = 0; k < 8; k++) {
        const a = (k * PI) / 4;
        cardeais.push(a);
        vetor.set(Math.cos(a) * (R_MOLDURA + 0.17), Math.sin(a) * (R_MOLDURA + 0.17), 0);
        quat.setFromAxisAngle(eixoZ, a);
        escalaV.set(1.3, 0.8, 0.4);
        cardinais.setMatrixAt(k, matriz.compose(vetor, quat, escalaV));
        cardinais.setColorAt(k, cPreto);
    }
    mundo.add(cardinais);

    // Ficha

    const capsulaG = registrar(new THREE.CapsuleGeometry(0.058, 0.2, 6, 12));
    // Miolo da ficha um pouco mais claro que o âmbar, para ler como luz (o halo leva o âmbar).
    const fichaM = material(THREE.MeshBasicMaterial, { color: 0xffd08a, toneMapped: false });
    const ficha = new THREE.Mesh(capsulaG, fichaM);
    ficha.visible = false;
    mundo.add(ficha);

    // Glitch do erro: duas cópias só deslocadas (não piscam), separação cromática teal e vermelha.
    const copias = [TRACO, ERRO].map((hex) => {
        // Mistura normal: o vermelho somado ao azul do fundo viraria rosa.
        const m = material(THREE.MeshBasicMaterial, { color: hex, transparent: true, opacity: 0.85, depthWrite: false, toneMapped: false });
        const c = new THREE.Mesh(capsulaG, m);
        c.visible = false;
        mundo.add(c);

        return c;
    });

    const fichaBrilhoM = material(THREE.SpriteMaterial, {
        map: brilhoTextura,
        color: AMBAR,
        transparent: true,
        opacity: 0.95,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        fog: false,
    });
    const fichaBrilho = new THREE.Sprite(fichaBrilhoM);
    fichaBrilho.scale.set(1.1, 1.1, 1);
    fichaBrilho.visible = false;
    fichaBrilho.renderOrder = 3;
    mundo.add(fichaBrilho);

    const luzFicha = new THREE.PointLight(AMBAR, 0, 3, 1.2);
    mundo.add(luzFicha);

    // Rastro: um tubo afinado ao longo do caminho, de cor que some (mistura aditiva).
    const LADOS = 6;
    const posRastro = new Float32Array(N_RASTRO * LADOS * 3);
    const corRastro = new Float32Array(N_RASTRO * LADOS * 3);
    const indRastro = [];
    for (let k = 0; k < N_RASTRO - 1; k++) {
        for (let l = 0; l < LADOS; l++) {
            const a = k * LADOS + l;
            const b = k * LADOS + ((l + 1) % LADOS);
            indRastro.push(a, a + LADOS, b, b, a + LADOS, b + LADOS);
        }
    }
    const rastroG = registrar(new THREE.BufferGeometry());
    rastroG.setAttribute('position', new THREE.BufferAttribute(posRastro, 3));
    rastroG.setAttribute('color', new THREE.BufferAttribute(corRastro, 3));
    rastroG.setIndex(indRastro);
    const rastroM = material(THREE.MeshBasicMaterial, {
        vertexColors: true,
        toneMapped: false,
        transparent: true,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        side: THREE.DoubleSide,
        fog: false,
    });
    const rastro = new THREE.Mesh(rastroG, rastroM);
    rastro.frustumCulled = false;
    rastro.visible = false;
    mundo.add(rastro);

    // Fragmentos do erro: estilhaços que giram e faíscas, com trajetória analítica e velocidades
    // sorteadas uma vez com o gerador determinístico.
    const sorteio = gerador(SEMENTE);
    const origemFrag = pontoDoCaminho(S_CONTATO);
    const fuga = new THREE.Vector3(origemFrag[0], origemFrag[1], 0).normalize(); // para fora do anel
    const sortearVelocidade = (min, max, abertura) => {
        const base = Math.atan2(fuga.y, fuga.x);
        const a = base + (sorteio() - 0.5) * abertura;
        const v = min + sorteio() * (max - min);

        return [Math.cos(a) * v, Math.sin(a) * v + 0.4, (sorteio() - 0.3) * 1.2];
    };
    const estilhacos = [];
    for (let k = 0; k < N_ESTILHACOS; k++) {
        estilhacos.push({
            v: sortearVelocidade(0.8, 1.8, 2.6),
            eixo: new THREE.Vector3(sorteio() - 0.5, sorteio() - 0.5, sorteio() - 0.5).normalize(),
            giro: 4 + sorteio() * 8,
            tamanho: 0.8 + sorteio() * 0.9,
        });
    }
    // Estilhaços opacos (não aditivos): vermelho somado ao fundo azul ficaria rosa. Somem encolhendo.
    const estilhacoG = registrar(new THREE.TetrahedronGeometry(0.085, 0));
    const estilhacoM = material(THREE.MeshBasicMaterial, { color: 0xffffff, fog: false, toneMapped: false });
    const estilhacosMalha = new THREE.InstancedMesh(estilhacoG, estilhacoM, N_ESTILHACOS);
    estilhacosMalha.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
    estilhacosMalha.frustumCulled = false;
    estilhacosMalha.visible = false;
    for (let k = 0; k < N_ESTILHACOS; k++) {
        estilhacosMalha.setColorAt(k, cAmbar);
    }
    mundo.add(estilhacosMalha);

    const velFaiscas = [];
    for (let k = 0; k < N_FAISCAS; k++) {
        velFaiscas.push(sortearVelocidade(1.2, 2.6, 3.4));
    }
    const posFaiscas = new Float32Array(N_FAISCAS * 3);
    const corFaiscas = new Float32Array(N_FAISCAS * 3);
    const faiscasG = registrar(new THREE.BufferGeometry());
    faiscasG.setAttribute('position', new THREE.BufferAttribute(posFaiscas, 3));
    faiscasG.setAttribute('color', new THREE.BufferAttribute(corFaiscas, 3));
    const faiscasM = material(THREE.PointsMaterial, {
        map: poeiraTextura,
        size: 0.07,
        vertexColors: true,
        transparent: true,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        fog: false,
    });
    const faiscas = new THREE.Points(faiscasG, faiscasM);
    faiscas.frustumCulled = false;
    faiscas.visible = false;
    mundo.add(faiscas);

    // Poeira com profundidade: pontos fixos numa casca em volta, a névoa apaga os do fundo.
    const posPoeira = new Float32Array(N_POEIRA * 3);
    const corPoeira = new Float32Array(N_POEIRA * 3);
    for (let k = 0; k < N_POEIRA; k++) {
        const a = sorteio() * DOIS_PI;
        const r = 2.4 + sorteio() * 4.2;
        const z = -5 + sorteio() * 6.5;
        posPoeira.set([Math.cos(a) * r, Math.sin(a) * r * 0.8, z], k * 3);
        const nivel = 0.3 + sorteio() * 0.7;
        rascunho.copy(cTraco).lerp(cClaro, sorteio() * 0.5).multiplyScalar(nivel);
        corPoeira.set([rascunho.r, rascunho.g, rascunho.b], k * 3);
    }
    const poeiraG = registrar(new THREE.BufferGeometry());
    poeiraG.setAttribute('position', new THREE.BufferAttribute(posPoeira, 3));
    poeiraG.setAttribute('color', new THREE.BufferAttribute(corPoeira, 3));
    const poeiraM = material(THREE.PointsMaterial, {
        map: poeiraTextura,
        size: 0.085,
        vertexColors: true,
        transparent: true,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
    });
    const poeira = new THREE.Points(poeiraG, poeiraM);
    poeira.renderOrder = -1;
    cena.add(poeira);

    // Estado

    const linha = criarLinhaDoTempo(inicial, 0);
    let agora = 0;
    let ultimo = null;
    let ultimoTerminou = null;
    let aoTerminarFn = null;
    let ponteiroX = 0;
    let ponteiroY = 0;
    let camX = 0;
    let camY = 0;
    let descartado = false;
    let largura = 1;
    let altura = 1;
    const eixoY = new THREE.Vector3(0, 1, 0);
    const tangente = new THREE.Vector3();
    const lateral = new THREE.Vector3();
    const normal = new THREE.Vector3();

    function redimensionar(l, a) {
        largura = Math.max(1, Math.floor(l));
        altura = Math.max(1, Math.floor(a));
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
        renderer.setSize(largura, altura, false);
        camera.aspect = largura / altura;
        const fovV = (FOV * PI) / 180;
        const fovH = 2 * Math.atan(Math.tan(fovV / 2) * camera.aspect);
        distancia = R_ENQUADRE / Math.tan(Math.min(fovV, fovH) / 2);
        cena.fog.near = distancia - 1;
        cena.fog.far = distancia + 7;
        camera.position.set(camX, camY, distancia);
        camera.lookAt(0, 0, 0);
        camera.updateProjectionMatrix();
        if (ultimo !== null) {
            desenhar(agora, 0);
        }
    }

    function posicionarFicha(f, vermelho) {
        const visivel = f.visivel;
        ficha.visible = visivel;
        fichaBrilho.visible = visivel;
        rastro.visible = visivel;
        for (const c of copias) {
            c.visible = visivel && f.glitch > 0;
        }
        if (!visivel) {
            luzFicha.intensity = vermelho > 0.01 ? 2.2 * vermelho : 0;
            luzFicha.color.copy(cErro);

            return;
        }
        const p = pontoDoCaminho(f.s);
        const a = pontoDoCaminho(Math.max(0, f.s - 0.01));
        const b = pontoDoCaminho(Math.min(1, f.s + 0.01));
        tangente.set(b[0] - a[0], b[1] - a[1], b[2] - a[2]).normalize();
        ficha.quaternion.setFromUnitVectors(eixoY, tangente);
        ficha.position.set(p[0] + f.tremorX, p[1] + f.tremorY, p[2]);
        fichaBrilho.position.copy(ficha.position);
        luzFicha.position.copy(ficha.position);
        luzFicha.position.z += 0.25;
        // A ficha ilumina o metal por onde passa; travada, a luz vai para o vermelho.
        luzFicha.color.copy(cAmbar).lerp(cErro, limitar(vermelho * 1.5));
        luzFicha.intensity = 4;

        // As cópias se deslocam de lado (perpendicular ao caminho), para a separação aparecer.
        lateral.crossVectors(tangente, eixoZ).normalize();
        copias.forEach((c, i) => {
            const sinal = i === 0 ? 1 : -1;
            const dl = sinal * f.glitch * 1.3;
            const dt = sinal * f.glitchY * 1.3;
            c.quaternion.copy(ficha.quaternion);
            c.position.set(
                ficha.position.x + lateral.x * dl + tangente.x * dt,
                ficha.position.y + lateral.y * dl + tangente.y * dt,
                // Um pouco atrás: a ficha âmbar fica por cima e a separação aparece só nas bordas.
                ficha.position.z + lateral.z * dl + tangente.z * dt - 0.06,
            );
        });

        for (let k = 0; k < N_RASTRO; k++) {
            const sk = f.s - k * 0.0095;
            const q = pontoDoCaminho(Math.max(0, sk));
            const q2 = pontoDoCaminho(Math.max(0, sk) + 0.005);
            tangente.set(q2[0] - q[0], q2[1] - q[1], q2[2] - q[2]).normalize();
            lateral.crossVectors(tangente, eixoZ).normalize();
            normal.crossVectors(lateral, tangente).normalize();
            const fim = k / (N_RASTRO - 1);
            const raio = sk < 0 ? 0 : 0.03 * (1 - fim) ** 0.6;
            const peso = sk < 0 ? 0 : 0.9 * (1 - fim) ** 2.2;
            for (let l = 0; l < LADOS; l++) {
                const ang = (l * DOIS_PI) / LADOS;
                const [c, s] = [Math.cos(ang) * raio, Math.sin(ang) * raio];
                const o = (k * LADOS + l) * 3;
                posRastro[o] = q[0] + f.tremorX + lateral.x * c + normal.x * s;
                posRastro[o + 1] = q[1] + f.tremorY + lateral.y * c + normal.y * s;
                posRastro[o + 2] = q[2] + lateral.z * c + normal.z * s;
                corRastro[o] = cAmbar.r * peso * 1.3;
                corRastro[o + 1] = cAmbar.g * peso * 1.3;
                corRastro[o + 2] = cAmbar.b * peso * 1.3;
            }
        }
        rastroG.attributes.position.needsUpdate = true;
        rastroG.attributes.color.needsUpdate = true;
    }

    function posicionarFragmentos(fr) {
        estilhacosMalha.visible = fr.ativo;
        faiscas.visible = fr.ativo;
        if (!fr.ativo) {
            return;
        }
        const u = fr.u;
        const vida = limitar(u / VIDA_FRAGMENTOS);
        // Estilhaços: âmbar no instante da quebra, vermelho logo depois; somem encolhendo.
        estilhacos.forEach((e, k) => {
            const [vx, vy, vz] = e.v;
            vetor.set(origemFrag[0] + vx * u, origemFrag[1] + vy * u + 0.5 * GRAVIDADE * u * u, origemFrag[2] + vz * u);
            quat.setFromAxisAngle(e.eixo, e.giro * u);
            escalaV.setScalar(e.tamanho * (1 - vida) ** 0.6);
            estilhacosMalha.setMatrixAt(k, matriz.compose(vetor, quat, escalaV));
            const quente = limitar(vida / 0.3 + (k % 3) * 0.15);
            estilhacosMalha.setColorAt(k, rascunho.copy(cAmbar).lerp(cErro, quente));
        });
        estilhacosMalha.instanceMatrix.needsUpdate = true;
        estilhacosMalha.instanceColor.needsUpdate = true;

        // Faíscas aditivas âmbar (somado ao azul, o âmbar continua quente), mais rápidas, somem antes.
        const vidaF = limitar(u / (VIDA_FRAGMENTOS * 0.7));
        rascunho2.copy(cAmbar).multiplyScalar(1.3 * (1 - vidaF) ** 1.5);
        velFaiscas.forEach(([vx, vy, vz], k) => {
            posFaiscas.set([origemFrag[0] + vx * u, origemFrag[1] + vy * u + 0.5 * GRAVIDADE * u * u, origemFrag[2] + vz * u], k * 3);
            corFaiscas.set([rascunho2.r, rascunho2.g, rascunho2.b], k * 3);
        });
        faiscasG.attributes.position.needsUpdate = true;
        faiscasG.attributes.color.needsUpdate = true;
    }

    function pintarMoldura(m) {
        const luz = (a) => {
            // Fração da volta (a partir de TETA) já acesa pela varredura.
            const f = ((((a - TETA) / DOIS_PI) % 1) + 1) % 1;

            return m.acesa >= 1 || f <= m.acesa ? 1 : 0;
        };
        const meia = VARREDURA_JANELA / 2;
        angulosMoldura.forEach((a, k) => {
            let nivel = 0.32 + 0.5 * luz(a);
            if (m.varredura !== null) {
                const d = Math.abs(Math.atan2(Math.sin(a - m.varredura), Math.cos(a - m.varredura)));
                if (d < meia) {
                    nivel += 0.9 * (0.5 + 0.5 * Math.cos((PI * d) / meia));
                }
            }
            rascunho.copy(cTraco).multiplyScalar(nivel);
            tracosMoldura.setColorAt(k, rascunho);
        });
        cardeais.forEach((a, k) => {
            rascunho.copy(cTraco).lerp(cClaro, luz(a)).multiplyScalar(0.55 + 0.45 * luz(a));
            cardinais.setColorAt(k, rascunho);
        });
        tracosMoldura.instanceColor.needsUpdate = true;
        cardinais.instanceColor.needsUpdate = true;
    }

    function aplicar(pose) {
        let vermelhoMax = 0;
        pose.aneis.forEach((a, i) => {
            const { grupo, corpoM, gravadoM, travaM } = aneis[i];
            grupo.rotation.set(a.inclX, a.inclY, a.fenda);
            grupo.position.z = a.z;
            grupo.scale.setScalar(a.escala);
            vermelhoMax = Math.max(vermelhoMax, a.vermelho);

            // Erro: o anel acende em vermelho de verdade (emissão), sem passar pelo rosa.
            const v = a.vermelho;
            corpoM.color.copy(cMetal).lerp(cMetalErro, v);
            corpoM.emissive.copy(cErro).multiplyScalar(0.3 * v);
            gravadoM.color.copy(cTraco).multiplyScalar(0.75 + 0.45 * a.alinhado).lerp(cErro, v);
            if (v > 0) {
                gravadoM.color.multiplyScalar(1 + 0.8 * v);
            }

            // Travas da fenda: claras soltas, âmbar quando o anel alinha.
            travaM.color.copy(cTraco).multiplyScalar(0.7).lerp(cAmbar, a.alinhado).multiplyScalar(1 + 0.4 * a.alinhado);
        });

        const { brilho, giro, pinoAmbar, pulso, onda } = pose.nucleo;
        // Acima do brilho do aberto (1.4) é o pulso único do liberado: o excesso ganha peso extra.
        const excesso = Math.max(0, brilho - 1.4);
        const respiro = 0.88 + 0.12 * pulso;
        nucleo.rotation.z = giro;
        // O cristal fica teal (misturar com âmbar dava marrom); só a centelha de dentro vira âmbar.
        cristalM.emissiveIntensity = 0.42 * brilho * respiro;
        centelhaM.color.copy(cClaro).lerp(cAmbar, pinoAmbar).multiplyScalar(1.1 + 0.6 * brilho * respiro);
        centelha.rotation.set(0.6, giro * 2, 0.3);
        // A luz do núcleo continua teal mesmo aberto: âmbar aqui dourava os anéis inteiros.
        luzNucleo.color.copy(cTraco).lerp(cAmbar, pinoAmbar * 0.15);
        luzNucleo.intensity = (1.1 * brilho + 2.2 * excesso) * respiro;
        brilhoNucleoM.color.copy(cTraco).lerp(cAmbar, pinoAmbar * 0.35);
        brilhoNucleoM.opacity = limitar((0.17 * brilho + 0.3 * excesso) * respiro, 0, 1);
        const escalaBrilho = 1.2 + 0.3 * brilho + 0.9 * excesso;
        brilhoNucleo.scale.set(escalaBrilho, escalaBrilho, 1);

        // Onda de luz única que sai do núcleo e atravessa os anéis quando a fechadura abre.
        ondaMalha.visible = onda > 0;
        if (onda > 0) {
            const r = 0.55 + 2.9 * (1 - (1 - onda) ** 3);
            ondaMalha.scale.set(r, r, 1);
            ondaM.color.copy(cTraco).lerp(cClaro, 0.3);
            ondaM.opacity = 0.75 * (1 - onda) ** 1.4;
        }

        pintarMoldura(pose.moldura);
        posicionarFicha(pose.ficha, vermelhoMax);
        posicionarFragmentos(pose.fragmentos);
    }

    function desenhar(segundos, dt) {
        const pose = linha.pose(segundos);
        aplicar(pose);

        // Poeira: deriva lenta e determinística.
        poeira.rotation.z = 0.012 * segundos;
        poeira.position.y = 0.08 * Math.sin(0.11 * segundos);

        // Parallax só com ponteiro fino, e desligado quando a porta abre.
        const livre = parallax && linha.estado !== 'liberado' && linha.estado !== 'aberto';
        const k = 1 - Math.exp(-dt / 0.35);
        camX += ((livre ? 0.45 * ponteiroX : 0) - camX) * k;
        camY += ((livre ? -0.3 * ponteiroY : 0) - camY) * k;
        camera.position.set(camX, camY, distancia);
        camera.lookAt(0, 0, 0);

        renderer.render(cena, camera);

        if (pose.terminou !== ultimoTerminou) {
            ultimoTerminou = pose.terminou;
            if (pose.terminou !== null && aoTerminarFn) {
                aoTerminarFn(pose.terminou);
            }
        }
    }

    function quadro(segundos) {
        if (descartado) {
            return;
        }
        const dt = ultimo === null ? 0 : limitar(segundos - ultimo, 0, 0.1);
        ultimo = segundos;
        agora = segundos;
        desenhar(segundos, dt);
    }

    function descartar() {
        if (descartado) {
            return;
        }
        descartado = true;
        cena.traverse((o) => {
            if (o.isInstancedMesh) {
                o.dispose();
            }
        });
        for (const g of geometrias) {
            g.dispose();
        }
        for (const m of materiais) {
            m.dispose();
        }
        for (const t of texturas) {
            t.dispose();
        }
        ambiente.dispose();
        renderer.dispose();
        if (typeof renderer.forceContextLoss === 'function') {
            renderer.forceContextLoss();
        }
        tela.remove();
    }

    return {
        canvas: tela,
        redimensionar,
        quadro,
        /** Troca de estado no instante `t` (por padrão, o do último quadro). */
        definirEstado(nome, t = agora) {
            linha.mudar(nome, t);
            ultimoTerminou = null;
        },
        /** Posição do ponteiro de -1 a 1 (parallax). */
        apontar(x, y) {
            ponteiroX = limitar(x, -1, 1);
            ponteiroY = limitar(y, -1, 1);
        },
        /** Chamada uma vez quando um estado com fim (erro, liberado) termina. */
        aoTerminar(fn) {
            aoTerminarFn = fn;
        },
        /** Contadores do último quadro desenhado. */
        info() {
            const r = renderer.info.render;

            return { chamadas: r.calls, triangulos: r.triangles, pontos: r.points, linhas: r.lines };
        },
        descartar,
    };
}
