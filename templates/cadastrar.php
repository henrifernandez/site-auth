<?php
/**
 * Variáveis: $v, $csrf, $nome, $email, $erros (idDoCampo => mensagem), $geral.
 */
?>
<nav class="abas" aria-label="Acesso">
  <a href="/entrar">Entrar</a>
  <a href="/cadastrar" aria-current="page">Criar conta</a>
</nav>

<h1>Crie sua conta</h1>
<p class="sub">Preencha os dados abaixo para se cadastrar.</p>

<form method="post" action="/cadastrar" novalidate>
  <?= $v->csrf($csrf) ?>
  <?= $v->resumoErros('resumo-erros', $erros, $geral) ?>
  <?= $v->campo('nome', 'Nome', ['valor' => $nome, 'autocomplete' => 'name', 'maxlength' => 100, 'erro' => $erros['nome'] ?? null]) ?>
  <?= $v->campo('email', 'E-mail', ['tipo' => 'email', 'valor' => $email, 'autocomplete' => 'email', 'erro' => $erros['email'] ?? null]) ?>
  <?= $v->campo('senha', 'Senha', ['tipo' => 'password', 'autocomplete' => 'new-password', 'minlength' => 8, 'dica' => 'Use de 8 a 72 caracteres.', 'erro' => $erros['senha'] ?? null]) ?>
  <button class="botao" type="submit">Criar conta</button>
</form>
