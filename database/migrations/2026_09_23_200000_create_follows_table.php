<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('followee_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['follower_id', 'followee_id']);
            // フォロワー一覧(followee_idでの検索)用。follower_id側はユニーク制約の先頭カラムで賄える
            $table->index('followee_id');
        });

        // 自分自身のフォローはアプリ側でも弾くが、DBでも保証する。
        // SQLite(テスト用)は既存テーブルへのCHECK制約追加に対応していないため、PostgreSQLのみに付与する
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE follows ADD CONSTRAINT follows_no_self_follow CHECK (follower_id <> followee_id)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
