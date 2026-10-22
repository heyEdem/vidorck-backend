<?php

namespace App\Support\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

class ApiResourceCollection extends AnonymousResourceCollection
{
    public function __construct($resource, $collects)
    {
        if (($resource instanceof AbstractPaginator && ! $resource instanceof LengthAwarePaginator)
            || $resource instanceof AbstractCursorPaginator) {
            throw new InvalidArgumentException('API pagination requires a length-aware paginator with totals.');
        }

        parent::__construct($resource, $collects);
    }

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array{meta: array{page: int, perPage: int, total: int}}
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return ['meta' => [
            'page' => (int) $paginated['current_page'],
            'perPage' => (int) $paginated['per_page'],
            'total' => (int) $paginated['total'],
        ]];
    }
}
