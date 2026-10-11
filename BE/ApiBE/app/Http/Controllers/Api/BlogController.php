<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BlogController extends Controller
{
    public function index()
    {
        return response()->json(Blog::latest()->paginate(12));
    }

    public function show(Blog $blog)
    {
        return response()->json($blog);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'img' => 'required|image|max:5120',
        ]);

        if ($request->hasFile('img')) {
            $data['img'] = $request->file('img')->store('blogs', 'public');
        }

        $blog = Blog::create($data);

        return response()->json([
            'message' => 'Thêm bài viết thành công',
            'blog' => $blog,
        ], 201);
    }

    public function update(Request $request, Blog $blog)
    {
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'img' => 'required|image|max:5120',
        ]);

        if ($request->hasFile('img')) {
            $oldImage = $blog->img;
            $newImage = $request->file('img')->store('blogs', 'public');
            $data['img'] = $newImage;

            // Xóa ảnh cũ sau khi ảnh mới đã được lưu thành công.
            if ($oldImage && $oldImage !== $newImage) {
                Storage::disk('public')->delete($oldImage);
            }
        }

        $blog->update($data);

        return response()->json([
            'message' => 'Cập nhật bài viết thành công',
            'blog' => $blog->fresh(),
        ]);
    }

    public function destroy(Blog $blog)
    {
        if ($blog->img) {
            Storage::disk('public')->delete($blog->img);
        }

        $blog->delete();

        return response()->json([
            'message' => 'Xóa bài viết thành công',
        ]);
    }
}
