<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('off_tag')->nullable()->index();   // e.g. en:dairies
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('allergens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('off_tag')->unique();               // e.g. en:gluten
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('barcode', 32)->nullable()->unique();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('image_url', 1024)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 3)->default('RON');
            $table->string('quantity')->nullable();            // e.g. "500 g"
            $table->text('ingredients_text')->nullable();
            $table->string('nutriscore', 1)->nullable();       // a..e
            $table->unsignedTinyInteger('nova_group')->nullable(); // 1..4
            $table->string('ecoscore', 1)->nullable();
            $table->boolean('is_vegetarian')->nullable();
            $table->boolean('is_vegan')->nullable();
            $table->string('perishability', 10)->default('medium'); // low | medium | high
            $table->unsignedInteger('shelf_life_days')->nullable();
            $table->date('expires_at')->nullable();
            $table->jsonb('nutriments')->nullable();
            $table->string('source', 10)->default('manual');   // manual | off
            $table->timestamp('off_synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['name']);
            $table->index(['source']);
        });

        Schema::create('allergen_product', function (Blueprint $table) {
            $table->foreignId('allergen_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('level', 10)->default('contains'); // contains | traces
            $table->primary(['allergen_id', 'product_id']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('url', 1024);
            $table->string('type', 20)->default('front');      // front | ingredients | nutrition | packaging | other
            $table->boolean('is_uploaded')->default(false);    // true = file stored locally
            $table->timestamps();
        });

        Schema::create('product_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('region', 64);                      // country code (RO) or region (Moldavia, Transylvania...)
            $table->unsignedTinyInteger('month_from')->default(1);
            $table->unsignedTinyInteger('month_to')->default(12);
            $table->boolean('is_available')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['region']);
        });

        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('cuisine')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedTinyInteger('price_level')->nullable(); // 1..4
            $table->string('website')->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('has_vegetarian_options')->default(false);
            $table->boolean('has_vegan_options')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('product_restaurant', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('dish_name')->nullable();
            $table->primary(['product_id', 'restaurant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_restaurant');
        Schema::dropIfExists('restaurants');
        Schema::dropIfExists('product_availabilities');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('allergen_product');
        Schema::dropIfExists('products');
        Schema::dropIfExists('allergens');
        Schema::dropIfExists('categories');
    }
};