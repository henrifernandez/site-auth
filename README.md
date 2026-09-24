# Site Auth

Sistema de autenticação completo com cadastro e login usando PHP, PostgreSQL e JavaScript.

## Funcionalidades

- ✅ Cadastro de usuários com validação
- ✅ Login com autenticação segura (bcrypt)
- ✅ Dashboard do usuário
- ✅ Design responsivo com CSS moderno
- ✅ Banco de dados PostgreSQL (Neon)
- ✅ API em PHP

## Setup

### 1. Configurar Banco de Dados

1. Acesse o painel do Neon em https://console.neon.tech
2. Copie a `DATABASE_URL` do seu projeto
3. Cole no arquivo `.env`:

```env
DATABASE_URL=postgresql://seu_usuario:sua_senha@ep-xxxx.neon.tech/dbname?sslmode=require&channel_binding=require
```

### 2. Executar Script SQL

Execute o conteúdo do arquivo `init.sql` no Neon SQL Editor para criar as tabelas:

```sql
-- Copia o conteúdo de init.sql e cola no Neon SQL Editor
```

### 3. Testar Localmente

```bash
# Iniciar servidor PHP
php -S localhost:8000

# Abrir no navegador
http://localhost:8000/index.html
```

## Estrutura do Projeto

```
site-auth-php/
├── public/
│   ├── index.html      # Página de login/cadastro
│   ├── dashboard.html  # Página do usuário autenticado
│   ├── api.php        # API de autenticação
│   └── index.php      # (Deprecated - usar index.html)
├── db.php             # Conexão com banco de dados
├── .env               # Variáveis de ambiente
└── init.sql           # Script de inicialização
```

## Fluxo de Autenticação

1. **Cadastro**: 
   - Email + Senha → Validação → Hash com bcrypt → Salvar no BD

2. **Login**:
   - Email + Senha → Buscar no BD → Verificar hash → Retornar token

3. **Dashboard**:
   - Dados armazenados em localStorage → Exibir informações

## Endpoints da API

### POST `/api.php?action=signup`
Cadastra um novo usuário

**Body:**
```json
{
  "email": "user@example.com",
  "password": "senha123",
  "confirm_password": "senha123"
}
```

**Respostas:**
- `201` - Sucesso
- `400` - Erro de validação

### POST `/api.php?action=login`
Realiza login de usuário

**Body:**
```json
{
  "email": "user@example.com",
  "password": "senha123"
}
```

**Respostas:**
- `200` - Login bem-sucedido
- `401` - Credenciais inválidas

## Deploy no Vercel

1. Fazer push do código para GitHub
2. No Vercel, importar o repositório
3. Adicionar variáveis de ambiente:
   - `DATABASE_URL` = sua connection string do Neon
4. Deploy automático

## Segurança

- Senhas hashadas com bcrypt (PASSWORD_BCRYPT)
- Validação de email com FILTER_VALIDATE_EMAIL
- PDO com prepared statements (proteção contra SQL injection)
- CORS e headers de segurança
- Mínimo 6 caracteres de senha

## Tecnologias

- PHP 7.4+
- PostgreSQL (Neon)
- HTML5 + CSS3
- JavaScript (Fetch API)
- bcrypt para hash de senhas

## Autor

Henri Fernandez

## Licença

MIT
