-- 0001: tabelas da aplicação.
-- Nunca apaga nada. A tabela "usuarios" só é criada se ainda não existir (o banco do Neon já a tem);
-- quando é esta migração que a cria, ela recebe um comentário-marca para o down saber que pode removê-la.

DO $$
BEGIN
    IF to_regclass('usuarios') IS NULL THEN
        CREATE TABLE usuarios (
            id SERIAL PRIMARY KEY,
            nome TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            senha_hash TEXT NOT NULL,
            criado_em TIMESTAMPTZ NOT NULL DEFAULT now()
        );
        COMMENT ON TABLE usuarios IS 'siteauth:0001';
    END IF;
END
$$;

CREATE TABLE sessoes (
    id_hash CHAR(64) PRIMARY KEY,
    usuario_id INTEGER REFERENCES usuarios (id) ON DELETE CASCADE,
    dados TEXT NOT NULL DEFAULT '',
    ip TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    criada_em TIMESTAMPTZ NOT NULL DEFAULT now(),
    ultimo_uso TIMESTAMPTZ NOT NULL DEFAULT now(),
    expira_em TIMESTAMPTZ NOT NULL
);
CREATE INDEX sessoes_usuario_idx ON sessoes (usuario_id);
CREATE INDEX sessoes_expira_idx ON sessoes (expira_em);

CREATE TABLE tokens_conta (
    id BIGSERIAL PRIMARY KEY,
    usuario_id INTEGER NOT NULL REFERENCES usuarios (id) ON DELETE CASCADE,
    tipo TEXT NOT NULL CHECK (tipo IN ('recuperar_senha', 'verificar_email')),
    hash_token CHAR(64) NOT NULL UNIQUE,
    expira_em TIMESTAMPTZ NOT NULL,
    usado_em TIMESTAMPTZ,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX tokens_conta_usuario_idx ON tokens_conta (usuario_id, tipo);

CREATE TABLE tentativas_acesso (
    id BIGSERIAL PRIMARY KEY,
    chave TEXT NOT NULL,
    ip TEXT NOT NULL,
    tipo TEXT NOT NULL,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX tentativas_acesso_chave_idx ON tentativas_acesso (chave, criado_em);
CREATE INDEX tentativas_acesso_ip_idx ON tentativas_acesso (ip, criado_em);
