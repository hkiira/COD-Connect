<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductInventoryResource extends JsonResource
{
    public function toArray($request)
    {
        // Get the first taxonomy (category) if available
        $category = 'Uncategorized';
        if ($this->accountProducts && $this->accountProducts->isNotEmpty()) {
            $firstAccountProduct = $this->accountProducts->first();
            if ($firstAccountProduct->taxonomies && $firstAccountProduct->taxonomies->isNotEmpty()) {
                $category = $firstAccountProduct->taxonomies->first()->title;
            }
        }

        // Get the principal image or the first image
        $imageUrl = 'https://via.placeholder.com/150';
        if ($this->images && $this->images->isNotEmpty()) {
            $principalImage = $this->images->where('pivot.statut', 2)->first();
            $image = $principalImage ?: $this->images->first();
            if ($image) {
                $imageUrl = url($image->photo_dir . $image->photo);
            }
        }

        $variations = $this->productVariationAttributes->map(function ($pva) {
            // Extract size and color from child variation attributes
            $size = 'N/A';
            $color = 'N/A';
            
            if ($pva->variationAttribute && $pva->variationAttribute->childVariationAttributes) {
                foreach ($pva->variationAttribute->childVariationAttributes as $child) {
                    if ($child->attribute && $child->attribute->typeAttribute) {
                        $typeTitle = strtolower($child->attribute->typeAttribute->title);
                        if ($typeTitle === 'size' || $typeTitle === 'taille') {
                            $size = $child->attribute->title;
                        } elseif ($typeTitle === 'color' || $typeTitle === 'couleur') {
                            $color = $child->attribute->title;
                        }
                    }
                }
            }

            // Calculate stock
            $availableStock = 0;
            if ($pva->activeWarehouses) {
                $availableStock = $pva->activeWarehouses->sum('pivot.quantity');
            }

            // Calculate ordered and transit stock based on order statuses
            // Assuming:
            // Ordered stock comes from supplier orders (statut 1 = pending/ordered)
            // 5: En préparation, 6: En livraison, 8: Annulée, 9: En souffrance -> Transit
            $orderedStock = 0;
            $transitStock = 0;

            if ($pva->supplierOrderPvas) {
                foreach ($pva->supplierOrderPvas as $supplierOrderPva) {
                    if ($supplierOrderPva->supplierOrder && $supplierOrderPva->supplierOrder->statut == 1) {
                        $orderedStock += $supplierOrderPva->quantity;
                    }
                }
            }

            if ($pva->orderPvas) {
                foreach ($pva->orderPvas as $orderPva) {
                    if ($orderPva->order && $orderPva->order->orderStatus) {
                        $statusId = $orderPva->order->orderStatus->id;
                        $quantity = $orderPva->quantity;

                        if (in_array($statusId, [5, 6, 8, 9])) {
                            $transitStock += $quantity;
                        }
                    }
                }
            }

            // Determine status string
            $status = 'In Stock';
            if ($availableStock <= 0) {
                $status = 'Out of Stock';
            } elseif ($availableStock < 5) {
                $status = 'Low Stock';
            }

            return [
                'size' => $size,
                'color' => $color,
                'ordered_stock' => $orderedStock,
                'transit_stock' => $transitStock,
                'available_stock' => $availableStock,
                'status' => $status,
            ];
        });

        return [
            'id' => $this->code ?? $this->reference ?? 'PRD-' . $this->id,
            'name' => $this->title,
            'category' => $category,
            'image' => $imageUrl,
            'variations' => $variations,
        ];
    }
}
