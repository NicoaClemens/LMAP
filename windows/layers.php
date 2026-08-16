<?php
function lmap_render_layers(): void
{
    echo '<aside class="lmap-sidebar left">';
    echo '  <div class="panel-title">Layers</div>';
    echo '  <div class="layer-list">';
    echo '    <div class="layer-item"><label><input type="checkbox" checked> Terrain</label></div>';
    echo '    <div class="layer-item"><label><input type="checkbox" checked> Borders</label></div>';
    echo '    <div class="layer-item"><label><input type="checkbox" checked> Water</label></div>';
    echo '    <div class="layer-item"><label><input type="checkbox"> POIs</label></div>';
    echo '  </div>';
    echo '</aside>';
}
