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

function lmap_read_region($path)
{
    if (!is_file($path)) {
        return [];
    }

    $bytes = file_get_contents($path);
    if ($bytes === false || strlen($bytes) < 8) {
        return [];
    }

    $region_header = unpack('a4magic Icount', substr($bytes, 0, 8));
    if (($region_header['magic'] ?? '') !== 'REGN') {
        return [];
    }

    $offset = 8;
    $entries = [];
    $count = (int) ($region_header['count'] ?? 0);

    for ($i = 0; $i < $count; $i++) {
        if ($offset + 48 > strlen($bytes)) {
            break;
        }

        $segment = unpack('a4magic a16uuid Cversion Cflags Ivec_count fbx1/fby1/fbx2/fby2 fscale', substr($bytes, $offset, 48));
        $offset += 48;

        if (($segment['magic'] ?? '') !== 'VECS') {
            continue;
        }

        $vectors = [];
        $vector_count = (int) ($segment['vec_count'] ?? 0);
        $record_size = 16;

        for ($j = 0; $j < $vector_count; $j++) {
            if ($offset + $record_size > strlen($bytes)) {
                break;
            }

            $record = unpack('ffff', substr($bytes, $offset, 16));
            $offset += 16;

            $vectors[] = [
                'x1' => (float) ($record[1] ?? 0.0),
                'y1' => (float) ($record[2] ?? 0.0),
                'x2' => (float) ($record[3] ?? 0.0),
                'y2' => (float) ($record[4] ?? 0.0),
            ];
        }

        $entries[] = [
            'uuid' => bin2hex(($segment['uuid'] ?? '')),
            'version' => (int) ($segment['version'] ?? 0),
            'flags' => (int) ($segment['flags'] ?? 0),
            'count' => $vector_count,
            'bounds' => [
                'x1' => (float) ($segment['bx1'] ?? 0.0),
                'y1' => (float) ($segment['by1'] ?? 0.0),
                'x2' => (float) ($segment['bx2'] ?? 0.0),
                'y2' => (float) ($segment['by2'] ?? 0.0),
            ],
            'scale' => (float) ($segment['scale'] ?? 1.0),
            'vectors' => $vectors,
        ];
    }

    return $entries;
}

$region_path = lmap_region_path();
$objects = $region_path ? lmap_read_region($region_path) : [];
$encoded_objects = json_encode($objects, JSON_THROW_ON_ERROR);
?>
<div class="lmap-window">
    <canvas id="lmap-canvas" aria-label="LMAP object view"></canvas>
    <?php if ($region_path === null): ?>
        <div class="lmap-empty-state">No REGION file found in data/0. Build one with the project tool to see the map preview.</div>
    <?php endif; ?>
</div>

<script>
const regionData = <?php echo $encoded_objects; ?>;
const canvas = document.getElementById('lmap-canvas');
const ctx = canvas.getContext('2d');

function resizeCanvas() {
    const ratio = window.devicePixelRatio || 1;
    const width = Math.max(640, Math.min(window.innerWidth - 32, 1200));
    const height = Math.max(400, Math.round(width / 2));
    canvas.width = Math.floor(width * ratio);
    canvas.height = Math.floor(height * ratio);
    canvas.style.width = width + 'px';
    canvas.style.height = height + 'px';
    canvas.style.aspectRatio = '2 / 1';
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

window.addEventListener('resize', () => {
    resizeCanvas();
    drawRegion();
});

resizeCanvas();
drawRegion();
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
        height: 100%;
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
