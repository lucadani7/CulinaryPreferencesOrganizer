<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('group_user', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member'); // owner | member
            $table->timestamp('joined_at')->useCurrent();
            $table->primary(['group_id', 'user_id']);
        });

        // Personal dietary restrictions (allergies / intolerances)
        Schema::create('allergen_user', function (Blueprint $table) {
            $table->foreignId('allergen_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('severity', 10)->default('avoid'); // avoid | strict
            $table->primary(['allergen_id', 'user_id']);
        });

        // Culinary preferences: polymorphic target (product, category, restaurant)
        Schema::create('preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->morphs('preferable');
            $table->string('sentiment', 10);                   // love | like | neutral | dislike | avoid
            $table->unsignedTinyInteger('rating')->nullable(); // 1..5
            $table->text('notes')->nullable();
            $table->timestamps();

            // NULLS NOT DISTINCT (PostgreSQL 15+): a single personal preference (group_id NULL) per target
            $table->unique(['user_id', 'group_id', 'preferable_type', 'preferable_id'], 'preferences_unique_target')->nullsNotDistinct();
            $table->index(['sentiment']);
        });

        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('status', 12)->default('open');     // open | completed | archived
            $table->date('planned_for')->nullable();
            $table->decimal('budget', 10, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('custom_name')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 16)->default('pcs');
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->boolean('is_checked')->default(false);
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
        Schema::dropIfExists('shopping_lists');
        Schema::dropIfExists('preferences');
        Schema::dropIfExists('allergen_user');
        Schema::dropIfExists('group_user');
        Schema::dropIfExists('groups');
    }
};