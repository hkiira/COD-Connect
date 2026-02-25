<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Region;
use App\Models\Country;
use App\Models\City;
use App\Models\Account;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\HelperFunctions;

class RegionController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\Region';
        //permet de récupérer la liste des regions inactive filtrés
        if(isset($request['countries']) && array_filter($request['countries'], function($value) {
            return $value !== null;
        })){
            $associated[]=[
                'model'=>'App\\Models\\Country',
                'title'=>'country',
                'search'=>true,
                'column'=>'title',
                'foreignKey'=>'country_id',
                'parent'=>['column'=>'title','key'=>'id'],
                'select'=>$request['countries'],
            ];
        }else{
            $associated[]=[
                'model'=>'App\\Models\\Country',
                'title'=>'country',
                'search'=>true,
            ];
        }
        if(isset($request['cities'])&& array_filter($request['cities'], function($value) {
            return $value !== null;
        })){
            $associated[]=[
                'model'=>'App\\Models\\City',
                'title'=>'cities',
                'search'=>true,
                'column'=>'title',
                'foreignKey'=>'region_id',
                'select'=>$request['cities'],
            ];
        }else{
            $associated[]=[
                'model'=>'App\\Models\\City',
                'title'=>'cities',
                'search'=>true,
                'column'=>'title',
                'foreignKey'=>'region_id',
            ]; 
        }
        $datas = FilterController::searchs(new Request($request),$model,['id','title'], true,$associated);
        return $datas;
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $countries= [];
        if (isset($request['cities']['inactive'])){ 
            $model = 'App\\Models\\City';
            //permet de récupérer la liste des regions inactive filtrés
            $countries['cities']['inactive'] = FilterController::searchs(new Request($request['cities']['inactive']),$model,['id','title'], true,[0=>['model'=>'App\\Models\\Region','title'=>'region','search'=>false]]);
        }
        
        return response()->json([
            'statut' => 1,
            'data' => $countries,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use($request){ // Custom validation rule
                    $index = str_replace(['*', '.title'], '', $attribute);
                    $country_id = $request->input("{$index}.country_id"); // Get ID from request
                    HelperFunctions::uniqueStoreBelongTo('region', 'title', $value,'country_id',$country_id,$fail);
                },
            ],
            '*.statut' => 'required',
            '*.country_id' => 'required|exists:countries,id',
            '*.cities.*' => 'exists:cities,id',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $regions = collect($request->all())->map(function ($region) {
            $data=collect($region)->all();
            $region_only=collect($region)->only('title','statut','country_id');
            $region = Region::create($region_only->all());
            if(isset($data['cities'])){
                foreach ($data['cities'] as $key => $cityId) {
                    $city = City::find($cityId);
                    if($city){
                        $city->region()->associate($region);
                        $city->save();
                    }
                }
            }
            return $region;
        });
    
        return response()->json([
            'statut' => 1,
            'data' => $regions,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        
        $request = collect($request->query())->toArray();
        $data=[];
        $region = Region::with('country')->find($id);
        if(!$region)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        if (isset($request['regionInfo'])){
            $info = collect($region->only('title', 'statut','country'))->toArray();
            $data["regionInfo"]['data']=$info;
        }
        if (isset($request['cities']['active'])){
            $model = 'App\\Models\\City';
            $request['cities']['active']['where']=['column'=>'region_id','value'=>$region->id];
            $data['cities']['active'] = FilterController::searchs(new Request($request['cities']['active']),$model,['id','title'], true);
        }

        if (isset($request['cities']['inactive'])){
            $model = 'App\\Models\\City';
            $request['cities']['inactive']['whereNot']=['column'=>'region_id','value'=>$region->id];
            $data['cities']['inactive'] = FilterController::searchs(new Request($request['cities']['inactive']),$model,['id','title'], true,[0=>['model'=>'App\\Models\\Region','title'=>'region','search'=>false]]);
        }

        return response()->json([
            'statut' => 1,
            'data' =>$data
        ]);
    }


    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:regions,id|max:255',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use($request){ // Custom validation rule
                    $index = str_replace(['*', '.title'], '', $attribute);
                    $country_id = $request->input("{$index}.country_id"); // Get ID from request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    HelperFunctions::uniqueUpdateBelongTo('region', 'title', $value,'country_id',$country_id,$id,$fail);
                },
            ],
            '*.citiesToActive.*' => 'exists:cities,id',
            '*.citiesToInactive.*' => 'exists:cities,id',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $regions = collect($request->all())->map(function ($regionData) use ($id) {
            $region_all=collect($regionData)->all();
            $enabled=isset($regionData['enabled'])?$regionData['enabled']:1;
            $region = Region::find($region_all['id']);
            if($enabled==0){
                $updateAccountRegion=AccountLocationController::attachLocation("regions",$region->id);
            }else{
                $updateAccountRegion=AccountLocationController::detachLocation("regions",$region->id);
            }
            if(isset($region_all['citiesToInactive'])){
                foreach ($region_all['citiesToInactive'] as $key => $cityId) {
                    $city = City::where(['id'=>$cityId,'region_id'=>$region->id])->first();
                    if($city){
                        $city->region()->dissociate();
                        $city->save();
                    }
                }
            }
            if(isset($region_all['citiesToActive'])){
                foreach ($region_all['citiesToActive'] as $key => $cityId) {
                    $city = City::where('id',$cityId)->first();
                    $city->region()->associate($region);
                    $city->save();
                }
            }
            $region->update($region_all);
            $region=Region::with('cities')->where('id',$region->id)->first();
            return $region;
        });

        return response()->json([
            'statut' => 1,
            'data' => $regions,
        ]);
    }


    public function destroy($id)
    {
        $region = Region::find($id);
        $region->delete();
        return response()->json([
            'statut' => 1,
            'data' => $region,
        ]);
    }
}
