<?php
function lmap_render_controls(): void
{
    echo '<aside class="lmap-sidebar right">';
    echo '  <div class="panel-title">Tools</div>';
    echo '  <div class="controls-list">';
    echo '    <button class="control-btn" type="button">Edit Border</button>';
    echo '    <button class="control-btn" type="button">New Object</button>';
    echo '    <button class="control-btn" type="button">Add POI</button>';
    echo '    <button class="control-btn" type="button">Save</button>';
    echo '  </div>';
    echo '</aside>';
}
