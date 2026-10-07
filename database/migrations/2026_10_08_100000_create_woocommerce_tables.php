<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WooCommerce control panel: an account can connect several stores. Everything that used to hide in
 * JSON / string columns (variation ids in product_variation_attribute.meta, the order id in orders.meta)
 * gets a real table keyed by store, so two stores cannot collide and lookups use an index.
 * Each table is created only if missing, so the migration can be re-run after a database restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('woocommerce_stores')) {
            Schema::create('woocommerce_stores', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('account_id')->index();
                $table->string('name');
                $table->string('base_url');               // https://shop.example.com/wp-json/wc/v3
                $table->text('consumer_key');             // encrypted (EncryptedCredential)
                $table->text('consumer_secret');          // encrypted
                $table->boolean('verify_ssl')->default(true);
                $table->string('ca_bundle')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('default_warehouse_id')->nullable();
                $table->unsignedBigInteger('default_brand_source_id')->nullable();
                $table->json('import_statuses')->nullable();           // ["processing"]
                $table->boolean('auto_import')->default(false);
                $table->string('wc_status_after_import')->default('completed');
                $table->timestamp('last_checked_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('woocommerce_variation_links')) {
            Schema::create('woocommerce_variation_links', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('account_id')->index();
                $table->unsignedBigInteger('wc_product_id');
                $table->unsignedBigInteger('wc_variation_id')->default(0); // 0 = simple product
                $table->unsignedBigInteger('product_variation_attribute_id');
                $table->string('source', 20)->default('manual');            // manual | auto | legacy
                $table->timestamps();

                $table->unique(['store_id', 'wc_product_id', 'wc_variation_id'], 'wc_variation_links_unique');
                $table->index('product_variation_attribute_id', 'wc_variation_links_pva_idx');
            });
        }

        if (! Schema::hasTable('woocommerce_order_links')) {
            Schema::create('woocommerce_order_links', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('account_id')->index();
                $table->unsignedBigInteger('wc_order_id');
                $table->unsignedBigInteger('order_id');
                $table->timestamp('imported_at')->nullable();
                $table->string('last_wc_status', 40)->nullable();
                $table->string('last_pushed_status', 40)->nullable();
                $table->timestamp('last_pushed_at')->nullable();
                $table->timestamps();

                $table->unique(['store_id', 'wc_order_id'], 'wc_order_links_unique');
                $table->index('order_id', 'wc_order_links_order_idx');
            });
        }

        if (! Schema::hasTable('woocommerce_status_mappings')) {
            Schema::create('woocommerce_status_mappings', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('order_status_id');
                $table->string('wc_status', 40);
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique(['store_id', 'order_status_id'], 'wc_status_mappings_unique');
            });
        }

        if (! Schema::hasTable('woocommerce_sync_logs')) {
            Schema::create('woocommerce_sync_logs', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('account_id')->index();
                $table->string('direction', 20);          // import | push | mapping
                $table->string('entity', 20);             // order | variation
                $table->unsignedBigInteger('entity_id')->nullable(); // local id (order / pva)
                $table->unsignedBigInteger('wc_id')->nullable();
                $table->string('status', 20);             // success | failed | skipped
                $table->text('message')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['store_id', 'created_at'], 'wc_sync_logs_store_created_idx');
                $table->index(['store_id', 'status'], 'wc_sync_logs_store_status_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['woocommerce_sync_logs', 'woocommerce_status_mappings', 'woocommerce_order_links', 'woocommerce_variation_links', 'woocommerce_stores'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
