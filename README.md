# Site Auth

Cadastro, login e área da conta em PHP 8.4 e PostgreSQL, sem framework e sem dependências de produção. Pensado para rodar como função serverless na Vercel com banco no Neon, mas funciona em qualquer lugar com PHP 8.4 e Postgres.

O projeto passou por uma reforma. A versão anterior tinha duas telas concorrentes (uma com formulário PHP, outra com `fetch` e `localStorage`), não rodava PHP na Vercel e guardava a sessão só no navegador. Esta versão tem uma entrada única, sessão no banco, recuperação de senha, verificação de e-mail, área da conta e testes. Veja "Limites e pontos de atenção" no fim para o que ainda não foi comprovado em produção.

## O que tem

- Criar conta, entrar e sair, com CSRF em todo POST.
- Recuperar senha por e-mail, com link de uso único que expira em 30 minutos.
- Verificar e-mail (link de 24 horas). Contas antigas, sem verificação, continuam entrando e veem um aviso.
- Área da conta (`/conta`): dados e data real de cadastro, trocar nome, trocar senha, listar e encerrar sessões, sair de todos os dispositivos e excluir a conta.
- Limite de tentativas guardado no Postgres (login, recuperação, reenvio de verificação, cadastro, senha atual).
- Cabeçalhos de segurança e CSP sem nada inline; fonte Manrope servida localmente.
- Acessibilidade pensada para WCAG 2.2 AA, com teste estrutural em PHP e teste com axe-core no CI.
- Cena de cadeado em three.js na coluna de marca e animação de desbloqueio na área da conta, com SVG de reserva (veja "Animação do cadeado").

## Fluxo de autenticação

```
Cadastro
  navegador --POST /cadastrar (csrf, nome, email, senha)--> valida --> password_hash --> INSERT usuarios
                                                              \--> token de verificação (24 h) --> e-mail
  <-- 303 /entrar (aviso "Cadastrado com sucesso!")

Login
  POST /entrar --> limite de tentativas (5 por e-mail+IP, 30 por IP, 15 min) --> password_verify
     usuário inexistente: verifica contra um hash falso (tempo parecido) e responde igual
     ok: troca o id da sessão, grava usuario_id --> 303 /conta
     erro: "E-mail ou senha incorretos." (HTTP 422) | bloqueado: HTTP 429 + Retry-After

Sessão (a cada requisição, porque cada função serverless é isolada)
  cookie sid (ou __Host-sid em HTTPS) --> sha256 --> SELECT em sessoes --> dados + usuario_id
  o banco guarda só o hash; expira em 7 dias (absoluta) ou 2 h sem uso (autenticada), 1 h (anônima)

Recuperar senha
  POST /esqueci-senha --> resposta SEMPRE igual, exista a conta ou não
     se existe e não passou do limite (3 por e-mail e 10 por IP, por hora): token random_bytes(32)
     (base64url no link; o banco guarda só o sha256) --> e-mail com APP_URL/redefinir-senha?token=...
  GET  /redefinir-senha?token=... --> valida o token e mostra o formulário (sem consumir)
  POST /redefinir-senha --> valida a senha nova antes --> UPDATE ... WHERE usado_em IS NULL AND expira_em > now()
     RETURNING usuario_id (atômico: um token vale uma vez) --> troca a senha, invalida os outros tokens,
     ENCERRA todas as sessões do usuário, envia "Sua senha foi alterada"

Verificar e-mail
  link /verificar-email?token=... mostra um botão; só o POST consome o token (leitores de e-mail não gastam o link)
```

## Estrutura

```
api/index.php            única entrada PHP: autoloader mínimo, .env em dev e App::handle()
src/                     namespace SiteAuth (PSR-4)
  App.php                monta as rotas, trata erros (500 genérico) e aplica os cabeçalhos
  Router.php, Request.php, Response.php
  Db.php                 conexão (DATABASE_URL); remove "-pooler" e envia options=endpoint no Neon
  PgSessionHandler.php   sessões no Postgres (SessionHandlerInterface), só o hash do id
  Sessao.php, Csrf.php, Tokens.php, Validador.php
  Usuarios.php, TokensConta.php, LimitadorTentativas.php, TentativasPg.php
  Controladores/         Auth, Recuperacao, Verificacao, Conta
  Mail/                  Mailer, LogMailer, ResendMailer, BrevoMailer, modelos em português
  Migrador.php           migrações reversíveis
templates/               HTML com escape (layout e uma página por tela)
public/assets/           css, js, fonts e vendor/three (únicos arquivos servidos como estáticos)
migrations/              0001_base e 0002_usuarios_verificacao, cada uma com .up.sql e .down.sql
bin/migrar.php           up | down | status
router.php               servidor de desenvolvimento (php -S)
vercel.json              runtime vercel-php@0.8.0 e rotas
tests/                   PHPUnit (unitários e de integração) e tests/a11y (axe-core)
docs/legado/init.sql     script antigo (tabelas users e sessions), mantido só como referência
```

## Rodando localmente

Requisitos: PHP 8.4 com `pdo_pgsql`, `mbstring` e `curl`; Docker (ou um Postgres qualquer); Composer só para as ferramentas de desenvolvimento.

```bash
# 1. Postgres descartável (escolha uma senha sua)
docker run -d --name siteauth-pg -e POSTGRES_PASSWORD=<sua-senha> -e POSTGRES_DB=siteauth -p 5432:5432 postgres:16

# 2. Configuração local (o .env é ignorado pelo git)
cp .env.example .env
# edite DATABASE_URL: postgresql://postgres:<sua-senha>@localhost:5432/siteauth

# 3. Esquema
php bin/migrar.php up

# 4. Servidor
php -S localhost:8081 router.php
```

Abra http://localhost:8081. Com `MAIL_PROVIDER=log` (padrão), os e-mails aparecem no terminal do servidor, inclusive os links de recuperação e verificação. O `router.php` emula o `vercel.json`: serve `public/` como estático, responde 404 para caminhos internos e manda o resto para `api/index.php`.

## Migrações

```bash
php bin/migrar.php status        # lista aplicadas e pendentes
php bin/migrar.php up            # aplica as pendentes, cada uma em uma transação
php bin/migrar.php down          # desfaz a última
php bin/migrar.php down 2        # desfaz as duas últimas
php bin/migrar.php down --todas
```

- `0001_base` cria `sessoes`, `tokens_conta` e `tentativas_acesso`, e cria `usuarios` somente se ela não existir (formato: `id`, `nome`, `email`, `senha_hash`, `criado_em`).
- `0002_usuarios_verificacao` acrescenta `email_verificado_em` e `atualizado_em` e cria o índice único em `lower(email)`. Antes disso confere duplicados sem diferenciar maiúsculas e minúsculas; se houver, aborta com a lista e não altera nada.
- O `up` nunca apaga tabelas ou dados. O `down` desfaz só o que o `up` criou: a tabela `usuarios` só é removida se foi a migração `0001` que a criou e se está vazia.
- As tabelas legadas `users` e `sessions` não são tocadas.

No Neon, rode `php bin/migrar.php up` da sua máquina com `DATABASE_URL` apontando para o banco (a Vercel não executa migrações neste projeto).

## Variáveis de ambiente

| Variável | Obrigatória | Para que serve |
| --- | --- | --- |
| `DATABASE_URL` | sim | Connection string do Postgres. Nunca vem de arquivo versionado. |
| `APP_URL` | para e-mails | Endereço público sem barra final. Os links dos e-mails usam só ele, nunca o cabeçalho `Host`. Sem ela, os e-mails com link não são enviados (e o erro vai para o log). |
| `MAIL_PROVIDER` | não | `log` (padrão), `resend` ou `brevo`. |
| `MAIL_API_KEY` | com resend/brevo | Chave da API do provedor. |
| `MAIL_FROM` | com resend/brevo | Remetente: `Nome <endereco@dominio>` ou só o endereço. |
| `TRUST_VERCEL_IP` | não | `0` desliga a leitura do cabeçalho `x-vercel-forwarded-for` (use fora da Vercel). |

Em produção (`VERCEL_ENV=production` ou `APP_ENV=production`), o `LogMailer` registra só o destinatário mascarado e o assunto, nunca o link. Em produção, configure `resend` ou `brevo`: com `log` nenhum e-mail sai.

## Deploy na Vercel

- O `vercel.json` usa `vercel-php@0.8.0` (PHP 8.4), roteia tudo para `api/index.php` depois de regras 404 para `/src`, `/templates`, `/migrations`, `/bin`, `/tests`, `/vendor`, `/docs`, `/.github` e arquivos de configuração, e serve `/assets/*` como estático (via `handle: filesystem`, a partir de `public/`).
- O runtime exige **Node 22.x**: em Settings > General > Node.js Version, escolha 22.x.
- Framework Preset: Other. Sem Build Command. Output Directory: `public` (padrão quando a pasta existe).
- Variáveis em Settings > Environment Variables: `DATABASE_URL`, `APP_URL`, `MAIL_PROVIDER`, `MAIL_API_KEY`, `MAIL_FROM`.
- Rode as migrações antes de publicar uma versão que mude o esquema.
- O deploy funciona sem `vendor/`: `api/index.php` tem um autoloader próprio de poucas linhas. Se houver `composer.json`, o runtime roda `composer install`; não há dependências de produção.

## Animação do cadeado

Dois lugares ganharam uma cena em three.js (wireframe, nas cores da identidade), sem mudar textos, cores nem layout das páginas:

- **Coluna de marca** (entrar, criar conta, esqueci a senha, redefinir): um cadeado flutua devagar, com partículas finas e um halo suave, e acompanha o mouse com parallax leve (só com mouse). A argola desce e o cadeado se fecha quando o campo de senha recebe o foco, e volta ao repouso quando ele perde. Se a página recarrega com erro, o cadeado dá uma sacudida curta (cerca de 400 ms) com um brilho vermelho suave que volta ao normal. Nada reage a teclas, para não revelar o tamanho da senha a quem olha a tela.
- **Área da conta**: na primeira carga de `/conta` depois de um login, a argola sobe e abre em cerca de 1,2 s, as partículas se expandem e se dissipam e o texto de boas-vindas surge. O servidor grava um sinal de uso único na sessão no login e o consome na renderização; recarregar mostra só o cadeado aberto, calmo (um SVG, sem three.js). Se o modal "Login realizado com sucesso!" estiver aberto, a animação espera ele fechar.

Arquivos: `public/assets/js/cena.js` (decide, carrega e cuida do ciclo de vida), `public/assets/js/cadeado.js` (a cena) e `public/assets/vendor/three/` (three.js 0.186.1, MIT, copiado do projeto irmão API-TESTE; origem e versão em `VERSION.txt`). Tudo é servido de `/assets`, sem CDN e sem script inline, então a CSP continua `script-src 'self'`.

Cuidados de acessibilidade e de desempenho:

- O canvas é decorativo (`aria-hidden`), nunca recebe foco e a animação não mexe no foco do teclado. Erros e confirmações continuam sendo anunciados pelos textos da página (`role="alert"` no resumo de erros), não pela cena.
- Com `prefers-reduced-motion`, sem WebGL (teste em `try/catch`), com falha ao carregar o módulo ou com pouco espaço (celular e janelas baixas) o three.js nem é baixado: fica o SVG estático (cadeado fechado no login, aberto na conta). Sem JavaScript, o SVG também aparece.
- O botão "Pausar animação" / "Retomar animação" (WCAG 2.2.2) fica visível e operável por teclado enquanto houver animação contínua. Pausar libera geometrias, materiais e o renderer e mostra o SVG; a cena também para quando a aba fica oculta.
- Nada pisca mais de 3 vezes por segundo; o brilho de erro cobre uma área pequena, com opacidade baixa, e não há flash de tela cheia. O canvas fica acima do texto da coluna de marca, nunca por baixo dele, então o contraste do texto não depende da cena.
- `devicePixelRatio` limitado a 2, no máximo 300 partículas (usa 160 no login e 120 na conta) e carregamento do three.js por `import()` dinâmico, em tempo ocioso, depois que o formulário já está utilizável.

## Segurança

- Senhas com `password_hash(PASSWORD_DEFAULT)` e rehash automático no login. A senha vale de 8 a 72 **bytes** (limite do bcrypt), sem `trim`.
- CSRF por sessão em todo POST; cookie `HttpOnly`, `SameSite=Lax`, `Secure` e prefixo `__Host-` em HTTPS; o id da sessão muda no login e ao trocar a senha; um id inventado pelo cliente nunca é adotado.
- Login com mensagem genérica, hash falso para igualar o tempo e limite de tentativas no Postgres (HTTP 429 com `Retry-After`).
- Recuperação de senha sem revelar se a conta existe (mesmo status e corpo, também quando o limite foi atingido). Tokens de 32 bytes aleatórios, só o sha256 no banco, uso único e atômico. Trocar a senha encerra todas as sessões.
- Consultas SQL sempre com parâmetros; saída sempre escapada nos templates.
- CSP `default-src 'self'` sem scripts nem estilos inline, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `X-Frame-Options`, HSTS em HTTPS e `Cache-Control: no-store` nas páginas dinâmicas. A página com token na URL usa `Referrer-Policy: no-referrer`.
- Erros: nunca aparecem exceção, host do banco ou stack; o detalhe vai só para `error_log`. Falha no envio de e-mail não quebra a resposta e não registra a chave de API.
- O cadastro com e-mail já existente continua dizendo "Este e-mail já está cadastrado. Tente entrar." (decisão do projeto). A regra de não revelar existência vale para a recuperação de senha.

## Testes

```bash
composer install
composer check        # php-cs-fixer (dry-run) + phpstan nível 6 + phpunit
composer test         # só o phpunit
```

Os testes unitários (validador, CSRF, tokens, limitador com repositório falso, roteador, e-mails, templates) rodam sem banco. Os de integração (migrações, cadastro, login, bloqueio, recuperação, verificação, conta, exclusão em cascata, sessão entre requisições, cabeçalhos) usam a variável `TEST_DATABASE_URL` e são ignorados (skipped) sem ela. **Atenção:** o esquema desse banco é apagado e recriado; a suíte recusa qualquer host que não seja local.

```bash
docker run -d --name siteauth-pg-teste -e POSTGRES_PASSWORD=<senha-descartavel> -e POSTGRES_DB=siteauth_test -p 55432:5432 postgres:16
export TEST_DATABASE_URL=postgresql://postgres:<senha-descartavel>@127.0.0.1:55432/siteauth_test
composer check
```

O CI (`.github/workflows/ci.yml`) roda lint, phpstan e phpunit com um Postgres 16 de serviço e, em outro job, o axe-core com Playwright (`tests/a11y`) contra o servidor local. As actions estão fixadas por SHA.

## Limites e pontos de atenção

- Na recuperação de senha, a resposta é idêntica, mas o tempo não é: quando a conta existe há uma gravação no banco e uma chamada ao provedor de e-mail. Quem medir tempo com precisão pode inferir a existência da conta. O limite de pedidos por IP e por e-mail reduz o alcance disso.
- Visitantes anônimos ganham uma linha em `sessoes` (por causa do token CSRF), com validade de 1 hora e limpeza oportunista.
- O IP vem de `x-vercel-forwarded-for`. Fora da Vercel, defina `TRUST_VERCEL_IP=0`, senão o cabeçalho poderia ser forjado.
- A verificação de e-mail não é obrigatória para entrar.
- O projeto ainda não foi validado numa implantação real na Vercel com o Neon e um provedor de e-mail; os testes cobrem a aplicação com o Postgres local e o `php -S`.

## Licença

MIT. Veja o arquivo [LICENSE](LICENSE). Autor: henrifernandez.
