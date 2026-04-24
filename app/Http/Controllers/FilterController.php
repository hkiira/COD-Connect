<?php

namespace App\Http\Controllers;

use App\Models\ProductVariationAttribute;
use Illuminate\Http\Request;
use App\Models\AccountUser;
use App\Models\BrandSource;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductBrandSource;
use App\Models\Shipment;
use App\Models\Supplier;
use Illuminate\Support\Facades\Auth;

class FilterController extends Controller
{

    /*
        request: la liste des infos demandes
            dans le cas récupérer seulement les objects d'un compte il faut ajouter dans request avant de l'appeler
                $request['inAccount']=['account_id',auth()->user()->toArray()["acount_user"]["account"]];
            dans le cas de récupérer seulement les objets actif il faut ajouter dans request :
                $request['where']=['column'=>$'foreign_key','value'=>$"id"];
            dans le cas de récupérer seulement les objets innactif il faut ajouter dans request :
                $request['whereNot']=['column'=>$'foreign_key','value'=>$"id"];
            dans le cas de récupérer liste des objets actif associer a des tables pivot
                $request['whereIn'][0]=['table'=>$'nom de la table','column'=>$'foreign_key','value'=>$'id'];
            dans le cas de récupérer liste des objets innactif associer a des tables pivot
                $request['whereNotIn'][0]=['table'=>$'nom de la table','column'=>$'foreign_key','value'=>$'id'];
                
        model: le modéle principale pour la recherche
        columns: les colonnes a rechercher
        paginate: demande de paginer les enregistrement ou non
        withModels: permet d'ajouter des modéles associé est cette table contient les elements suivant:
            'model'=> le modéle en relation
            'title'=>le nom avec lequel il es associé dans le modéles
            'search'=>permet de rechercher aussi dans le modéle associés si il es true
    */

    public static function searchs(Request $request, $model, $columns = [], $paginate = false, $withModels = [])
    {

        // organiser les colonnes
        $filters = HelperFunctions::filterColumns($request->toArray(), $columns);
        $modelToSearch = collect();
        // array pour récupérer la liste des modéles a appeler avec le modéle principale
        $withObjects = [];
        $isSelect = false;
        $userAccount = true;
        // condition pour vérifier si le modél principal a des modéles en relation demandés
        if ($withModels) {
            // boucle permet de récupérer les modéles en relations
            foreach ($withModels as $key => $withModel) {
                //récupérer le modéle associé
                $withObjects[] = $withModel['title'];
                // vérifier si la recherche est demandé pour le modéle associé
                if ($withModel['search'] == true) {
                    //vérifier les modéles selectionner dans le filtre
                    if (isset($withModel['select'])) {
                        if (isset($withModel['isUserAccount']))
                            $userAccount = false;
                        $isSelect = true;
                        if (isset($withModel['parent'])) {
                            $modelToSearch = collect($modelToSearch)->merge($model::whereIn($withModel['foreignKey'], $withModel['select'])->get()->pluck('id'));
                            if ($filters['search']) {
                                $parentIds = $withModel['model']::where($withModel['parent']['column'], 'like', "%{$filters['search']}%")->get()->pluck($withModel['parent']['key']);
                                $modelToSearch = collect($modelToSearch)->merge($model::whereIn($withModel['foreignKey'], $parentIds)->get()->pluck('id'));
                            }
                        } elseif (isset($withModel['pivot'])) {
                            $pivotIds = $withModel['model']::with($withModel['pivot']['table'])
                                ->whereIn($withModel['pivot']['key'], $withModel['select'])
                                ->get()
                                ->flatMap(function ($flatMap) use ($withModel) {
                                    return optional($flatMap->{$withModel['pivot']['table']})->pluck($withModel['pivot']['key']) ?? [];
                                })
                                ->toArray();
                            $modelToSearch = collect($modelToSearch)->merge($pivotIds);
                        } else {
                            $modelToSearch = collect($modelToSearch)->merge($withModel['model']::whereIn('id', $withModel['select'])->get()->pluck($withModel['foreignKey']));
                            //collecter les enregistrement qui ont trouvé est merger avec les autres enregistrement de l'array $modelToSearch
                            if ($filters['search']) {
                                $modelToSearch = collect($modelToSearch)->merge($withModel['model']::where($withModel['column'], 'like', "%{$filters['search']}%")->get()->pluck($withModel['foreignKey']))->toArray();
                            }
                        }
                    }
                }
            }
        }
        //recherche pour récupérer l'ensemble des enregistrement
        $objects = $model::with(collect($withObjects)->unique()->toArray())
            ->when(($columns && $filters['search']), function ($query) use ($columns, $filters) {
                $query->where(function ($subQuery) use ($columns, $filters) {
                    foreach ($columns as $column) {
                        $subQuery->orWhere($column, 'like', "%{$filters['search']}%");
                    }
                });
            })->when((isset($filters['whereHas'])), function ($query) use ($columns, $filters) {
                $query->whereHas($filters['whereHas']);
            })
            ->when((isset($filters['whereDoesntHave'])), function ($query) use ($columns, $filters) {
                $query->whereDoesntHave($filters['whereDoesntHave']['table'], function ($query) use ($filters) {
                    $query->where($filters['whereDoesntHave']['column'], $filters['whereDoesntHave']['value']);
                });
            })
            ->when(($isSelect && $userAccount), function ($query) use ($modelToSearch, $isSelect, $filters) {
                ($filters['search']) ? $query->orWhereIn('id', collect($modelToSearch)->unique()->toArray()) : $query->whereIn('id', collect($modelToSearch)->unique()->toArray());
            })
            // pour récupérer seulement les objects dans le compte
            ->when(isset($filters['inAccount']), function ($query) use ($filters) {
                $query->where($filters['inAccount'][0], $filters['inAccount'][1]);
            })
            // pour récupérer seulement les objects avec le statut demandé
            ->when(isset($filters['statut']), function ($query) use ($filters) {
                $query->where('statut', $filters['statut']);
            })
            ->when(isset($filters['inAccountUser']), function ($query) use ($filters) {
                $accountUsers = AccountUser::where('account_id', $filters['inAccountUser'][1])->pluck('id')->toArray();
                $query->whereIn($filters['inAccountUser'][0], $accountUsers);
            })
            // pour récupérer seulement les objets qui ont une condition je l'utilise pour edit pour afficher seulement les objects actif qui n'ont pas relation un table pivot
            ->when(isset($filters['where']), function ($query) use ($filters) {
                $query->where($filters['where']['column'], $filters['where']['value']);
            })
            // pour récupérer seulement les objets qui ont une condition je l'utilise pour edit pour afficher seulement les objects actif qui n'ont pas relation un table pivot
            ->when(isset($filters['wheres']), function ($query) use ($filters) {
                foreach ($filters['wheres'] as $key => $where) {
                    $query->where($where['column'], $where['value']);
                }
            })
            // pour récupérer seulement les objets qui ont une condition je l'utilise pour edit pour afficher seulement les objects actif qui n'ont pas relation un table pivot
            ->when(isset($filters['whereNots']), function ($query) use ($filters) {
                foreach ($filters['whereNots'] as $key => $whereNot) {
                    $query->whereNot($whereNot['column'], $whereNot['value']);
                }
            })
            ->when(isset($filters['whereArray']), function ($query) use ($filters) {
                $query->whereIn($filters['whereArray']['column'],  $filters['whereArray']['values']);
            })
            // pour récupérer seulement les objets qui ont une condition je l'utilise pour edit pour afficher seulement les objects inactif qui n'ont pas relation un table pivot
            ->when(isset($filters['whereNot']), function ($query) use ($filters) {
                $query->where(function ($subQuery) use ($filters) {
                    $subQuery->where($filters['whereNot']['column'], '!=', $filters['whereNot']['value'])
                        ->orWhereNull($filters['whereNot']['column']);
                });
            })
            ->when(isset($filters['whereNotArray']), function ($query) use ($filters) {
                $query->whereNotIn($filters['whereNotArray']['column'],  $filters['whereNotArray']['values']);
            })
            // pour récupérer seulement les objets qui ont une condition je l'utilise pour edit pour afficher seulement les objects actif qui ont en relation un table pivot
            ->when(isset($filters['whereIn']), function ($query) use ($filters) {
                foreach ($filters['whereIn'] as $key => $whereIn) {
                    $query->whereHas($whereIn["table"], function ($query1) use ($whereIn) {
                        $query1->where($whereIn["column"], $whereIn["value"]);
                    });
                }
            })
            // pour récupérer seulement les objets qui ont une condition je l'utilise pour edit pour afficher seulement les objects innactif qui ont en relation un table pivot
            ->when(isset($filters['whereNotIn']), function ($query) use ($filters) {
                foreach ($filters['whereNotIn'] as $key => $whereNotIn) {
                    $query->whereDoesntHave($whereNotIn["table"], function ($query1) use ($whereNotIn) {
                        $query1->where($whereNotIn["column"], $whereNotIn["value"]);
                    });
                }
            })
            // recherche entre deux dates
            ->when($filters['startDate'] && $filters['endDate'], function ($query) use ($filters) {
                $query->whereBetween('created_at', [$filters['startDate'] . " 00:00:00", $filters['endDate'] . " 23:59:59"]);
            })
            //tri
            ->when($filters['sort'], function ($query) use ($filters) {
                foreach ($filters['sort'] as $sort)
                    $query->orderBy($sort['column'], $sort['order']);
            })
            ->get();
        //si la pagination est demander on appel la fonction getPagination pour la faire sinon on va envoyer selement l'objet sans rien modifier
        if ($paginate == true) {
            return HelperFunctions::getPagination(collect($objects), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        return $objects;
    }

    public static function getCombinations($arrays, $current = array(), $index = 0)
    {
        $result = array();

        // Si nous avons atteint la fin de tous les tableaux, ajoutons la combinaison actuelle au résultat
        if ($index == count($arrays)) {
            $result[] = $current;
            return $result;
        }

        // Pour chaque élément du tableau actuel, appel récursif pour les tableaux suivants
        foreach ($arrays[$index] as $value) {
            // Création d'une nouvelle combinaison avec l'élément actuel
            $newCurrent = $current;
            $newCurrent[] = $value;

            // Appel récursif pour les tableaux suivants
            $result = array_merge($result, FilterController::getCombinations($arrays, $newCurrent, $index + 1));
        }

        return $result;
    }
    public static function filterselects(Request $request, $model, $id = null)
    {
        $selectElement = ['id', 'title'];
        $inAccount = false;
        $inAccountUsers = [];
        $conditions = [];
        $with = [];
        $object = null;

        switch ($model) {
            case 'cities':
                $object = "App\\Models\\City";
                break;
            case 'measurements':
                $object = "App\\Models\\Measurement";
                break;
            case 'countries':
                $object = "App\\Models\\Country";
                break;
            case 'sectors':
                $object = "App\\Models\\Sector";
                break;
            case 'regions':
                $object = "App\\Models\\Region";
                break;
            case 'offer_types':
                $object = "App\\Models\\OfferType";
                break;
            case 'taxonomy_types':
                $object = "App\\Models\\TypeTaxonomy";
                break;
            case 'product_types':
                $object = "App\\Models\\ProductType";
                break;
            case 'transaction_types':
                $object = "App\\Models\\TransactionType";
                $conditions[] = ['column' => 'statut', 'value' => 1];
                break;
            case 'transaction_model_types':
                return [
                    "statut" => 1,
                    "type" => "transaction_types",
                    "data" => [
                        ["id" => 1, "title" => "supplier"],
                        ["id" => 2, "title" => "user"],
                        ["id" => 3, "title" => "shipment"],
                        ["id" => 4, "title" => "expense"],
                    ]
                ];
            case 'suppliers':
                $object = "App\\Models\\Supplier";
                $inAccount = true;
                break;
            case 'permissions':
                $object = "App\\Models\\Permission";
                $selectElement = ['id', 'name'];
                break;
            case 'phone_types':
                $object = "App\\Models\\PhoneTypes";
                $conditions[] = ['column' => 'account_id', 'value' => null];
                $conditions[] = ['column' => 'account_id', 'value' => getAccountUser()->account_id];
                break;
            case 'collectors':
                $object = "App\\Models\\Collector";
                $conditions[] = ['column' => 'carrier_id', 'value' => $id];
                $selectElement = ['id', 'name'];
                break;
            case 'roles':
                $object = "App\\Models\\Role";
                $selectElement = ['id', 'name'];
                break;
            case 'warehouses':
                $object = "App\\Models\\Warehouse";
                $inAccount = true;
                $conditions[] = ['column' => 'warehouse_id', 'value' => null];
                break;
            case 'rays':
                $object = "App\\Models\\Warehouse";
                $inAccount = true;
                $conditions[] = ['column' => 'warehouse_type_id', 'value' => ($model == 'partitions' ? 2 : 3)];
                break;
            case 'brands':
                $object = "App\\Models\\Brand";
                $inAccount = true;
                break;
            case 'payment_methods':
                $object = "App\\Models\\PaymentMethod";
                break;
            case 'comparison_operators':
                $object = "App\\Models\\ComparisonOperator";
                $selectElement = ['id', 'title', 'symbol'];
                break;
            case 'compensation_goals':
                $object = "App\\Models\\Compensati onGoal";
                break;
            case 'sources':
                $object = "App\\Models\\Source";
                $inAccount = true;
                break;
            case 'carriers':
                $object = "App\\Models\\Carrier";
                $inAccount = true;
                break;
            case 'type_attributes':
                $object = "App\\Models\\Taxonomy";
                $inAccountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
                break;
            case 'offers':
                $object = "App\\Models\\Offer";
                $inAccount = true;
                break;
            case 'statuses':
                $object = "App\\Models\\OrderStatus";
                break;
            case 'customer_types':
                $object = "App\\Models\\CustomerType";
                $inAccountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
                break;
            case 'transaction_models':
                return self::handleTransactionModels($id, $model);
            case 'salaries':
                return self::handleSalaries($model);
            case 'commissions':
                return self::handleCompensations($model, 2);
            case 'bonuses':
                return self::handleCompensations($model, 3);
            case 'order_customers':
                return self::handleOrderCustomers($model, $id);
            case 'users':
                return self::handleUsers($model);
            case 'brand_sources':
                return self::handleBrandSources($model);
            case 'order_statuts':
                return self::handleOrderStatuts($model, $id);
            case 'product_attributes':
                return self::handleProductAttributes($model, $id);
            case 'products':
                return self::handleProducts($model, $with);
            case 'order_offers':
                return self::handleOrderOffers($model, $request, $id);
            default:
                $object = "App\\Models\\Country";
                break;
        }

        $results = self::fetchResults($object, $selectElement, $id, $conditions, $inAccount, $inAccountUsers, $with);
        return $results;
        return ["statut" => 1, "type" => $model, "data" => $results->toArray()];
    }

    private static function handleTransactionModels($id, $model)
    {
        switch ($id) {
            case 1:
                $object = "App\\Models\\Supplier";
                $inAccount = true;
                $selectElement = 'title';
                break;
            case 2:
                $object = "App\\Models\\User";
                $inAccount = true;
                $selectElement = 'firstname';
                break;
            case 3:
                $object = "App\\Models\\Shipment";
                $selectElement = 'code';
                $inAccount = true;
                break;

            default:
                return "not exist";
                break;
        }
        $results = $object::get();
        $datas = $results->map(function ($result) use ($selectElement) {
            return [
                "id" => $result->id,
                "title" => $result->$selectElement,
            ];
        });
        return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
    }

    private static function handleSalaries($model)
    {
        $compensations = "App\\Models\\AccountCompensation"::whereIn('account_user_id', "App\\Models\\AccountUser"::where('account_id', getAccountUser()->account_id)->get()->pluck('id')->toArray())->get()->pluck('compensation_id')->toArray();
        $results = "App\\Models\\Compensation"::get()->whereNotIn('id', $compensations)->where('compensation_type_id', 1);
        $datas = $results->map(function ($result) {
            return $result->only('id', 'title');
        })->values();
        return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
    }

    private static function handleCompensations($model, $typeId)
    {

        $results = "App\\Models\\Compensation"::get()->where('compensation_type_id', 2);
        $datas = $results->map(function ($result) {
            $data = $result->only('id', 'title', 'multi');
            $data['multi'] = $result->statut == 2 ? true : false;
            return $data;
        })->values();
        return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
    }

    private static function handleOrderCustomers($model, $id)
    {
        $object = "App\\Models\\Phone";
        $results = $object::where('account_id', getAccountUser()->account_id)->where('title', 'like', "%{$id}%")->orderBy('created_at', 'desc')->get();
        $datas = $results->flatMap(function ($result) {
            return $result->customers->map(function ($customer) use ($result) {
                $customerData = $customer->only('id', 'name');
                $customerData['phone'] = $result->only('id', 'title');
                if ($result->phoneTypes) {
                    $customerData['phone']['phoneTypes'] = $result->phoneTypes->map(function ($phoneType) {
                        return $phoneType->only('id', 'title');
                    });
                } else {
                    $customerData['phone']['phoneTypes'] = [];
                }
                $customerData['addresses'] = $customer->addresses->map(function ($address) {
                    $addressData = $address->only('id', 'title');
                    return $addressData;
                });
                return $customerData;
            });
        })->unique()->filter();
        return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
    }

    private static function handleUsers($model)
    {
        $object = "App\\Models\\AccountUser";
        $results = $object::with('user')->get();
        $results->where('account_id', getAccountUser()->account_id);
        $datas = $results->map(function ($result) {
            $data = ['id' => $result->id, 'title' => $result->user->firstname . " " . $result->user->lastname];
            return $data;
        });
        return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
    }

    private static function handleBrandSources($model)
    {
        $object = "App\\Models\\Brand";
        $results = $object::with('sources')->get()->where('account_id', getAccountUser()->account_id);
        $datas = $results->flatMap(function ($result) {
            return $result->sources->map(function ($source) use ($result) {
                return [
                    "id" => $source->pivot->id,
                    "source" => $source->title,
                    "brand" => $result->title,
                ];
            });
        });
        return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
    }

    private static function handleOrderStatuts($model, $id)
    {
        $object = "App\\Models\\OrderStatus";
        $results = $object::with('comments.childComments')->find($id);
        $datas = $results->comments->map(function ($result) {
            $data = ['id' => $result->id, 'title' => $result->title];
            $data['statuts_childs'] = $result->childComments->map(function ($childComment) {
                return ['id' => $childComment->id, 'title' => $childComment->title, 'is_change' => $childComment->is_change, 'postponed' => $childComment->postponed];
            });
            return $data;
        });
        return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
    }

    private static function handleProductAttributes($model, $id)
    {
        $object = "App\\Models\\Product";
        if ($id) {
            $result = $object::find($id);
            $pvas = [];
            if ($result)
                $pvas = $result->activePvas->flatMap(function ($pva) {
                    return $pva->variationAttribute->childVariationAttributes->map(function ($variationAttribute) {
                        return ["id" => $variationAttribute->attribute->id, "title" => $variationAttribute->attribute->title, "typeId" => $variationAttribute->attribute->typeAttribute->id, "typeTitle" => $variationAttribute->attribute->typeAttribute->title];
                    });
                });
        } else {
            $results = $object::get();
            $pvas = $results->flatMap(function ($result) {
                return $result->productVariationAttributes->flatMap(function ($pva) {
                    if ($pva->variationAttribute)
                        return $pva->variationAttribute->childVariationAttributes->map(function ($variationAttribute) {
                            return ["id" => $variationAttribute->attribute->id, "title" => $variationAttribute->attribute->title, "typeId" => $variationAttribute->attribute->typeAttribute->id, "typeTitle" => $variationAttribute->attribute->typeAttribute->title];
                        });
                });
            });
        }
        $datas = [];
        foreach ($pvas as $key => $pva) {
            $datas[$pva['id']] = ['id' => $pva['id'], 'attribute_type' => $pva['typeTitle'], 'title' => $pva['title']];
        }
        $values = collect($datas)->values();
        return ["statut" => 1, "type" => $model, "data" => $values->toArray()];
    }

    private static function handleProducts($model, $with)
    {
        $object = "App\\Models\\Product";
        $inAccountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
        $results = $object::with($with)->get();
        $results->whereIn('account_user_id', $inAccountUsers);
        $datas = $results->map(function ($result) {
            $hasVariations = $result->productVariationAttributes->map(function ($pva) {
                if ($pva->variationAttribute)
                    return $pva->variationAttribute->childVariationAttributes;
            });

            if ($hasVariations) {
                $data = [
                    'id' => $result->id,
                    'title' => $result->title,
                    'price' => $result->price->first()->price,
                    'principalImage' => $result->principalImage,
                    'productType' => $result->productType->only('id', 'title'),
                ];
                return $data;
            }
        });
        return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
    }

    private static function handleOrderOffers($model, $request, $id)
    {
        $object = "App\\Models\\Product";
        $attributes = $request['attributes'];
        $thePva = [];
        $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
        $product = $object::with(['productVariationAttributes.variationAttribute.childVariationAttributes' => function ($vattributes) use ($attributes) {
            $vattributes->whereIn('attribute_id', $attributes);
        }])->where(['id' => $id])->whereIn("account_user_id", $accountUsers)->first();
        if ($product) {

            $product->productVariationAttributes->map(function ($pva) use (&$thePva, $attributes) {
                $childs = $pva->variationAttribute->childVariationAttributes->map(function ($child) use (&$thePva) {
                    return $child->attribute_id;
                });
                $childPvas = $childs->toArray();
                sort($attributes);
                if ($childPvas == $attributes)
                    $thePva = $pva->id;
            })->toArray();
            $pva = ProductVariationAttribute::find($thePva);
            $results = $pva->product->offers->map(function ($offer) {
                $offerData = $offer->only('id', 'title', 'price', 'shipping_price', 'started', 'expired');
                $offerData['images'] = $offer->images;
                return $offerData;
            });
            return ["statut" => 1, "type" => $model, "data" => $results->toArray()];
        }
        return ["statut" => 1, "type" => $model, "data" => []];
    }

    private static function fetchResults($object, $selectElement, $id, $conditions, $inAccount, $inAccountUsers, $with)
    {
        if ($id) {
            return $object::select($selectElement)
                ->where('id', $id)
                ->when($conditions, function ($query) use ($conditions) {
                    foreach ($conditions as $condition) {
                        $query->where($condition['column'], $condition['value']);
                    }
                })
                ->when($inAccount == 1, function ($query) {
                    $query->where('account_id', getAccountUser()->account_id);
                })
                ->when($inAccountUsers, function ($query) use ($inAccountUsers) {
                    $query->whereIn('account_user_id', $inAccountUsers);
                })
                ->where('statut', 1)
                ->get();
        } else {
            return $object::when($with, function ($query) use ($with) {
                $query->with($with);
            })
                ->when($inAccount, function ($query) {
                    $query->where('account_id', getAccountUser()->account_id);
                })
                ->when($inAccountUsers, function ($query) use ($inAccountUsers) {
                    $query->whereIn('account_user_id', $inAccountUsers);
                })
                ->when($conditions, function ($query) use ($conditions) {
                    foreach ($conditions as $condition) {
                        $query->where($condition['column'], $condition['value']);
                    }
                })
                ->where('statut', 1)
                ->get()
                ->map(function ($result) use ($selectElement, $with) {
                    $data = $result->only($selectElement);
                    foreach ($with as $withData) {
                        $data[$withData] = $result->{$withData};
                    }
                    return $data;
                });
        }
    }
    public static function filterselect(Request $request, $model, $id = null)
    {
        $selectElement = ['id', 'title'];
        $inAccount = false;
        $inAccountUsers = [];
        $conditions = [];
        $with = [];
        switch ($model) {
            case 'cities':
                $object = "App\\Models\\City";
                $results = $object::get();
                $datas = $results->map(function ($result) {
                    return [
                        "id" => $result->id,
                        "title" => $result->title.($result->titlear ? " - ".$result->titlear : "")
                    ];
                });
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
                break;
            case 'measurements':
                $object = "App\\Models\\Measurement";
                $selectElement = ['id', 'title'];
                break;
            case 'countries':
                $object = "App\\Models\\Country";
                break;
            case 'sectors':
                $object = "App\\Models\\Sector";
                break;
            case 'regions':
                $object = "App\\Models\\Region";
                break;
            case 'offer_types':
                $object = "App\\Models\\OfferType";
                break;
            case 'taxonomy_types':
                $object = "App\\Models\\TypeTaxonomy";
                break;
            case 'product_types':
                $object = "App\\Models\\ProductType";
                break;

            case 'transaction_types':
                $object = "App\\Models\\TransactionType";
                $conditions[] = ['column' => 'statut', 'value' => 1];
                break;

            case 'transaction_model_types':
                return [
                    "statut" => 1,
                    "type" => "transaction_types",
                    "data" => [
                        ["id" => 1, "title" => "supplier"],
                        ["id" => 2, "title" => "user"],
                        ["id" => 3, "title" => "shipment"],
                        ["id" => 4, "title" => "expense"],
                    ]
                ];
            case 'transaction_models':
                $data = [];
                if ($id == 1) {
                    $suppliers = Supplier::where(['statut' => 1, 'account_id' => getAccountUser()->account_id])->get();
                    $data = $suppliers->map(function ($supplier) {
                        return $supplier->only('id', 'title');
                    });
                } elseif ($id == 2) {
                    $users = AccountUser::where(['statut' => 1, 'account_id' => getAccountUser()->account_id])->get();
                    $data = $users->map(function ($user) {
                        $userdata = [
                            'id' => $user->id,
                            'title' => $user->user->firstname . " " . $user->user->lastname
                        ];
                        return $userdata;
                    });
                } elseif ($id == 3) {
                    $shipments = Shipment::where('statut', 1)->whereNull('shipment_id')->whereIn('account_user_id', AccountUser::where('account_id', getAccountUser()->account_id)->get()->pluck('id')->toArray())->get();
                    $data = $shipments->map(function ($shipment) {
                        $userdata = [
                            'id' => $shipment->id,
                            'title' => $shipment->code . '-' . $shipment->title
                        ];
                        return $userdata;
                    });
                } elseif ($id == 4) {
                    $expenses = Expense::where('statut', 1)->whereIn('account_user_id', AccountUser::where('account_id', getAccountUser()->account_id)->get()->pluck('id')->toArray())->get();
                    $data = $expenses->map(function ($expense) {
                        $userdata = [
                            'id' => $expense->id,
                            'title' => $expense->code . '-' . $expense->description
                        ];
                        return $userdata;
                    });
                }
                return [
                    "statut" => 1,
                    "type" => "transaction_types",
                    "data" => $data
                ];
            case 'suppliers':
                $object = "App\\Models\\Supplier";
                $inAccount = true;
                break;
            case 'permissions':
                $object = "App\\Models\\Permission";
                $selectElement = ['id', 'name'];
            case 'salaries':
                $compensations = "App\\Models\\AccountCompensation"::whereIn('account_user_id', "App\\Models\\AccountUser"::where('account_id', getAccountUser()->account_id)->get()->pluck('id')->toArray())->get()->pluck('compensation_id')->toArray();
                $results = "App\\Models\\Compensation"::get()->whereNotIn('id', $compensations)->where('compensation_type_id', 1);
                $datas = $results->map(function ($result) {
                    return $result->only('id', 'title');
                })->values();
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
            case 'productsizes':
                $result = "App\\Models\\VariationAttribute"::where('id', $id)->first();
                $attributes = $result->childVariationAttributes->map(function ($child) {
                    return $child->attribute->id;
                })->toArray();
                return $attributes;
            case 'commissions':
                $results = "App\\Models\\Compensation"::get()->where('compensation_type_id', 2);
                $datas = $results->map(function ($result) {
                    $data = $result->only('id', 'title', 'multi');
                    $data['multi'] = $result->statut == 2 ? true : false;
                    return $data;
                })->values();
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
            case 'bonuses':
                $results = "App\\Models\\Compensation"::get()->where('compensation_type_id', 3);
                $datas = $results->map(function ($result) {
                    return $result->only('id', 'title');
                })->values();
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
            case 'type_phones':
                $object = "App\\Models\\PhoneTypes";
                $conditions[] = ['column' => 'account_id', 'value' => null];
                $conditions[] = ['column' => 'account_id', 'value' => getAccountUser()->account_id];
                $selectElement = ['id', 'title'];
                break;
            case 'phone_types':
                $object = "App\\Models\\PhoneTypes";
                $conditions[] = ['column' => 'account_id', 'value' => null];
                $conditions[] = ['column' => 'account_id', 'value' => getAccountUser()->account_id];
                $selectElement = ['id', 'title'];
                break;
            case 'collectors':
                $object = "App\\Models\\Collector";
                $conditions[] = ['column' => 'carrier_id', 'value' => $id];
                $selectElement = ['id', 'name'];
                $results = $object::get()->where('carrier_id', $id);
                $datas = $results->flatMap(function ($result) {
                    return [
                        "id" => $result->id,
                        "name" => $result->name,
                    ];
                });
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
            case 'roles':
                $object = "App\\Models\\Role";
                $selectElement = ['id', 'name'];
                break;
            case 'warehouses':
                $object = "App\\Models\\Warehouse";
                $results = $object::where('account_id', getAccountUser()->account_id)->whereNull('warehouse_id')->get();
                $datas = $results->map(function ($result) {
                    $data = ['id' => $result->id, 'title' => $result->title];
                    return $data;
                });
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];

            case 'partitions':
                $object = "App\\Models\\Warehouse";
                $selectElement = ['id', 'title'];
                $inAccount = true;
                $conditions[] = ['column' => 'warehouse_type_id', 'value' => 2];
                break;

            case 'rays':
                $object = "App\\Models\\Warehouse";
                $selectElement = ['id', 'title'];
                $inAccount = true;
                $conditions[] = ['column' => 'warehouse_type_id', 'value' => 3];
                break;
            case 'brands':
                $object = "App\\Models\\Brand";
                $selectElement = ['id', 'title'];
                $inAccount = true;
                break;
            case 'payment_methods':
                $object = "App\\Models\\PaymentMethod";
                $results = $object::get();
                $datas = $results->map(function ($result) {
                    $data = ['id' => $result->id, 'title' => $result->title];
                    return $data;
                });
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
            case 'payment_types':
                $object = "App\\Models\\PaymentType";
                $results = $object::get();
                $datas = $results->map(function ($result) {
                    $data = ['id' => $result->id, 'title' => $result->title];
                    return $data;
                });
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
            case 'comparison_operators':
                $object = "App\\Models\\ComparisonOperator";
                $selectElement = ['id', 'title', 'symbol'];
                break;
            case 'compensation_goals':
                $object = "App\\Models\\CompensationGoal";
                $selectElement = ['id', 'title'];
                break;
            case 'sources':
                $object = "App\\Models\\Source";
                $selectElement = ['id', 'title'];
                $inAccount = true;
                break;
            case 'carriers':
                $object = "App\\Models\\Carrier";
                $selectElement = ['id', 'title','images'];
                $inAccount = true;
                break;
            case 'categories':
                $object = "App\\Models\\Taxonomy";
                $selectElement = ['id', 'title'];
                $conditions[] = ['column' => 'type_taxonomy_id', 'value' => 1];
                $inAccountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
                break;
            case 'type_attributes':
                $object = "App\\Models\\TypeAttribute";
                $selectElement = ['id', 'title'];
                $inAccountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
                break;

            case 'offers':
                $object = "App\\Models\\Offer";
                $selectElement = ['id', 'title'];
                $inAccount = true;
                break;
            case 'order_customers':
                $object = "App\\Models\\Phone";
                $results = $object::where('account_id', getAccountUser()->account_id)->where('title', 'like', "%{$id}%")->orderBy('created_at', 'desc')->take(10)->get();
                $datas = $results->flatMap(function ($result) {
                    return $result->customers->map(function ($customer) use ($result) {
                        $customerData = $customer->only('id', 'name');
                        $customerData['phone'] = $result->only('id', 'title');
                        if ($result->phoneTypes) {
                            $customerData['phone']['phoneTypes'] = $result->phoneTypes->map(function ($phoneType) {
                                return $phoneType->only('id', 'title');
                            });
                        } else {
                            $customerData['phone']['phoneTypes'] = [];
                        }
                        $customerData['addresses'] = $customer->addresses->map(function ($address) {
                            $addressData = $address->only('id', 'title');
                            $addressData['city'] = ['id' => $address->city->id, 'title' => $address->city->title.($address->city->titlear ? " - ".$address->city->titlear : "")];
                            return $addressData;
                        });
                        $customerData['orders'] = $customer->orders->map(function ($data) {
                            $orderData = $data->only('id', 'code', 'shipping_code', 'note', 'order_id', 'created_at', 'updated_at');
                            // Calculate score dynamically from account_user_order_status and order_comment tables
                            if (!function_exists('calculateTotalOrderScore')) {
                                require_once app_path('Helpers/OrderScoreHelper.php');
                            }
                            
                            if (!$orderData['shipping_code'])
                                $orderData['shipping_code'] = "";
                            $orderData['can_change'] = in_array($data->order_status_id, [1, 2, 3, 4, 5]) ? true : false;
                            $orderData['user'] = $data->userCreated->map(function ($user) {
                                return [
                                    "id" => $user->id,
                                    "firstname" => $user->user->firstname,
                                    "lastname" => $user->user->lastname,
                                    "images" => $user->user->images,
                                ];
                            });
                            $orderData['comments'] = $data->lastOrderComments()->where('type', 'comment')->get()->map(function ($comment) {
                                $data = [
                                    "id" => $comment->id,
                                    "comment" => $comment->comment_id."",
                                    "title" => $comment->title,
                                    "created_at" => $comment->created_at,
                                    "user" => $comment->accountUser->user,
                                    "status" => $comment->orderStatus->only('id', 'title', 'statut'),
                                ];
                                $data["status"]['created_at'] = $comment->created_at;
                                return $data;
                            });
                            $orderData['customer'] = $data->customer->only('id', 'name', 'images');
                            $orderData['customer']['phones'] = $data->customer->phones->map(function ($phone) {
                                return $phone->only('id', 'title');
                            });
                            $orderData['customer']['address'] = $data->customer->addresses->map(function ($address) {
                                return $address->only('id', 'title', 'city');
                            });
                            $totalOrder = 0;
                            $orderData['products'] = ($data->order_status_id==2 ? $data->inactiveOrderPvas : $data->activeOrderPvas)->map(function ($actfOrderPva) use (&$totalOrder) {
                                $totalOrder += $actfOrderPva->price * $actfOrderPva->quantity;
                                $attributes = $actfOrderPva->ProductVariationAttribute->variationAttribute->childVariationAttributes->map(function ($child) {
                                    return $child->attribute->code;
                                })->toArray();
                                $productInfo = [
                                    'id' => $actfOrderPva->productVariationAttribute->product->id,
                                    'order_pva' => $actfOrderPva->id,
                                    'price' => $actfOrderPva->price,
                                    'quantity' => $actfOrderPva->quantity,
                                    'images' => $actfOrderPva->productVariationAttribute->product->images->sortByDesc('created_at')->values(),
                                    'productType' => $actfOrderPva->productVariationAttribute->product->productType,
                                    'product' => $actfOrderPva->productVariationAttribute->product->title . " " . implode('-', $attributes),
                                    'reference' => $actfOrderPva->productVariationAttribute->product->reference,
                                    'productsize' => $actfOrderPva->productVariationAttribute->variationAttribute->id,
                                    'attributes' => $actfOrderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($child) {
                                        return [
                                            "id" => $child->attribute->id,
                                            "title" => $child->attribute->title,
                                            "typeAttribute" => $child->attribute->typeAttribute->title,
                                        ];
                                    }),
                                ];
                                return $productInfo;
                            });
                            $orderData['total'] = $totalOrder;
                            $orderData['discount'] = $data->discount;
                            $orderData['carrier_price'] = $data->carrier_price;
                            $orderData['status'] = $data->orderStatus->only('id', 'title');
                            $orderData['brand'] = $data->brandSource->brand->only('id', 'title', 'images');
                            $orderData['carrier'] = ($data->pickup) ? $data->pickup->carrier->only('id', 'title', 'images') : null;
                            $source = $data->brandSource->source;
                            $sourceArr = $source->only('id', 'title', 'images');
                            if (isset($source->images) && $source->images instanceof \Illuminate\Support\Collection) {
                                $sourceArr['images'] = $source->images->sortByDesc('created_at')->values();
                            }
                            $orderData['source'] = $sourceArr;
                            return $orderData;
                        });
                        return $customerData;
                    });
                })->unique()->filter();
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
            case 'users':
                $object = "App\\Models\\AccountUser";
                $results = $object::with('user')->get();
                $results->where('account_id', getAccountUser()->account_id);
                $datas = $results->map(function ($result) {
                    $data = ['id' => $result->id, 'title' => $result->user->firstname . " " . $result->user->lastname];
                    return $data;
                });
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
            case 'brand_sources':
                $object = "App\\Models\\Brand";
                $results = $object::with('sources')->get()->where('account_id', getAccountUser()->account_id);
                $datas = $results->flatMap(function ($result) {
                    return $result->sources->map(function ($source) use ($result) {
                        return [
                            "id" => $source->pivot->id,
                            "source" => $source->title,
                            "brand" => $result->title,
                        ];
                    })->unique()->filter();
                });
                $filtered = [];

                // Stocker les plus petits ID pour chaque (brand, source)
                foreach ($datas as $item) {
                    $key = $item['brand'] . '-' . $item['source'];

                    // Vérifier si la clé existe déjà et comparer les ID
                    if (!isset($filtered[$key]) || $item['id'] < $filtered[$key]['id']) {
                        $filtered[$key] = $item;
                    }
                }

                // Convertir en array simple
                $result = array_values($filtered);
                return ["statut" => 1, "type" => $model, "data" => $result];
            case 'order_statuts':
                $object = "App\\Models\\OrderStatus";
                if ($id) {
                    $results = $object::with('comments.childComments')->find($id);
                    $datas = $results->comments->sortByDesc('id')->map(function ($result) {
                        $data = ['id' => $result->id, 'title' => $result->title];
                        $data['statuts_childs'] = $result->childComments->map(function ($childComment) {
                            return ['id' => $childComment->id, 'title' => $childComment->title, 'is_change' => $childComment->is_change, 'postponed' => $childComment->postponed];
                        });
                        return $data;
                    })->values();
                } else {
                    $results = $object::with('comments.childComments')->get();
                    $datas = $results->map(function ($result) {
                        $dataResult = ['id' => $result->id, 'title' => $result->title];
                        $dataResult['statutses'] =$result->comments;
                        $dataResult['statutses'] =  $result->comments->map(function ($comment) {
                            $data = ['id' => $comment->id, 'title' => $comment->title];
                            $data['statuts_childs'] = $comment->childComments->map(function ($childComment) {
                                $childData= ['id' => $childComment->id, 'title' => $childComment->title, 'is_change' => $childComment->is_change, 'postponed' => $childComment->postponed];
                                return $childData;
                            });
                            return $data;
                        });
                         return $dataResult;
                    });
                }
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];
            case 'statuses':
                $object = "App\\Models\\OrderStatus";
                $selectElement = ['id', 'title'];
                $object = "App\\Models\\OrderStatus";
                break;
            case 'product_attributes':
                $object = "App\\Models\\Product";
                if ($id) {
                    $result = $object::find($id);
                    $pvas = $result->activePvas->flatMap(function ($pva) {
                        return $pva->variationAttribute->childVariationAttributes->map(function ($variationAttribute) {
                            return ["id" => $variationAttribute->attribute->id, "code" => $variationAttribute->attribute->code, "title" => $variationAttribute->attribute->title, "typeId" => $variationAttribute->attribute->typeAttribute->id, "typeTitle" => $variationAttribute->attribute->typeAttribute->title];
                        });
                    });
                } else {
                    $results = $object::get();
                    $pvas = $results->flatMap(function ($result) {
                        return $result->productVariationAttributes->flatMap(function ($pva) {
                            if ($pva->variationAttribute)
                                return $pva->variationAttribute->childVariationAttributes->map(function ($variationAttribute) {
                                    return ["id" => $variationAttribute->attribute->id, "title" => $variationAttribute->attribute->title, "typeId" => $variationAttribute->attribute->typeAttribute->id, "typeTitle" => $variationAttribute->attribute->typeAttribute->title];
                                });
                        });
                    });
                }
                $datas = [];
                foreach ($pvas as $key => $pva) {
                    $datas[$pva['id']] = ['id' => $pva['id'], 'code' => $pva['title'], 'attribute_type' => $pva['typeTitle'], 'title' => $pva['title']];
                }

                $sorted = collect($datas)->sortBy('attribute_type')->values();
                return ["statut" => 1, "type" => $model, "data" => $sorted->toArray()];
            case 'products':
                $object = "App\\Models\\Product";
                $inAccountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
                /*$products = Product::whereIn('account_user_id', $inAccountUsers)->get();
                $brandsources = BrandSource::where('account_id', getAccountUser()->account_id)->get();
                $products->map(function ($product) use ($brandsources) {
                    $brandsources->map(function ($brandsource) use ($product) {
                        $product->brandSources()->syncWithoutDetaching([$brandsource->id => ['statut' => 1, 'created_at' => now(), 'account_user_id' => getAccountUser()->id, 'updated_at' => now()]]);
                    });
                });*/
                $results = $object::whereHas('brandSources', function ($query) use ($id) {
                    $query->where('brand_source_id', $id);
                })->whereIn('account_user_id', $inAccountUsers)->get();
                $datas = $results->map(function ($result) {
                    $hasVariations = $result->productVariationAttributes->map(function ($pva) {
                        if ($pva->variationAttribute)
                            return $pva->variationAttribute->childVariationAttributes;
                    });

                    if ($hasVariations) {
                        $data = [
                            'id' => $result->id,
                            'title' => $result->title,
                            'price' => $result->price->first()->price,
                            'principalImage' => $result->principalImage,
                            'productType' => $result->productType->only('id', 'title'),
                        ];
                        return $data;
                    }
                });
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];

            case 'customer_types':
                $object = "App\\Models\\CustomerType";
                $selectElement = ['id', 'title'];
                $inAccountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
                break;

            case 'shipment_types':
                $object = "App\\Models\\ShipmentType";
                $results = $object::whereNot('id',4)->get();
                $datas = $results->map(function ($result) {
                    $data = ['id' => $result->id, 'title' => $result->title];
                    return $data;
                });
                return ["statut" => 1, "type" => $model, "data" => $datas->toArray()];


            case 'order_offers':
                $object = "App\\Models\\Product";
                $attributes = $request['attributes'];
                $thePva = [];
                $accountUsers = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();
                $product = $object::with(['productVariationAttributes.variationAttribute.childVariationAttributes' => function ($vattributes) use ($attributes) {
                    $vattributes->whereIn('attribute_id', $attributes);
                }])->where(['id' => $id])->whereIn("account_user_id", $accountUsers)->first();
                $product->productVariationAttributes->map(function ($pva) use (&$thePva, $attributes) {
                    $childs = $pva->variationAttribute->childVariationAttributes->map(function ($child) use (&$thePva) {
                        return $child->attribute_id;
                    });
                    $childPvas = $childs->toArray();
                    sort($attributes);
                    if ($childPvas == $attributes)
                        $thePva = $pva->id;
                })->toArray();
                $pva = ProductVariationAttribute::find($thePva);
                $results = $pva->product->offers->map(function ($offer) {
                    $offerData = $offer->only('id', 'title', 'price', 'shipping_price', 'started', 'expired');
                    $offerData['images'] = $offer->images;
                    return $offerData;
                });
                return ["statut" => 1, "type" => $model, "data" => $results->toArray()];

            default:
                $object = "App\\Models\\Country";
                break;
        }
        if ($id) {
            $results = $object::select($selectElement)
                ->where('id', $id)
                ->when(($conditions), function ($query) use ($conditions) {
                    foreach ($conditions as $key => $condition) {
                        $query->where($condition['column'], $condition['value']);
                    }
                })
                ->when(($inAccount), function ($query) {
                    $query->where('account_id', getAccountUser()->account_id);
                })
                ->when(($inAccountUsers), function ($query) use ($inAccountUsers) {
                    $query->whereIn('account_user_id', $inAccountUsers);
                })->where('statut', 1)
                ->get();
        } else {
            $selects = $object::when(($with), function ($query) use ($with) {
                $query->with($with);
            })->when(($inAccount), function ($query) {
                $query->where('account_id', getAccountUser()->account_id);
            })
                ->when(($inAccountUsers), function ($query) use ($inAccountUsers) {
                    $query->whereIn('account_user_id', $inAccountUsers);
                })
                ->when(($conditions), function ($query) use ($conditions) {
                    if (count($conditions) > 1) {
                        foreach ($conditions as $key => $condition) {
                            $query->orWhere($condition['column'], $condition['value']);
                        }
                    } else {
                        foreach ($conditions as $key => $condition) {
                            $query->where($condition['column'], $condition['value']);
                        }
                    }
                })->where('statut', 1)
                ->get();
            $results = $selects->map(function ($result) use ($selectElement, $with) {
                $data = $result->only($selectElement);
                foreach ($with as $key => $withData) {
                    $data[$withData] = $result->{$withData};
                }
                return $data;
            });
        }
        return ["statut" => 1, "type" => $model, "data" => $results->toArray()];
    }
}
