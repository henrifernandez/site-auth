-- LEGADO: não use este arquivo.
-- Ele criava as tabelas "users" e "sessions" da primeira versão do projeto (api.php + localStorage).
-- Nenhuma das duas é usada hoje. O esquema atual vive em migrations/ e é aplicado com
-- "php bin/migrar.php up". As tabelas legadas continuam no Neon, intocadas, e não são lidas nem alteradas
-- pela aplicação nova.

-- Script de inicialização do banco de dados
-- Execute este script no Neon para criar a tabela de usuários

CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Criar índice no email para melhorar performance de buscas
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);

-- Criar tabela de sessões (opcional, para gerenciar sessões)
CREATE TABLE IF NOT EXISTS sessions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES users(id) ON DELETE CASCADE,
    token VARCHAR(255) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token);
