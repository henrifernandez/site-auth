<?php
/**
 * Variáveis: $v, $logado.
 */
?>
<h1>E-mail confirmado</h1>
<p class="sub">Tudo certo: seu endereço de e-mail foi verificado.</p>
<p><a class="botao botao-link" href="<?= $logado ? '/conta' : '/entrar' ?>"><?= $logado ? 'Ir para a minha conta' : 'Entrar' ?></a></p>
