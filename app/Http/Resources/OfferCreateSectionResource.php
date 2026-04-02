<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferCreateSectionResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     */
    public function toArray($request): array
    {
        $rows = collect(data_get($this->resource, 'data', []))
            ->map(function ($item) {
                if ($item instanceof Model) {
                    return $item->toArray();
                }

                return is_array($item) ? $item : (array) $item;
            })
            ->values()
            ->all();

        return [
            'data' => $rows,
            'total' => (int) data_get($this->resource, 'total', 0),
            'current_page' => (int) data_get($this->resource, 'current_page', 0),
            'per_page' => (int) data_get($this->resource, 'per_page', 10),
        ];
    }
}
