<?php

namespace App\Modules\Orders\Actions;

use App\Modules\Catalog\Models\Bundle;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Actions\ListReconciliationAttempts;
use App\Modules\Checkout\Enums\CheckoutAttemptStatus;
use App\Modules\Checkout\Models\CheckoutAttempt;
use App\Modules\Complaints\Models\Complaint;
use App\Modules\Customers\Models\Customer;
use App\Modules\Notifications\Actions\CountFailedNotifications;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundStatus;
use App\Modules\Orders\Enums\RefundTransactionStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\RefundRequest;
use App\Modules\Orders\Models\RefundTransaction;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Models\StaffUser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/** Admin dashboard summary (product-spec §6.8, website-spec §17.2). */
final class BuildDashboard
{
    public const TIMEZONE = 'Africa/Accra';

    /** @return array<string, mixed> */
    public function execute(?CarbonImmutable $now = null, string $period = 'today', ?string $from = null, ?string $to = null, ?StaffUser $staff = null): array
    {
        $day = ($now ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE)->startOfDay();
        [$start, $end] = match ($period) {
            'today' => [$day, $day->addDay()],
            'week' => [$day->startOfWeek(CarbonInterface::MONDAY), $day->startOfWeek(CarbonInterface::MONDAY)->addWeek()],
            'month' => [$day->startOfMonth(), $day->startOfMonth()->addMonth()],
            'year' => [$day->startOfYear(), $day->startOfYear()->addYear()],
            'custom' => [CarbonImmutable::parse($from, self::TIMEZONE)->startOfDay(), CarbonImmutable::parse($to, self::TIMEZONE)->startOfDay()->addDay()],
            default => throw new \InvalidArgumentException('Unsupported dashboard period.'),
        };
        $can = static fn (Permission $permission): bool => $staff instanceof StaffUser && $staff->is_active && $staff->role->allows($permission);
        $data = [
            'date' => $day->toDateString(), 'timezone' => self::TIMEZONE, 'period' => $period,
            'startDate' => $start->toDateString(), 'endDate' => $end->subDay()->toDateString(),
        ];

        if ($can(Permission::VIEW_ORDERS) || $can(Permission::VIEW_SALES_FIGURES)) {
            $selected = $this->sales($start, $end);
            $today = $period === 'today' ? $selected : $this->sales($day, $day->addDay());
            if ($can(Permission::VIEW_ORDERS)) {
                $data['paidOrders'] = $selected['count'];
                $data['paidOrdersToday'] = $today['count'];
                $count = static fn (OrderStatus $status): int => Order::query()->where('status', $status->value)->count();
                $data['processingCount'] = $count(OrderStatus::PROCESSING);
                $data['readyForDispatchCount'] = $count(OrderStatus::READY_FOR_DISPATCH);
                $data['dispatchedCount'] = $count(OrderStatus::DISPATCHED);
                $metricsEnd = $period === 'custom' ? $end : CarbonImmutable::createFromTimestamp(min($end->getTimestamp(), $day->addDay()->getTimestamp()), self::TIMEZONE);
                $metricsStart = $start;
                $previousStart = $this->previousStart($period, $metricsStart, $metricsEnd);
                $previousEnd = $previousStart->addDays(max(1, $metricsStart->diffInDays($metricsEnd)));
                if ($previousEnd > $metricsStart) {
                    $previousEnd = $metricsStart;
                }
                $queue = $this->attentionQueue($now ?? CarbonImmutable::now(), $can(Permission::VIEW_RECONCILIATION), $can(Permission::VIEW_REFUNDS), $can(Permission::VIEW_CUSTOMERS));
                $data['attentionSummary'] = $this->attentionSummary($queue, $can(Permission::VIEW_REFUNDS), $can(Permission::VIEW_RECONCILIATION));
                $data['attentionQueue'] = $this->limitAttentionQueue($queue);
                $data['checkoutOutcomes'] = $this->checkoutOutcomes($metricsStart, $metricsEnd, $can(Permission::VIEW_SALES_FIGURES), $can(Permission::VIEW_RECONCILIATION));
                $data['fulfillment'] = $this->fulfillment($metricsStart, $metricsEnd);
                $data['fulfillmentComparison'] = $this->fulfillment($previousStart, $previousEnd);
                $data['catalogue'] = $this->catalogue($metricsStart, $metricsEnd, $can(Permission::VIEW_SALES_FIGURES));
            }
            if ($can(Permission::VIEW_SALES_FIGURES)) {
                $data['salesMinor'] = $selected['sales'];
                $data['salesTodayMinor'] = $today['sales'];
                $data['currency'] = 'GHS';
                $metricsEnd = $period === 'custom' ? $end : CarbonImmutable::createFromTimestamp(min($end->getTimestamp(), $day->addDay()->getTimestamp()), self::TIMEZONE);
                $previousStart = $this->previousStart($period, $start, $metricsEnd);
                $previousEnd = $previousStart->addDays(max(1, $start->diffInDays($metricsEnd)));
                if ($previousEnd > $start) {
                    $previousEnd = $start;
                }
                $storage = (string) config('app.timezone');
                $hasPrevious = Order::query()->where('paid_at', '>=', $previousStart->setTimezone($storage))
                    ->where('paid_at', '<', $previousEnd->setTimezone($storage))->exists();
                $data['range'] = [
                    'current' => $this->rangeMetrics($start, $metricsEnd, $can(Permission::VIEW_CUSTOMERS)),
                    'previous' => $hasPrevious ? $this->rangeMetrics($previousStart, $previousEnd, false) : null,
                ];
            }
        }

        if ($can(Permission::VIEW_REFUNDS)) {
            $data['openRefundRequests'] = RefundRequest::query()
                ->whereIn('status', array_map(static fn (RefundStatus $s): string => $s->value, RefundStatus::open()))->count();
            $data['refunds'] = $this->refundMetrics($start, $end, $can(Permission::VIEW_SALES_FIGURES), $can(Permission::VIEW_RECONCILIATION));
        }
        if ($can(Permission::VIEW_RECONCILIATION)) {
            $data['reconciliationCount'] = app(ListReconciliationAttempts::class)->query()->count();
            $data['refundReconciliationCount'] = app(ListRefunds::class)->reconciliationCount();
            $data['failedNotificationCount'] = app(CountFailedNotifications::class)->execute();
        }
        if ($can(Permission::VIEW_COMPLAINTS)) {
            $data['newComplaints'] = Complaint::query()->where('status', 'NEW')->count();
            $storage = (string) config('app.timezone');
            $data['complaintsWaiting'] = Complaint::query()->where('status', 'NEW')
                ->where('created_at', '<', ($now ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE)->subHours(24)->setTimezone($storage))->count();
        }

        return $data;
    }

    /** @return array{count: int, sales: int} */
    private function sales(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $storage = (string) config('app.timezone');
        $totals = Order::query()->where('paid_at', '>=', $start->setTimezone($storage))->where('paid_at', '<', $end->setTimezone($storage))
            ->whereNotIn('status', [OrderStatus::CANCELLED->value, OrderStatus::REFUNDED->value])
            ->selectRaw('COUNT(*) AS paid_count, COALESCE(SUM(total_paid_minor), 0) AS sales_minor')->toBase()->first();

        return ['count' => (int) $totals->paid_count, 'sales' => (int) $totals->sales_minor];
    }

    /** @return array<string, mixed> */
    private function rangeMetrics(CarbonImmutable $start, CarbonImmutable $end, bool $includeCustomers = true): array
    {
        $storage = (string) config('app.timezone');
        $paid = Order::query()->where('paid_at', '>=', $start->setTimezone($storage))->where('paid_at', '<', $end->setTimezone($storage));
        $received = (int) (clone $paid)->sum('total_paid_minor');
        $paidCount = (int) (clone $paid)->count();
        $refundQuery = RefundTransaction::query()->where('status', RefundTransactionStatus::PROCESSED->value)
            ->where('created_at', '>=', $start->setTimezone($storage))->where('created_at', '<', $end->setTimezone($storage));
        $refunds = (int) (clone $refundQuery)->sum('amount_minor');
        $result = [
            'startDate' => $start->toDateString(), 'endDate' => $end->subDay()->toDateString(),
            'netSalesMinor' => $received - $refunds, 'receivedMinor' => $received, 'refundsMinor' => $refunds,
            'paidOrders' => $paidCount, 'averageOrderValueMinor' => $paidCount > 0 ? (int) round($received / $paidCount) : 0,
            'refundCount' => (clone $refundQuery)->count(),
        ];
        $customerOrders = (clone $paid)->whereNotIn('status', [OrderStatus::CANCELLED->value, OrderStatus::REFUNDED->value]);
        $customers = (clone $customerOrders)->select('customer_id')->selectRaw('COUNT(*) AS orders, SUM(total_paid_minor) AS amount_minor')->groupBy('customer_id')->toBase()->get();
        $returningIds = Order::query()->whereIn('customer_id', $customers->pluck('customer_id'))
            ->where('paid_at', '<', $start->setTimezone($storage))->whereNotIn('status', [OrderStatus::CANCELLED->value, OrderStatus::REFUNDED->value])
            ->distinct()->pluck('customer_id')->all();
        $returning = $customers->filter(static fn ($row): bool => in_array($row->customer_id, $returningIds, true));
        $list = [];
        $customerRecords = Customer::query()->whereIn('id', $returning->pluck('customer_id'))->get()->keyBy('id');
        foreach ($includeCustomers ? $returning : [] as $row) {
            $customer = $customerRecords->get($row->customer_id);
            if ($customer) {
                $list[] = ['publicId' => $customer->public_id, 'name' => $customer->full_name, 'orders' => (int) $row->orders,
                    'amountMinor' => (int) $row->amount_minor, 'firstPurchaseDate' => $customer->first_order_at?->setTimezone(self::TIMEZONE)->toDateString()];
            }
        }
        usort($list, static fn (array $a, array $b): int => $b['amountMinor'] <=> $a['amountMinor']);
        $customerCount = $customers->count();

        return $result + ['returningCustomerPercent' => $customerCount ? (int) round($returning->count() / $customerCount * 100) : 0,
            'returningCustomerCount' => $returning->count(), 'customerCount' => $customerCount]
            + ($includeCustomers ? ['returningCustomers' => array_slice($list, 0, 4)] : []);
    }

    private function previousStart(string $period, CarbonImmutable $start, CarbonImmutable $end): CarbonImmutable
    {
        return match ($period) {
            'today', 'week' => $start->subWeek(),
            'month' => $start->subMonth(),
            'year' => $start->subYear(),
            default => $start->subDays($start->diffInDays($end)),
        };
    }

    /** @param list<array<string, mixed>> $queue
     * @return array{staleCount: int, oldestChangedAt: string|null}
     */
    private function attentionSummary(array $queue, bool $includeRefunds, bool $includeReconciliation): array
    {
        $stale = array_values(array_filter($queue, static fn (array $item): bool => $item['kind'] === 'order' && $item['stale']));
        $result = ['staleCount' => count($stale), 'oldestChangedAt' => $stale[0]['changedAt'] ?? null];
        if ($includeRefunds) {
            $result['openRefundCount'] = count(array_filter($queue, static fn (array $item): bool => $item['kind'] === 'refund'));
        }
        if ($includeReconciliation) {
            $result['unmatchedPaymentCount'] = count(array_filter($queue, static fn (array $item): bool => $item['kind'] === 'payment'));
            $result['mismatchCount'] = count(array_filter($queue, static fn (array $item): bool => $item['kind'] === 'mismatch'));
            $result['failedNotificationCount'] = app(CountFailedNotifications::class)->execute();
            $result['refundReconciliationCount'] = app(ListRefunds::class)->reconciliationCount();
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $queue
     * @return list<array<string, mixed>>
     */
    private function limitAttentionQueue(array $queue): array
    {
        $limited = [];
        foreach (['order', 'payment', 'mismatch', 'refund'] as $kind) {
            $kindRows = array_values(array_filter($queue, static fn (array $item): bool => $item['kind'] === $kind));
            array_push($limited, ...array_slice($kindRows, 0, 10));
        }
        usort($limited, static fn (array $a, array $b): int => strcmp($a['changedAt'], $b['changedAt']));

        return $limited;
    }

    /** @return list<array<string, mixed>> */
    private function attentionQueue(CarbonImmutable $now, bool $includeReconciliation, bool $includeRefunds, bool $includeCustomers): array
    {
        $storage = (string) config('app.timezone');
        $active = [OrderStatus::PAID->value, OrderStatus::PROCESSING->value, OrderStatus::READY_FOR_DISPATCH->value, OrderStatus::DISPATCHED->value];
        $orders = Order::query()->whereIn('status', $active)->with(['customer', 'statusEvents'])->get();
        $queue = [];
        foreach ($orders as $order) {
            $event = $order->statusEvents->where('to_status', $order->status)->sortByDesc('created_at')->first();
            $changedAt = $event ? $event->created_at : $order->created_at;
            $changedAt ??= $order->paid_at;
            $changed = $changedAt->setTimezone(self::TIMEZONE);
            $age = max(0, (int) $changed->diffInHours($now->setTimezone(self::TIMEZONE), false));
            $entry = ['id' => $order->id, 'kind' => 'order', 'orderNumber' => $order->order_number, 'status' => $order->status->value,
                'amountMinor' => (int) $order->total_paid_minor, 'currency' => $order->currency,
                'changedAt' => $changed->toIso8601String(), 'ageHours' => $age, 'stale' => $age >= 48 && $order->status !== OrderStatus::DISPATCHED,
                'reason' => $age >= 48 ? 'stale' : 'active'];
            if ($includeCustomers) {
                $entry += ['customerName' => $order->customer?->full_name, 'customerPublicId' => $order->customer?->public_id];
            }
            $queue[] = $entry;
        }
        $attempts = $includeReconciliation
            ? CheckoutAttempt::query()->where('status', CheckoutAttemptStatus::MISMATCH->value)
                ->orWhereIn('id', $this->successfulUnmatchedPayments()->select('id'))
                ->with('order')->get()
            : collect();
        foreach ($attempts as $attempt) {
            $kind = $attempt->status === CheckoutAttemptStatus::MISMATCH ? 'mismatch' : 'payment';
            $changed = ($attempt->verified_at ?? $attempt->created_at)->setTimezone(self::TIMEZONE);
            $queue[] = ['id' => $attempt->id, 'kind' => $kind, 'orderNumber' => $attempt->order?->order_number, 'status' => $attempt->status->value,
                'amountMinor' => (int) $attempt->amount_minor, 'currency' => $attempt->currency,
                'changedAt' => $changed->toIso8601String(), 'ageHours' => max(0, (int) $changed->diffInHours($now->setTimezone(self::TIMEZONE), false)), 'stale' => false,
                'reason' => $kind === 'mismatch' ? 'amount_mismatch' : 'payment_without_order'];
        }
        if ($includeRefunds) {
            $requests = RefundRequest::query()->whereIn('status', array_map(static fn (RefundStatus $s): string => $s->value, RefundStatus::open()))
                ->with('order.customer')->get();
            foreach ($requests as $request) {
                $changed = $request->created_at->setTimezone(self::TIMEZONE);
                $entry = ['id' => $request->id, 'kind' => 'refund', 'orderNumber' => $request->order?->order_number,
                    'status' => $request->status->value,
                    'currency' => $request->order->currency,
                    'changedAt' => $changed->toIso8601String(), 'ageHours' => max(0, (int) $changed->diffInHours($now->setTimezone(self::TIMEZONE), false)),
                    'stale' => false, 'reason' => 'refund_review'];
                if ($includeCustomers) {
                    $entry += ['customerName' => $request->order?->customer?->full_name, 'customerPublicId' => $request->order?->customer?->public_id];
                }
                $queue[] = $entry;
            }
        }
        usort($queue, static fn (array $a, array $b): int => strcmp($a['changedAt'], $b['changedAt']));

        return $queue;
    }

    /** @return array<string, int> */
    private function checkoutOutcomes(CarbonImmutable $start, CarbonImmutable $end, bool $includeMoney, bool $includeReconciliation): array
    {
        $q = CheckoutAttempt::query()->where('created_at', '>=', $start->setTimezone((string) config('app.timezone')))->where('created_at', '<', $end->setTimezone((string) config('app.timezone')));
        $paid = (clone $q)->where('status', CheckoutAttemptStatus::SUCCEEDED->value);
        $failed = (clone $q)->where('status', CheckoutAttemptStatus::FAILED->value);
        $abandoned = (clone $q)->where('status', CheckoutAttemptStatus::ABANDONED->value);

        return ['total' => (clone $q)->count(), 'paid' => $paid->count(),
            'failed' => $failed->count(), 'abandoned' => $abandoned->count(),
            'successRatePercent' => (clone $q)->count() ? (int) round($paid->count() / (clone $q)->count() * 100) : 0]
            + ($includeReconciliation ? ['unmatchedPayment' => $this->successfulUnmatchedPayments()->count(),
                'mismatch' => CheckoutAttempt::query()->where('status', CheckoutAttemptStatus::MISMATCH->value)->count()] : [])
            + ($includeMoney ? ['paidAmountMinor' => (int) (clone $paid)->sum('amount_minor'), 'failedAmountMinor' => (int) (clone $failed)->sum('amount_minor'),
                'abandonedAmountMinor' => (int) (clone $abandoned)->sum('amount_minor')] : []);
    }

    /** @return Builder<CheckoutAttempt> */
    private function successfulUnmatchedPayments(): Builder
    {
        return CheckoutAttempt::query()->whereNot('status', CheckoutAttemptStatus::MISMATCH->value)
            ->whereNotNull('verified_at')->whereDoesntHave('order')
            ->where(function (Builder $query): void {
                $query->where('status', CheckoutAttemptStatus::SUCCEEDED->value)
                    ->orWhere('verification_snapshot_json->status', 'success');
            });
    }

    /** @return array<string, int|null> */
    private function fulfillment(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $storage = (string) config('app.timezone');
        $done = Order::query()->with('statusEvents')->whereHas('statusEvents', fn (Builder $q) => $q->where('to_status', OrderStatus::DELIVERED->value)
            ->where('created_at', '>=', $start->setTimezone($storage))->where('created_at', '<', $end->setTimezone($storage)))->get();
        $toDelivered = $toDispatched = [];
        foreach ($done as $order) {
            $deliveredEvent = $order->statusEvents->where('to_status', OrderStatus::DELIVERED)->filter(fn ($event) => $event->created_at >= $start->setTimezone($storage) && $event->created_at < $end->setTimezone($storage))->sortBy('created_at')->first();
            $delivered = $deliveredEvent?->created_at;
            $dispatched = $order->statusEvents->where('to_status', OrderStatus::DISPATCHED)->filter(fn ($event) => $delivered && $event->created_at <= $delivered)->sortByDesc('created_at')->first()?->created_at;
            if ($delivered) {
                $toDelivered[] = max(0, $order->paid_at->diffInMinutes($delivered) / 60);
            }
            if ($dispatched) {
                $toDispatched[] = max(0, $order->paid_at->diffInMinutes($dispatched) / 60);
            }
        }

        return ['processing' => Order::query()->where('status', OrderStatus::PROCESSING->value)->count(),
            'readyForDispatch' => Order::query()->where('status', OrderStatus::READY_FOR_DISPATCH->value)->count(), 'dispatched' => Order::query()->where('status', OrderStatus::DISPATCHED->value)->count(),
            'averageHoursToDelivered' => $toDelivered ? (int) round(array_sum($toDelivered) / count($toDelivered)) : null,
            'averageHoursToDispatched' => $toDispatched ? (int) round(array_sum($toDispatched) / count($toDispatched)) : null, 'deliveredCount' => count($toDelivered)];
    }

    /** @return array<string, mixed> */
    private function catalogue(CarbonImmutable $start, CarbonImmutable $end, bool $includeMoney): array
    {
        $storage = (string) config('app.timezone');
        $orders = Order::query()->where('paid_at', '>=', $start->setTimezone($storage))->where('paid_at', '<', $end->setTimezone($storage))
            ->whereNotIn('status', [OrderStatus::CANCELLED->value, OrderStatus::REFUNDED->value])->pluck('id');
        $ranked = OrderItem::query()->whereIn('order_items.order_id', $orders)
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->whereIn('order_items.line_type', ['PRODUCT', 'BUNDLE', 'BUNDLE_EXTRA'])
            ->selectRaw("CASE WHEN order_items.line_type = 'BUNDLE' THEN 'bundle' ELSE 'product' END AS catalogue_type,
                CASE WHEN order_items.line_type = 'BUNDLE' THEN order_items.bundle_id ELSE product_variants.product_id END AS catalogue_id,
                MAX(order_items.name_snapshot) AS name, SUM(order_items.quantity) AS quantity, SUM(order_items.line_total_minor) AS amount_minor")
            ->groupBy('catalogue_type', 'catalogue_id')->orderByDesc('amount_minor')->toBase()->get()
            ->map(static fn ($r) => ['catalogueType' => $r->catalogue_type, 'productId' => $r->catalogue_type === 'product' ? (int) $r->catalogue_id : null,
                'bundleId' => $r->catalogue_type === 'bundle' ? (int) $r->catalogue_id : null, 'name' => $r->name,
                'quantity' => (int) $r->quantity, 'amountMinor' => (int) $r->amount_minor])->values()->all();
        $soldProducts = collect($ranked)->where('catalogueType', 'product')->pluck('productId')->all();
        $soldBundles = collect($ranked)->where('catalogueType', 'bundle')->pluck('bundleId')->all();
        $unsoldProducts = Product::query()->where('is_published', true)->orderBy('display_order')->get(['id', 'name'])->filter(fn ($p) => ! in_array($p->id, $soldProducts, true))
            ->map(static fn ($p) => ['catalogueType' => 'product', 'productId' => (int) $p->id, 'bundleId' => null, 'name' => $p->name])->values();
        $unsoldBundles = Bundle::query()->where('is_published', true)->orderBy('display_order')->get(['id', 'name'])->filter(fn ($b) => ! in_array($b->id, $soldBundles, true))
            ->map(static fn ($b) => ['catalogueType' => 'bundle', 'productId' => null, 'bundleId' => (int) $b->id, 'name' => $b->name])->values();
        $unsold = $unsoldProducts->concat($unsoldBundles)->all();
        $channels = Order::query()->whereIn('id', $orders)->select('source')->selectRaw('COUNT(*) AS count, SUM(total_paid_minor) AS net_sales_minor')->groupBy('source')->toBase()->get()
            ->map(static fn ($r) => ['source' => (string) $r->source, 'count' => (int) $r->count, 'salesMinor' => (int) $r->net_sales_minor])->values()->all();
        if (! $includeMoney) {
            $ranked = array_map(static fn (array $row): array => array_diff_key($row, ['amountMinor' => true]), $ranked);
        }

        return ['bestSellers' => array_slice($ranked, 0, 4), 'bestSellersTotal' => count($ranked),
            'unsold' => array_slice($unsold, 0, 20), 'unsoldTotal' => count($unsold)]
            + ($includeMoney ? ['sourceChannels' => $channels] : []);
    }

    /** @return array<string, int> */
    private function refundMetrics(CarbonImmutable $start, CarbonImmutable $end, bool $includeMoney, bool $includeReconciliation): array
    {
        $storage = (string) config('app.timezone');
        $completed = RefundTransaction::query()->where('status', RefundTransactionStatus::PROCESSED->value)
            ->where('created_at', '>=', $start->setTimezone($storage))->where('created_at', '<', $end->setTimezone($storage));

        return ['openRequests' => RefundRequest::query()->whereIn('status', array_map(static fn (RefundStatus $s): string => $s->value, RefundStatus::open()))->count(),
            'completedCount' => (clone $completed)->count()]
            + ($includeMoney ? ['completedMinor' => (int) (clone $completed)->sum('amount_minor')] : [])
            + ($includeReconciliation ? ['failedReconciliation' => app(ListRefunds::class)->reconciliationCount()] : []);
    }
}
