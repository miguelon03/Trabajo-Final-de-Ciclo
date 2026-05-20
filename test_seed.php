<?php
$seed = include 'backend/seed/catalog_seed.php';
echo json_encode([
    'total_products' => count($seed['products'] ?? []),
    'first_3_slugs' => array_slice(array_map(fn($p) => $p['slug'], $seed['products'] ?? []), 0, 3),
]);
