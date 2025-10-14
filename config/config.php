<?php
// Database configuration and PDO connection helper.
// Update the default values or use environment variables to set credentials securely.

$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'u371107598_cnm';
$DB_USER = getenv('DB_USER') ?: 'u371107598_usercnm';
$DB_PASS = getenv('DB_PASS') ?: '@Amora051307';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao conectar ao banco de dados.',
        'details' => $e->getMessage(),
    ]);
    exit;
}
