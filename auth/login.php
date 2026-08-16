<?php
require __DIR__ . '/app.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $user = lmap_validate_login($username, $password);

    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        header('Location: /index.php');
        exit;
    }

    $error = 'Invalid username or password.';
}

if (!empty($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LMAP Login</title>
    <link rel="stylesheet" href="/assets/lmap.css">
</head>
<body class="login-page">
    <div class="login-box">
        <h1>LMAP</h1>
        <form method="post" action="/auth/login.php">
            <label>
                Username
                <input type="text" name="username" required>
            </label>
            <label>
                Password
                <input type="password" name="password" required>
            </label>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <button type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
