<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    match ([$method, $action]) {
        ['POST', 'signup'] => handle_signup(),
        ['POST', 'login'] => handle_login(),
        default => send_error('Ação não permitida', 400),
    };
} catch (Exception $e) {
    send_error($e->getMessage(), 500);
}

function handle_signup(): void
{
    $data = json_decode(file_get_contents('php://input'), true);
    
    $email = trim($data['email'] ?? '');
    $password = trim($data['password'] ?? '');
    $confirm_password = trim($data['confirm_password'] ?? '');

    if (!$email || !$password || !$confirm_password) {
        send_error('Todos os campos são obrigatórios', 400);
    }

    if ($password !== $confirm_password) {
        send_error('As senhas não coincidem', 400);
    }

    if (strlen($password) < 6) {
        send_error('A senha deve ter no mínimo 6 caracteres', 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_error('Email inválido', 400);
    }

    $pdo = db();
    
    // Verificar se email já existe
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = $1');
    $stmt->execute([$email]);
    
    if ($stmt->fetch()) {
        send_error('Email já registrado', 400);
    }

    // Hash da senha com bcrypt
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // Inserir usuário
    $stmt = $pdo->prepare('INSERT INTO users (email, password, created_at) VALUES ($1, $2, NOW()) RETURNING id');
    $stmt->execute([$email, $hashed_password]);
    
    $user = $stmt->fetch();
    
    send_success('Usuário registrado com sucesso! Faça login para continuar.', 201);
}

function handle_login(): void
{
    $data = json_decode(file_get_contents('php://input'), true);
    
    $email = trim($data['email'] ?? '');
    $password = trim($data['password'] ?? '');

    if (!$email || !$password) {
        send_error('Email e senha são obrigatórios', 400);
    }

    $pdo = db();
    
    $stmt = $pdo->prepare('SELECT id, email, password FROM users WHERE email = $1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        send_error('Email ou senha inválidos', 401);
    }

    // Login bem-sucedido
    session_start();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email'] = $user['email'];

    send_success('Login realizado com sucesso!', 200, ['user_id' => $user['id'], 'email' => $user['email']]);
}

function send_success(string $message, int $code = 200, array $data = []): void
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => true, 'message' => $message], $data));
    exit;
}

function send_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}
?>
