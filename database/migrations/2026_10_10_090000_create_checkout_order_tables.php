<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->json('aliases_json');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'display_order']);
        });

        Schema::create('checkout_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('paystack_reference')->unique();
            $table->json('customer_snapshot_json');
            $table->json('delivery_snapshot_json');
            $table->json('items_snapshot_json');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('GHS');
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('expires_at');
            $table->foreignId('parent_attempt_id')->nullable()->constrained('checkout_attempts')->restrictOnDelete();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('verification_snapshot_json')->nullable();
            $table->timestamps();
            $table->index(['status', 'verified_at']);
        });

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('provider_event_id');
            $table->string('event_type');
            $table->string('reference');
            $table->boolean('signature_valid');
            $table->json('payload_redacted_json');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_event_id']);
            $table->index(['reference', 'processed_at']);
        });

        Schema::create('order_number_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_value');
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('full_name');
            $table->string('phone_e164')->unique();
            $table->string('phone_display');
            $table->string('email')->nullable();
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedBigInteger('lifetime_paid_minor')->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number')->unique();
            $table->string('tracking_token', 32)->unique();
            $table->string('source', 10)->default('ONLINE');
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('checkout_attempt_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('PAID');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('total_paid_minor');
            $table->string('currency', 3)->default('GHS');
            $table->string('payment_method');
            $table->string('payment_reference')->nullable()->unique();
            $table->json('customer_snapshot_json');
            $table->json('delivery_snapshot_json');
            $table->string('delivery_fee_note')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('line_type', 20);
            $table->foreignId('parent_item_id')->nullable()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('bundle_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name_snapshot');
            $table->string('size_snapshot')->nullable();
            $table->string('product_code_snapshot');
            $table->string('image_ref_snapshot')->nullable();
            $table->json('bundle_contents_json')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();
        });

        Schema::create('order_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('actor_type', 10);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER checkout_attempts_nonnegative_amount_insert BEFORE INSERT ON checkout_attempts
            WHEN NEW.amount_minor < 0 BEGIN SELECT RAISE(ABORT, 'money values must be nonnegative'); END;
            CREATE TRIGGER checkout_attempts_nonnegative_amount_update BEFORE UPDATE OF amount_minor ON checkout_attempts
            WHEN NEW.amount_minor < 0 BEGIN SELECT RAISE(ABORT, 'money values must be nonnegative'); END;
            CREATE TRIGGER customers_nonnegative_lifetime_insert BEFORE INSERT ON customers
            WHEN NEW.lifetime_paid_minor < 0 BEGIN SELECT RAISE(ABORT, 'money values must be nonnegative'); END;
            CREATE TRIGGER customers_nonnegative_lifetime_update BEFORE UPDATE OF lifetime_paid_minor ON customers
            WHEN NEW.lifetime_paid_minor < 0 BEGIN SELECT RAISE(ABORT, 'money values must be nonnegative'); END;
            CREATE TRIGGER orders_nonnegative_money_insert BEFORE INSERT ON orders
            WHEN NEW.subtotal_minor < 0 OR NEW.total_paid_minor < 0 BEGIN SELECT RAISE(ABORT, 'money values must be nonnegative'); END;
            CREATE TRIGGER orders_nonnegative_money_update BEFORE UPDATE OF subtotal_minor, total_paid_minor ON orders
            WHEN NEW.subtotal_minor < 0 OR NEW.total_paid_minor < 0 BEGIN SELECT RAISE(ABORT, 'money values must be nonnegative'); END;
            CREATE TRIGGER order_items_nonnegative_money_insert BEFORE INSERT ON order_items
            WHEN NEW.unit_price_minor < 0 OR NEW.line_total_minor < 0 BEGIN SELECT RAISE(ABORT, 'money values must be nonnegative'); END;
            CREATE TRIGGER order_items_nonnegative_money_update BEFORE UPDATE OF unit_price_minor, line_total_minor ON order_items
            WHEN NEW.unit_price_minor < 0 OR NEW.line_total_minor < 0 BEGIN SELECT RAISE(ABORT, 'money values must be nonnegative'); END;
            CREATE TRIGGER order_items_positive_quantity_insert BEFORE INSERT ON order_items
            WHEN NEW.quantity <= 0 BEGIN SELECT RAISE(ABORT, 'order item quantity must be positive'); END;
            CREATE TRIGGER order_items_positive_quantity_update BEFORE UPDATE OF quantity ON order_items
            WHEN NEW.quantity <= 0 BEGIN SELECT RAISE(ABORT, 'order item quantity must be positive'); END;
            CREATE TRIGGER checkout_attempts_valid_status_insert BEFORE INSERT ON checkout_attempts
            WHEN NEW.status NOT IN ('PENDING', 'SUCCEEDED', 'FAILED', 'ABANDONED', 'MISMATCH') OR NEW.currency != 'GHS'
            BEGIN SELECT RAISE(ABORT, 'invalid checkout attempt status or currency'); END;
            CREATE TRIGGER checkout_attempts_valid_status_update BEFORE UPDATE OF status, currency ON checkout_attempts
            WHEN NEW.status NOT IN ('PENDING', 'SUCCEEDED', 'FAILED', 'ABANDONED', 'MISMATCH') OR NEW.currency != 'GHS'
            BEGIN SELECT RAISE(ABORT, 'invalid checkout attempt status or currency'); END;
            CREATE TRIGGER orders_valid_values_insert BEFORE INSERT ON orders
            WHEN NEW.source NOT IN ('ONLINE', 'MANUAL') OR NEW.status NOT IN ('PAID', 'PROCESSING', 'READY_FOR_DISPATCH', 'DISPATCHED', 'DELIVERED', 'CANCELLED', 'REFUNDED') OR NEW.currency != 'GHS'
            BEGIN SELECT RAISE(ABORT, 'invalid order source, status, or currency'); END;
            CREATE TRIGGER orders_valid_values_update BEFORE UPDATE OF source, status, currency ON orders
            WHEN NEW.source NOT IN ('ONLINE', 'MANUAL') OR NEW.status NOT IN ('PAID', 'PROCESSING', 'READY_FOR_DISPATCH', 'DISPATCHED', 'DELIVERED', 'CANCELLED', 'REFUNDED') OR NEW.currency != 'GHS'
            BEGIN SELECT RAISE(ABORT, 'invalid order source, status, or currency'); END;
            CREATE TRIGGER order_items_valid_line_type_insert BEFORE INSERT ON order_items
            WHEN NEW.line_type NOT IN ('PRODUCT', 'BUNDLE', 'BUNDLE_EXTRA')
            BEGIN SELECT RAISE(ABORT, 'invalid order line type'); END;
            CREATE TRIGGER order_items_valid_line_type_update BEFORE UPDATE OF line_type ON order_items
            WHEN NEW.line_type NOT IN ('PRODUCT', 'BUNDLE', 'BUNDLE_EXTRA')
            BEGIN SELECT RAISE(ABORT, 'invalid order line type'); END;
            CREATE TRIGGER delivery_areas_nonnegative_order_insert BEFORE INSERT ON delivery_areas
            WHEN NEW.display_order < 0 BEGIN SELECT RAISE(ABORT, 'display order must be nonnegative'); END;
            CREATE TRIGGER delivery_areas_nonnegative_order_update BEFORE UPDATE OF display_order ON delivery_areas
            WHEN NEW.display_order < 0 BEGIN SELECT RAISE(ABORT, 'display order must be nonnegative'); END;
            CREATE TRIGGER customers_nonnegative_count_insert BEFORE INSERT ON customers
            WHEN NEW.orders_count < 0 BEGIN SELECT RAISE(ABORT, 'customer order count must be nonnegative'); END;
            CREATE TRIGGER customers_nonnegative_count_update BEFORE UPDATE OF orders_count ON customers
            WHEN NEW.orders_count < 0 BEGIN SELECT RAISE(ABORT, 'customer order count must be nonnegative'); END;
            CREATE TRIGGER order_number_sequences_nonnegative_insert BEFORE INSERT ON order_number_sequences
            WHEN NEW.last_value < 0 BEGIN SELECT RAISE(ABORT, 'sequence value must be nonnegative'); END;
            CREATE TRIGGER order_number_sequences_nonnegative_update BEFORE UPDATE OF last_value ON order_number_sequences
            WHEN NEW.last_value < 0 BEGIN SELECT RAISE(ABORT, 'sequence value must be nonnegative'); END;
            CREATE TRIGGER order_status_events_valid_values_insert BEFORE INSERT ON order_status_events
            WHEN NEW.actor_type NOT IN ('SYSTEM', 'STAFF') OR NEW.to_status NOT IN ('PAID', 'PROCESSING', 'READY_FOR_DISPATCH', 'DISPATCHED', 'DELIVERED', 'CANCELLED', 'REFUNDED') OR (NEW.from_status IS NOT NULL AND NEW.from_status NOT IN ('PAID', 'PROCESSING', 'READY_FOR_DISPATCH', 'DISPATCHED', 'DELIVERED', 'CANCELLED', 'REFUNDED'))
            BEGIN SELECT RAISE(ABORT, 'invalid order event actor or status'); END;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('order_number_sequences');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('checkout_attempts');
        Schema::dropIfExists('delivery_areas');
    }
};
