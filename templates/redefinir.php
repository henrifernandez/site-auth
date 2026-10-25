<?php
/**
 * Variáveis: $v, $csrf, $token, $erros, $geral.
 */
?>
<h1>Crie uma nova senha</h1>
<p class="sub">Escolha uma senha nova para a sua conta. Ao concluir, você sairá de todos os dispositivos.</p>

<form method="post" action="/redefinir-senha" novalidate>
  <?= $v->csrf($csrf) ?>
  <input type="hidden" name="token" value="<?= $v->e($token) ?>">
  <?= $v->resumoErros('resumo-erros', $erros, $geral) ?>
  <?= $v->campo('senha', 'Nova senha', ['tipo' => 'password', 'autocomplete' => 'new-password', 'minlength' => 8, 'dica' => 'Use de 8 a 72 caracteres.', 'erro' => $erros['senha'] ?? null]) ?>
  <button class="botao" type="submit">Salvar nova senha</button>
</form>
