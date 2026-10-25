<?php
/**
 * Variáveis: $v, $csrf, $enviado, $mensagem, $email, $erros, $geral.
 */
?>
<h1>Esqueci minha senha</h1>

<?php if ($enviado): ?>
  <div class="aviso" id="confirmacao" tabindex="-1" data-foco>
    <p><?= $v->e($mensagem) ?></p>
  </div>
  <p class="rodape-form"><a href="/entrar">Voltar para entrar</a></p>
<?php else: ?>
  <p class="sub">Informe o e-mail da sua conta. Se ela existir, você receberá um link para criar uma nova senha.</p>

  <form method="post" action="/esqueci-senha" novalidate>
    <?= $v->csrf($csrf) ?>
    <?= $v->resumoErros('resumo-erros', $erros, $geral) ?>
    <?= $v->campo('email', 'E-mail', ['tipo' => 'email', 'valor' => $email, 'autocomplete' => 'email', 'erro' => $erros['email'] ?? null]) ?>
    <button class="botao" type="submit">Enviar link</button>
  </form>

  <p class="rodape-form"><a href="/entrar">Voltar para entrar</a></p>
<?php endif; ?>
