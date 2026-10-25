<?php
/**
 * Variáveis: $v, $logado.
 */
?>
<h1>Link inválido ou expirado</h1>
<?php if ($logado): ?>
  <p class="sub">Este link de verificação já foi usado, expirou ou não existe. Você pode pedir um novo na área da conta.</p>
  <p><a class="botao botao-link" href="/conta">Ir para a minha conta</a></p>
<?php else: ?>
  <p class="sub">Este link de verificação já foi usado, expirou ou não existe. Entre na sua conta para pedir um novo.</p>
  <p><a class="botao botao-link" href="/entrar">Entrar</a></p>
<?php endif; ?>
