<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('user')->after('password');          // user | admin
            $table->boolean('is_active')->default(true)->after('role');
            $table->string('diet', 20)->default('omnivore')->after('is_active');       // omnivore | vegetarian | vegan | pescatarian
            $table->string('country', 2)->nullable()->after('diet');                    // ISO 3166-1 alpha-2, e.g. RO
            $table->string('locale', 5)->default('en')->after('country');
            $table->timestamp('last_login_at')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active', 'diet', 'country', 'locale', 'last_login_at']);
        });
    }
};