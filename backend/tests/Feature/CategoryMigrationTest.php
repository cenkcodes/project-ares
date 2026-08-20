<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CategoryMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_table_has_required_schema(): void
    {
        $this->assertTrue(
            Schema::hasColumns(
                'categories',
                [
                    'id',
                    'name',
                    'slug',
                    'description',
                    'is_active',
                    'created_at',
                    'updated_at',
                ],
            ),
        );
    }

    public function test_category_slug_is_unique(): void
    {
        DB::table('categories')->insert([
            'name' => 'First Category',
            'slug' => 'unique-category',
            'is_active' => true,
        ]);

        $this->expectException(
            QueryException::class
        );

        DB::table('categories')->insert([
            'name' => 'Second Category',
            'slug' => 'unique-category',
            'is_active' => true,
        ]);
    }

    public function test_category_defaults_match_application_schema(): void
    {
        $id = DB::table('categories')
            ->insertGetId([
                'name' => 'Default Category',
                'slug' => 'default-category',
            ]);

        $category = DB::table('categories')
            ->where('id', $id)
            ->first();

        $this->assertNull(
            $category->description
        );

        $this->assertTrue(
            (bool) $category->is_active
        );
    }
}
