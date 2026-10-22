<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Product extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'img',
        'category_id',
    ];

    // Một sản phẩm thuộc một danh mục
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    public function images(): HasMany
{
    return $this->hasMany(ProductImage::class)
        ->orderBy('sort_order');
}
}