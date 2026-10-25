// Cena "fechadura de anéis" em three.js: monta os objetos e aplica a pose de aneis-pose.js.
//
// Autenticar é alinhar as fendas de três anéis: uma ficha âmbar sai do lado do formulário e só
// chega ao núcleo pelo canal alinhado. Este módulo não decide nada sobre a animação (isso é da
// pose) e não tem relógio próprio: o tempo, em segundos, vem sempre de quadro(segundos).

import { CONST, criarLinhaDoTempo, pontoDoCaminho } from './aneis-pose.js';

const PI = Math.PI;
const { RAIOS, TUBO, FENDA, TETA, R_MOLDURA, R_ENQUADRE, S_CONTATO, VARREDURA_JANELA, VIDA_FRAGMENTOS } = CONST;

const FOV = 28;
const FUNDO = 0x10233a; // mesma cor da coluna de marca
const TRACO = 0x7cc9ca;
const BRILHO_TEAL = 0x0f8b8d;
const CLARO = 0xe6edf4;
const AMBAR = 0xffb454;
const ERRO = 0xff7a7a;
const OPACIDADE_ANEL = [0.9, 0.75, 0.6]; // do anel interno ao externo
const N_MOLDURA = 72;
const N_RASTRO = 14;
const N_FRAGMENTOS = 16;
const GRAVIDADE = -2.5;
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

function texturaDoBrilho(THREE) {
    const lado = 128;
    const tela = document.createElement('canvas');
    tela.width = lado;
    tela.height = lado;
    const ctx = tela.getContext('2d');
    const gradiente = ctx.createRadialGradient(lado / 2, lado / 2, 0, lado / 2, lado / 2, lado / 2);
    gradiente.addColorStop(0, 'rgba(255,255,255,1)');
    gradiente.addColorStop(0.4, 'rgba(255,255,255,0.35)');
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
 * @param {{ inicial?: string, parallax?: boolean }} opcoes
 */
export function criarAneis(THREE, contenedor, { inicial = 'repouso', parallax = true } = {}) {
    // As cores foram escolhidas em hexadecimal "puro": sem gerenciamento de cor e saída linear,
    // como na cena anterior e na da API de teste.
    THREE.ColorManagement.enabled = false;

    const renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'low-power' });
    renderer.outputColorSpace = THREE.LinearSRGBColorSpace;
    renderer.setClearColor(FUNDO, 1);
    const tela = renderer.domElement;
    contenedor.appendChild(tela);

    const cena = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(FOV, 1, 0.1, 80);
    let distancia = 14;
    cena.fog = new THREE.Fog(FUNDO, distancia - 0.5, distancia + 5);

    const cor = (hex) => new THREE.Color(hex);
    const cTraco = cor(TRACO);
    const cBrilhoTeal = cor(BRILHO_TEAL);
    const cClaro = cor(CLARO);
    const cAmbar = cor(AMBAR);
    const cErro = cor(ERRO);
    const rascunho = cor(0);
    const geometrias = [];
    const materiais = [];
    const registrar = (g) => {
        geometrias.push(g);

        return g;
    };
    const material = (Classe, parametros) => {
        const m = new Classe(parametros);
        materiais.push(m);

        return m;
    };
    const brilhoTextura = texturaDoBrilho(THREE);

    // Anéis

    const arco = PI * 2 - FENDA;
    const aneis = RAIOS.map((raio, i) => {
        const grupo = new THREE.Group();

        const faixaG = registrar(new THREE.TorusGeometry(raio, TUBO[i], 8, 160, arco));
        faixaG.rotateZ(FENDA / 2);
        const faixaM = material(THREE.MeshBasicMaterial, { color: TRACO, transparent: true, opacity: OPACIDADE_ANEL[i], depthWrite: false });
        grupo.add(new THREE.Mesh(faixaG, faixaM));

        const brilhoG = registrar(new THREE.TorusGeometry(raio, 0.07, 6, 120, arco));
        brilhoG.rotateZ(FENDA / 2);
        const brilhoM = material(THREE.MeshBasicMaterial, {
            color: BRILHO_TEAL,
            transparent: true,
            opacity: 0.18,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
            fog: false,
        });
        grupo.add(new THREE.Mesh(brilhoG, brilhoM));

        // Marcas a cada 6 graus (as de índice múltiplo de 5 são maiores), sem a fenda.
        const pontas = [];
        for (let k = 0; k < 60; k++) {
            const angulo = (k * 6 * PI) / 180;
            const relativo = Math.atan2(Math.sin(angulo), Math.cos(angulo));
            if (Math.abs(relativo) < FENDA / 2 + 0.03) {
                continue;
            }
            const comprimento = k % 5 === 0 ? 0.15 : 0.07;
            const [c, s] = [Math.cos(angulo), Math.sin(angulo)];
            pontas.push(c * (raio + 0.05), s * (raio + 0.05), 0, c * (raio + 0.05 + comprimento), s * (raio + 0.05 + comprimento), 0);
        }
        const marcasG = registrar(new THREE.BufferGeometry());
        marcasG.setAttribute('position', new THREE.Float32BufferAttribute(pontas, 3));
        const marcasM = material(THREE.LineBasicMaterial, { color: TRACO, transparent: true, opacity: 0.35, depthWrite: false });
        grupo.add(new THREE.LineSegments(marcasG, marcasM));

        cena.add(grupo);

        return { grupo, faixaM, brilhoM, marcasM };
    });

    // Pontas das fendas: uma só malha instanciada (2 por anel), recalculada a cada quadro.
    const pontaG = registrar(new THREE.SphereGeometry(0.045, 12, 8));
    const pontaM = material(THREE.MeshBasicMaterial, { color: 0xffffff });
    const pontas = new THREE.InstancedMesh(pontaG, pontaM, 6);
    pontas.frustumCulled = false;
    pontas.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
    cena.add(pontas);
    const matrizPonta = new THREE.Matrix4();
    const matrizLocal = new THREE.Matrix4();

    // Núcleo

    const nucleo = new THREE.Group();
    const hexG = registrar(new THREE.CylinderGeometry(0.34, 0.34, 0.26, 6));
    hexG.rotateX(PI / 2);
    const hexM = material(THREE.MeshBasicMaterial, { color: 0x17304d, polygonOffset: true, polygonOffsetFactor: 1, polygonOffsetUnits: 1 });
    nucleo.add(new THREE.Mesh(hexG, hexM));
    const arestasG = registrar(new THREE.EdgesGeometry(hexG));
    const arestasM = material(THREE.LineBasicMaterial, { color: TRACO, transparent: true, opacity: 0.9 });
    nucleo.add(new THREE.LineSegments(arestasG, arestasM));
    const pinoG = registrar(new THREE.CircleGeometry(0.07, 24));
    const pinoM = material(THREE.MeshBasicMaterial, { color: CLARO });
    const pino = new THREE.Mesh(pinoG, pinoM);
    pino.position.z = 0.135;
    nucleo.add(pino);
    cena.add(nucleo);

    const brilhoNucleoM = material(THREE.SpriteMaterial, {
        map: brilhoTextura,
        color: TRACO,
        transparent: true,
        opacity: 0.28,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        fog: false,
    });
    const brilhoNucleo = new THREE.Sprite(brilhoNucleoM);
    brilhoNucleo.scale.set(1.6, 1.6, 1);
    cena.add(brilhoNucleo);

    // Moldura

    const posMoldura = new Float32Array(N_MOLDURA * 3);
    const angulosMoldura = [];
    for (let k = 0; k < N_MOLDURA; k++) {
        const a = (k * PI * 2) / N_MOLDURA;
        angulosMoldura.push(a);
        posMoldura.set([Math.cos(a) * R_MOLDURA, Math.sin(a) * R_MOLDURA, 0], k * 3);
    }
    const molduraG = registrar(new THREE.BufferGeometry());
    molduraG.setAttribute('position', new THREE.BufferAttribute(posMoldura, 3));
    const corMoldura = new Float32Array(N_MOLDURA * 3);
    molduraG.setAttribute('color', new THREE.BufferAttribute(corMoldura, 3));
    const molduraM = material(THREE.PointsMaterial, { size: 2, sizeAttenuation: false, vertexColors: true, depthWrite: false });
    cena.add(new THREE.Points(molduraG, molduraM));

    const cardeais = [];
    const posCardeais = new Float32Array(8 * 3);
    for (let k = 0; k < 8; k++) {
        const a = (k * PI) / 4;
        cardeais.push(a);
        posCardeais.set([Math.cos(a) * R_MOLDURA, Math.sin(a) * R_MOLDURA, 0], k * 3);
    }
    const cardeaisG = registrar(new THREE.BufferGeometry());
    cardeaisG.setAttribute('position', new THREE.BufferAttribute(posCardeais, 3));
    const corCardeais = new Float32Array(8 * 3);
    cardeaisG.setAttribute('color', new THREE.BufferAttribute(corCardeais, 3));
    const cardeaisM = material(THREE.PointsMaterial, {
        size: 3.5,
        sizeAttenuation: false,
        vertexColors: true,
        transparent: true,
        opacity: 0.6,
        depthWrite: false,
    });
    cena.add(new THREE.Points(cardeaisG, cardeaisM));

    const tracejadoPontos = [];
    for (let k = 0; k < 180; k++) {
        const a = (k * PI * 2) / 180;
        tracejadoPontos.push(new THREE.Vector3(Math.cos(a) * 2.75, Math.sin(a) * 2.75, 0));
    }
    const tracejadoG = registrar(new THREE.BufferGeometry().setFromPoints(tracejadoPontos));
    const tracejadoM = material(THREE.LineDashedMaterial, { color: TRACO, dashSize: 0.06, gapSize: 0.1, transparent: true, opacity: 0.16, depthWrite: false });
    const tracejado = new THREE.LineLoop(tracejadoG, tracejadoM);
    tracejado.computeLineDistances();
    cena.add(tracejado);

    // Ficha

    const capsulaG = registrar(new THREE.CapsuleGeometry(0.045, 0.2, 4, 8));
    const fichaM = material(THREE.MeshBasicMaterial, { color: AMBAR });
    const ficha = new THREE.Mesh(capsulaG, fichaM);
    ficha.visible = false;
    cena.add(ficha);
    const copiaM = material(THREE.MeshBasicMaterial, { color: TRACO, transparent: true, opacity: 0.55, depthWrite: false });
    const copias = [new THREE.Mesh(capsulaG, copiaM), new THREE.Mesh(capsulaG, copiaM)];
    for (const c of copias) {
        c.visible = false;
        cena.add(c);
    }
    const fichaBrilhoM = material(THREE.SpriteMaterial, {
        map: brilhoTextura,
        color: AMBAR,
        transparent: true,
        opacity: 0.55,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        fog: false,
    });
    const fichaBrilho = new THREE.Sprite(fichaBrilhoM);
    fichaBrilho.scale.set(0.7, 0.7, 1);
    fichaBrilho.visible = false;
    cena.add(fichaBrilho);

    const posRastro = new Float32Array(N_RASTRO * 3);
    const corRastro = new Float32Array(N_RASTRO * 3);
    const rastroG = registrar(new THREE.BufferGeometry());
    rastroG.setAttribute('position', new THREE.BufferAttribute(posRastro, 3));
    rastroG.setAttribute('color', new THREE.BufferAttribute(corRastro, 3));
    const rastroM = material(THREE.PointsMaterial, {
        size: 3,
        sizeAttenuation: false,
        vertexColors: true,
        transparent: true,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        fog: false,
    });
    const rastro = new THREE.Points(rastroG, rastroM);
    rastro.frustumCulled = false;
    rastro.visible = false;
    cena.add(rastro);

    // Fragmentos da ficha (erro): velocidades sorteadas uma vez; a trajetória é analítica.
    const sorteio = gerador(SEMENTE);
    const velocidades = [];
    for (let k = 0; k < N_FRAGMENTOS; k++) {
        const angulo = sorteio() * PI * 2;
        const rapidez = 0.6 + sorteio() * 0.8;
        velocidades.push([Math.cos(angulo) * rapidez, Math.sin(angulo) * rapidez, (sorteio() - 0.5) * 0.6]);
    }
    const posFrag = new Float32Array(N_FRAGMENTOS * 3);
    const corFrag = new Float32Array(N_FRAGMENTOS * 3);
    const fragG = registrar(new THREE.BufferGeometry());
    fragG.setAttribute('position', new THREE.BufferAttribute(posFrag, 3));
    fragG.setAttribute('color', new THREE.BufferAttribute(corFrag, 3));
    const fragM = material(THREE.PointsMaterial, {
        size: 3,
        sizeAttenuation: false,
        vertexColors: true,
        transparent: true,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        fog: false,
    });
    const fragmentos = new THREE.Points(fragG, fragM);
    fragmentos.frustumCulled = false;
    fragmentos.visible = false;
    cena.add(fragmentos);
    const origemFrag = pontoDoCaminho(S_CONTATO);

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

    function redimensionar(l, a) {
        largura = Math.max(1, Math.floor(l));
        altura = Math.max(1, Math.floor(a));
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
        renderer.setSize(largura, altura, false);
        camera.aspect = largura / altura;
        const fovV = (FOV * PI) / 180;
        const fovH = 2 * Math.atan(Math.tan(fovV / 2) * camera.aspect);
        distancia = R_ENQUADRE / Math.tan(Math.min(fovV, fovH) / 2);
        cena.fog.near = distancia - 0.5;
        cena.fog.far = distancia + 5;
        camera.position.set(camX, camY, distancia);
        camera.lookAt(0, 0, 0);
        camera.updateProjectionMatrix();
        if (ultimo !== null) {
            desenhar(agora, 0);
        }
    }

    function posicionarFicha(f) {
        if (!f.visivel) {
            ficha.visible = false;
            fichaBrilho.visible = false;
            rastro.visible = false;
            for (const c of copias) {
                c.visible = false;
            }

            return;
        }
        const p = pontoDoCaminho(f.s);
        const a = pontoDoCaminho(Math.max(0, f.s - 0.01));
        const b = pontoDoCaminho(Math.min(1, f.s + 0.01));
        tangente.set(b[0] - a[0], b[1] - a[1], b[2] - a[2]).normalize();
        ficha.quaternion.setFromUnitVectors(eixoY, tangente);
        ficha.position.set(p[0] + f.tremorX, p[1] + f.tremorY, p[2]);
        ficha.visible = true;
        fichaBrilho.position.copy(ficha.position);
        fichaBrilho.visible = true;

        const duplo = f.glitch > 0;
        copias.forEach((c, i) => {
            const sinal = i === 0 ? 1 : -1;
            c.visible = duplo;
            c.quaternion.copy(ficha.quaternion);
            c.position.set(ficha.position.x + sinal * f.glitch, ficha.position.y + sinal * f.glitchY, ficha.position.z);
        });

        for (let k = 0; k < N_RASTRO; k++) {
            const sk = f.s - (k + 1) * 0.018;
            const q = pontoDoCaminho(Math.max(0, sk));
            const peso = sk < 0 ? 0 : 1 - (k + 1) / N_RASTRO;
            posRastro.set([q[0] + f.tremorX, q[1] + f.tremorY, q[2]], k * 3);
            corRastro.set([cAmbar.r * peso, cAmbar.g * peso, cAmbar.b * peso], k * 3);
        }
        rastroG.attributes.position.needsUpdate = true;
        rastroG.attributes.color.needsUpdate = true;
        rastro.visible = true;
    }

    function posicionarFragmentos(fr) {
        fragmentos.visible = fr.ativo;
        if (!fr.ativo) {
            return;
        }
        const u = fr.u;
        const vida = limitar(u / VIDA_FRAGMENTOS);
        for (let k = 0; k < N_FRAGMENTOS; k++) {
            const v = velocidades[k];
            posFrag.set([origemFrag[0] + v[0] * u, origemFrag[1] + v[1] * u + 0.5 * GRAVIDADE * u * u, origemFrag[2] + v[2] * u], k * 3);
        }
        // Âmbar, depois vermelho, depois preto (a mistura é aditiva, então preto some).
        if (vida < 0.5) {
            rascunho.copy(cAmbar).lerp(cErro, vida / 0.5);
        } else {
            rascunho.copy(cErro).multiplyScalar(1 - (vida - 0.5) / 0.5);
        }
        for (let k = 0; k < N_FRAGMENTOS; k++) {
            corFrag.set([rascunho.r, rascunho.g, rascunho.b], k * 3);
        }
        fragG.attributes.position.needsUpdate = true;
        fragG.attributes.color.needsUpdate = true;
    }

    function pintarMoldura(m) {
        const luz = (a) => {
            // Fração da volta (a partir de TETA) já acesa pela varredura.
            const f = (((a - TETA) / (PI * 2)) % 1 + 1) % 1;

            return m.acesa >= 1 || f <= m.acesa ? 1 : 0;
        };
        const meia = VARREDURA_JANELA / 2;
        angulosMoldura.forEach((a, k) => {
            let nivel = 0.35 + 0.45 * luz(a);
            if (m.varredura !== null) {
                const d = Math.abs(Math.atan2(Math.sin(a - m.varredura), Math.cos(a - m.varredura)));
                if (d < meia) {
                    nivel += 0.5 * (0.5 + 0.5 * Math.cos((PI * d) / meia));
                }
            }
            corMoldura.set([cTraco.r * nivel, cTraco.g * nivel, cTraco.b * nivel], k * 3);
        });
        cardeais.forEach((a, k) => {
            rascunho.copy(cTraco).lerp(cClaro, luz(a));
            corCardeais.set([rascunho.r, rascunho.g, rascunho.b], k * 3);
        });
        molduraG.attributes.color.needsUpdate = true;
        cardeaisG.attributes.color.needsUpdate = true;
    }

    function aplicar(pose) {
        pose.aneis.forEach((a, i) => {
            const { grupo, faixaM, brilhoM, marcasM } = aneis[i];
            grupo.rotation.set(a.inclX, a.inclY, a.fenda);
            grupo.scale.setScalar(a.escala);
            grupo.updateMatrix();

            const vermelho = 0.7 * a.vermelho;
            faixaM.color.copy(cTraco).lerp(cErro, vermelho);
            marcasM.color.copy(cTraco).lerp(cErro, vermelho);
            brilhoM.color.copy(cBrilhoTeal).lerp(cErro, vermelho);

            // Pontas da fenda, em +-FENDA/2 no plano do anel.
            rascunho.copy(cClaro).lerp(cAmbar, a.alinhado);
            [-1, 1].forEach((lado, j) => {
                const ang = (lado * FENDA) / 2;
                matrizLocal.makeTranslation(Math.cos(ang) * RAIOS[i], Math.sin(ang) * RAIOS[i], 0);
                matrizPonta.copy(grupo.matrix).multiply(matrizLocal);
                pontas.setMatrixAt(i * 2 + j, matrizPonta);
                pontas.setColorAt(i * 2 + j, rascunho);
            });
        });
        pontas.instanceMatrix.needsUpdate = true;
        pontas.instanceColor.needsUpdate = true;

        nucleo.rotation.z = pose.nucleo.giro;
        pinoM.color.copy(cClaro).lerp(cAmbar, pose.nucleo.pinoAmbar);
        brilhoNucleoM.opacity = limitar(0.28 * pose.nucleo.brilho, 0, 1);

        pintarMoldura(pose.moldura);
        posicionarFicha(pose.ficha);
        posicionarFragmentos(pose.fragmentos);
    }

    function desenhar(segundos, dt) {
        const pose = linha.pose(segundos);
        aplicar(pose);

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
        pontas.dispose();
        for (const g of geometrias) {
            g.dispose();
        }
        for (const m of materiais) {
            m.dispose();
        }
        brilhoTextura.dispose();
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
