<?php

declare(strict_types=1);

use App\Support\Money;
use Tests\Fixtures\WeakMoneyCaller;

it('serializes integer pesewas as GHS money', function () {
    $money = new Money(14300);
    expect($money->amountMinor)->toBe(14300)
        ->and($money->currency)->toBe('GHS')
        ->and(json_decode(json_encode($money), true))->toBe(['amountMinor' => 14300, 'currency' => 'GHS']);
});

it('supports zero and negative integer amounts without rounding', function (int $amount) {
    expect((new Money($amount))->amountMinor)->toBe($amount);
})->with([0, -100, PHP_INT_MAX]);

it('rejects floating point money', function () {
    expect(fn () => new Money(1.5))->toThrow(TypeError::class);
});

it('rejects non-integer amounts from callers without strict typing', function (mixed $amount) {
    expect(fn () => WeakMoneyCaller::create($amount))->toThrow(TypeError::class);
})->with([1.5, '14300', true, null]);
