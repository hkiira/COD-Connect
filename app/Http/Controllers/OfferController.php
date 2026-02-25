<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Taxonomy;
use App\Models\Brand;
use App\Models\Source;
use App\Models\CustomerType;
use App\Models\City;
use App\Models\Region;
use App\Models\Country;
use App\Models\Sector;
use App\Models\OfferType;
use App\Models\ProductVariationAttribute;
use App\Models\Image;
use App\Models\AccountUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class OfferController extends Controller
{
    public static function index(Request $request)
    {
        $searchIds = [];
        $request = collect($request->query())->toArray();
        if (isset($request['products']) && array_filter($request['products'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['products'] as $productId) {
                if (Product::find($productId))
                    $searchIds = array_merge($searchIds, Product::find($productId)->offers->pluck('id')->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['warehouses']) && array_filter($request['warehouses'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['warehouses'] as $warehouseId) {
                if (Warehouse::find($warehouseId))
                    $searchIds = array_merge($searchIds, Warehouse::find($warehouseId)->offers->pluck('id')->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['categories']) && array_filter($request['categories'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['categories'] as $taxonomyId) {
                if (Taxonomy::find($taxonomyId))
                    $searchIds = array_merge($searchIds, Taxonomy::find($taxonomyId)->offers->pluck('id')->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['brands']) && array_filter($request['brands'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['brands'] as $brandId) {
                if (Brand::find($brandId))
                    $searchIds = array_merge($searchIds, Brand::find($brandId)->offers->pluck('id')->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['sources']) && array_filter($request['sources'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['sources'] as $sourceId) {
                if (Source::find($sourceId))
                    $searchIds = array_merge($searchIds, Source::find($sourceId)->offers->pluck('id')->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['customer_types']) && array_filter($request['customer_types'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['customer_types'] as $customerTypeId) {
                if (CustomerType::find($customerTypeId))
                    $searchIds = array_merge($searchIds, CustomerType::find($customerTypeId)->offers->pluck('id')->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['cities']) && array_filter($request['cities'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['cities'] as $cityId) {
                if (City::find($cityId))
                    $searchIds = array_merge($searchIds, City::find($cityId)->offers->pluck('id')->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['regions']) && array_filter($request['regions'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['regions'] as $regionId) {
                if (Region::find($regionId))
                    $searchIds = array_merge($searchIds, Region::find($regionId)->offers->pluck('id')->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['countries']) && array_filter($request['countries'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['countries'] as $countryId) {
                if (Country::find($countryId))
                    $searchIds = array_merge($searchIds, Country::find($countryId)->offers->pluck('id')->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['sectors']) && array_filter($request['sectors'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['sectors'] as $sectorId) {
                if (Sector::find($sectorId))
                    $searchIds = array_merge($searchIds, Sector::find($sectorId)->offers->pluck('id')->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }
        if (isset($request['offer_types']) && array_filter($request['offer_types'], function ($value) {
            return $value !== null;
        })) {
            foreach ($request['offer_types'] as $offerTypeId) {
                if (OfferType::find($offerTypeId))
                    $searchIds = array_merge($searchIds, OfferType::find($offerTypeId)->offers->pluck('id')->toArray());
            }
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }

        $associated[] = [
            'model' => 'App\\Models\\Image',
            'title' => 'images',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Product',
            'title' => 'products',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Warehouse',
            'title' => 'warehouses',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Taxonomy',
            'title' => 'taxonomies',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Brand',
            'title' => 'brands',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Source',
            'title' => 'sources',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\CustomerType',
            'title' => 'customerTypes',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\City',
            'title' => 'cities',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Region',
            'title' => 'regions',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Country',
            'title' => 'countries',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Sector',
            'title' => 'sectors',
            'search' => true,
        ];
        $associated[] = [
            'model' => 'App\\Models\\OfferType',
            'title' => 'offerType',
            'search' => true,
        ];
        $model = 'App\\Models\\Offer';
        $request['inAccount'] = ['account_id', getAccountUser()->account_id];
        $request['whereNot'] = ['column' => 'offer_type_id', 'value' => 1];
        $dataswithoutPvas = FilterController::searchs(new Request($request), $model, ['id', 'title'], false, $associated);
        $datas = collect($dataswithoutPvas)->map(function ($data) {
            $pvas = $data->productVariationAttributes->map(function ($pva) {
                $product = $pva->product;
                return $product;
            })->unique();
            $data->products->merge($pvas)->unique()->filter();
            return $data;
        });
        $filters = HelperFunctions::filterColumns($request, []);
        return HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['brands']['inactive'])) {
            $model = 'App\\Models\\Brand';
            $associated[] = [
                'model' => 'App\\Models\\Source',
                'title' => 'sources',
                'search' => true,
            ];
            $request['brands']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['brands']['inactive'] = FilterController::searchs(new Request($request['brands']['inactive']), $model, ['id', 'title'], true, $associated);
        }
        if (isset($request['sources']['inactive'])) {
            $model = 'App\\Models\\Source';
            $request['sources']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['sources']['inactive'] = FilterController::searchs(new Request($request['sources']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['customerTypes']['inactive'])) {
            $model = 'App\\Models\\CustomerType';
            $request['customerTypes']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $data['customerTypes']['inactive'] = FilterController::searchs(new Request($request['customerTypes']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['customers']['inactive'])) {
            $model = 'App\\Models\\Customer';
            $request['customers']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['customers']['inactive'] = FilterController::searchs(new Request($request['customers']['inactive']), $model, ['id', 'name'], true);
        }
        if (isset($request['cities']['inactive'])) {
            $model = 'App\\Models\\City';
            $data['cities']['inactive'] = FilterController::searchs(new Request($request['cities']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['regions']['inactive'])) {
            $model = 'App\\Models\\Region';
            $data['regions']['inactive'] = FilterController::searchs(new Request($request['regions']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['countries']['inactive'])) {
            $model = 'App\\Models\\Country';
            $data['countries']['inactive'] = FilterController::searchs(new Request($request['countries']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            $request['warehouses']['inactive']['where'] = ['column' => 'warehouse_id', 'value' => NULL];
            $request['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['warehouses']['inactive'] = FilterController::searchs(new Request($request['warehouses']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['products']['inactive'])) {
            $model = 'App\\Models\\Product';
            //permet de récupérer la liste des regions inactive filtrés
            $request['products']['inactive']['where'] = ['column' => 'product_type_id', 'value' => 1];
            $request['products']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $filters = HelperFunctions::filterColumns($request['products']['inactive'], ['title', 'addresse', 'phone', 'products']);
            $products = FilterController::searchs(new Request($request['products']['inactive']), $model, ['id', 'title'], false, [0 => ['model' => 'App\\Models\\ProductVariationAttribute', 'title' => 'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute', 'search' => false]])->map(function ($product) {
                $productData = $product->only('id', 'title', 'created_at', 'statut');
                $productData['productType'] = $product->productType;
                $productData['images'] = $product->images;
                $productData['productVariations'] = $product->productVariationAttributes->map(function ($productVariationAttribute) {
                    $pvaData = ["id" => $productVariationAttribute->id];
                    $pvaData['variations'] = $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                        if ($childVariationAttribute->attribute->typeAttribute)
                            return [
                                "id" => $childVariationAttribute->id,
                                "type" => $childVariationAttribute->attribute->typeAttribute->title,
                                "value" => $childVariationAttribute->attribute->title
                            ];
                    })->filter();
                    return $pvaData;
                });
                return $productData;
            });
            $data['products']['inactive'] =  HelperFunctions::getPagination($products, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['categories']['inactive'])) {
            $filters = HelperFunctions::filterColumns($request['categories']['inactive'], ['title', 'description']);
            $model = 'App\\Models\\Taxonomy';
            $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
            $categories = $model::whereNull('taxonomy_id')->with(['images', 'childTaxonomies'])->where('type_taxonomy_id', 1)->whereIn('account_user_id', $accountUsers)->get();
            $formattedCategories = [];
            foreach ($categories as $category) {
                $formattedCategories[] = TaxonomyController::formatTaxonomy($category);
            }
            $data['categories']['inactive'] =  HelperFunctions::getPagination(collect($formattedCategories), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public function store(Request $requests)
    {
        // avoir code
        $validator = Validator::make($requests->except('_method'), [
            '*.title' => [
                'required',
                'max:255',
                function ($attribute, $value, $fail) {
                    $user = getAccountUser()->account_id;
                    $hasTitle = Offer::where('title', $value)
                        ->where('account_id', $user)
                        ->exists();

                    if ($hasTitle) {
                        $fail("exist");
                    }
                },
            ],
            '*.price' => 'required|numeric',
            '*.shipping_price' => 'required|numeric',
            '*.discount' => 'required|numeric',
            '*.statut' => 'required|int',
            '*.started' => 'date',
            '*.expired' => 'date',
            '*.offer_type_id' => 'required|exists:offer_types,id',
            '*.categories.*' => 'exists:taxonomies,id|max:255',
            '*.products.*' => 'exists:products,id|max:255',
            '*.productVariationAttributes.*' => 'exists:product_variation_attribute,id|max:255',
            '*.warehouses.*' => 'exists:warehouses,id|max:255',
            '*.countries.*' => 'exists:countries,id|max:255',
            '*.regions.*' => 'exists:regions,id|max:255',
            '*.cities.*' => 'exists:cities,id|max:255',
            '*.brands.*' => 'exists:brands,id|max:255',
            '*.customers.*' => 'exists:customers,id|max:255',
            '*.customerTypes.*' => 'exists:customer_types,id|max:255',
            '*.brandSources.*' => 'exists:brand_source,id|max:255',
            '*.gifts.*' => 'exists:products,id|max:255',
            '*.sources.*' => 'exists:sources,id|max:255',
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage == null) {
                        $fail("not exist");
                    } elseif ($principalImage->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        $offers = collect($requests->except('_method'))->map(function ($request) {
            
            $request['account_id'] = getAccountUser()->account_id;
            $request['code'] = DefaultCodeController::getAccountCode('Offer', getAccountUser()->account_id);
            $offer_only = collect($request)->only('code', 'title', 'price', 'shipping_price', 'discount', 'statut', 'started', 'expired', 'offer_type_id', 'account_id');
            $offer = Offer::create($offer_only->all());
            if (isset($request['productVariationAttributes'])) {
                foreach ($request['productVariationAttributes'] as $key => $productVariationAttributeId) {
                    $offer->productVariationAttributes()->syncWithoutDetaching([$productVariationAttributeId => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            return $offer->productVariationAttributes;
            if (isset($request['warehouses'])) {
                foreach ($request['warehouses'] as $key => $warehouseId) {
                    $offer->warehouses()->syncWithoutDetaching([$warehouseId => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['categories'])) {
                foreach ($request['categories'] as $key => $taxonomyId) {
                    $offer->taxonomies()->syncWithoutDetaching([$taxonomyId => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['countries'])) {
                foreach ($request['countries'] as $key => $countryId) {
                    $offer->countries()->syncWithoutDetaching([$countryId => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['regions'])) {
                foreach ($request['regions'] as $key => $regionId) {
                    $offer->regions()->syncWithoutDetaching([$regionId => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['cities'])) {
                foreach ($request['cities'] as $key => $cityId) {
                    $offer->cities()->syncWithoutDetaching([$cityId => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['brands'])) {
                foreach ($request['brands'] as $key => $brandId) {
                    $offer->brands()->syncWithoutDetaching([$brandId => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['sources'])) {
                foreach ($request['sources'] as $key => $sourceId) {
                    $offer->sources()->syncWithoutDetaching([$sourceId => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['brandSources'])) {
                foreach ($request['brandSources'] as $key => $brandSourceId) {
                    $offer->brandSources()->syncWithoutDetaching([$brandSourceId => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['customers'])) {
                foreach ($request['customers'] as $key => $customerId) {
                    $offer->customers()->syncWithoutDetaching([$customerId => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['customerTypes'])) {
                foreach ($request['customerTypes'] as $key => $customerTypeId) {
                    $offer->customerTypes()->syncWithoutDetaching([$customerTypeId => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

           

            if (isset($request['products'])) {
                foreach ($request['products'] as $key => $productId) {
                    $offer->products()->syncWithoutDetaching([$productId => ['account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['gifts'])) {
                foreach ($request['gifts'] as $key => $productId) {
                    $offer->products()->syncWithoutDetaching([$productId => ['gift' => 1, 'product_id' => $productId, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                $offer->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($request['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $offer->title,
                    'type' => 'offer',
                    'image_type_id' => 12,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $offer);
            }
            $offer = Offer::find($offer->id);

            return $offer;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $offers,
        ]);
    }

    public function print($id)
    {
        // Fetch the offer data from the database
        $offer = Offer::findOrFail($id);

        // Load the PDF view and pass the offer data
        $pdf = PDF::loadView('pdf.offer', compact('offer'));

        // Stream the PDF to the browser
        return $pdf->stream('offer.pdf');
    }

    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $offer = Offer::with('OfferType')->find($id);
        if (!$offer)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ]);
        $offerBrands = $offer->brands->pluck('id')->toArray();
        $offerSources = $offer->sources->pluck('id')->toArray();
        $offerCustomerTypes = $offer->customerTypes->pluck('id')->toArray();
        $offerCustomers = $offer->customers->pluck('id')->toArray();
        $offerCities = $offer->cities->pluck('id')->toArray();
        $offerRegions = $offer->regions->pluck('id')->toArray();
        $offerCountries = $offer->countries->pluck('id')->toArray();
        $offerWarehouses = $offer->warehouses->pluck('id')->toArray();
        $offerProducts = $offer->products->pluck('id')->toArray();
        $offerCategories = $offer->taxonomies->pluck('id')->toArray();
        $data = [];

        if (isset($request['offerInfo'])) {
            $info = collect($offer)->toArray();
            $info['principalImage'] = $offer->images;
            $data["offerInfo"]['data'] = $info;
        }
        if (isset($request['brands']['inactive'])) {
            $brands = $offer->brands->pluck('id')->toArray();
            $model = 'App\\Models\\Brand';
            $associated[] = [
                'model' => 'App\\Models\\Source',
                'title' => 'sources',
                'search' => true,
            ];
            $request['brands']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['brands']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $offerBrands];
            $data['brands']['inactive'] = FilterController::searchs(new Request($request['brands']['inactive']), $model, ['id', 'title'], true, $associated);
        }
        if (isset($request['brands']['active'])) {
            $model = 'App\\Models\\Brand';
            $associated = [];
            $associated[] = [
                'model' => 'App\\Models\\Source',
                'title' => 'sources',
                'search' => true,
            ];

            $request['brands']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['brands']['active']['whereArray'] = ['column' => 'id', 'values' => $offerBrands];
            $data['brands']['active'] = FilterController::searchs(new Request($request['brands']['active']), $model, ['id', 'title'], true, $associated);
        }

        if (isset($request['sources']['inactive'])) {
            $model = 'App\\Models\\Source';
            $request['sources']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['sources']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $offerSources];
            $data['sources']['inactive'] = FilterController::searchs(new Request($request['sources']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['sources']['active'])) {
            $model = 'App\\Models\\Source';
            $request['sources']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['sources']['active']['whereArray'] = ['column' => 'id', 'values' => $offerSources];
            $data['sources']['active'] = FilterController::searchs(new Request($request['sources']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['customerTypes']['inactive'])) {
            $model = 'App\\Models\\CustomerType';
            $request['customerTypes']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $request['customerTypes']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $offerCustomerTypes];
            $data['customerTypes']['inactive'] = FilterController::searchs(new Request($request['customerTypes']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['customerTypes']['active'])) {
            $model = 'App\\Models\\CustomerType';
            $request['customerTypes']['active']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $request['customerTypes']['active']['whereArray'] = ['column' => 'id', 'values' => $offerCustomerTypes];
            $data['customerTypes']['active'] = FilterController::searchs(new Request($request['customerTypes']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['customers']['inactive'])) {
            $model = 'App\\Models\\Customer';
            $request['customers']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['customers']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $offerCustomers];
            $data['customers']['inactive'] = FilterController::searchs(new Request($request['customers']['inactive']), $model, ['id', 'name'], true);
        }
        if (isset($request['customers']['active'])) {
            $model = 'App\\Models\\Customer';
            $request['customers']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['customers']['active']['whereArray'] = ['column' => 'id', 'values' => $offerCustomers];
            $data['customers']['active'] = FilterController::searchs(new Request($request['customers']['active']), $model, ['id', 'name'], true);
        }

        if (isset($request['cities']['inactive'])) {
            $model = 'App\\Models\\City';
            $request['cities']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $offerCities];
            $data['cities']['inactive'] = FilterController::searchs(new Request($request['cities']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['cities']['active'])) {
            $model = 'App\\Models\\City';
            $request['cities']['active']['whereArray'] = ['column' => 'id', 'values' => $offerCities];
            $data['cities']['active'] = FilterController::searchs(new Request($request['cities']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['regions']['inactive'])) {
            $model = 'App\\Models\\Region';
            $request['regions']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $offerRegions];
            $data['regions']['inactive'] = FilterController::searchs(new Request($request['regions']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['regions']['active'])) {
            $model = 'App\\Models\\Region';
            $request['regions']['active']['whereArray'] = ['column' => 'id', 'values' => $offerRegions];
            $data['regions']['active'] = FilterController::searchs(new Request($request['regions']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['countries']['inactive'])) {
            $model = 'App\\Models\\Country';
            $request['countries']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $offerCountries];
            $data['countries']['inactive'] = FilterController::searchs(new Request($request['countries']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['countries']['active'])) {
            $model = 'App\\Models\\Country';
            $request['countries']['active']['whereArray'] = ['column' => 'id', 'values' => $offerCountries];
            $data['countries']['active'] = FilterController::searchs(new Request($request['countries']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            $request['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $request['warehouses']['inactive']['whereNotArray'] = ['column' => 'id', 'values' => $offerWarehouses];
            $data['warehouses']['inactive'] = FilterController::searchs(new Request($request['warehouses']['inactive']), $model, ['id', 'title'], true);
        }
        if (isset($request['warehouses']['active'])) {
            $model = 'App\\Models\\Warehouse';
            $request['warehouses']['active']['whereArray'] = ['column' => 'id', 'values' => $offerWarehouses];
            $request['warehouses']['active']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $data['warehouses']['active'] = FilterController::searchs(new Request($request['warehouses']['active']), $model, ['id', 'title'], true);
        }

        if (isset($request['products']['active'])) {
            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $filters = HelperFunctions::filterColumns($request['products']['active'], ['title', 'addresse', 'phone', 'products']);
            $productIds = Product::where('reference', 'like', "%{$filters['search']}%")->orWhere('title', 'like', "%{$filters['search']}%")->get()->pluck('id')->toArray();
            $offerProducts = Offer::with(['products', 'productVariationAttributes.product', 'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute'])->find($id);
            $products = $offerProducts->products->map(function ($product) use ($productIds) {
                if (in_array($product->id, $productIds)) {
                    $productData["id"] = $product->id;
                    $productData["title"] = $product->title;
                    $productData["created_at"] = $product->created_at;
                    $productData["statut"] = $product->statut;
                    $productData["images"] = $product->images;
                    $productData["productType"] = $product->productType;
                    $productData["productVariations"] = $product->productVariations;
                    return $productData;
                }
            })->filter();

            $data['products']['active'] =  HelperFunctions::getPagination(collect($products), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['products']['inactive'])) {

            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $filters = HelperFunctions::filterColumns($request['products']['inactive'], ['title', 'addresse', 'phone', 'products']);
            $productIds = Product::where('reference', 'like', "%{$filters['search']}%")->orWhere('title', 'like', "%{$filters['search']}%")->get()->pluck('id')->toArray();
            $offerProducts = ProductVariationAttribute::with(['product', 'variationAttribute.childVariationAttributes.attribute.typeAttribute'])->whereDoesntHave('offers', function ($query) use ($offer) {
                $query->where('offerable_id', $offer->id);
            })->whereIn('product_id', $productIds)->get();
            // Mapper les données des produits pour les formater correctement
            $productDatas = $offerProducts->map(function ($productVariationAttribute) {
                // Créer un tableau avec les données de base du produit
                $pvaData = [
                    "id" => $productVariationAttribute->id,
                    "title" => $productVariationAttribute->product->title,
                    "created_at" => $productVariationAttribute->product->created_at,
                    "statut" => $productVariationAttribute->product->statut,
                    "images" => $productVariationAttribute->product->images,
                    "productType" => $productVariationAttribute->product->productType,
                    "product_id" => $productVariationAttribute->product->id
                ];
                if ($productVariationAttribute->variationAttribute) {
                    // Récupérer les variations d'attributs pour chaque produit
                    $pvaData['variations'] = $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                        // Vérifier si l'attribut a un type
                        if ($childVariationAttribute->attribute->typeAttribute) {
                            // Retourner les données formatées pour chaque attribut de variation
                            return [
                                "id" => $childVariationAttribute->id,
                                "type" => $childVariationAttribute->attribute->typeAttribute->title,
                                "value" => $childVariationAttribute->attribute->title
                            ];
                        }
                    })->filter(); // Filtrer les valeurs nulles (attributs sans type)

                    return $pvaData;
                } // Retourner les données formatées du produit
            })->filter();

            $pvas = [];
            foreach ($productDatas as $key => $productData) {
                $pvas[$productData['product_id']][] = ["id" => $productData["id"], "variations" => $productData["variations"]];
            }
            $products = [];
            foreach ($productDatas as $key => $productData) {
                $products[$productData['product_id']]["id"] = $productData['product_id'];
                $products[$productData['product_id']]["title"] = $productData['title'];
                $products[$productData['product_id']]["created_at"] = $productData['created_at'];
                $products[$productData['product_id']]["statut"] = $productData['statut'];
                $products[$productData['product_id']]["images"] = $productData['images'];
                $products[$productData['product_id']]["productType"] = $productData['productType'];
                $products[$productData['product_id']]["productVariations"] = $pvas[$productData['product_id']];
            }

            $data['products']['inactive'] =  HelperFunctions::getPagination(collect($products), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        if (isset($request['categories']['active'])) {
            $filters = HelperFunctions::filterColumns($request['categories']['active'], ['title', 'description']);
            $model = 'App\\Models\\Taxonomy';
            $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
            $categories = $model::whereNull('taxonomy_id')->with(['images', 'childTaxonomies'])->where('type_taxonomy_id', 1)->whereIn('account_user_id', $accountUsers)->get();
            $formattedCategories = [];
            $taxonomyIds = $offer->taxonomies->pluck('id')->toArray();
            foreach ($categories as $category) {
                if (in_array($category->id, $taxonomyIds))
                    $category->checked = true;
                $formattedCategories[] = TaxonomyController::formatTaxonomy($category, $taxonomyIds);
            }
            $data['categories']['active'] =  HelperFunctions::getPagination(collect($formattedCategories), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public function update(Request $requests, $id)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => 'required|exists:offers,id',
            '*.statut' => 'required|int',
            '*.started' => 'date',
            '*.expired' => 'date',
            '*.categoriesToActive.*' => 'exists:taxonomies,id|max:255',
            '*.categoriesToInactive.*' => 'exists:taxonomies,id|max:255',
            '*.productsToActive.*' => 'exists:products,id|max:255',
            '*.productsToInactive.*' => 'exists:products,id|max:255',
            '*.productVariationAttributesToActive.*' => 'exists:product_variation_attribute,id|max:255',
            '*.productVariationAttributesToInactive.*' => 'exists:product_variation_attribute,id|max:255',
            '*.warehousesToActive.*' => 'exists:warehouses,id|max:255',
            '*.warehousesToInactive.*' => 'exists:warehouses,id|max:255',
            '*.countriesToActive.*' => 'exists:countries,id|max:255',
            '*.countriesToInactive.*' => 'exists:countries,id|max:255',
            '*.regionsToActive.*' => 'exists:regions,id|max:255',
            '*.regionsToInactive.*' => 'exists:regions,id|max:255',
            '*.citiesToActive.*' => 'exists:cities,id|max:255',
            '*.citiesToInactive.*' => 'exists:cities,id|max:255',
            '*.brandsToActive.*' => 'exists:brands,id|max:255',
            '*.brandsToInactive.*' => 'exists:brands,id|max:255',
            '*.customersToActive.*' => 'exists:customers,id|max:255',
            '*.customersToInactive.*' => 'exists:customers,id|max:255',
            '*.customerTypesToActive.*' => 'exists:customer_types,id|max:255',
            '*.customerTypesToInactive.*' => 'exists:customer_types,id|max:255',
            '*.brandSourcesToActive.*' => 'exists:brand_source,id|max:255',
            '*.brandSourcesToInactive.*' => 'exists:brand_source,id|max:255',
            '*.giftsToActive.*' => 'exists:products,id|max:255',
            '*.giftsToInactive.*' => 'exists:products,id|max:255',
            '*.sourcesToActive.*' => 'exists:sources,id|max:255',
            '*.sourcesToInactive.*' => 'exists:sources,id|max:255',
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) { // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage == null) {
                        $fail("not exist");
                    } elseif ($principalImage->account_id !== getAccountUser()->account_id) {
                        $fail("not exist");
                    }
                },
            ],
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        };
        return $requests;
        $offers = collect($requests->except('_method'))->map(function ($request) {
            $offer_only = collect($request)->only('statut', 'started', 'expired');
            $offer = Offer::find($request['id']);
            $offer->update($offer_only->all());

            if (isset($request['warehousesToInactive'])) {
                foreach ($request['warehousesToInactive'] as $key => $warehouseId) {
                    $offer->warehouses()->syncWithoutDetaching([$warehouseId => ['statut' => 0, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['warehousesToActive'])) {
                foreach ($request['warehousesToActive'] as $key => $warehouseId) {
                    $offer->warehouses()->syncWithoutDetaching([$warehouseId => ['statut' => 1, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['categoriesToInactive'])) {
                foreach ($request['categoriesToInactive'] as $key => $taxonomyId) {
                    $offer->taxonomies()->syncWithoutDetaching([$taxonomyId => ['statut' => 0, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['categoriesToActive'])) {
                foreach ($request['categoriesToActive'] as $key => $taxonomyId) {
                    $offer->taxonomies()->syncWithoutDetaching([$taxonomyId => ['statut' => 1, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['countriesToInactive'])) {
                foreach ($request['countriesToInactive'] as $key => $countryId) {
                    $offer->countries()->syncWithoutDetaching([$countryId => ['statut' => 0, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['countriesToActive'])) {
                foreach ($request['countriesToActive'] as $key => $countryId) {
                    $offer->countries()->syncWithoutDetaching([$countryId => ['statut' => 1, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['regionsToInactive'])) {
                foreach ($request['regionsToInactive'] as $key => $regionId) {
                    $offer->regions()->syncWithoutDetaching([$regionId => ['statut' => 0, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['regionsToActive'])) {
                foreach ($request['regionsToActive'] as $key => $regionId) {
                    $offer->regions()->syncWithoutDetaching([$regionId => ['statut' => 1, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['citiesToInactive'])) {
                foreach ($request['citiesToInactive'] as $key => $cityId) {
                    $offer->cities()->syncWithoutDetaching([$cityId => ['statut' => 0, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['citiesToActive'])) {
                foreach ($request['citiesToActive'] as $key => $cityId) {
                    $offer->cities()->syncWithoutDetaching([$cityId => ['statut' => 1, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['brandsToInactive'])) {
                foreach ($request['brandsToInactive'] as $key => $brandId) {
                    $offer->brands()->syncWithoutDetaching([$brandId => ['statut' => 0, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['brandsToActive'])) {
                foreach ($request['brandsToActive'] as $key => $brandId) {
                    $offer->brands()->syncWithoutDetaching([$brandId => ['statut' => 1, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['sourcesToInactive'])) {
                foreach ($request['sourcesToInactive'] as $key => $sourceId) {
                    $offer->sources()->syncWithoutDetaching([$sourceId => ['statut' => 0, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['sourcesToActive'])) {
                foreach ($request['sourcesToActive'] as $key => $sourceId) {
                    $offer->sources()->syncWithoutDetaching([$sourceId => ['statut' => 1, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['brandSourcesToInactive'])) {
                foreach ($request['brandSourcesToInactive'] as $key => $brandSourceId) {
                    $offer->brandSources()->syncWithoutDetaching([$brandSourceId => ['statut' => 0, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['brandSourcesToActive'])) {
                foreach ($request['brandSourcesToActive'] as $key => $brandSourceId) {
                    $offer->brandSources()->syncWithoutDetaching([$brandSourceId => ['statut' => 1, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['customersToInactive'])) {
                foreach ($request['customersToInactive'] as $key => $customerId) {
                    $offer->customers()->syncWithoutDetaching([$customerId => ['statut' => 0, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['customersToActive'])) {
                foreach ($request['customersToActive'] as $key => $customerId) {
                    $offer->customers()->syncWithoutDetaching([$customerId => ['statut' => 1, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['customerTypesToInactive'])) {
                foreach ($request['customerTypesToInactive'] as $key => $customerTypeId) {
                    $offer->customerTypes()->syncWithoutDetaching([$customerTypeId => ['statut' => 0, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['customerTypesToActive'])) {
                foreach ($request['customerTypesToActive'] as $key => $customerTypeId) {
                    $offer->customerTypes()->syncWithoutDetaching([$customerTypeId => ['statut' => 1, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['productVariationAttributesToInactive'])) {
                foreach ($request['productVariationAttributesToInactive'] as $key => $productVariationAttributeId) {
                    $offer->productVariationAttributes()->syncWithoutDetaching([$productVariationAttributeId => ['statut' => 0, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['productVariationAttributesToActive'])) {
                foreach ($request['productVariationAttributesToActive'] as $key => $productVariationAttributeId) {
                    $offer->productVariationAttributes()->syncWithoutDetaching([$productVariationAttributeId => ['statut' => 1, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['productsToInactive'])) {
                foreach ($request['productsToInactive'] as $key => $productId) {
                    $offer->products()->syncWithoutDetaching([$productId => ['statut' => 0, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['productsToActive'])) {
                foreach ($request['productsToActive'] as $key => $productId) {
                    $offer->products()->syncWithoutDetaching([$productId => ['statut' => 1, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['giftsToInactive'])) {
                foreach ($request['giftsToInactive'] as $key => $productId) {
                    $offer->products()->syncWithoutDetaching([$productId => ['statut' => 0, 'gift' => 1, 'product_id' => $productId, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }
            if (isset($request['giftsToActive'])) {
                foreach ($request['giftsToActive'] as $key => $productId) {
                    $offer->products()->syncWithoutDetaching([$productId => ['statut' => 1, 'gift' => 1, 'product_id' => $productId, 'account_user_id' => getAccountUser()->id, 'created_at' => now(), 'updated_at' => now()]]);
                }
            }

            if (isset($request['principalImage'])) {
                $image = Image::find($request['principalImage']);
                $offer->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            } elseif (isset($request['newPrincipalImage'])) {
                $images[]["image"] = $request['newPrincipalImage'];
                $imageData = [
                    'title' => $offer->title,
                    'type' => 'offer',
                    'image_type_id' => 12,
                    'images' => $images
                ];
                ImageController::store(new Request([$imageData]), $offer);
            }
            $offer = Offer::find($offer->id);

            return $offer;
        });

        return response()->json([
            'statut' => 1,
            'data' => $offers,
        ]);
    }



    public function destroy($id)
    {
        $Offer = Offer::find($id);
        $Offer->delete();
        return response()->json([
            'statut' => 1,
            'data' => $Offer,
        ]);
    }
}
