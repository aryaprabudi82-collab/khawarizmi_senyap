<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\Supplier;

class ItemService
{
    public function createItem(array $data): Item
    {
        return Item::query()->create($data);
    }

    public function updateItem(Item $item, array $data): Item
    {
        $item->update($data);

        return $item->refresh();
    }

    public function createCategory(array $data): ItemCategory
    {
        return ItemCategory::query()->create($data);
    }

    public function updateCategory(ItemCategory $category, array $data): ItemCategory
    {
        $category->update($data);

        return $category->refresh();
    }

    public function createSupplier(array $data): Supplier
    {
        return Supplier::query()->create($data);
    }

    public function updateSupplier(Supplier $supplier, array $data): Supplier
    {
        $supplier->update($data);

        return $supplier->refresh();
    }
}
