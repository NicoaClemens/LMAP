<?php
require __DIR__ . '/../auth/app.php';
lmap_require_login();

header('Content-Type: application/json; charset=utf-8');

function lmap_project_root(): string
{
    return dirname(__DIR__);
}

function lmap_data_directory(): string
{
    $config = lmap_project_config();
    $data_dir = $config['data']['directory'] ?? './data';
    $data_dir = trim((string) $data_dir);

    if ($data_dir === '') {
        $data_dir = './data';
    }

    $root = lmap_project_root();
    $is_absolute = str_starts_with($data_dir, '/') || preg_match('/^[A-Za-z]:[\\\/]/', $data_dir) === 1;

    if ($is_absolute) {
        $base_dir = $data_dir;
    } else {
        $base_dir = rtrim($root, '/\\') . '/' . ltrim($data_dir, './\\');
    }

    return rtrim($base_dir, '/\\');
}

function lmap_region_files(): array
{
    $data_dir = lmap_data_directory();
    $candidate_dirs = [];

    if (is_dir($data_dir . '/0')) {
        $candidate_dirs[] = $data_dir . '/0';
    }

    if (is_dir($data_dir)) {
        $candidate_dirs[] = $data_dir;
    }

    $files = [];
    foreach ($candidate_dirs as $dir) {
        $matches = glob($dir . '/*');
        if ($matches === false) {
            continue;
        }

        foreach ($matches as $path) {
            if (is_file($path) && strcasecmp(pathinfo($path, PATHINFO_EXTENSION), 'REGION') === 0) {
                $files[] = $path;
            }
        }
    }

    sort($files, SORT_STRING);
    return array_values(array_unique($files));
}

function lmap_region_path(): ?string
{
    $files = lmap_region_files();
    return $files[0] ?? null;
}

function lmap_python_edit_path(): string
{
    $root = dirname(__DIR__);
    $candidate = $root . '/.venv/Scripts/python.exe';
    if (is_file($candidate)) {
        return $candidate;
    }

    return PHP_BINARY;
}

function lmap_region_edit_payload(array $payload): array
{
    $region_path = lmap_region_path();
    if ($region_path === null || !is_file($region_path)) {
        throw new RuntimeException('No REGION file is available to edit.');
    }

    $edit_json = json_encode($payload, JSON_THROW_ON_ERROR);
    $python = lmap_python_edit_path();
    $command = escapeshellarg($python)
        . ' -m tools.edit '
        . '--region ' . escapeshellarg($region_path)
        . ' --edit-json ' . escapeshellarg($edit_json);

    $output = [];
    $status = 0;
    exec($command, $output, $status);

    if ($status !== 0) {
        $stderr = implode("\n", $output);
        throw new RuntimeException($stderr !== '' ? $stderr : 'Edit command failed.');
    }

    $stdout = implode("\n", $output);
    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Edit command returned invalid JSON.');
    }

    return $decoded;
}

try {
    $raw = file_get_contents('php://input');
    $payload = $raw === false || trim($raw) === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($payload)) {
        throw new InvalidArgumentException('JSON payload must decode to an object.');
    }

    $result = lmap_region_edit_payload($payload);
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
}
