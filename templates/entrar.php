<?php
/**
 * Variáveis: $v, $csrf, $email, $erros (idDoCampo => mensagem), $geral.
 */
?>
<nav class="abas" aria-label="Acesso">
  <a href="/entrar" aria-current="page">Entrar</a>
  <a href="/cadastrar">Criar conta</a>
</nav>

<h1>Bem-vindo de volta</h1>
<p class="sub">Entre com seu e-mail e senha.</p>

<form method="post" action="/entrar" novalidate>
  <?= $v->csrf($csrf) ?>
  <?= $v->resumoErros('resumo-erros', $erros, $geral) ?>
  <?= $v->campo('email', 'E-mail', ['tipo' => 'email', 'valor' => $email, 'autocomplete' => 'username', 'erro' => $erros['email'] ?? null]) ?>
  <?= $v->campo('senha', 'Senha', ['tipo' => 'password', 'autocomplete' => 'current-password', 'erro' => $erros['senha'] ?? null]) ?>
  <button class="botao" type="submit">Entrar</button>
</form>

<p class="rodape-form"><a href="/esqueci-senha">Esqueci minha senha</a></p>
