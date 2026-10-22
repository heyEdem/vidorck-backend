<?php

namespace App\Modules\Catalog\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class RevalidateStorefront implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 15;

    /** @var list<int> */
    public array $backoff = [10, 60, 180];

    /** @param list<string> $tags */
    public function __construct(public array $tags) {}

    public function handle(): void
    {
        $url = config('services.storefront.revalidate_url');
        $secret = config('services.storefront.revalidate_secret');
        if (! is_string($url) || $url === '') {
            return;
        }
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Storefront revalidation is not configured.');
        }

        $response = Http::withoutRedirecting()
            ->timeout(10)
            ->withHeaders(['x-revalidate-secret' => $secret])
            ->post($url, ['tags' => array_values(array_unique($this->tags))]);
        $response->throw();
        if (! $response->successful()) {
            throw new RuntimeException('Storefront revalidation returned a non-success status.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Storefront revalidation failed after retries.', [
            'tags' => $this->tags,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
