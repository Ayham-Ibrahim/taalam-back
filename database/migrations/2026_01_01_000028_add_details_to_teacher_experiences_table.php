<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_experiences', function (Blueprint $table) {
            $table->string('company', 200)->nullable()->after('title');
            $table->string('location', 150)->nullable()->after('period');
            $table->text('description')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_experiences', function (Blueprint $table) {
            $table->dropColumn(['company', 'location', 'description']);
        });
    }
};
