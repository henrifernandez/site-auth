-- Desfaz só o que o up da 0001 criou.

DROP TABLE IF EXISTS tentativas_acesso;
DROP TABLE IF EXISTS tokens_conta;
DROP TABLE IF EXISTS sessoes;

-- "usuarios" só é removida se foi criada pela 0001 (comentário-marca) e está vazia.
-- Uma tabela que já existia antes, ou que já tem contas, nunca é apagada.
DO $$
BEGIN
    IF to_regclass('usuarios') IS NOT NULL
       AND obj_description(to_regclass('usuarios'), 'pg_class') = 'siteauth:0001' THEN
        IF NOT EXISTS (SELECT 1 FROM usuarios) THEN
            DROP TABLE usuarios;
        ELSE
            RAISE NOTICE 'A tabela usuarios tem dados e foi mantida.';
        END IF;
    END IF;
END
$$;
