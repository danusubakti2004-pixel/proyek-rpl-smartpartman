<?php
declare(strict_types=1);

/**
 * CLI: menghasilkan APP_KEY acak (64 karakter hex) untuk file .env.
 *
 * Pemakaian: php database\keygen.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya untuk CLI.');
}

echo bin2hex(random_bytes(32)) . "\n";
