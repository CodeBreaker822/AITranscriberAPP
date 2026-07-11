<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audio_chunks', function (Blueprint $table): void {
            $table->string('audio_path')->nullable()->after('audio_blob');
            $table->unsignedBigInteger('audio_size')->nullable()->after('audio_path');
            $table->string('audio_hash', 64)->nullable()->after('audio_size');
        });
    }

    public function down(): void
    {
        Schema::table('audio_chunks', function (Blueprint $table): void {
            $table->dropColumn(['audio_path', 'audio_size', 'audio_hash']);
        });
    }
};
