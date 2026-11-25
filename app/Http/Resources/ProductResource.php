<?php

namespace App\Http\Resources;

use App\Services\InventoryCostService;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'barcode' => $this->barcode,
            'sku' => $this->sku,
            'image' => $this->image
                ? (str_starts_with($this->image, 'http') ? $this->image : url('storage/'.ltrim($this->image, '/')))
                : null,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            // A recipe has no buy price of its own: report its cost per portion in the active outlet.
            'buy_price' => $this->is_composite
                ? (float) app(InventoryCostService::class)->lineCost($this->resource, app(OutletAccessService::class)->costWarehouseId($request), 1)
                : (float) $this->buy_price,
            'sell_price' => (float) $this->sell_price,
            'stock' => (int) $this->stock,
            'min_stock' => (int) $this->min_stock,
            'max_stock' => (int) $this->max_stock,
            'tax_type' => $this->tax_type,
            'tax_rate' => (float) $this->tax_rate,
            'is_composite' => (bool) $this->is_composite,
            'is_raw_material' => (bool) $this->is_raw_material,
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
