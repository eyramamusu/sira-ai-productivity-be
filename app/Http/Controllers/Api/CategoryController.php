<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CategoryResource::collection($request->user()->categories()->orderBy('created_at')->get());
    }

    public function store(Request $request): CategoryResource
    {
        $data = $request->validate($this->rules() + ['id' => ['sometimes', 'uuid']]);
        $user = $request->user();

        $category = $this->replayed(Category::class, $data['id'] ?? null, $user);
        if ($category) {
            $category->update($data);
        } else {
            $category = $user->categories()->create($data);
        }

        return new CategoryResource($category);
    }

    public function update(Request $request, Category $category): CategoryResource
    {
        $this->own($request, $category);
        $category->update($request->validate(array_map(fn ($r) => ['sometimes', ...$r], $this->rules())));

        return new CategoryResource($category);
    }

    public function destroy(Request $request, Category $category): Response
    {
        $this->own($request, $category);
        DB::transaction(function () use ($category) {
            $category->tasks()->update(['category_id' => null, 'updated_at' => now()]);
            $category->delete();
        });

        return response()->noContent();
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:9'],
            'icon' => ['nullable', 'string', 'max:40'],
        ];
    }
}
