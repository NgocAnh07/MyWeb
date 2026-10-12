<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BlogController extends Controller
{
    public function index(Request $request)
    {
        if ((string) $request->query('random') === '3') {
            return response()->json(Blog::inRandomOrder()->limit(3)->get());
        }

        return response()->json(Blog::latest()->paginate(12));
    }

    public function show(Blog $blog)
    {
        return response()->json($blog);
    }

    public function uploadContentImage(Request $request)
    {
        $validated = $request->validate([
            'image' => 'required|image|max:5120',
        ]);

        $path = $request->file('image')->store('blogs/content', 'public');

        return response()->json([
            'message' => 'Tải ảnh trong nội dung thành công.',
            'path' => $path,
        ], 201);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'img' => 'nullable|image|max:5120',
        ]);

        $data['description'] = $this->sanitizeBlogHtml($data['description']);

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
            'img' => 'sometimes|nullable|image|max:5120',
        ]);

        if (array_key_exists('description', $data)) {
            $data['description'] = $this->sanitizeBlogHtml($data['description']);
        }

        if ($request->hasFile('img')) {
            $oldImage = $blog->img;
            $newImage = $request->file('img')->store('blogs', 'public');
            $data['img'] = $newImage;

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

    /**
     * Chỉ giữ các thẻ/thuộc tính cần cho nội dung bài viết SEO.
     * Dùng allowlist để không lưu script, event handler hay URL nguy hiểm.
     */
    private function sanitizeBlogHtml(string $html): string
    {
        $allowedTags = '<div><p><br><hr><h2><h3><h4><strong><b><em><i><u><ul><ol><li><blockquote><a><img><figure><figcaption>';
        $clean = strip_tags($html, $allowedTags);

        // Xóa các thuộc tính có thể thực thi code hoặc ghi đè kiểu trình bày.
        $clean = preg_replace('/\s+(?:on[a-z]+|style|srcdoc|formaction|xlink:href)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';

        // Chỉ cho phép thuộc tính href/title/target ở link.
        $clean = preg_replace_callback('/<a\b([^>]*)>/i', function (array $match): string {
            $attributes = $this->parseSafeHtmlAttributes($match[1]);
            $href = trim($attributes['href'] ?? '');
            if ($href !== '' && !$this->isSafeContentUrl($href)) {
                $href = '';
            }

            $result = '<a';
            if ($href !== '') {
                $result .= ' href="' . $this->escapeHtmlAttribute($href) . '"';
            }
            if (!empty($attributes['title'])) {
                $result .= ' title="' . $this->escapeHtmlAttribute($attributes['title']) . '"';
            }
            if (($attributes['target'] ?? '') === '_blank') {
                $result .= ' target="_blank" rel="noopener noreferrer"';
            }
            return $result . '>';
        }, $clean) ?? '';

        // Ảnh chỉ giữ src/alt/title và kích thước số; ALT luôn được xuất ra.
        $clean = preg_replace_callback('/<img\b([^>]*)>/i', function (array $match): string {
            $attributes = $this->parseSafeHtmlAttributes($match[1]);
            $src = trim($attributes['src'] ?? '');
            if ($src === '' || !$this->isSafeContentUrl($src)) {
                return '';
            }

            $alt = trim($attributes['alt'] ?? '');
            $result = '<img src="' . $this->escapeHtmlAttribute($src) . '" alt="' . $this->escapeHtmlAttribute($alt) . '" loading="lazy"';
            if (!empty($attributes['title'])) {
                $result .= ' title="' . $this->escapeHtmlAttribute($attributes['title']) . '"';
            }
            foreach (['width', 'height'] as $dimension) {
                if (!empty($attributes[$dimension]) && ctype_digit($attributes[$dimension])) {
                    $result .= ' ' . $dimension . '="' . $attributes[$dimension] . '"';
                }
            }
            return $result . '>';
        }, $clean) ?? '';

        // Các thẻ cấu trúc không cần class, id hay style do người dùng nhập.
        $clean = preg_replace_callback('/<(\/)?(div|p|br|hr|h2|h3|h4|strong|b|em|i|u|ul|ol|li|blockquote|figure|figcaption)\b[^>]*>/i', function (array $match): string {
            $tag = strtolower($match[2]);
            if (in_array($tag, ['br', 'hr'], true)) {
                return $match[1] ? '' : '<' . $tag . '>';
            }
            return $match[1] ? '</' . $tag . '>' : '<' . $tag . '>';
        }, $clean) ?? '';

        return trim($clean);
    }

    /** @return array<string, string> */
    private function parseSafeHtmlAttributes(string $rawAttributes): array
    {
        $attributes = [];
        preg_match_all('/([a-zA-Z_:][a-zA-Z0-9:._-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $rawAttributes, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            $doubleQuoted = $match[2] ?? '';
            $singleQuoted = $match[3] ?? '';
            $unquoted = $match[4] ?? '';
            $value = html_entity_decode($doubleQuoted !== '' ? $doubleQuoted : ($singleQuoted !== '' ? $singleQuoted : $unquoted), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (in_array($name, ['href', 'src', 'alt', 'title', 'target', 'width', 'height'], true)) {
                $attributes[$name] = $value;
            }
        }
        return $attributes;
    }

    private function escapeHtmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isSafeContentUrl(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/[\x00-\x20]/', $url)) {
            return false;
        }

        return (bool) preg_match('/^(https?:\/\/|\/(?!\/)|#|\.\.?\/|storage\/)/i', $url);
    }

}
