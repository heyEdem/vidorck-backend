<?php

namespace App\Modules\Checkout\Actions;

use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Models\DeliveryArea;
use App\Modules\Checkout\Paystack\PaystackClient;
use App\Modules\Checkout\Paystack\PaystackUnavailable;
use App\Support\Http\ApiException;
use App\Support\PhoneNumber;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StartCheckout
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{authorizationUrl: string, reference: string}
     */
    public function execute(array $input, ?CheckoutAttempt $parent = null): array
    {
        $area = $this->resolveArea((string) $input['area']);
        $priced = app(PriceCart::class)->price($input['items']);
        app(PriceCart::class)->applyParents($input['items'], $priced['lines']);
        $amount = 0;
        foreach ($priced['lines'] as $line) {
            if ($line['unitPriceMinor'] < 0 || $line['unitPriceMinor'] > intdiv(PHP_INT_MAX, $line['quantity'])) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'The cart total is too large.', ['items' => ['Reduce the cart total.']]);
            }
            $total = $line['unitPriceMinor'] * $line['quantity'];
            if ($total > PHP_INT_MAX - $amount) {
                throw new ApiException(422, 'VALIDATION_ERROR', 'The cart total is too large.', ['items' => ['Reduce the cart total.']]);
            }
            $amount += $total;
        }
        if ($amount <= 0) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'The cart total must be greater than zero.', ['items' => ['Select items with a payable price.']]);
        }

        $phone = PhoneNumber::fromGhanaInput((string) $input['phone']);
        $reference = 'VHI-'.Str::random(32);
        $attempt = DB::transaction(function () use ($input, $phone, $priced, $amount, $reference, $parent, $area): CheckoutAttempt {
            if ($parent !== null) {
                $freshParent = CheckoutAttempt::query()->whereKey($parent->id)->lockForUpdate()->first();
                if ($freshParent === null || $freshParent->verified_at !== null || $freshParent->order()->exists()
                    || $freshParent->getRawOriginal('status') === CheckoutAttemptStatus::SUCCEEDED->value) {
                    throw new ApiException(409, 'CHECKOUT_ALREADY_PAID', 'This checkout has already been paid.');
                }
                if ($freshParent->getRawOriginal('status') === CheckoutAttemptStatus::MISMATCH->value) {
                    throw new ApiException(409, 'CHECKOUT_NOT_RETRYABLE', 'This checkout requires payment reconciliation.');
                }
                if ($freshParent->children()->exists()) {
                    throw new ApiException(409, 'CHECKOUT_ALREADY_RETRIED', 'This checkout already has a retry.');
                }
                $terminal = in_array($freshParent->getRawOriginal('status'), [CheckoutAttemptStatus::FAILED->value, CheckoutAttemptStatus::ABANDONED->value], true)
                    || ($freshParent->getRawOriginal('status') === CheckoutAttemptStatus::PENDING->value && Carbon::parse($freshParent->getRawOriginal('expires_at'))->lessThanOrEqualTo(now()));
                if (! $terminal) {
                    throw new ApiException(409, 'CHECKOUT_PENDING', 'This checkout is still pending.');
                }
            }

            return CheckoutAttempt::query()->create([
                'paystack_reference' => $reference,
                'customer_snapshot_json' => ['fullName' => $input['fullName'], 'phoneE164' => $phone->e164, 'phoneDisplay' => $phone->display, 'email' => $input['email']],
                'delivery_snapshot_json' => ['region' => $input['region'], 'area' => $area->name, 'address' => $input['address'], 'landmark' => $input['landmark'], 'ghanaPostGps' => $input['ghanaPostGps']],
                'items_snapshot_json' => $priced['lines'],
                'amount_minor' => $amount,
                'currency' => 'GHS',
                'status' => CheckoutAttemptStatus::PENDING,
                'expires_at' => now()->addMinutes(60),
                'parent_attempt_id' => $parent?->id,
            ]);
        });

        $callback = config('services.paystack.callback_url') ?: rtrim((string) config('services.storefront.url'), '/').'/checkout/return';
        try {
            $data = app(PaystackClient::class)->initialize([
                'email' => $input['email'], 'amount' => $amount, 'currency' => 'GHS', 'reference' => $reference,
                'channels' => ['mobile_money', 'card'], 'callback_url' => $callback,
                'metadata' => ['attemptId' => $attempt->public_id],
            ]);
            if (($data['reference'] ?? null) !== $reference || ! is_string($data['authorization_url'] ?? null)
                || filter_var($data['authorization_url'], FILTER_VALIDATE_URL) === false
                || parse_url($data['authorization_url'], PHP_URL_SCHEME) !== 'https') {
                throw new PaystackUnavailable;
            }
        } catch (PaystackUnavailable) {
            CheckoutAttempt::query()->whereKey($attempt->id)->where('status', 'PENDING')->update(['status' => 'FAILED']);
            throw new HttpResponseException(response()->json(['message' => 'Payment provider is temporarily unavailable.', 'code' => 'PAYMENT_PROVIDER_UNAVAILABLE', 'errors' => (object) []], 503));
        }

        return ['authorizationUrl' => $data['authorization_url'], 'reference' => $reference];
    }

    private function resolveArea(string $areaInput): DeliveryArea
    {
        $needle = mb_strtolower(trim($areaInput));
        $area = DeliveryArea::query()->where('is_active', true)->get()->first(function (DeliveryArea $area) use ($needle): bool {
            if (mb_strtolower(trim($area->name)) === $needle) {
                return true;
            }
            $aliases = $area->getAttribute('aliases_json');
            if (! is_array($aliases)) {
                return false;
            }
            foreach ($aliases as $alias) {
                if (is_string($alias) && mb_strtolower(trim($alias)) === $needle) {
                    return true;
                }
            }

            return false;
        });
        if ($area === null) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'This area requires a special delivery arrangement before payment.', ['area' => ['Choose an accepted normal-delivery area.']]);
        }

        return $area;
    }
}
