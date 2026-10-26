<?php
/**
 * Layout comum. Variáveis: $v (Visao), $titulo, $conteudo (HTML já escapado), $popup, $aviso, $classe.
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $v->e($titulo) ?> | Acesso à conta</title>
<link rel="preload" href="/assets/fonts/manrope-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/css/app.css">
<script type="module" src="/assets/js/app.js"></script>
</head>
<body class="<?= $v->e($classe) ?>">
<div class="pagina">
  <main id="conteudo" class="principal">
    <div class="cartao">
      <?php if ($aviso): ?>
        <p class="aviso"><?= $v->e($aviso) ?></p>
      <?php endif; ?>
      <?= $conteudo ?>
    </div>
  </main>

  <aside class="marca" aria-label="Sobre o site">
    <div id="cena" aria-hidden="true">
      <?= $v->renderizar('cena_svg', ['variante' => in_array('conta', explode(' ', $classe), true) ? 'aberto' : 'repouso']) ?>
    </div>
    <div class="marca-texto">
      <p class="marca-titulo">Sua conta, com acesso seguro.</p>
      <p>Crie seu cadastro em menos de um minuto ou entre para continuar de onde parou.</p>
    </div>
    <button type="button" class="pausar-cena" data-pausar-cena aria-controls="cena" data-estado="rodando" hidden>Pausar animação</button>
  </aside>
</div>

<?php if ($popup): ?>
<div class="overlay" data-popup>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="popup-titulo" aria-describedby="popup-texto">
    <div class="icone" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
    <h2 id="popup-titulo"><?= $v->e($popup[0]) ?></h2>
    <p id="popup-texto"><?= $v->e($popup[1]) ?></p>
    <a class="botao" href="<?= $v->e($popup[2]) ?>" data-fechar-popup>Continuar</a>
  </div>
</div>
<?php endif; ?>
</body>
</html>
