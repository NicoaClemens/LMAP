<?php
require __DIR__ . '/app.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This command must be run from the command line.\n";
    exit(1);
}

if ($argc < 3) {
    echo "Usage: php create_user.php <username> <password>\n";
    exit(1);
}

try {
    $user = lmap_create_user($argv[1], $argv[2]);
    echo "Created user: {$user['username']} (id={$user['id']})\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
