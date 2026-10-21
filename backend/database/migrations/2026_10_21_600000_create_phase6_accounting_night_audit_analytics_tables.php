<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Chart of Accounts
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique(); // e.g. 1010, 2010, 4010
            $table->string('name', 128);
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->string('department', 32)->nullable()->default('GA'); // ROOMS, POS, HK, MAINT, GA
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false); // System standard accounts cannot be deleted
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index('department');
        });

        // 2. Fiscal Periods / Financial Calendars
        Schema::create('financial_periods', function (Blueprint $table) {
            $table->id();
            $table->string('period_name', 64); // e.g. "October 2026", "Q4 2026"
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'closed', 'locked'])->default('open');
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['start_date', 'end_date']);
        });

        // 3. Journal Entries (Header)
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number', 64)->unique(); // e.g. JE-20261021-0001
            $table->date('entry_date');
            $table->string('source_module', 32)->default('MANUAL'); // MANUAL, NIGHT_AUDIT, FOLIO, POS, INVENTORY, PROCUREMENT, MAINTENANCE
            $table->string('source_reference', 128)->nullable(); // e.g. NightAudit:12, Folio:84, PosOrder:102
            $table->string('description', 255);
            $table->enum('status', ['draft', 'posted', 'reversed'])->default('posted');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['entry_date', 'status']);
            $table->index(['source_module', 'source_reference']);
        });

        // 4. Journal Entry Lines (Double-Entry Debit / Credit in integer minor units)
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->bigInteger('debit_minor')->default(0); // in kobo / minor units
            $table->bigInteger('credit_minor')->default(0); // in kobo / minor units
            $table->string('department', 32)->nullable();
            $table->string('memo', 255)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
        });

        // 5. Daily Night Audits
        Schema::create('night_audits', function (Blueprint $table) {
            $table->id();
            $table->date('audit_date')->unique(); // The hotel business date being audited and closed
            $table->enum('status', ['in_progress', 'completed', 'failed'])->default('in_progress');
            $table->foreignId('auditor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();

            // Statistical snapshot
            $table->integer('total_rooms')->default(0);
            $table->integer('occupied_rooms')->default(0);
            $table->integer('vacant_clean_rooms')->default(0);
            $table->integer('vacant_dirty_rooms')->default(0);
            $table->integer('out_of_order_rooms')->default(0);
            $table->decimal('occupancy_percentage', 5, 2)->default(0.00);

            // Revenue and financial totals in kobo
            $table->bigInteger('room_revenue_minor')->default(0);
            $table->bigInteger('fnb_revenue_minor')->default(0);
            $table->bigInteger('other_revenue_minor')->default(0);
            $table->bigInteger('total_revenue_minor')->default(0);
            $table->bigInteger('tax_collected_minor')->default(0);
            $table->bigInteger('payments_received_minor')->default(0);

            // Key performance indicators in kobo
            $table->bigInteger('adr_minor')->default(0); // Average Daily Rate = Room Revenue / Occupied Rooms
            $table->bigInteger('revpar_minor')->default(0); // RevPAR = Room Revenue / Total Rooms

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('operational_checklist')->nullable(); // Status checks: unassigned arrivals, open POS orders, folio balances
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // Database triggers for MariaDB/MySQL to enforce append-only ledger on posted journal entry lines
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::unprepared("
                CREATE TRIGGER trg_journal_entry_lines_no_delete
                BEFORE DELETE ON journal_entry_lines
                FOR EACH ROW
                BEGIN
                    DECLARE v_status VARCHAR(32);
                    SELECT status INTO v_status FROM journal_entries WHERE id = OLD.journal_entry_id;
                    IF v_status = 'posted' THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Posted journal entry lines cannot be deleted. Must post a reversing journal entry.';
                    END IF;
                END;
            ");

            DB::unprepared("
                CREATE TRIGGER trg_journal_entry_lines_no_update
                BEFORE UPDATE ON journal_entry_lines
                FOR EACH ROW
                BEGIN
                    DECLARE v_status VARCHAR(32);
                    SELECT status INTO v_status FROM journal_entries WHERE id = OLD.journal_entry_id;
                    IF v_status = 'posted' THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Posted journal entry lines cannot be modified. Must post a reversing journal entry.';
                    END IF;
                END;
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_journal_entry_lines_no_delete;");
            DB::unprepared("DROP TRIGGER IF EXISTS trg_journal_entry_lines_no_update;");
        }

        Schema::dropIfExists('night_audits');
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('financial_periods');
        Schema::dropIfExists('chart_of_accounts');
    }
};
