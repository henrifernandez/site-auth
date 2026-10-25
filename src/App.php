<?php

declare(strict_types=1);

namespace SiteAuth;

use SiteAuth\Controladores\AuthControlador;
use SiteAuth\Controladores\ContaControlador;
use SiteAuth\Controladores\RecuperacaoControlador;
use SiteAuth\Controladores\VerificacaoControlador;
use SiteAuth\Mail\Mailer;
use SiteAuth\Mail\MailerFabrica;
use SiteAuth\Mail\Notificador;
use Throwable;

/**
 * Aplicação: monta as rotas e transforma uma Request em Response.
 */
final class App
{
    private readonly Servicos $servicos;

    public function __construct(private readonly Db $db, private readonly Config $config, ?Visao $visao = null, ?Mailer $mailer = null)
    {
        $this->servicos = new Servicos(
            $db,
            $config,
            $visao ?? new Visao(dirname(__DIR__) . '/templates'),
            new LimitadorTentativas(new TentativasPg($db)),
            new Notificador($mailer ?? MailerFabrica::criar($config), $config->mailApiKey),
        );
    }

    public function handle(Request $requisicao): Response
    {
        try {
            $sessao = Sessao::iniciar($this->servicos->sessoes, $requisicao, $this->config->confiarIpDaVercel);
            $resposta = $this->roteador($requisicao, $sessao)->despachar($requisicao);
            $resposta = $sessao->finalizar($resposta);
        } catch (Throwable $erro) {
            // Detalhes só no log do servidor; o usuário recebe uma página genérica.
            error_log(sprintf('Erro interno: %s: %s em %s:%d', $erro::class, $erro->getMessage(), $erro->getFile(), $erro->getLine()));
            $resposta = $this->paginaDeErro(500, 'Algo deu errado', Validador::ERRO_GENERICO);
        }

        return Seguranca::aplicar($resposta, $requisicao);
    }

    private function roteador(Request $requisicao, Sessao $sessao): Router
    {
        $s = $this->servicos;
        $auth = new AuthControlador($s, $requisicao, $sessao);
        $recuperacao = new RecuperacaoControlador($s, $requisicao, $sessao);
        $verificacao = new VerificacaoControlador($s, $requisicao, $sessao);
        $conta = new ContaControlador($s, $requisicao, $sessao);

        $roteador = new Router(
            fn(Request $r): Response => $this->paginaDeErro(404, 'Página não encontrada', 'O endereço que você abriu não existe ou foi movido.'),
            fn(Request $r, array $permitidos): Response => $this->paginaDeErro(405, 'Método não permitido', 'Esta página não aceita esse tipo de requisição.')
                ->comCabecalho('Allow', implode(', ', $permitidos)),
        );

        $roteador->adicionar('GET', '/', $auth->inicio(...));
        $roteador->adicionar('GET', '/entrar', $auth->entrarForm(...));
        $roteador->adicionar('POST', '/entrar', $auth->entrar(...));
        $roteador->adicionar('GET', '/cadastrar', $auth->cadastrarForm(...));
        $roteador->adicionar('POST', '/cadastrar', $auth->cadastrar(...));
        $roteador->adicionar('POST', '/sair', $auth->sair(...));
        $roteador->adicionar('GET', '/esqueci-senha', $recuperacao->esqueciForm(...));
        $roteador->adicionar('POST', '/esqueci-senha', $recuperacao->esqueci(...));
        $roteador->adicionar('GET', '/redefinir-senha', $recuperacao->redefinirForm(...));
        $roteador->adicionar('POST', '/redefinir-senha', $recuperacao->redefinir(...));
        $roteador->adicionar('GET', '/verificar-email', $verificacao->confirmarForm(...));
        $roteador->adicionar('POST', '/verificar-email', $verificacao->confirmar(...));
        $roteador->adicionar('POST', '/reenviar-verificacao', $verificacao->reenviar(...));
        $roteador->adicionar('GET', '/conta', $conta->ver(...));
        $roteador->adicionar('POST', '/conta/nome', $conta->alterarNome(...));
        $roteador->adicionar('POST', '/conta/senha', $conta->alterarSenha(...));
        $roteador->adicionar('POST', '/conta/sessoes/encerrar', $conta->encerrarSessao(...));
        $roteador->adicionar('POST', '/conta/sessoes/encerrar-todas', $conta->encerrarTodas(...));
        $roteador->adicionar('POST', '/conta/excluir', $conta->excluir(...));

        return $roteador;
    }

    private function paginaDeErro(int $status, string $titulo, string $mensagem): Response
    {
        try {
            $html = $this->servicos->visao->pagina('erro', ['titulo' => $titulo, 'mensagem' => $mensagem], ['titulo' => $titulo]);
        } catch (Throwable) {
            return Response::texto($titulo . "\n", $status);
        }

        return Response::html($html, $status)->comCabecalho('Cache-Control', 'no-store');
    }
}
