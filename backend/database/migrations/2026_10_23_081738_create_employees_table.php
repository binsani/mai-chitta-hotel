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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('staff_number', 40)->unique();
            $table->string('name', 120);
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('department', 100);
            $table->string('job_title', 120);
            $table->date('start_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('confidential_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
