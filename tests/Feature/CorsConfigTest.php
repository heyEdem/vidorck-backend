<?php

/** Loads config/cors.php with the given env values, restoring the previous ones afterwards. */
function corsConfigWith(array $env): array
{
    $previous = [];
    foreach ($env as $name => $value) {
        $previous[$name] = getenv($name);
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }
    try {
        return require config_path('cors.php');
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv("{$name}={$value}");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
    }
}

it('reads a comma-separated list of exact origins', function () {
    $cors = corsConfigWith(['CORS_ALLOWED_ORIGINS' => ' http://localhost:3000 , http://localhost:3100 ,', 'STOREFRONT_URL' => 'http://localhost:3000']);
    expect($cors['allowed_origins'])->toBe(['http://localhost:3000', 'http://localhost:3100']);
});

it('falls back to the storefront URL and never includes the admin link base', function () {
    $cors = corsConfigWith(['CORS_ALLOWED_ORIGINS' => null, 'STOREFRONT_URL' => 'https://vidorckhair.com', 'ADMIN_URL' => 'https://vidorckhair.com/admin']);
    expect($cors['allowed_origins'])->toBe(['https://vidorckhair.com'])
        ->and($cors['supports_credentials'])->toBeTrue();
});
