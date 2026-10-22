<?php

use App\Support\Http\ApiResource;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

it('recursively emits camelCase resource keys while preserving lists and money', function () {
    $resource = new ApiResource([
        'product_code' => 'VHI-001',
        'hair_types' => [['display_name' => 'Natural']],
        'unit_price' => new Money(14300),
    ]);
    expect($resource->resolve(Request::create('/api/v1/products')))->toEqual([
        'productCode' => 'VHI-001',
        'hairTypes' => [['displayName' => 'Natural']],
        'unitPrice' => ['amountMinor' => 14300, 'currency' => 'GHS'],
    ]);
});

it('normalizes keys in subclasses and their collections', function () {
    $resource = new class(['product_code' => 'VHI-001']) extends ApiResource
    {
        public function toArray(Request $request): array
        {
            return ['product_code' => $this->resource['product_code']];
        }
    };

    expect($resource->resolve(Request::create('/')))->toBe(['productCode' => 'VHI-001']);
    expect($resource::collection([['product_code' => 'VHI-002']])->response()->getData(true))
        ->toBe(['data' => [['productCode' => 'VHI-002']]]);
});

it('uses the standard pagination meta', function () {
    $resource = ApiResource::collection(new LengthAwarePaginator(
        [['product_code' => 'VHI-001']], 31, 15, 2,
    ));
    expect($resource->response()->getData(true))->toBe([
        'data' => [['productCode' => 'VHI-001']],
        'meta' => ['page' => 2, 'perPage' => 15, 'total' => 31],
    ]);
});

it('normalizes nested Arrayable and JsonSerializable data', function () {
    $model = new class extends Model {};
    $model->setRawAttributes(['display_name' => 'Natural']);
    $resource = new ApiResource([
        'hair_types' => collect([$model]), 'unit_price' => new Money(14300),
    ]);
    expect($resource->resolve(Request::create('/')))->toBe([
        'hairTypes' => [['displayName' => 'Natural']],
        'unitPrice' => ['amountMinor' => 14300, 'currency' => 'GHS'],
    ]);
});

it('explicitly rejects pagination without the contract total', function (string $type) {
    $paginator = $type === 'simple'
        ? new Paginator([], 15)
        : new CursorPaginator([], 15);
    expect(fn () => ApiResource::collection($paginator))->toThrow(InvalidArgumentException::class);
})->with(['simple', 'cursor']);
