<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\Product;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Sinh sitemap.xml tự động từ bảng products và blogs.
     * Thêm / sửa sản phẩm, bài viết thì sitemap tự cập nhật.
     */
    public function index(): Response
    {
        $baseUrl = 'https://duychandung.com';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Các trang cố định
        $xml .= $this->urlTag($baseUrl . '/', null, '1.0');
        $xml .= $this->urlTag($baseUrl . '/search-products', null, '0.8');

        // Sản phẩm: FE dùng dạng /{slug}
        Product::query()
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->each(function ($product) use (&$xml, $baseUrl) {
                $xml .= $this->urlTag(
                    $baseUrl . '/' . rawurlencode($product->slug),
                    $product->updated_at,
                    '0.8'
                );
            });

        // Bài viết: FE cũng dùng dạng /{slug}
        Blog::query()
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->each(function ($blog) use (&$xml, $baseUrl) {
                $xml .= $this->urlTag(
                    $baseUrl . '/' . rawurlencode($blog->slug),
                    $blog->updated_at,
                    '0.6'
                );
            });

        $xml .= '</urlset>' . "\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    private function urlTag(string $loc, $lastmod, string $priority): string
    {
        $tag = "  <url>\n";
        $tag .= '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";

        if ($lastmod) {
            $tag .= '    <lastmod>' . $lastmod->toAtomString() . "</lastmod>\n";
        }

        $tag .= '    <priority>' . $priority . "</priority>\n";
        $tag .= "  </url>\n";

        return $tag;
    }
}
