<?php
/**
 * Variáveis: $v, $csrf, $token.
 */
?>
<h1>Confirmar e-mail</h1>
<p class="sub">Falta só um passo: confirme que este endereço de e-mail é seu.</p>

<form method="post" action="/verificar-email">
  <?= $v->csrf($csrf) ?>
  <input type="hidden" name="token" value="<?= $v->e($token) ?>">
  <button class="botao" type="submit">Confirmar meu e-mail</button>
</form>
