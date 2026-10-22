<?php

/** @return list<string> */
function orderStatusWriteViolations(string $source): array
{
    $touchesOrders = preg_match('/Orders\\\\Models\\\\Order;|namespace App\\\\Modules\\\\Orders\\\\Models;|[\'"]orders[\'"]/', $source) === 1;
    if (! $touchesOrders) {
        return [];
    }
    $patterns = [
        '/->status\s*=[^=]/',
        '/(?:update|forceFill|fill|updateOrCreate|upsert|insert|setAttribute|increment|decrement)\w*\s*\([^;]*OrderStatus::/s',
        '/\bOrder::[^;]*->(?:update|upsert|insert|fill|forceFill)\w*\s*\([^;]*status/s',
        '/\$order\w*->(?:forceFill|fill|update|setAttribute|setRawAttributes)\w*\s*\([^;]*status/s',
        '/table\(\s*[\'"]orders[\'"]\s*\)[^;]*->(?:update|upsert|insert|delete)/s',
        '/DB::(?:update|statement|unprepared)\s*\([^;]*orders/is',
    ];
    $hits = [];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $source, $m) === 1) {
            $hits[] = trim($m[0]);
        }
    }

    return $hits;
}

it('detects status writes in the scanner itself', function () {
    $uses = "use App\\Modules\\Orders\\Models\\Order;\n";
    expect(orderStatusWriteViolations($uses.'$o->status = OrderStatus::DELIVERED;'))->not->toBeEmpty()
        ->and(orderStatusWriteViolations($uses.'$order->forceFill([\'status\' => $x])->save();'))->not->toBeEmpty()
        ->and(orderStatusWriteViolations($uses.'Order::query()->update([\'status\' => $x]);'))->not->toBeEmpty()
        ->and(orderStatusWriteViolations('DB::table(\'orders\')->update([\'a\' => 1]);'))->not->toBeEmpty()
        ->and(orderStatusWriteViolations($uses.'Order::query()->create([\'status\' => OrderStatus::PAID]);'))->toBeEmpty();
});

it('lets only OrderStateMachine write orders.status after creation', function () {
    $allowed = [realpath(app_path('Modules/Orders/OrderStateMachine.php'))];
    // Order model hooks only throw on status changes; they never write.
    $allowed[] = realpath(app_path('Modules/Orders/Models/Order.php'));
    $violations = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || in_array($file->getRealPath(), $allowed, true)) {
            continue;
        }
        foreach (orderStatusWriteViolations((string) file_get_contents($file->getRealPath())) as $hit) {
            $violations[] = str_replace(base_path().'/', '', $file->getRealPath()).': '.$hit;
        }
    }

    expect($violations)->toBe([]);
});
