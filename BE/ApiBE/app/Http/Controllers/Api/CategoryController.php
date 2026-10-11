<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    // Danh sách danh mục
    public function index()
    {
        return response()->json(
            Category::withCount('products')
                ->orderBy('name')
                ->paginate(50)
        );
    }

    // Chi tiết danh mục
    public function show(Category $category)
    {
        $category->loadCount('products');

        return response()->json($category);
    }

    // Thêm danh mục
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'slug' => 'required|string|max:180|unique:categories,slug',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $category = Category::create($data);

        return response()->json([
            'message' => 'Thêm danh mục thành công',
            'category' => $category,
        ], 201);
    }

    // Cập nhật danh mục
    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:180',
                Rule::unique('categories', 'slug')->ignore($category->id),
            ],
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $category->update($data);

        return response()->json([
            'message' => 'Cập nhật danh mục thành công',
            'category' => $category,
        ]);
    }

    // Xóa danh mục
    public function destroy(Category $category)
    {
        $category->delete();

        return response()->json([
            'message' => 'Xóa danh mục thành công',
        ]);
    }
}