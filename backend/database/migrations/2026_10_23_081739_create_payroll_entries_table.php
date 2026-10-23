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
        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('period', 7);
            $table->unsignedBigInteger('basic_minor');
            $table->unsignedBigInteger('allowances_minor')->default(0);
            $table->unsignedBigInteger('deductions_minor')->default(0);
            $table->unsignedBigInteger('net_minor');
            $table->string('status', 20)->default('pending');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unique(['employee_id', 'period']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_entries');
    }
};
