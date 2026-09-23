<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 要件定義書7節のTBDだった「定員を無制限(null)で作成できるか」を、必須入力(1以上の整数)に決定したため、
     * DB側でもnullを許容しないようにする。
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedInteger('capacity')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedInteger('capacity')->nullable()->change();
        });
    }
};
