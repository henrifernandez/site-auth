-- Desfaz só o que o up da 0002 criou.

DROP INDEX IF EXISTS usuarios_email_lower_uq;
ALTER TABLE usuarios DROP COLUMN IF EXISTS atualizado_em;
ALTER TABLE usuarios DROP COLUMN IF EXISTS email_verificado_em;
