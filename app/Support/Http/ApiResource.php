<?php

namespace App\Support\Http;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use JsonSerializable;

class ApiResource extends JsonResource
{
    /**
     * @param  Request|null  $request
     * @return array<string|int, mixed>
     */
    public function resolve($request = null): array
    {
        return $this->camelCaseKeys(parent::resolve($request));
    }

    protected static function newCollection($resource): ApiResourceCollection
    {
        return new ApiResourceCollection($resource, static::class);
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @return array<string|int, mixed>
     */
    private function camelCaseKeys(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if ($value instanceof Arrayable) {
                $value = $value->toArray();
            } elseif ($value instanceof JsonSerializable) {
                $value = $value->jsonSerialize();
            }

            $result[is_string($key) ? Str::camel($key) : $key] = is_array($value)
                ? $this->camelCaseKeys($value) : $value;
        }

        return $result;
    }
}
