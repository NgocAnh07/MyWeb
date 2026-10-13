<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $blogs = DB::table('blogs')
            ->select('id', 'title')
            ->orderBy('id')
            ->get();

        // Tạm bỏ unique để tránh xung đột slug khi đổi tên
        Schema::table('blogs', function (Blueprint $table) {
            $table->dropUnique(['slug']);
        });

        // Đưa slug cũ sang tên tạm
        foreach ($blogs as $blog) {
            DB::table('blogs')
                ->where('id', $blog->id)
                ->update(['slug' => '__tmp_blog_' . $blog->id]);
        }

        $usedSlugs = [];

        foreach ($blogs as $blog) {
            $baseSlug = Str::slug($blog->title) ?: 'blog';
            $slug = $baseSlug;
            $suffix = 2;

            // Nếu trùng tiêu đề thì thêm số thứ tự, không thêm ID
            while (isset($usedSlugs[$slug])) {
                $slug = $baseSlug . '-' . $suffix;
                $suffix++;
            }

            $usedSlugs[$slug] = true;

            DB::table('blogs')
                ->where('id', $blog->id)
                ->update(['slug' => $slug]);
        }

        Schema::table('blogs', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        $blogs = DB::table('blogs')
            ->select('id', 'title')
            ->orderBy('id')
            ->get();

        Schema::table('blogs', function (Blueprint $table) {
            $table->dropUnique(['slug']);
        });

        foreach ($blogs as $blog) {
            DB::table('blogs')
                ->where('id', $blog->id)
                ->update(['slug' => '__tmp_blog_' . $blog->id]);
        }

        // Khôi phục định dạng slug cũ: tiêu đề + ID
        foreach ($blogs as $blog) {
            $slug = (Str::slug($blog->title) ?: 'blog')
                . '-' . $blog->id;

            DB::table('blogs')
                ->where('id', $blog->id)
                ->update(['slug' => $slug]);
        }

        Schema::table('blogs', function (Blueprint $table) {
            $table->unique('slug');
        });
    }
};