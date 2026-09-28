<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 05 §3.8〜§3.10（会計・明細・明細のオプション）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->char('client_uuid', 36);
            $table->date('business_date');
            $table->dateTime('sold_at');
            $table->foreignId('tax_type_id')->constrained();
            $table->string('tax_type_name', 20);
            $table->integer('tax_rate_permille');
            $table->string('price_mode', 20);
            $table->string('rounding', 10);
            $table->integer('subtotal');
            $table->string('discount_type', 10)->nullable();
            $table->integer('discount_value')->default(0);
            $table->integer('discount_amount')->default(0);
            $table->integer('total');
            $table->integer('tax_amount');
            $table->foreignId('payment_method_id')->constrained();
            $table->string('payment_method_name', 20);
            $table->boolean('is_cash');
            $table->integer('received');
            $table->integer('change_amount');
            $table->integer('customer_count')->nullable();
            $table->string('memo', 200)->nullable();
            $table->string('status', 10)->default('completed');
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->foreignId('user_id')->constrained();
            $table->string('device_name', 30)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'client_uuid']);
            $table->index(['store_id', 'business_date', 'status']);
            $table->index(['store_id', 'sold_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->index()->constrained();
            $table->foreignId('product_id')->index()->constrained();
            $table->string('product_name', 50);
            $table->integer('unit_price');
            $table->integer('options_price')->default(0);
            $table->integer('quantity');
            $table->integer('line_total');
            $table->integer('sort_order')->default(0);
        });

        Schema::create('sale_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->index()->constrained();
            $table->foreignId('product_option_id')->constrained();
            $table->string('option_name', 30);
            $table->integer('price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_options');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
