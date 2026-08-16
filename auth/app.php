<?php
session_start();

function lmap_project_root(): string
{
    return dirname(__DIR__);
}

function lmap_project_config_path(): string
{
    return lmap_project_root() . '/project.yaml';
}

function lmap_project_config(): array
{
    $config_path = lmap_project_config_path();
    if (!is_file($config_path)) {
        return [];
    }

    $lines = file($config_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $config = [];
    $section = null;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (preg_match('/^([A-Za-z0-9_-]+):\s*(?:#.*)?$/', $trimmed, $matches)) {
            $section = $matches[1];
            $config[$section] = [];
            continue;
        }

        if ($section === null) {
            continue;
        }

        if (preg_match('/^\s+([A-Za-z0-9_-]+):\s*(.*?)\s*(?:#.*)?$/', $line, $matches)) {
            $key = $matches[1];
            $value = trim($matches[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && $value[strlen($value) - 1] === '"') || ($value[0] === '\'' && $value[strlen($value) - 1] === '\''))) {
                $value = substr($value, 1, -1);
            }
            $config[$section][$key] = $value;
        }
    }

    return $config;
}

function lmap_db_path(): string
{
    $config = lmap_project_config();
    $data_dir = $config['data']['directory'] ?? './data';
    $data_dir = trim((string) $data_dir);

    if ($data_dir === '') {
        $data_dir = './data';
    }

    $root = lmap_project_root();
    $is_absolute = str_starts_with($data_dir, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $data_dir) === 1;

    if ($is_absolute) {
        $base_dir = $data_dir;
    } else {
        $base_dir = rtrim($root, '/\\') . '/' . ltrim($data_dir, './\\');
    }

    $base_dir = preg_replace('#[\\/]$#', '', $base_dir);
    return rtrim($base_dir, '/\\') . '/db/lmap.sqlite';
}

function lmap_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db_dir = dirname(lmap_db_path());
    if (!is_dir($db_dir)) {
        mkdir($db_dir, 0777, true);
    }

    $pdo = new PDO('sqlite:' . lmap_db_path());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
        . 'username TEXT NOT NULL UNIQUE,'
        . 'password_hash TEXT NOT NULL,'
        . 'created_at INTEGER NOT NULL'
        . ')'
    );

    return $pdo;
}

function lmap_require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /auth/login.php');
        exit;
    }
}

function lmap_current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $pdo = lmap_db();
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
    ];
}

function lmap_find_user_by_username(string $username): ?array
{
    $pdo = lmap_db();
    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => trim($username)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ? [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'password_hash' => (string) $user['password_hash'],
    ] : null;
}

function lmap_create_user(string $username, string $password): array
{
    $username = trim($username);
    if ($username === '') {
        throw new InvalidArgumentException('Username is required.');
    }

    if (strlen($password) < 6) {
        throw new InvalidArgumentException('Password must be at least 6 characters.');
    }

    $existing = lmap_find_user_by_username($username);
    if ($existing) {
        throw new InvalidArgumentException('That username already exists.');
    }

    $pdo = lmap_db();
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, created_at) VALUES (:username, :password_hash, :created_at)'
    );
    $stmt->execute([
        ':username' => $username,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':created_at' => time(),
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'username' => $username,
    ];
}

function lmap_validate_login(string $username, string $password): ?array
{
    $user = lmap_find_user_by_username($username);
    if (!$user) {
        return null;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return null;
    }

    return [
        'id' => $user['id'],
        'username' => $user['username'],
    ];
}


