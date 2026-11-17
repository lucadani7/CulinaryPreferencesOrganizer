<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('currency', 3);
            // units of `currency` for 1 EUR
            $table->decimal('rate', 18, 6);
            $table->timestamps();
            $table->unique(['date', 'currency']);
        });

        // prices are now kept in euro; local currencies are only shown, converted at the ECB rate
        Schema::table('products', function (Blueprint $table) {
            $table->string('currency', 3)->default('EUR')->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('currency', 3)->default('RON')->change();
        });
        Schema::dropIfExists('exchange_rates');
    }
};
