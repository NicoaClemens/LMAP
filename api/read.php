<?php
require __DIR__ . '/../auth/app.php';
lmap_require_login();

header('Content-Type: application/json; charset=utf-8');

function lmap_api_data_directory(): string
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

function lmap_api_region_files(): array
{
    $data_dir = lmap_api_data_directory();
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

function lmap_api_read_region(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $bytes = file_get_contents($path);
    if ($bytes === false || strlen($bytes) < 8) {
        return [];
    }

    $magic = substr($bytes, 0, 4);
    if ($magic !== 'REGN') {
        return [];
    }

    $count = unpack('V', substr($bytes, 4, 4));
    $count = (int) ($count[1] ?? 0);
    $offset = 8;
    $entries = [];

    for ($i = 0; $i < $count; $i++) {
        if ($offset + 48 > strlen($bytes)) {
            break;
        }

        $segment = substr($bytes, $offset, 48);
        $offset += 48;

        $segment_magic = substr($segment, 0, 4);
        if ($segment_magic !== 'VECS') {
            continue;
        }

        $uuid = substr($segment, 4, 16);
        $version = unpack('v', substr($segment, 20, 2))[1];
        $flags = unpack('v', substr($segment, 22, 2))[1];
        $vec_count = unpack('V', substr($segment, 24, 4))[1];
        $float_values = array_values(unpack('g*', substr($segment, 28, 20)));

        while (count($float_values) < 5) {
            $float_values[] = 0.0;
        }

        $bounds = [
            'x1' => (float) $float_values[0],
            'y1' => (float) $float_values[1],
            'x2' => (float) $float_values[2],
            'y2' => (float) $float_values[3],
        ];
        $scale = (float) $float_values[4];

        $vectors = [];
        for ($j = 0; $j < $vec_count; $j++) {
            if ($offset + 16 > strlen($bytes)) {
                break;
            }

            $record = substr($bytes, $offset, 16);
            $offset += 16;
            $values = array_values(unpack('g*', $record));

            while (count($values) < 4) {
                $values[] = 0.0;
            }

            $vectors[] = [
                'x1' => (float) $values[0],
                'y1' => (float) $values[1],
                'x2' => (float) $values[2],
                'y2' => (float) $values[3],
            ];
        }

        $entries[] = [
            'uuid' => bin2hex($uuid),
            'version' => (int) $version,
            'flags' => (int) $flags,
            'count' => (int) $vec_count,
            'bounds' => $bounds,
            'scale' => $scale,
            'vectors' => $vectors,
        ];
    }

    return $entries;
}

try {
    $requested_path = $_GET['path'] ?? null;
    $candidate_paths = [];

    if (is_string($requested_path) && trim($requested_path) !== '') {
        $candidate_paths[] = trim($requested_path);
    }

    foreach (lmap_api_region_files() as $region_path) {
        $candidate_paths[] = $region_path;
    }

    $path = null;
    foreach (array_values(array_unique($candidate_paths)) as $candidate) {
        if (is_file($candidate)) {
            $path = $candidate;
            break;
        }
    }

    if ($path === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'No REGION file exists.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $region = lmap_api_read_region($path);
    echo json_encode([
        'ok' => true,
        'path' => $path,
        'region' => $region,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
