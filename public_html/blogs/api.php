<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!db_ready()) {
    echo json_encode(['posts' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = max(1, min(6, (int) ($_GET['limit'] ?? 3)));
$posts = array_map(static function (array $p): array {
    return [
        'title' => $p['title'],
        'slug' => $p['slug'],
        'excerpt' => $p['excerpt'],
        'date' => format_date($p['published_at'] ?? ''),
        'has_pdf' => $p['pdf_path'] !== '',
        'url' => 'blogs/ver.php?slug=' . rawurlencode($p['slug']),
    ];
}, published_posts($limit));

echo json_encode(['posts' => $posts], JSON_UNESCAPED_UNICODE);
