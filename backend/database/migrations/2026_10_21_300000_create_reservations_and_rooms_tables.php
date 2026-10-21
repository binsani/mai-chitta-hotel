<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: Rooms, Rate Plans, Availability Engine, Online Booking & Front-Desk Reservations.
 *
 * Non-destructive and additive only. Preserves all Phase 1 and Phase 2 tables and data.
 * All monetary columns are stored both in minor integer units (*_minor, e.g. kobo) and
 * fixed-precision decimal(14,2) for human readability and reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Room Types (Categories)
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 120)->unique();
            $table->string('code', 24)->nullable()->unique();
            $table->string('subtitle', 255)->nullable();
            $table->text('description')->nullable();
            $table->longText('public_description')->nullable();
            $table->unsignedTinyInteger('max_adults')->default(2);
            $table->unsignedTinyInteger('max_children')->default(0);
            $table->unsignedTinyInteger('max_occupancy')->default(2);
            $table->unsignedTinyInteger('base_occupancy')->default(2);
            $table->string('bed_configuration', 160)->nullable();
            $table->string('size_label', 64)->nullable();
            $table->json('amenities')->nullable();
            $table->foreignId('featured_media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->json('gallery_media_ids')->nullable();
            $table->unsignedBigInteger('starting_rate_minor')->nullable();
            $table->decimal('starting_rate', 14, 2)->nullable();
            $table->string('currency_code', 8)->default('NGN');
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_public')->default(true)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->integer('sort_order')->default(0);
            $table->text('housekeeping_notes')->nullable();
            $table->text('operational_notes')->nullable();
            $table->foreignId('room_content_id')->nullable()->constrained('room_contents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 2. Individual Physical Rooms
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('room_number', 32)->unique();
            $table->foreignId('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->string('floor', 32)->nullable();
            $table->string('building', 64)->nullable();
            $table->string('operational_status', 24)->default('active')->index(); // active | inactive | out_of_service
            $table->string('housekeeping_status', 24)->default('clean')->index(); // clean | dirty | inspecting | maintenance
            $table->date('out_of_service_from')->nullable();
            $table->date('out_of_service_until')->nullable();
            $table->string('out_of_service_reason', 255)->nullable();
            $table->text('internal_notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['room_type_id', 'operational_status']);
        });

        // 3. Rate Plans
        Schema::create('rate_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('code', 32)->unique();
            $table->text('description')->nullable();
            $table->string('meal_Basis', 48)->default('room_only'); // room_only | breakfast_included | half_board | full_board
            $table->string('cancellation_policy_type', 32)->default('unconfigured'); // unconfigured | flexible | moderate | non_refundable | custom
            $table->unsignedSmallInteger('free_cancellation_hours')->nullable();
            $table->string('cancellation_penalty_type', 24)->default('none'); // none | first_night | percentage | full_stay
            $table->decimal('cancellation_penalty_value', 8, 2)->default(0);
            $table->text('cancellation_terms')->nullable();
            $table->string('currency_code', 8)->default('NGN');
            $table->unsignedBigInteger('default_base_rate_minor')->default(0);
            $table->decimal('default_base_rate', 14, 2)->default(0);
            $table->unsignedBigInteger('extra_adult_rate_minor')->default(0);
            $table->decimal('extra_adult_rate', 14, 2)->default(0);
            $table->unsignedBigInteger('extra_child_rate_minor')->default(0);
            $table->decimal('extra_child_rate', 14, 2)->default(0);
            $table->date('effective_from')->nullable()->index();
            $table->date('effective_until')->nullable()->index();
            $table->unsignedSmallInteger('min_stay_nights')->default(1);
            $table->unsignedSmallInteger('max_stay_nights')->nullable();
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_public')->default(true)->index();
            $table->boolean('is_refundable')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 4. Rate Plan <-> Room Type Eligibility & Base/Weekday Pricing
        Schema::create('rate_plan_room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained('rate_plans')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->unsignedBigInteger('base_rate_minor');
            $table->decimal('base_rate', 14, 2);
            $table->unsignedBigInteger('extra_adult_rate_minor')->default(0);
            $table->decimal('extra_adult_rate', 14, 2)->default(0);
            $table->unsignedBigInteger('extra_child_rate_minor')->default(0);
            $table->decimal('extra_child_rate', 14, 2)->default(0);
            $table->json('weekday_rates_minor')->nullable(); // {"mon": 4500000, "fri": 5000000, ...}
            $table->json('weekday_rates')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['rate_plan_id', 'room_type_id']);
        });

        // 5. Seasonal & Date-Specific Pricing Overrides / Restrictions
        Schema::create('rate_plan_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained('rate_plans')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->string('label', 120)->nullable();
            $table->string('override_type', 24)->default('date_range'); // exact_date | date_range
            $table->date('start_date')->index();
            $table->date('end_date')->index(); // inclusive night date
            $table->unsignedBigInteger('nightly_rate_minor')->nullable();
            $table->decimal('nightly_rate', 14, 2)->nullable();
            $table->unsignedBigInteger('extra_adult_rate_minor')->nullable();
            $table->decimal('extra_adult_rate', 14, 2)->nullable();
            $table->unsignedBigInteger('extra_child_rate_minor')->nullable();
            $table->decimal('extra_child_rate', 14, 2)->nullable();
            $table->unsignedSmallInteger('min_stay_nights')->nullable();
            $table->unsignedSmallInteger('max_stay_nights')->nullable();
            $table->boolean('closed_to_sell')->default(false);
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);
            $table->unsignedSmallInteger('priority')->default(10);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['rate_plan_id', 'room_type_id', 'start_date', 'end_date'], 'rpo_lookup_idx');
        });

        // 6. Guest Profiles
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 160)->index();
            $table->string('email', 255)->nullable()->index();
            $table->string('phone', 40)->nullable()->index();
            $table->string('nationality', 80)->nullable();
            $table->string('id_document_type', 48)->nullable(); // sensitive: requires guests.sensitive
            $table->string('id_document_number', 80)->nullable(); // sensitive: requires guests.sensitive
            $table->string('address', 255)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('country', 80)->nullable();
            $table->text('preferences')->nullable();
            $table->text('internal_notes')->nullable(); // sensitive: requires guests.sensitive
            $table->boolean('is_vip')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 7. Time-Limited Booking Holds (Quotes + Inventory Locks)
        Schema::create('booking_holds', function (Blueprint $table) {
            $table->id();
            $table->string('hold_token', 64)->unique();
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->foreignId('rate_plan_id')->constrained('rate_plans')->cascadeOnDelete();
            $table->date('check_in_date')->index();
            $table->date('check_out_date')->index();
            $table->unsignedSmallInteger('nights');
            $table->unsignedTinyInteger('rooms_count')->default(1);
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->string('currency_code', 8)->default('NGN');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('taxes_minor')->default(0);
            $table->unsignedBigInteger('fees_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->decimal('total_amount', 14, 2);
            $table->json('quote_snapshot'); // Full nightly breakdown & cancellation terms
            $table->string('guest_name', 160)->nullable();
            $table->string('guest_email', 255)->nullable();
            $table->string('guest_phone', 40)->nullable();
            $table->string('status', 24)->default('active')->index(); // active | converted | released | expired
            $table->timestamp('expires_at')->index();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['room_type_id', 'status', 'expires_at', 'check_in_date', 'check_out_date'], 'bh_avail_idx');
        });

        // 8. Reservations
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique(); // MCH-YYYY-XXXXXX
            $table->string('public_access_token', 64)->unique();
            $table->string('idempotency_key', 96)->nullable()->unique();
            $table->foreignId('booking_hold_id')->nullable()->constrained('booking_holds')->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->foreignId('rate_plan_id')->constrained('rate_plans')->restrictOnDelete();
            $table->foreignId('assigned_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('guest_name', 160)->index();
            $table->string('guest_email', 255)->index();
            $table->string('guest_phone', 40)->index();
            $table->date('check_in_date')->index();
            $table->date('check_out_date')->index();
            $table->unsignedSmallInteger('nights');
            $table->unsignedTinyInteger('rooms_count')->default(1);
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->string('source', 32)->default('online')->index(); // online | walk_in | phone | staff | corporate
            $table->string('status', 24)->default('confirmed')->index(); // pending_payment | confirmed | checked_in | checked_out | cancelled | no_show | expired
            $table->string('payment_status', 24)->default('unpaid')->index(); // unpaid | partially_paid | paid | partially_refunded | refunded | payment_failed
            $table->string('currency_code', 8)->default('NGN');
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('taxes_minor')->default(0);
            $table->unsignedBigInteger('fees_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('amount_paid_minor')->default(0);
            $table->unsignedBigInteger('amount_refunded_minor')->default(0);
            $table->unsignedBigInteger('cancellation_fee_minor')->default(0);
            $table->decimal('subtotal_amount', 14, 2)->default(0);
            $table->decimal('taxes_amount', 14, 2)->default(0);
            $table->decimal('fees_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('amount_refunded', 14, 2)->default(0);
            $table->json('price_snapshot'); // Immutable snapshot of quote, tax rates, and cancellation rules at booking/amendment
            $table->json('cancellation_policy_snapshot')->nullable();
            $table->text('special_requests')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->string('no_show_reason', 255)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['room_type_id', 'status', 'check_in_date', 'check_out_date'], 'res_avail_idx');
        });

        // 9. Nightly Reservation Allocations (1 row per room-unit per night)
        Schema::create('reservation_nights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->foreignId('rate_plan_id')->constrained('rate_plans')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->unsignedTinyInteger('unit_index')->default(1); // 1..rooms_count
            $table->date('stay_date')->index(); // inclusive night date (check_in .. check_out - 1 day)
            $table->string('currency_code', 8)->default('NGN');
            $table->unsignedBigInteger('base_rate_minor');
            $table->unsignedBigInteger('extra_guest_minor')->default(0);
            $table->unsignedBigInteger('total_night_minor');
            $table->decimal('total_night_amount', 14, 2);
            $table->string('pricing_source', 48)->default('base_rate'); // exact_date_override | date_range_override | weekday_rate | base_rate
            $table->string('status', 24)->default('active')->index(); // active | released
            $table->timestamps();
            $table->unique(['reservation_id', 'unit_index', 'stay_date'], 'res_night_unit_unique');
            $table->index(['room_type_id', 'stay_date', 'status'], 'res_night_avail_idx');
            $table->index(['room_id', 'stay_date', 'status'], 'res_night_room_idx');
        });

        // 10. Reservation Status History
        Schema::create('reservation_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('from_payment_status', 24)->nullable();
            $table->string('to_payment_status', 24)->nullable();
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['reservation_id', 'created_at']);
        });

        // 11. Payment Transactions (Manual & Online-Ready)
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_reference', 64)->unique();
            $table->foreignId('reservation_id')->constrained('reservations')->restrictOnDelete();
            $table->string('type', 24)->default('payment')->index(); // payment | reversal | refund_payout
            $table->string('method', 32)->index(); // cash | bank_transfer | pos | online_paystack | online_flutterwave
            $table->string('provider', 32)->nullable()->index(); // manual | paystack | flutterwave
            $table->string('provider_reference', 120)->nullable()->index();
            $table->string('webhook_event_id', 120)->nullable()->unique();
            $table->string('currency_code', 8)->default('NGN');
            $table->bigInteger('amount_minor'); // positive for payment, negative for reversal/refund_payout
            $table->decimal('amount', 14, 2);
            $table->string('status', 24)->default('completed')->index(); // pending | completed | failed | reversed
            $table->string('receipt_number', 64)->nullable();
            $table->text('notes')->nullable();
            $table->json('provider_payload')->nullable(); // redacted payload
            $table->foreignId('reversed_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['reservation_id', 'status']);
        });

        // 12. Refund Requests & Approvals
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_reference', 64)->unique();
            $table->foreignId('reservation_id')->constrained('reservations')->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->foreignId('payout_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->string('currency_code', 8)->default('NGN');
            $table->unsignedBigInteger('amount_minor');
            $table->decimal('amount', 14, 2);
            $table->string('reason', 255);
            $table->string('status', 24)->default('pending')->index(); // pending | approved | rejected | processed
            $table->string('payout_method', 32)->nullable(); // cash | bank_transfer | pos | provider_refund
            $table->string('payout_reference', 120)->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('reservation_status_histories');
        Schema::dropIfExists('reservation_nights');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('booking_holds');
        Schema::dropIfExists('guests');
        Schema::dropIfExists('rate_plan_overrides');
        Schema::dropIfExists('rate_plan_room_types');
        Schema::dropIfExists('rate_plans');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_types');
    }
};
