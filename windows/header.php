<?php
function lmap_render_header(array $user): void
{
    echo '<header class="lmap-header">';
    echo '  <div class="brand">LMAP</div>';
    echo '  <nav class="lmap-topnav">';
    echo '    <div class="dropdown">Project ▾</div>';
    echo '    <div class="dropdown">View ▾</div>';
    echo '    <div class="dropdown">Data ▾</div>';
    echo '  </nav>';
    echo '  <div class="lmap-user">';
    echo '    <span>' . htmlspecialchars($user['username']) . '</span>';
    echo '    <a href="/auth/logout.php">Logout</a>';
    echo '  </div>';
    echo '</header>';
}
