<?php

namespace App\Rules;

use App\Models\Category;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Case-insensitive uniqueness check on the category name.
 *
 * SQLite compares text with a binary collation and MySQL's default collation
 * is case-insensitive, so a plain unique() rule behaves differently depending
 * on the connection. This keeps "Cola" and "cola" colliding on both.
 */
class UniqueCategoryName implements ValidationRule
{
    public function __construct(private readonly ?Category $ignore = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = Category::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)]);

        if ($this->ignore !== null) {
            $query->whereKeyNot($this->ignore->getKey());
        }

        if ($query->exists()) {
            $fail('A category with this name already exists.');
        }
    }
}
