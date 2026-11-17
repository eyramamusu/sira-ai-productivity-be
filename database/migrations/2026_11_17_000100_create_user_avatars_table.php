<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Profile photos kept in the database when SIRA_AVATAR_STORAGE=database, for hosts
        // whose disk is wiped on restart (e.g. Render's free tier).
        Schema::create('user_avatars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Random, unguessable id used in the public image URL.
            $table->string('token', 40)->unique();
            $table->string('mime', 50);
            // Base64 so the column type is the same on MySQL, MariaDB and SQLite.
            $table->longText('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_avatars');
    }
};
