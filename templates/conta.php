<?php
/**
 * Variáveis: $v, $csrf, $usuario (Usuario), $sessoes, $formComErro, $erros, $geral, $nomeInformado.
 */
$errosDe = static fn(string $form): array => $formComErro === $form ? $erros : [];
$geralDe = static fn(string $form): ?string => $formComErro === $form ? $geral : null;
?>
<div class="cabecalho-conta">
  <div>
    <h1>Minha conta</h1>
    <p class="sub boas-vindas">Olá, <?= $v->e($usuario->primeiroNome()) ?>.</p>
  </div>
  <form method="post" action="/sair">
    <?= $v->csrf($csrf) ?>
    <button class="botao botao-secundario botao-pequeno" type="submit">Sair</button>
  </form>
</div>

<?php if (!$usuario->emailVerificado()): ?>
  <div class="aviso aviso-alerta">
    <p>Seu e-mail ainda não foi confirmado. Você pode continuar usando a conta, mas a confirmação ajuda a proteger o acesso.</p>
    <form method="post" action="/reenviar-verificacao">
      <?= $v->csrf($csrf) ?>
      <button class="botao botao-secundario botao-pequeno" type="submit">Reenviar e-mail de verificação</button>
    </form>
  </div>
<?php endif; ?>

<section aria-labelledby="titulo-dados">
  <h2 id="titulo-dados">Seus dados</h2>
  <dl class="dados">
    <dt>Nome</dt>
    <dd><?= $v->e($usuario->nome) ?></dd>
    <dt>E-mail</dt>
    <dd><?= $v->e($usuario->email) ?> (<?= $usuario->emailVerificado() ? 'confirmado' : 'não confirmado' ?>)</dd>
    <dt>Cadastro em</dt>
    <dd><?= $v->e($v->data($usuario->criadoEm)) ?></dd>
  </dl>
</section>

<section class="secao" aria-labelledby="titulo-nome">
  <h2 id="titulo-nome">Alterar nome</h2>
  <form method="post" action="/conta/nome" novalidate>
    <?= $v->csrf($csrf) ?>
    <?= $v->resumoErros('resumo-erros-nome', $errosDe('nome'), $geralDe('nome')) ?>
    <?= $v->campo('conta-nome', 'Nome', ['nome' => 'nome', 'valor' => $nomeInformado, 'autocomplete' => 'name', 'maxlength' => 100, 'erro' => $errosDe('nome')['conta-nome'] ?? null]) ?>
    <button class="botao botao-pequeno" type="submit">Salvar nome</button>
  </form>
</section>

<section class="secao" aria-labelledby="titulo-senha">
  <h2 id="titulo-senha">Alterar senha</h2>
  <p class="sub">Ao trocar a senha, os outros dispositivos são desconectados.</p>
  <form method="post" action="/conta/senha" novalidate>
    <?= $v->csrf($csrf) ?>
    <?= $v->resumoErros('resumo-erros-senha', $errosDe('senha'), $geralDe('senha')) ?>
    <?= $v->campo('senha-atual', 'Senha atual', ['tipo' => 'password', 'nome' => 'senha_atual', 'autocomplete' => 'current-password', 'erro' => $errosDe('senha')['senha-atual'] ?? null]) ?>
    <?= $v->campo('senha-nova', 'Nova senha', ['tipo' => 'password', 'nome' => 'senha_nova', 'autocomplete' => 'new-password', 'minlength' => 8, 'dica' => 'Use de 8 a 72 caracteres.', 'erro' => $errosDe('senha')['senha-nova'] ?? null]) ?>
    <button class="botao botao-pequeno" type="submit">Alterar senha</button>
  </form>
</section>

<section class="secao" aria-labelledby="titulo-sessoes">
  <h2 id="titulo-sessoes">Sessões ativas</h2>
  <p class="sub">Dispositivos com acesso à sua conta agora.</p>
  <ul class="lista-sessoes">
    <?php foreach ($sessoes as $sessao): ?>
      <li>
        <div class="sessao-detalhe">
          <strong><?= $v->e($sessao['dispositivo']) ?></strong>
          <?php if ($sessao['atual']): ?><span class="etiqueta">Esta sessão</span><?php endif; ?>
          <br>IP <?= $v->e($sessao['ip']) ?> · entrou em <?= $v->e($v->data($sessao['criada_em'])) ?>
          <br>Última atividade: <?= $v->e($v->data($sessao['ultimo_uso'])) ?>
        </div>
        <?php if (!$sessao['atual']): ?>
          <form method="post" action="/conta/sessoes/encerrar">
            <?= $v->csrf($csrf) ?>
            <input type="hidden" name="sessao" value="<?= $v->e($sessao['id_hash']) ?>">
            <button class="botao botao-secundario botao-pequeno" type="submit">Encerrar<span class="so-leitor"> sessão de <?= $v->e($sessao['dispositivo']) ?></span></button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <form method="post" action="/conta/sessoes/encerrar-todas">
    <?= $v->csrf($csrf) ?>
    <button class="botao botao-secundario botao-pequeno" type="submit">Sair de todos os dispositivos</button>
  </form>
</section>

<section class="secao zona-perigo" aria-labelledby="titulo-excluir">
  <h2 id="titulo-excluir">Excluir conta</h2>
  <p class="sub">Esta ação apaga sua conta e todos os dados ligados a ela, e não pode ser desfeita.</p>
  <form method="post" action="/conta/excluir" novalidate>
    <?= $v->csrf($csrf) ?>
    <?= $v->resumoErros('resumo-erros-excluir', $errosDe('excluir'), $geralDe('excluir')) ?>
    <?= $v->campo('excluir-senha', 'Senha atual', ['tipo' => 'password', 'nome' => 'senha_excluir', 'autocomplete' => 'current-password', 'erro' => $errosDe('excluir')['excluir-senha'] ?? null]) ?>
    <?= $v->campo('excluir-confirmacao', 'Digite EXCLUIR para confirmar', ['nome' => 'confirmacao', 'autocomplete' => 'off', 'erro' => $errosDe('excluir')['excluir-confirmacao'] ?? null]) ?>
    <button class="botao botao-perigo botao-pequeno" type="submit">Excluir minha conta</button>
  </form>
</section>
