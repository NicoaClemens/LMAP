<?php
require __DIR__ . '/../auth/app.php';
lmap_require_login();

header('Content-Type: application/json; charset=utf-8');

function lmap_api_list_data_directory(): string
{
    $config = lmap_project_config();
    $data_dir = $config['data']['directory'] ?? './data';
    $data_dir = trim((string) $data_dir);

    if ($data_dir === '') {
        $data_dir = './data';
    }

    $root = dirname(__DIR__);
    $is_absolute = str_starts_with($data_dir, '/') || preg_match('/^[A-Za-z]:[\\\/]/', $data_dir) === 1;

    if ($is_absolute) {
        $base_dir = $data_dir;
    } else {
        $base_dir = rtrim($root, '/\\') . '/' . ltrim($data_dir, './\\');
    }

    return rtrim($base_dir, '/\\');
}

function lmap_api_list_region_files(): array
{
    $data_dir = lmap_api_list_data_directory();
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

try {
    $regions = [];
    foreach (lmap_api_list_region_files() as $region_path) {
        $regions[] = [
            'path' => $region_path,
            'name' => basename($region_path),
        ];
    }

    echo json_encode([
        'ok' => true,
        'regions' => $regions,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
