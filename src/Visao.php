<?php

declare(strict_types=1);

namespace SiteAuth;

use InvalidArgumentException;

/**
 * Renderização de templates PHP. Tudo que vem do usuário passa por e() antes de ir para o HTML.
 */
final class Visao
{
    public function __construct(private readonly string $pasta) {}

    public function e(?string $texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Renderiza o template dentro do layout.
     *
     * @param array<string, mixed> $dados
     * @param array{titulo: string, popup?: array{0: string, 1: string, 2: string}|null, aviso?: string|null, classe?: string, referrer?: string} $layout
     */
    public function pagina(string $template, array $dados, array $layout): string
    {
        $conteudo = $this->renderizar($template, $dados);

        return $this->renderizar('layout', [
            'titulo' => $layout['titulo'],
            'popup' => $layout['popup'] ?? null,
            'aviso' => $layout['aviso'] ?? null,
            'classe' => $layout['classe'] ?? '',
            'conteudo' => $conteudo,
        ]);
    }

    /** @param array<string, mixed> $dados */
    public function renderizar(string $template, array $dados = []): string
    {
        if (preg_match('/^[a-z_]+$/', $template) !== 1) {
            throw new InvalidArgumentException('Nome de template inválido.');
        }

        $arquivo = $this->pasta . '/' . $template . '.php';
        $v = $this;
        $renderizar = static function (string $__arquivo, array $__dados) use ($v): string {
            extract($__dados, EXTR_SKIP);
            ob_start();
            try {
                require $__arquivo;

                return (string) ob_get_clean();
            } catch (\Throwable $erro) {
                ob_end_clean();
                throw $erro;
            }
        };

        return $renderizar($arquivo, $dados);
    }

    public function csrf(string $token): string
    {
        return '<input type="hidden" name="' . Csrf::CAMPO . '" value="' . $this->e($token) . '">';
    }

    /**
     * Campo de formulário acessível: rótulo ligado ao input, dica e erro ligados por aria-describedby.
     *
     * @param array{tipo?: string, nome?: string, valor?: string, autocomplete?: string, erro?: string|null, dica?: string|null, minlength?: int, maxlength?: int, requerido?: bool} $o
     */
    public function campo(string $id, string $rotulo, array $o = []): string
    {
        $tipo = $o['tipo'] ?? 'text';
        $erro = $o['erro'] ?? null;
        $dica = $o['dica'] ?? null;
        $descricao = array_filter([
            $dica !== null ? $id . '-dica' : null,
            $erro !== null ? $id . '-erro' : null,
        ]);

        $atributos = [
            'id' => $id,
            'name' => $o['nome'] ?? $id,
            'type' => $tipo,
        ];
        if ($tipo !== 'password') {
            $atributos['value'] = $o['valor'] ?? '';
        }
        if (isset($o['autocomplete'])) {
            $atributos['autocomplete'] = $o['autocomplete'];
        }
        if (isset($o['minlength'])) {
            $atributos['minlength'] = (string) $o['minlength'];
        }
        if (isset($o['maxlength'])) {
            $atributos['maxlength'] = (string) $o['maxlength'];
        }
        if (in_array($tipo, ['email', 'password'], true)) {
            $atributos['autocapitalize'] = 'none';
            $atributos['spellcheck'] = 'false';
        }
        if ($erro !== null) {
            $atributos['aria-invalid'] = 'true';
        }
        if ($descricao !== []) {
            $atributos['aria-describedby'] = implode(' ', $descricao);
        }

        $html = '';
        foreach ($atributos as $nome => $valor) {
            $html .= ' ' . $nome . '="' . $this->e($valor) . '"';
        }
        if ($o['requerido'] ?? true) {
            $html .= ' required';
        }
        $input = '<input' . $html . '>';

        if ($tipo === 'password') {
            $input = '<div class="senha-grupo">' . $input
                . '<button type="button" class="mostrar" data-mostrar-senha="' . $this->e($id) . '" aria-controls="' . $this->e($id) . '" hidden>Mostrar senha</button></div>';
        }

        $saida = '<div class="campo' . ($erro !== null ? ' campo-com-erro' : '') . '">'
            . '<label for="' . $this->e($id) . '">' . $this->e($rotulo) . '</label>';
        if ($dica !== null) {
            $saida .= '<p class="dica" id="' . $this->e($id) . '-dica">' . $this->e($dica) . '</p>';
        }
        $saida .= $input;
        if ($erro !== null) {
            $saida .= '<p class="erro-campo" id="' . $this->e($id) . '-erro"><span class="so-leitor">Erro: </span>' . $this->e($erro) . '</p>';
        }

        return $saida . '</div>';
    }

    /**
     * Resumo de erros do formulário. role="alert" só aqui; o foco é movido para ele pelo JavaScript.
     *
     * @param array<string, string> $erros idDoCampo => mensagem
     */
    public function resumoErros(string $id, array $erros, ?string $geral = null): string
    {
        if ($erros === [] && $geral === null) {
            return '';
        }

        $html = '<div class="resumo-erros" id="' . $this->e($id) . '" role="alert" tabindex="-1" data-foco-erro>';
        if ($geral !== null) {
            $html .= '<p>' . $this->e($geral) . '</p>';
        }
        if ($erros !== []) {
            $html .= '<p class="resumo-titulo">Corrija para continuar:</p><ul>';
            foreach ($erros as $campo => $mensagem) {
                $html .= '<li><a href="#' . $this->e($campo) . '">' . $this->e($mensagem) . '</a></li>';
            }
            $html .= '</ul>';
        }

        return $html . '</div>';
    }

    public function data(string $timestamp): string
    {
        return Usuario::formatarData($timestamp);
    }
}
