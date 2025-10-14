<?php
/**
 * Safely retrieve a GET parameter.
 */
function get_query_param(string $key, ?callable $sanitizer = null): ?string
{
    if (!isset($_GET[$key])) {
        return null;
    }

    $value = trim((string) $_GET[$key]);
    if ($sanitizer) {
        $value = $sanitizer($value);
    }

    return $value;
}

/**
 * Emit a JSON response with the given HTTP status code.
 */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Format a numeric variation percentage safely.
 */
function calculate_variation(float $valueA, float $valueB): float
{
    if ($valueA == 0.0) {
        return $valueB == 0.0 ? 0.0 : 100.0;
    }

    return (($valueB - $valueA) / $valueA) * 100.0;
}
