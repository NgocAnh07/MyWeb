<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BannerController extends Controller
{
    // API công khai: lấy đường dẫn banner hiện tại.
    public function show()
    {
        $disk = Storage::disk('public');

        $files = collect($disk->files('banner'))
            ->filter(function ($path) use ($disk) {
                return preg_match(
                    '#^banner/home-banner-[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$#i',
                    $path
                ) && $disk->exists($path);
            })
            ->sortByDesc(fn ($path) => $disk->lastModified($path))
            ->values();

        $path = $files->first();

        return response()->json([
            'path' => $path,
            'updated_at' => $path
                ? $disk->lastModified($path)
                : null,
        ]);
    }

    // Chỉ Admin được tải banner mới.
    public function update(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $disk = Storage::disk('public');
        $file = $request->file('image');

        $extension = strtolower(
            $file->guessExtension()
                ?: $file->getClientOriginalExtension()
                ?: 'jpg'
        );

        $filename = 'home-banner-'
            . now()->format('YmdHis')
            . '-'
            . Str::random(8)
            . '.'
            . $extension;

        // Lưu ảnh mới trước khi xóa banner cũ.
        $newPath = $file->storeAs(
            'banner',
            $filename,
            'public'
        );

        if (!$newPath) {
            return response()->json([
                'message' => 'Không thể lưu banner mới.',
            ], 500);
        }

        // Chỉ xóa các banner do chức năng này quản lý.
        foreach ($disk->files('banner') as $oldPath) {
            if (
                $oldPath !== $newPath
                && preg_match(
                    '#^banner/home-banner-[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$#i',
                    $oldPath
                )
            ) {
                $disk->delete($oldPath);
            }
        }

        return response()->json([
            'message' => 'Cập nhật banner thành công.',
            'path' => $newPath,
            'updated_at' => $disk->lastModified($newPath),
        ]);
    }
}