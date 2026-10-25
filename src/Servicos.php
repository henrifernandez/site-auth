<?php

declare(strict_types=1);

namespace SiteAuth;

use SiteAuth\Mail\Notificador;

/**
 * Serviços compartilhados pelos controladores durante uma requisição.
 */
final readonly class Servicos
{
    public Usuarios $usuarios;

    public TokensConta $tokens;

    public PgSessionHandler $sessoes;

    public VerificacaoEmail $verificacao;

    public function __construct(
        public Db $db,
        public Config $config,
        public Visao $visao,
        public LimitadorTentativas $limitador,
        public Notificador $notificador,
    ) {
        $this->usuarios = new Usuarios($db);
        $this->tokens = new TokensConta($db);
        $this->sessoes = new PgSessionHandler($db);
        $this->verificacao = new VerificacaoEmail($this->tokens, $config, $notificador);
    }
}
