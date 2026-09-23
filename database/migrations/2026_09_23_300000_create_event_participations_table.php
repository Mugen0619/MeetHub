<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 取消し時は行を削除する方式のため、updated_at・status・cancelled_atは持たない
            $table->timestamp('created_at')->useCurrent();

            // 1ユーザーにつき1イベントへの申込みは1件のみ(重複申込みの最終防衛線)
            $table->unique(['event_id', 'user_id']);
            // マイ参加予定イベント一覧(ユーザー起点の検索)用
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_participations');
    }
};
