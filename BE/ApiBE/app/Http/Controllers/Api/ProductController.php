<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    // Lấy danh sách sản phẩm kèm danh mục
    public function index()
    {
        return response()->json(
            Product::with('category')
                ->latest()
                ->paginate(12)
        );
    }

    // Lấy chi tiết sản phẩm kèm danh mục
    public function show(Product $product)
    {
        $product->load('category');

        return response()->json($product);
    }

    // Thêm sản phẩm
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|integer|exists:categories,id',
            'img' => 'required|image|max:5120',
        ]);

        if ($request->hasFile('img')) {
            $data['img'] = $request->file('img')
                ->store('products', 'public');
        }

        $product = Product::create($data);
        $product->load('category');

        return response()->json([
            'message' => 'Thêm sản phẩm thành công',
            'product' => $product,
        ], 201);
    }

    // Cập nhật sản phẩm
    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
    'name' => 'sometimes|required|string|max:255',
    'description' => 'sometimes|required|string',
    'category_id' => 'sometimes|required|integer|exists:categories,id',
    'img' => 'required|image|max:5120',
]);

        if ($request->hasFile('img')) {
            if ($product->img) {
                Storage::disk('public')->delete($product->img);
            }

            $data['img'] = $request->file('img')
                ->store('products', 'public');
        }

        $product->update($data);
        $product->load('category');

        return response()->json([
            'message' => 'Cập nhật sản phẩm thành công',
            'product' => $product,
        ]);
    }

    // Xóa sản phẩm
    public function destroy(Product $product)
    {
        if ($product->img) {
            Storage::disk('public')->delete($product->img);
        }

        $product->delete();

        return response()->json([
            'message' => 'Xóa sản phẩm thành công',
        ]);
    }
}