<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hair_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('normalized_name')->index();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_new_look')->default(false);
            $table->string('image_alt')->nullable();
            $table->string('image_position')->default('center');
            $table->timestamps();
            $table->index(['is_published', 'display_order']);
        });

        Schema::create('product_hair_type', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('hair_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('display_order')->default(0);
            $table->primary(['product_id', 'hair_type_id']);
            $table->index(['hair_type_id', 'product_id']);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('size_label');
            $table->string('normalized_size');
            $table->string('product_code')->unique();
            $table->unsignedInteger('source_price_minor')->nullable();
            $table->unsignedInteger('price_minor');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'normalized_size']);
            $table->index(['product_id', 'is_active', 'display_order']);
        });

        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->morphs('owner');
            $table->string('original_path');
            $table->json('variants_json');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('mime', 100);
            $table->unsignedBigInteger('bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestamp('retired_at')->nullable()->index();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id', 'retired_at']);
        });
        DB::statement('CREATE UNIQUE INDEX media_one_active_per_owner ON media (owner_type, owner_id) WHERE retired_at IS NULL');

        Schema::create('bundles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('product_code')->unique();
            $table->string('eyebrow')->nullable();
            $table->unsignedInteger('price_minor');
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->string('image_alt')->nullable();
            $table->timestamps();
            $table->index(['is_published', 'display_order']);
        });

        Schema::create('bundle_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->unique(['bundle_id', 'product_variant_id']);
            $table->index(['bundle_id', 'display_order']);
        });
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_variants_nonnegative_prices_insert
            BEFORE INSERT ON product_variants
            WHEN NEW.price_minor < 0 OR NEW.source_price_minor < 0
            BEGIN SELECT RAISE(ABORT, 'catalogue prices must be nonnegative'); END;
            CREATE TRIGGER product_variants_nonnegative_prices_update
            BEFORE UPDATE OF price_minor, source_price_minor ON product_variants
            WHEN NEW.price_minor < 0 OR NEW.source_price_minor < 0
            BEGIN SELECT RAISE(ABORT, 'catalogue prices must be nonnegative'); END;
            CREATE TRIGGER bundles_nonnegative_price_insert
            BEFORE INSERT ON bundles WHEN NEW.price_minor < 0
            BEGIN SELECT RAISE(ABORT, 'catalogue prices must be nonnegative'); END;
            CREATE TRIGGER bundles_nonnegative_price_update
            BEFORE UPDATE OF price_minor ON bundles WHEN NEW.price_minor < 0
            BEGIN SELECT RAISE(ABORT, 'catalogue prices must be nonnegative'); END;
            CREATE TRIGGER bundle_items_positive_quantity_insert
            BEFORE INSERT ON bundle_items WHEN NEW.quantity <= 0
            BEGIN SELECT RAISE(ABORT, 'bundle item quantity must be positive'); END;
            CREATE TRIGGER bundle_items_positive_quantity_update
            BEFORE UPDATE OF quantity ON bundle_items WHEN NEW.quantity <= 0
            BEGIN SELECT RAISE(ABORT, 'bundle item quantity must be positive'); END;
            SQL);

        Schema::create('slug_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type');
            $table->string('old_slug');
            $table->string('new_slug');
            $table->timestamps();
            $table->unique(['entity_type', 'old_slug']);
            $table->index(['entity_type', 'new_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slug_redirects');
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('bundles');
        Schema::dropIfExists('media');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_hair_type');
        Schema::dropIfExists('products');
        Schema::dropIfExists('hair_types');
        Schema::dropIfExists('categories');
    }
};
