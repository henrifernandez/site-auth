-- 0002: verificação de e-mail, data de atualização e unicidade do e-mail sem diferenciar maiúsculas.
-- Antes de criar o índice único, confere duplicados e aborta com mensagem clara (nada é alterado).

DO $$
DECLARE
    duplicados TEXT;
BEGIN
    SELECT string_agg(email_normalizado, ', ') INTO duplicados
      FROM (
          SELECT lower(email) AS email_normalizado
            FROM usuarios
           GROUP BY lower(email)
          HAVING count(*) > 1
           LIMIT 10
      ) d;

    IF duplicados IS NOT NULL THEN
        RAISE EXCEPTION 'Migracao 0002 abortada: ha e-mails duplicados quando se ignora maiusculas e minusculas (%). Resolva os duplicados manualmente e rode de novo.', duplicados;
    END IF;
END
$$;

ALTER TABLE usuarios ADD COLUMN email_verificado_em TIMESTAMPTZ;
ALTER TABLE usuarios ADD COLUMN atualizado_em TIMESTAMPTZ NOT NULL DEFAULT now();
UPDATE usuarios SET atualizado_em = criado_em;

CREATE UNIQUE INDEX usuarios_email_lower_uq ON usuarios (lower(email));
