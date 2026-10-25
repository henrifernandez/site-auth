// Melhorias progressivas. Tudo funciona sem JavaScript, exceto o botão "mostrar senha".
import { iniciarCena } from './cena.js';

function configurarMostrarSenha() {
    document.querySelectorAll('[data-mostrar-senha]').forEach((botao) => {
        const campo = document.getElementById(botao.dataset.mostrarSenha);
        if (!campo) {
            return;
        }
        botao.hidden = false;
        botao.addEventListener('click', () => {
            const mostrando = campo.type === 'text';
            campo.type = mostrando ? 'password' : 'text';
            botao.textContent = mostrando ? 'Mostrar senha' : 'Ocultar senha';
        });
    });
}

function focarResumoDeErros() {
    const resumo = document.querySelector('[data-foco-erro]');
    if (resumo) {
        resumo.focus();
    }
}

function configurarPopup() {
    const sobreposicao = document.querySelector('[data-popup]');
    if (!sobreposicao) {
        return;
    }

    const fundo = document.querySelector('.pagina');
    const botao = sobreposicao.querySelector('[data-fechar-popup]');
    if (!botao) {
        return;
    }

    if (fundo) {
        fundo.inert = true;
    }
    botao.focus();

    function fechar() {
        sobreposicao.remove();
        if (fundo) {
            fundo.inert = false;
        }
        const titulo = document.querySelector('h1');
        if (titulo) {
            titulo.tabIndex = -1;
            titulo.focus();
        }
    }

    botao.addEventListener('click', (evento) => {
        evento.preventDefault();
        fechar();
    });

    // Foco preso no diálogo (só há um elemento focável) e Esc fecha.
    sobreposicao.addEventListener('keydown', (evento) => {
        if (evento.key === 'Escape') {
            evento.preventDefault();
            fechar();
        } else if (evento.key === 'Tab') {
            evento.preventDefault();
            botao.focus();
        }
    });
}

configurarMostrarSenha();
focarResumoDeErros();
configurarPopup();
iniciarCena(document.getElementById('cena'));
