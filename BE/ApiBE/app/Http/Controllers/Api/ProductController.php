<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
class ProductController extends Controller
{
    // Lấy danh sách sản phẩm kèm danh mục.
    public function index()
    {
        return response()->json(
            Product::with('category')
                ->latest()
                ->paginate(12)
        );
    }

    // Lấy chi tiết sản phẩm kèm danh mục.

public function show(string $product)
{
    if (ctype_digit($product)) {
        // URL cũ: tìm sản phẩm bằng ID.
        $item = Product::with('category')
            ->findOrFail((int) $product);
    } else {
        // URL mới: tìm sản phẩm bằng slug.
        $item = Product::with('category')
            ->where('slug', $product)
            ->firstOrFail();
    }

    return response()->json($item);
}


    // Thêm sản phẩm.
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|integer|exists:categories,id',
            'img' => 'required|image|max:5120',
        ]);
        $data['slug'] = $this->makeUniqueSlug($data['name']);

        // Chỉ lưu HTML đã được lọc an toàn.
        $data['description'] = $this->sanitizeProductHtml(
            $data['description']
        );

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

    // Cập nhật sản phẩm.
    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'category_id' => 'sometimes|required|integer|exists:categories,id',

            // Không bắt buộc chọn ảnh mới khi sửa sản phẩm.
            'img' => 'sometimes|nullable|image|max:5120',
        ]);
        // Chỉ tạo slug mới khi tên sản phẩm thực sự thay đổi.
if (
    isset($data['name']) &&
    $data['name'] !== $product->name
) {
    $data['slug'] = $this->makeUniqueSlug(
        $data['name'],
        $product->id
    );
}

        if (array_key_exists('description', $data)) {
            $data['description'] = $this->sanitizeProductHtml(
                $data['description']
            );
        }

        if ($request->hasFile('img')) {
            $newImage = $request->file('img')
                ->store('products', 'public');

            // Chỉ xóa ảnh cũ sau khi ảnh mới đã lưu thành công.
            $oldImage = $product->img;

            $data['img'] = $newImage;

            if ($oldImage && $oldImage !== $newImage) {
                Storage::disk('public')->delete($oldImage);
            }
        }

        $product->update($data);
        $product->load('category');

        return response()->json([
            'message' => 'Cập nhật sản phẩm thành công',
            'product' => $product,
        ]);
    }

    // Xóa sản phẩm.
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

    /**
     * Lọc HTML mô tả sản phẩm bằng danh sách thẻ cho phép.
     * Loại bỏ script, event handler và các URL nguy hiểm.
     */
    private function sanitizeProductHtml(string $html): string
    {
        $allowedTags =
            '<p><br><hr><h2><h3><h4><strong><b><em><i><u>' .
            '<ul><ol><li><blockquote><a><img><figure><figcaption>';

        $clean = strip_tags($html, $allowedTags);

        // Xóa comment HTML.
        $clean = preg_replace('/<!--.*?-->/s', '', $clean) ?? '';

        // Chỉ dựng lại thẻ với thuộc tính đã được cho phép.
        return preg_replace_callback(
            '/<\s*(\/?)\s*([a-z][a-z0-9]*)\b([^>]*)>/i',
            function (array $match): string {
                $closing = $match[1] === '/';
                $tag = strtolower($match[2]);
                $attributes = $match[3];

                $allowed = [
                    'p', 'br', 'hr', 'h2', 'h3', 'h4',
                    'strong', 'b', 'em', 'i', 'u',
                    'ul', 'ol', 'li', 'blockquote',
                    'a', 'img', 'figure', 'figcaption',
                ];

                if (!in_array($tag, $allowed, true)) {
                    return '';
                }

                if ($closing) {
                    // Các thẻ rỗng không cần thẻ đóng.
                    if (in_array($tag, ['br', 'hr', 'img'], true)) {
                        return '';
                    }

                    return '</' . $tag . '>';
                }

                if ($tag === 'a') {
                    $href = $this->readHtmlAttribute(
                        $attributes,
                        'href'
                    );

                    $title = $this->readHtmlAttribute(
                        $attributes,
                        'title'
                    );

                    $target = $this->readHtmlAttribute(
                        $attributes,
                        'target'
                    );

                    $result = '<a';

                    if (
                        $href !== null &&
                        $this->isSafeProductUrl($href)
                    ) {
                        $result .= ' href="' .
                            $this->escapeHtmlAttribute($href) . '"';
                    }

                    if ($title !== null) {
                        $result .= ' title="' .
                            $this->escapeHtmlAttribute($title) . '"';
                    }

                    if (in_array($target, ['_blank', '_self'], true)) {
                        $result .= ' target="' . $target . '"';
                    }

                    if ($target === '_blank') {
                        $result .= ' rel="noopener noreferrer"';
                    }

                    return $result . '>';
                }

                if ($tag === 'img') {
                    $src = $this->readHtmlAttribute(
                        $attributes,
                        'src'
                    );

                    // Ảnh không có nguồn hợp lệ sẽ bị loại bỏ.
                    if (
                        $src === null ||
                        !$this->isSafeProductUrl($src, true)
                    ) {
                        return '';
                    }

                    $alt = $this->readHtmlAttribute(
                        $attributes,
                        'alt'
                    ) ?? '';

                    return '<img src="' .
                        $this->escapeHtmlAttribute($src) .
                        '" alt="' .
                        $this->escapeHtmlAttribute($alt) .
                        '" loading="lazy">';
                }

                // Loại toàn bộ thuộc tính khỏi các thẻ định dạng khác.
                return '<' . $tag . '>';
            },
            $clean
        ) ?? '';
    }

    private function readHtmlAttribute(
        string $attributes,
        string $name
    ): ?string {
        $pattern = "/(?:^|\\s)" . preg_quote($name, '/') .
            "\\s*=\\s*(?:\"([^\"]*)\"|'([^']*)'|([^\\s>]+))/i";

        if (!preg_match($pattern, $attributes, $match)) {
            return null;
        }

        $value = $match[1] ?? '';

        if ($value === '' && isset($match[2])) {
            $value = $match[2];
        } elseif ($value === '' && isset($match[3])) {
            $value = $match[3];
        }

        return trim(html_entity_decode(
            $value,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));
    }

    private function isSafeProductUrl(
        string $url,
        bool $isImage = false
    ): bool {
        $url = trim($url);

        if (
            $url === '' ||
            preg_match('/[\x00-\x20<>"\']/', $url)
        ) {
            return false;
        }

        // Cho phép URL HTTP/HTTPS.
        if (preg_match('/^https?:\/\//i', $url)) {
            return true;
        }

        // Cho phép đường dẫn nội bộ, nhưng không cho //domain.
        if (
            str_starts_with($url, '/') &&
            !str_starts_with($url, '//') &&
            !str_starts_with($url, '/\\')
        ) {
            return true;
        }

        // Ảnh chỉ nhận URL HTTP/HTTPS hoặc đường dẫn nội bộ.
        if ($isImage) {
            return false;
        }

        // Hỗ trợ liên kết neo và đường dẫn tương đối.
        return str_starts_with($url, '#') ||
            str_starts_with($url, './') ||
            str_starts_with($url, '../');
    }

    private function escapeHtmlAttribute(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
    private function makeUniqueSlug(
    string $name,
    ?int $ignoreId = null
): string {
    $baseSlug = Str::slug($name);

    if ($baseSlug === '') {
        $baseSlug = 'san-pham';
    }

    // Giới hạn độ dài để URL gọn và phù hợp cột slug.
    $baseSlug = Str::limit($baseSlug, 150, '');
    $slug = $baseSlug;
    $suffix = 2;

    while (
        Product::where('slug', $slug)
            ->when(
                $ignoreId !== null,
                fn ($query) => $query->where('id', '!=', $ignoreId)
            )
            ->exists()
    ) {
        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    }

    return $slug;
}
}
