<?php

use App\Support\Http\ApiException;

it('keeps domain API errors in the client-error range', function (int $status) {
    expect(fn () => new ApiException($status, 'INVALID', 'Invalid request.'))
        ->toThrow(InvalidArgumentException::class);
})->with([200, 399, 500, 503]);
