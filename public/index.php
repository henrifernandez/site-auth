<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/../db.php';

class ErroForm extends Exception {}

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$aba = ($_GET['aba'] ?? 'login') === 'cadastro' ? 'cadastro' : 'login';
$erro = '';
$valores = ['nome' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = ($_POST['acao'] ?? '') === 'cadastro' ? 'cadastro' : 'login';
    $aba = $acao;
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $senha = (string) ($_POST['senha'] ?? '');
    $valores = ['nome' => $nome, 'email' => $email];

    try {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new ErroForm('Sessão expirada. Recarregue a página e tente de novo.');
        }

        if ($acao === 'cadastro') {
            if (mb_strlen($nome) < 2) throw new ErroForm('Informe seu nome completo.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ErroForm('Informe um e-mail válido.');
            if (mb_strlen($senha) < 8) throw new ErroForm('A senha precisa ter pelo menos 8 caracteres.');
        } elseif ($email === '' || $senha === '') {
            throw new ErroForm('Informe e-mail e senha.');
        }

        $pdo = db();
        $pdo->exec('CREATE TABLE IF NOT EXISTS usuarios (
            id SERIAL PRIMARY KEY,
            nome TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            senha_hash TEXT NOT NULL,
            criado_em TIMESTAMPTZ NOT NULL DEFAULT now()
        )');

        if ($acao === 'cadastro') {
            $st = $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash) VALUES (?, ?, ?)
                                 ON CONFLICT (email) DO NOTHING RETURNING id');
            $st->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
            if (!$st->fetch()) throw new ErroForm('Este e-mail já está cadastrado. Tente entrar.');
            $primeiro = explode(' ', $nome)[0];
            $_SESSION['popup'] = ['Cadastrado com sucesso!', "Sua conta foi criada, $primeiro. Agora é só entrar."];
        } else {
            $st = $pdo->prepare('SELECT nome, senha_hash FROM usuarios WHERE email = ?');
            $st->execute([$email]);
            $u = $st->fetch();
            if (!$u || !password_verify($senha, $u['senha_hash'])) {
                throw new ErroForm('E-mail ou senha incorretos.');
            }
            $primeiro = explode(' ', $u['nome'])[0];
            $_SESSION['popup'] = ['Login realizado com sucesso!', "Que bom ver você, $primeiro."];
        }

        header('Location: ?aba=login');
        exit;
    } catch (ErroForm $ex) {
        $erro = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log($ex->getMessage());
        $erro = 'Não foi possível concluir agora. Tente novamente.';
    }
}

$popup = $_SESSION['popup'] ?? null;
unset($_SESSION['popup']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acesso à conta</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;800&display=swap" rel="stylesheet">
<style>
:root{--ink:#10233A;--teal:#0F8B8D;--teal-d:#0B6E70;--bg:#F3F6F8;--line:#D5DDE5;--muted:#5B6B7C;--err:#B3261E;--ok:#1E8E5A}
*{box-sizing:border-box;margin:0}
body{font-family:Manrope,system-ui,sans-serif;color:var(--ink);background:var(--bg);min-height:100vh;display:grid;grid-template-columns:1fr 1fr}
.brand{background:var(--ink);color:#fff;padding:64px;display:flex;flex-direction:column;justify-content:flex-end}
.brand h1{font-size:clamp(2rem,3.4vw,3.2rem);font-weight:800;line-height:1.1;max-width:14ch}
.brand p{margin-top:20px;color:#B9C7D6;max-width:38ch;line-height:1.6}
main{display:flex;align-items:center;justify-content:center;padding:32px}
.card{width:100%;max-width:400px}
.tabs{display:flex;border-bottom:1px solid var(--line);margin-bottom:28px}
.tabs a{flex:1;text-align:center;text-decoration:none;padding:14px;font-weight:600;color:var(--muted);border-bottom:3px solid transparent;margin-bottom:-1px}
.tabs a[aria-selected=true]{color:var(--ink);border-color:var(--teal)}
h2{font-size:1.5rem;margin-bottom:6px}
.sub{color:var(--muted);margin-bottom:24px}
label{display:block;font-weight:600;font-size:.9rem;margin:16px 0 6px}
input{width:100%;padding:13px 14px;border:1px solid var(--line);border-radius:8px;font:inherit;background:#fff;color:var(--ink)}
input:focus-visible,a:focus-visible,button:focus-visible{outline:3px solid #7CC9CA;outline-offset:1px}
.erro{color:var(--err);font-size:.9rem;min-height:1.3em;margin-top:14px}
.cta{display:block;width:100%;margin-top:8px;padding:14px;border:0;border-radius:8px;background:var(--teal);color:#fff;font:700 1rem Manrope,sans-serif;text-align:center;text-decoration:none;cursor:pointer}
.cta:hover{background:var(--teal-d)}
.overlay{position:fixed;inset:0;background:rgba(16,35,58,.55);display:flex;align-items:center;justify-content:center;padding:24px}
.modal{background:#fff;border-radius:14px;padding:36px 32px;max-width:380px;width:100%;text-align:center;animation:pop .28s ease-out}
.modal .icone{width:64px;height:64px;border-radius:50%;background:var(--ok);margin:0 auto 18px;display:grid;place-items:center}
.modal svg{width:32px;height:32px;stroke:#fff;fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}
.modal h3{font-size:1.3rem;margin-bottom:8px}
.modal p{color:var(--muted);margin-bottom:24px;line-height:1.5}
@keyframes pop{from{transform:scale(.92);opacity:0}to{transform:none;opacity:1}}
@media (prefers-reduced-motion:reduce){.modal{animation:none}}
@media (max-width:820px){body{grid-template-columns:1fr}.brand{padding:32px 24px}.brand h1{font-size:1.8rem}}
</style>
</head>
<body>
<section class="brand">
  <h1>Sua conta, com acesso seguro.</h1>
  <p>Crie seu cadastro em menos de um minuto ou entre para continuar de onde parou.</p>
</section>

<main>
  <div class="card">
    <nav class="tabs" role="tablist">
      <a role="tab" href="?aba=login" aria-selected="<?= $aba === 'login' ? 'true' : 'false' ?>">Entrar</a>
      <a role="tab" href="?aba=cadastro" aria-selected="<?= $aba === 'cadastro' ? 'true' : 'false' ?>">Criar conta</a>
    </nav>

    <form method="post" action="?aba=<?= e($aba) ?>">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
      <input type="hidden" name="acao" value="<?= e($aba) ?>">

      <?php if ($aba === 'cadastro'): ?>
        <h2>Crie sua conta</h2>
        <p class="sub">Preencha os dados abaixo para se cadastrar.</p>
        <label for="nome">Nome</label>
        <input id="nome" name="nome" type="text" autocomplete="name" value="<?= e($valores['nome']) ?>" required>
      <?php else: ?>
        <h2>Bem-vindo de volta</h2>
        <p class="sub">Entre com seu e-mail e senha.</p>
      <?php endif; ?>

      <label for="email">E-mail</label>
      <input id="email" name="email" type="email" autocomplete="email" value="<?= e($valores['email']) ?>" required>
      <label for="senha">Senha</label>
      <input id="senha" name="senha" type="password" minlength="<?= $aba === 'cadastro' ? 8 : 1 ?>"
             autocomplete="<?= $aba === 'cadastro' ? 'new-password' : 'current-password' ?>" required>

      <p class="erro" role="alert"><?= e($erro) ?></p>
      <button class="cta" type="submit"><?= $aba === 'cadastro' ? 'Criar conta' : 'Entrar' ?></button>
    </form>
  </div>
</main>

<?php if ($popup): ?>
<div class="overlay" role="dialog" aria-modal="true" aria-labelledby="popup-titulo">
  <div class="modal">
    <div class="icone"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
    <h3 id="popup-titulo"><?= e($popup[0]) ?></h3>
    <p><?= e($popup[1]) ?></p>
    <a class="cta" href="?aba=login" autofocus>Continuar</a>
  </div>
</div>
<?php endif; ?>
</body>
</html>
