<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

try {
    $pdo->query('SELECT 1');
    json_response(['status' => 'ok']);
} catch (PDOException $e) {
    json_response([
        'status' => 'error',
        'details' => $e->getMessage(),
    ], 500);
}
