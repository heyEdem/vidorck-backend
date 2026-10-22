<?php

namespace App\Modules\Checkout\Actions;

use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Http\Requests\CheckoutRequest;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Checkout\Paystack\PaystackClient;
use App\Modules\Checkout\Paystack\PaystackTransactionNotFound;
use App\Modules\Checkout\Paystack\PaystackUnavailable;
use App\Support\Http\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

final class RetryCheckout
{
    /** @return array{authorizationUrl: string, reference: string} */
    public function execute(string $reference): array
    {
        $parent = CheckoutAttempt::query()->where('paystack_reference', $reference)->first();
        if ($parent === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Not found.');
        }
        $this->assertEligible($parent);

        try {
            $verified = app(PaystackClient::class)->verify($parent->paystack_reference);
        } catch (PaystackTransactionNotFound) {
            $verified = null;
        } catch (PaystackUnavailable) {
            $this->providerUnavailable();
        }

        if ($verified !== null) {
            $providerStatus = $verified['status'] ?? null;
            if ($providerStatus === 'success') {
                app(HandleChargeSuccess::class)->execute($parent, $verified);
                $parent = $parent->fresh();
                if ($parent?->order()->exists() || $parent?->verified_at !== null) {
                    throw new ApiException(409, 'CHECKOUT_ALREADY_PAID', 'This checkout has already been paid.');
                }
                if ($parent?->getRawOriginal('status') === CheckoutAttemptStatus::MISMATCH->value) {
                    throw new ApiException(409, 'CHECKOUT_NOT_RETRYABLE', 'This checkout requires payment reconciliation.');
                }
            } elseif (is_string($providerStatus) && in_array($providerStatus, ['failed', 'reversed'], true)) {
                $parent->newQuery()->whereKey($parent->id)->whereNull('verified_at')->whereNotIn('status', [CheckoutAttemptStatus::SUCCEEDED->value, CheckoutAttemptStatus::MISMATCH->value])
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('orders')->whereColumn('orders.checkout_attempt_id', 'checkout_attempts.id'))
                    ->update(['status' => CheckoutAttemptStatus::FAILED->value, 'updated_at' => now()]);
            } elseif (is_string($providerStatus) && in_array($providerStatus, ['pending', 'ongoing', 'processing', 'queued'], true)) {
                throw new ApiException(409, 'CHECKOUT_PENDING', 'This checkout is still pending with the payment provider.');
            } elseif ($providerStatus === 'abandoned') {
                // Paystack confirms this reference will not proceed; local terminal eligibility still applies.
            } else {
                // Unknown authoritative states are handled as reconciliation mismatches and block another payment.
                app(HandleChargeSuccess::class)->execute($parent, $verified);
                throw new ApiException(409, 'CHECKOUT_NOT_RETRYABLE', 'This checkout requires payment reconciliation.');
            }
        }

        $parent = $parent->fresh();
        if ($parent === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Not found.');
        }
        $this->assertEligible($parent);
        $input = $this->inputFromSnapshot($parent);
        $this->validateSnapshot($input);

        try {
            return app(StartCheckout::class)->execute($input, $parent);
        } catch (UniqueConstraintViolationException $exception) {
            if (CheckoutAttempt::query()->where('parent_attempt_id', $parent->id)->exists()) {
                throw new ApiException(409, 'CHECKOUT_ALREADY_RETRIED', 'This checkout already has a retry.');
            }
            throw $exception;
        }
    }

    private function assertEligible(CheckoutAttempt $attempt): void
    {
        if ($attempt->verified_at !== null || $attempt->order()->exists() || $attempt->getRawOriginal('status') === CheckoutAttemptStatus::SUCCEEDED->value) {
            throw new ApiException(409, 'CHECKOUT_ALREADY_PAID', 'This checkout has already been paid.');
        }
        if ($attempt->getRawOriginal('status') === CheckoutAttemptStatus::MISMATCH->value) {
            throw new ApiException(409, 'CHECKOUT_NOT_RETRYABLE', 'This checkout requires payment reconciliation.');
        }
        if ($attempt->children()->exists()) {
            throw new ApiException(409, 'CHECKOUT_ALREADY_RETRIED', 'This checkout already has a retry.');
        }
        $status = $attempt->getRawOriginal('status');
        $eligible = in_array($status, [CheckoutAttemptStatus::FAILED->value, CheckoutAttemptStatus::ABANDONED->value], true)
            || ($status === CheckoutAttemptStatus::PENDING->value && Carbon::parse($attempt->getRawOriginal('expires_at'))->lessThanOrEqualTo(now()));
        if (! $eligible) {
            throw new ApiException(409, 'CHECKOUT_PENDING', 'This checkout is still pending.');
        }
    }

    /** @return array<string, mixed> */
    private function inputFromSnapshot(CheckoutAttempt $attempt): array
    {
        $customer = $attempt->getAttribute('customer_snapshot_json');
        $delivery = $attempt->getAttribute('delivery_snapshot_json');
        $snapshotItems = $attempt->getAttribute('items_snapshot_json');
        $customer = is_array($customer) ? $customer : [];
        $delivery = is_array($delivery) ? $delivery : [];
        $snapshotItems = is_array($snapshotItems) ? $snapshotItems : [];
        $items = array_map(static fn (array $line): array => [
            'slug' => $line['slug'] ?? null,
            'productCode' => $line['productCode'] ?? null,
            'quantity' => $line['quantity'] ?? null,
            'lineId' => $line['lineId'] ?? null,
            'parentLineId' => $line['parentLineId'] ?? null,
            'expectedUnitPriceMinor' => $line['unitPriceMinor'] ?? null,
        ], $snapshotItems);

        return [
            'fullName' => $customer['fullName'] ?? null,
            'phone' => $customer['phoneDisplay'] ?? $customer['phoneE164'] ?? null,
            'email' => $customer['email'] ?? null,
            'region' => $delivery['region'] ?? null,
            'area' => $delivery['area'] ?? null,
            'address' => $delivery['address'] ?? null,
            'landmark' => $delivery['landmark'] ?? null,
            'ghanaPostGps' => $delivery['ghanaPostGps'] ?? null,
            'policyAccepted' => true,
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $input */
    private function validateSnapshot(array $input): void
    {
        $validator = Validator::make($input, (new CheckoutRequest)->rules());
        if ($validator->fails()) {
            throw new ApiException(422, 'VALIDATION_ERROR', 'The original checkout details are no longer valid.', $validator->errors()->toArray());
        }
    }

    private function providerUnavailable(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Payment provider is temporarily unavailable.',
            'code' => 'PAYMENT_PROVIDER_UNAVAILABLE',
            'errors' => (object) [],
        ], 503));
    }
}
