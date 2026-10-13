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

        // Bỏ unique tạm thời để cập nhật slug an toàn
        Schema::table('blogs', function (Blueprint $table) {
            $table->dropUnique(['slug']);
        });

        // Đưa slug hiện tại sang giá trị tạm
        foreach ($blogs as $blog) {
            DB::table('blogs')
                ->where('id', $blog->id)
                ->update([
                    'slug' => '__tmp_blog_' . $blog->id,
                ]);
        }

        $usedSlugs = [];

        foreach ($blogs as $blog) {
            // Tạo slug từ tiêu đề, không nối ID
            $baseSlug = Str::slug($blog->title) ?: 'blog';
            $slug = $baseSlug;
            $suffix = 2;

            // Nếu tiêu đề trùng nhau thì thêm số thứ tự
            while (isset($usedSlugs[$slug])) {
                $slug = $baseSlug . '-' . $suffix;
                $suffix++;
            }

            $usedSlugs[$slug] = true;

            DB::table('blogs')
                ->where('id', $blog->id)
                ->update(['slug' => $slug]);
        }

        // Thêm lại ràng buộc slug duy nhất
        Schema::table('blogs', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        // Không tự động khôi phục slug cũ để tránh thay đổi dữ liệu ngoài ý muốn.
        // Nếu cần rollback, hãy xử lý dữ liệu slug trước khi hoàn tác.
    }
};