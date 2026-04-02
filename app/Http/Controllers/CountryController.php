<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Country;
use App\Models\CountryHistory;
use App\Models\Region;
use App\Models\Image;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CountryController extends Controller
{
    public function index(Request $request)
    {
        $params      = collect($request->query())->toArray();
        $search      = $params['search'] ?? null;
        $sort        = (isset($params['sort'][0]) && is_array($params['sort'][0]))
                           ? $params['sort'][0]
                           : ['column' => 'created_at', 'order' => 'DESC'];
        $perPage     = (int) ($params['pagination']['per_page'] ?? 10);
        $perPage     = $perPage > 0 ? $perPage : 10;
        $currentPage = (int) ($params['pagination']['current_page'] ?? 0) + 1;
        $startDate   = $params['startDate'] ?? null;
        $endDate     = $params['endDate'] ?? null;

        $selectedRegions = (isset($params['regions']) && is_array($params['regions'])
            && array_filter($params['regions'], fn($v) => $v !== null))
            ? $params['regions'] : null;

        $paginated = Country::with(['regions', 'images', 'history'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('id', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%");
                });
            })
            ->when($selectedRegions, function ($q) use ($selectedRegions, $search) {
                $countryIds = Region::whereIn('id', $selectedRegions)->pluck('country_id');
                if ($search) {
                    $countryIds = $countryIds->merge(
                        Region::where('title', 'like', "%{$search}%")->pluck('country_id')
                    )->unique();
                    $q->orWhereIn('id', $countryIds->toArray());
                } else {
                    $q->whereIn('id', $countryIds->toArray());
                }
            })
            ->when($startDate && $endDate, function ($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', ["{$startDate} 00:00:00", "{$endDate} 23:59:59"]);
            })
            ->orderBy($sort['column'], $sort['order'])
            ->paginate($perPage, ['*'], 'page', $currentPage);

        return [
            'statut' => 1,
            'data'   => $paginated->items(),
            'meta'   => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
            ],
        ];
    }

    /*public function create()
    {
    }*/

    public function create(Request $request)
    {
        //transformer les données sous des array
        $request = collect($request->query())->toArray();
        $countries= [];
        if (isset($request['regions']['inactive'])){ 
            $model = 'App\\Models\\Region';
            //permet de récupérer la liste des regions inactive filtrés
            $countries['regions']['inactive'] = FilterController::searchs(new Request($request['regions']['inactive']),$model,['id','title'], true,[0=>['model'=>'App\\Models\\Country','title'=>'country','search'=>false]]);
        }
        
        return response()->json([
            'statut' => 1,
            'data' => $countries,
        ]);
    }

    public function store(Request $request)
    {
      
        // Validation des données
        $validator = Validator::make($request->all(), [
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('country', 'title', $value);
                    $titleModel = Country::where('title', $value)->first();
                    if ($titleModel) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.statut' => 'required',
            '*.regions.*' => 'exists:regions,id',
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage==null) {
                        $fail("not exist"); 
                    }elseif($principalImage->account_id!==getAccountUser()->account_id){
                        $fail("not exist"); 
                    }
                },
            ],
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $countries = collect($request->all())->map(function ($country) {
            $data=collect($country)->all();
            $country_only=collect($country)->only('title','statut');
            $country = Country::create($country_only->all());
            if(isset($data['principalImage'])){
                $image=Image::find($data['principalImage']);
                $country->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            }elseif(isset($data['newPrincipalImage'])){
                
                $images[]["image"]=$data['newPrincipalImage'];
                $imageData=[
                        'title'=>$country->title,
                        'type'=>'country',
                        'image_type_id'=>8,
                        'images'=>$images
                    ];
                ImageController::store( new Request([$imageData]),$country);
            }
            if(isset($data['regions'])){
                Region::whereIn('id', $data['regions'])->update(['country_id' => $country->id]);
            }
            return $country;
        });
    
        return response()->json([
            'statut' => 1,
            'data' => $countries,
        ]);

    }

    public function show($id)
    {
        $country = Country::find($id);
        return view('countries.show', compact('country'));
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data=[];
        $country = Country::with('images')->find($id);
        if(!$country)
        return response()->json([
            'statut'=>0,
            'data'=> 'not exist'
        ]);
        if (isset($request['countryInfo'])){

            $info = collect($country->only('title', 'statut'))->toArray();
            $info['principalImage']=$country->images;
            $data["countryInfo"]['data']=$info;
        }
        if (isset($request['regions'])) {
            $model = Region::class;

            $activeRequest = isset($request['regions']['active']) && is_array($request['regions']['active'])
                ? $request['regions']['active']
                : [];
            $inactiveRequest = isset($request['regions']['inactive']) && is_array($request['regions']['inactive'])
                ? $request['regions']['inactive']
                : [];

            $activeRequest['where'] = ['column' => 'country_id', 'value' => $country->id];
            $inactiveRequest['whereNot'] = ['column' => 'country_id', 'value' => $country->id];

            // Always return both paginated blocks so frontend pagination state stays defined.
            $data['regions']['active'] = FilterController::searchs(new Request($activeRequest), $model, ['id', 'title'], true);
            $data['regions']['inactive'] = FilterController::searchs(new Request($inactiveRequest), $model, ['id', 'title'], true);
        }


        return response()->json([
            'statut' => 1,
            'data' =>$data
        ]);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->except('_method'), [
            '*.id' => 'required|exists:countries,id|max:255', // Validate ID field
            '*.title' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('country', 'title', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.title'], '', $attribute);
                    
                    // Get the ID and title from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $titleModel = Country::where('title', $value)->first(); // Find model by title
                    $idModel = Country::where('id', $id)->first(); // Find model by ID
                    
                    // Check if a country with the same title exists but with a different ID
                    if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.regionsToActive.*' => 'exists:regions,id', // Validate regionsToActive field
            '*.regionsToInactive.*' => 'exists:regions,id', 
            '*.newPrincipalImage' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            '*.principalImage' => [ // Validate title field
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    $principalImage = Image::where('id', $value)->first();
                    if ($principalImage==null) {
                        $fail("not exist"); 
                    }elseif($principalImage->account_id!==getAccountUser()->account_id){
                        $fail("not exist"); 
                    }
                },
            ],
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $countries = collect($request->except('_method'))->map(function ($countryData) use ($id) {
            $country_all=collect($countryData)->all();
            $country = Country::find($country_all['id']);
            $enabled=isset($countryData['enabled'])?$countryData['enabled']:1;
            if($enabled==0){
                $updateAccountCountry=AccountLocationController::attachLocation("countries",$country->id);
            }else{
                $updateAccountCountry=AccountLocationController::detachLocation("countries",$country->id);
            }
            
            if(isset($country_all['principalImage'])){
                $country->images()->detach($country->images->pluck('id')->toArray());
                $image=Image::find($country_all['principalImage']);
                $country->images()->syncWithoutDetaching([
                    $image->id => [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            }elseif(isset($country_all['newPrincipalImage'])){
                $images[]["image"]=$country_all['newPrincipalImage'];
                $imageData=[
                        'title'=>$country->title,
                        'type'=>'country',
                        'image_type_id'=>8,
                        'images'=>$images
                    ];
                ImageController::store( new Request([$imageData]),$country);
            }
            if(isset($country_all['regionsToInactive'])){
                foreach ($country_all['regionsToInactive'] as $key => $regionId) {
                    $region = Region::where(['id'=>$regionId,'country_id'=>$country->id])->first();
                    if($region){
                        $region->country()->dissociate();
                        $region->save();
                    }
                }
            }
            if(isset($country_all['regionsToActive'])){
                foreach ($country_all['regionsToActive'] as $key => $regionId) {
                    $region = Region::find($regionId);
                    $region->country()->associate($country);
                    $region->save();
                }
            }
            $country->update($country_all);
            $newData = $country->toArray();
            CountryHistory::create([
                'country_id' => $country->id,
                'changes' => json_encode([$newData]),
                'user_id' => auth()->id(),
            ]);
            $country=Country::with(['regions','images'])->where('id',$country->id)->first();
            return $country;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $countries,
        ]);
     }

    public function destroy($id)
    {
        $country = Country::find($id);
        $country->delete();
        return response()->json([
            'statut' => 1,
            'data' => $country,
        ]);
    }
}
