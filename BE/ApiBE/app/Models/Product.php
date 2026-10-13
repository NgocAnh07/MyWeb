<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}