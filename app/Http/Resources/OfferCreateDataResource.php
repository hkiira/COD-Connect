<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OfferCreateDataResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     */
    public function toArray($request): array
    {
        $sections = data_get($this->resource, 'data', []);
        $formatted = [];

        foreach ($sections as $section => $values) {
            $formatted[$section] = [
                'inactive' => (new OfferCreateSectionResource(data_get($values, 'inactive', [])))->resolve($request),
            ];
        }

        return [
            'statut' => (int) data_get($this->resource, 'statut', 1),
            'data' => $formatted,
        ];
    }
}
