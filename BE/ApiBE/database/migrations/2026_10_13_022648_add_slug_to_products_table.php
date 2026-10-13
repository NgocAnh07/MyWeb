<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug', 191)->nullable()->unique();
        });

        // Tạo slug cho các sản phẩm đã tồn tại.
        $products = DB::table('products')
            ->select('id', 'name')
            ->orderBy('id')
            ->get();

        $usedSlugs = [];

        foreach ($products as $product) {
            $baseSlug = Str::slug($product->name ?? '');

            if ($baseSlug === '') {
                $baseSlug = 'san-pham';
            }

            $baseSlug = Str::limit($baseSlug, 150, '');
            $slug = $baseSlug;

            // Nếu tên bị trùng, thêm ID để URL không bị trùng.
            if (isset($usedSlugs[$slug])) {
                $slug = $baseSlug . '-' . $product->id;
            }

            $suffix = 1;

            while (isset($usedSlugs[$slug])) {
                $slug = Str::limit($baseSlug, 140, '')
                    . '-' . $product->id . '-' . $suffix;

                $suffix++;
            }

            $usedSlugs[$slug] = true;

            DB::table('products')
                ->where('id', $product->id)
                ->update(['slug' => $slug]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};