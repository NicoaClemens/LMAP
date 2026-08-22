<?php
function lmap_data_directory(): string
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

function lmap_region_aspect_ratio(array $objects): float
{
    $min_x = INF;
    $min_y = INF;
    $max_x = -INF;
    $max_y = -INF;

    foreach ($objects as $obj) {
        if (!isset($obj['vectors']) || !is_array($obj['vectors'])) {
            continue;
        }

        foreach ($obj['vectors'] as $segment) {
            if (!isset($segment['x1'], $segment['y1'], $segment['x2'], $segment['y2'])) {
                continue;
            }

            $min_x = min($min_x, (float) $segment['x1'], (float) $segment['x2']);
            $min_y = min($min_y, (float) $segment['y1'], (float) $segment['y2']);
            $max_x = max($max_x, (float) $segment['x1'], (float) $segment['x2']);
            $max_y = max($max_y, (float) $segment['y1'], (float) $segment['y2']);
        }
    }

    if (!is_finite($min_x) || !is_finite($min_y) || !is_finite($max_x) || !is_finite($max_y)) {
        return 1.0;
    }

    $width = $max_x - $min_x;
    $height = $max_y - $min_y;

    if ($height <= 0.0) {
        return $width > 0.0 ? $width : 1.0;
    }

    return $width / $height;
}

function lmap_read_region($path)
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

$region_path = lmap_region_path();
?>
<div class="lmap-window">
    <canvas id="lmap-canvas" aria-label="LMAP object view"></canvas>
    <?php if ($region_path === null): ?>
        <div class="lmap-empty-state">No REGION file found in data/0. Build one with the project tool to see the map preview.</div>
    <?php endif; ?>
</div>

<script>
let regionData = [];
let aspectRatio = 1;
const canvas = document.getElementById('lmap-canvas');
const ctx = canvas.getContext('2d');

function resizeCanvas() {
    const ratio = window.devicePixelRatio || 1;
    const safeAspectRatio = Number.isFinite(aspectRatio) && aspectRatio > 0 ? aspectRatio : 1;
    const width = Math.max(640, Math.min(window.innerWidth - 32, 1200));
    const height = Math.max(400, Math.round(width / safeAspectRatio));
    canvas.width = Math.floor(width * ratio);
    canvas.height = Math.floor(height * ratio);
    canvas.style.width = width + 'px';
    canvas.style.height = height + 'px';
    canvas.style.aspectRatio = safeAspectRatio + ' / 1';
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
}

function getBounds() {
    const objects = Array.isArray(regionData) ? regionData : [];
    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;

    objects.forEach((obj) => {
        if (!Array.isArray(obj.vectors)) {
            return;
        }

        obj.vectors.forEach((segment) => {
            minX = Math.min(minX, segment.x1, segment.x2);
            minY = Math.min(minY, segment.y1, segment.y2);
            maxX = Math.max(maxX, segment.x1, segment.x2);
            maxY = Math.max(maxY, segment.y1, segment.y2);
        });
    });

    if (!Number.isFinite(minX)) {
        return null;
    }

    return { minX, minY, maxX, maxY };
}

function drawRegion() {
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    ctx.clearRect(0, 0, width, height);
    ctx.fillStyle = '#f8fafc';
    ctx.fillRect(0, 0, width, height);

    const objects = Array.isArray(regionData) ? regionData : [];
    if (!objects.length) {
        return;
    }

    const bounds = getBounds();
    if (!bounds) {
        return;
    }

    const pad = 20;
    const spanX = Math.max(bounds.maxX - bounds.minX, 0.0001);
    const spanY = Math.max(bounds.maxY - bounds.minY, 0.0001);
    const scaleX = (width - pad * 2) / spanX;
    const scaleY = (height - pad * 2) / spanY;
    const scale = Math.min(scaleX, scaleY);

    objects.forEach((obj, index) => {
        if (!Array.isArray(obj.vectors) || obj.vectors.length === 0) {
            return;
        }

        ctx.beginPath();
        obj.vectors.forEach((segment, segIndex) => {
            const x1 = (segment.x1 - bounds.minX) * scale + pad;
            const y1 = height - ((segment.y1 - bounds.minY) * scale + pad);
            const x2 = (segment.x2 - bounds.minX) * scale + pad;
            const y2 = height - ((segment.y2 - bounds.minY) * scale + pad);

            if (segIndex === 0) {
                ctx.moveTo(x1, y1);
            }

            ctx.lineTo(x2, y2);
        });

        ctx.strokeStyle = index % 2 === 0 ? '#1f2937' : '#475569';
        ctx.lineWidth = 1.2;
        ctx.stroke();
    });
}

async function loadRegion() {
    try {
        const response = await fetch('/api/read.php');
        if (!response.ok) {
            throw new Error('Unable to load region');
        }

        const payload = await response.json();
        if (!payload || !payload.ok || !Array.isArray(payload.region)) {
            return;
        }

        regionData = payload.region;

        const objects = Array.isArray(regionData) ? regionData : [];
        let minX = Infinity;
        let minY = Infinity;
        let maxX = -Infinity;
        let maxY = -Infinity;

        objects.forEach((obj) => {
            if (!Array.isArray(obj.vectors)) {
                return;
            }

            obj.vectors.forEach((segment) => {
                minX = Math.min(minX, segment.x1, segment.x2);
                minY = Math.min(minY, segment.y1, segment.y2);
                maxX = Math.max(maxX, segment.x1, segment.x2);
                maxY = Math.max(maxY, segment.y1, segment.y2);
            });
        });

        if (Number.isFinite(minX) && Number.isFinite(minY) && Number.isFinite(maxX) && Number.isFinite(maxY)) {
            const width = maxX - minX;
            const height = maxY - minY;
            aspectRatio = height > 0 ? width / height : (width > 0 ? width : 1);
        } else {
            aspectRatio = 1;
        }

        resizeCanvas();
        drawRegion();
    } catch (error) {
        console.error(error);
    }
}

window.addEventListener('resize', () => {
    resizeCanvas();
    drawRegion();
});

loadRegion();
</script>

<style>
    .lmap-window {
        display: block;
        width: 100%;
        padding: 0;
        margin: 0;
        background: #f8fafc;
        border: 1px solid #dfe7f1;
        box-sizing: border-box;
    }

    #lmap-canvas {
        display: block;
        width: 100%;
        height: auto;
        max-width: 1200px;
        background: #f8fafc;
    }

    .lmap-empty-state {
        padding: 1rem;
        color: #475569;
        font-size: 0.95rem;
        background: #f8fafc;
        border-top: 1px solid #dfe7f1;
    }
</style>
