<?php

declare(strict_types=1);

namespace SiteAuth;

/**
 * Sessão de uma requisição, apoiada no PgSessionHandler.
 *
 * O identificador vem do cookie, mas só é aceito se existir no banco: um valor inventado pelo cliente
 * nunca é adotado (contra fixação de sessão). Os dados são JSON. A sessão só é gravada, e o cookie só é
 * enviado, quando há algo a guardar ou quando o identificador muda.
 */
final class Sessao implements Armazem
{
    /** Renova o "último uso" no máximo a cada tantos segundos, para não gravar a cada requisição. */
    private const int INTERVALO_TOQUE = 60;

    private string $id;

    private bool $existe = false;

    private bool $sujo = false;

    private bool $cookieNovo = false;

    private bool $limparCookie = false;

    private int $idadeUltimoUso = 0;

    private ?int $usuarioId = null;

    /** @var array<string, mixed> */
    private array $dados = [];

    private function __construct(private readonly PgSessionHandler $handler, private readonly bool $https)
    {
        $this->id = self::novoId();
    }

    public static function nomeDoCookie(bool $https): string
    {
        return $https ? '__Host-sid' : 'sid';
    }

    public static function iniciar(PgSessionHandler $handler, Request $requisicao, bool $confiarProxy = true): self
    {
        $handler->definirContexto($requisicao->ip($confiarProxy), $requisicao->header('user-agent') ?? '');
        $sessao = new self($handler, $requisicao->https);

        $cookie = $requisicao->cookies[self::nomeDoCookie($requisicao->https)] ?? '';
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $cookie) === 1) {
            $linha = $handler->carregar($cookie);
            if ($linha !== null) {
                $decodificado = json_decode($linha['dados'], true);
                $sessao->id = $cookie;
                $sessao->existe = true;
                $sessao->usuarioId = $linha['usuario_id'];
                $sessao->idadeUltimoUso = $linha['idade_ultimo_uso'];
                $sessao->dados = is_array($decodificado) ? $decodificado : [];
            }
        }

        return $sessao;
    }

    public function usuarioId(): ?int
    {
        return $this->usuarioId;
    }

    /** Hash do identificador atual, para comparar com as sessões listadas. */
    public function hashAtual(): string
    {
        return PgSessionHandler::hash($this->id);
    }

    public function obter(string $chave): mixed
    {
        return $this->dados[$chave] ?? null;
    }

    public function definir(string $chave, mixed $valor): void
    {
        $this->dados[$chave] = $valor;
        $this->sujo = true;
    }

    /** Lê e remove (mensagens de uma única exibição). */
    public function pegarUmaVez(string $chave): mixed
    {
        $valor = $this->dados[$chave] ?? null;
        if (array_key_exists($chave, $this->dados)) {
            unset($this->dados[$chave]);
            $this->sujo = true;
        }

        return $valor;
    }

    /** Vincula o usuário e troca o identificador (e o token CSRF), contra fixação de sessão. */
    public function autenticar(int $usuarioId): void
    {
        $this->regenerarId();
        $this->usuarioId = $usuarioId;
        $this->dados['csrf'] = bin2hex(random_bytes(32));
        $this->sujo = true;
    }

    /** Troca só o identificador, mantendo usuário e dados. */
    public function regenerarId(): void
    {
        $antigo = $this->id;
        $this->id = self::novoId();
        if ($this->existe) {
            $this->handler->regenerar($antigo, $this->id);
        }
        $this->cookieNovo = true;
        $this->sujo = true;
    }

    /** Encerra a sessão atual. Dados definidos depois disso vão para uma sessão nova. */
    public function destruir(): void
    {
        if ($this->existe) {
            $this->handler->destroy($this->id);
        }
        $this->id = self::novoId();
        $this->existe = false;
        $this->usuarioId = null;
        $this->dados = [];
        $this->sujo = false;
        $this->cookieNovo = false;
        $this->limparCookie = true;
    }

    /** Grava a sessão (se preciso) e acrescenta o cookie à resposta. */
    public function finalizar(Response $resposta): Response
    {
        $temConteudo = $this->dados !== [] || $this->usuarioId !== null;

        if ($temConteudo && ($this->sujo || !$this->existe)) {
            $this->handler->salvar(
                $this->id,
                json_encode($this->dados, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $this->usuarioId,
            );
            if (!$this->existe) {
                $this->cookieNovo = true;
            }
            $this->existe = true;
        } elseif ($this->existe && $this->idadeUltimoUso >= self::INTERVALO_TOQUE) {
            $this->handler->tocar($this->id);
        }

        if ($this->cookieNovo && $this->existe) {
            return $resposta->comCookie($this->linhaDoCookie($this->id, null));
        }
        if ($this->limparCookie && !$this->existe) {
            return $resposta->comCookie($this->linhaDoCookie('', 0));
        }

        return $resposta;
    }

    /** Sem Max-Age o cookie vale só até fechar o navegador; a validade real está no banco. */
    private function linhaDoCookie(string $valor, ?int $maxAge): string
    {
        $linha = self::nomeDoCookie($this->https) . '=' . $valor . '; Path=/; HttpOnly; SameSite=Lax';
        if ($maxAge !== null) {
            $linha .= '; Max-Age=' . $maxAge . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }
        if ($this->https) {
            $linha .= '; Secure';
        }

        return $linha;
    }

    private static function novoId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
