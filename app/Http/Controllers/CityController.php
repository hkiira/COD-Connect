<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\City;
use App\Models\Sector;
use App\Models\Region;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CityController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\City';
        //permet de récupérer la liste des regions inactive filtrés
        if(isset($request['regions']) && array_filter($request['regions'], function($value) {
            return $value !== null;
        })){
            $associated[]=[
                'model'=>'App\\Models\\Region',
                'title'=>'region',
                'search'=>true,
                'column'=>'title',
                'foreignKey'=>'region_id',
                'parent'=>['column'=>'title','key'=>'id'],
                'select'=>array_filter($request['regions'], function($value) {
                    return $value !== null;
                }),
            ];
        }else{
            $associated[]=[
                'model'=>'App\\Models\\Region',
                'title'=>'region',
                'search'=>true,
            ];
        }
        if(isset($request['sectors'])&& array_filter($request['sectors'], function($value) {
            return $value !== null;
        })){
            $associated[]=[
                'model'=>'App\\Models\\Sector',
                'title'=>'sectors',
                'search'=>true,
                'column'=>'title',
                'foreignKey'=>'city_id',
                'select'=>$request['sectors'],
            ];
        }else{
            $associated[]=[
                'model'=>'App\\Models\\Sector',
                'title'=>'sectors',
                'search'=>true,
                'column'=>'title',
                'foreignKey'=>'city_id',
            ]; 
        }
        $datas = FilterController::searchs(new Request($request),$model,['id','title'], true,$associated);
        return $datas;
    }
    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data= [];
        if (isset($request['sectors']['inactive'])){ 
            $model = 'App\\Models\\Sector';
            //permet de récupérer la liste des regions inactive filtrés
            $data['sectors']['inactive'] = FilterController::searchs(new Request($request['sectors']['inactive']),$model,['id','title'], true,[0=>['model'=>'App\\Models\\City','title'=>'city','search'=>false]]);
        }
        
        return response()->json([
            'statut' => 1,
            'data' => $data,
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
                    $region_id = $request->input("{$index}.region_id"); // Get ID from request
                    HelperFunctions::uniqueStoreBelongTo('city', 'title', $value,'region_id',$region_id,$fail);
                },
            ],
            '*.statut' => 'required',
            '*.region_id' => 'required|exists:regions,id',
            '*.sectors.*' => 'exists:sectors,id',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $cities = collect($request->all())->map(function ($city) {
            $data=collect($city)->all();
            $city_only=collect($city)->only('title','statut','region_id');
            $city = city::create($city_only->all());
            if(isset($data['sectors'])){
                foreach ($data['sectors'] as $key => $sectorId) {
                    $sector = Sector::find($sectorId);
                    if($sector){
                        $sector->city()->associate($city);
                        $sector->save();
                    }
                }
            }
            $city=City::with(['region','sectors'])->find($city->id);
            return $city;
        });
        return response()->json([
            'statut' => 1,
            'data' =>  $cities,
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
        $city = City::with('region')->find($id);
        if(!$city)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        if (isset($request['cityInfo'])){
            $info = collect($city->only('title', 'statut','region'))->toArray();
            $data["cityInfo"]['data']=$info;
        }
        if (isset($request['sectors']['active'])){
            $model = 'App\\Models\\Sector';
            $request['sectors']['active']['where']=['column'=>'city_id','value'=>$city->id];
            $data['sectors']['active'] = FilterController::searchs(new Request($request['sectors']['active']),$model,['id','title'], true);
        }

        if (isset($request['sectors']['inactive'])){
            $model = 'App\\Models\\Sector';
            $request['sectors']['inactive']['whereNot']=['column'=>'city_id','value'=>$city->id];
            $data['sectors']['inactive'] = FilterController::searchs(new Request($request['sectors']['inactive']),$model,['id','title'], true,[0=>['model'=>'App\\Models\\City','title'=>'city','search'=>false]]);
        }

        return response()->json([
            'statut' => 1,
            'data' =>$data
        ]);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:cities,id|max:255',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use($request){ // Custom validation rule
                    $index = str_replace(['*', '.title'], '', $attribute);
                    $region_id = $request->input("{$index}.region_id"); // Get ID from request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    HelperFunctions::uniqueUpdateBelongTo('city', 'title', $value,'region_id',$region_id,$id,$fail);
                },
            ],
            '*.sectorsToActive.*' => 'exists:sectors,id',
            '*.sectorsToInactive.*' => 'exists:sectors,id',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $cities = collect($request->all())->map(function ($cityData) use ($id) {
            $city_all=collect($cityData)->all();
            $city = city::find($city_all['id']);
            $enabled=isset($cityData['enabled'])?$cityData['enabled']:1;
            if($enabled==0){
                AccountLocationController::attachLocation("cities",$city->id);
            }else{
                AccountLocationController::detachLocation("cities",$city->id);
            }
            if(isset($city_all['sectorsToActive'])){
                foreach ($city_all['sectorsToActive'] as $key => $sectorId) {
                    $sector = Sector::find($sectorId);
                    $sector->city()->associate($city);
                    $sector->save();
                }
            }
            if(isset($city_all['sectorsToInactive'])){
                foreach ($city_all['sectorsToInactive'] as $key => $sectorId) {
                    $sector = Sector::find($sectorId);
                    $sector->city()->dissociate();
                    $sector->save();
                }
            }
            $city->update($city_all);
            $city=City::with(['region','sectors'])->find($city->id);
            return $city;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $cities,
        ]);
     }

     public function destroy($id)
     {
         $city = City::find($id);
         $city->delete();
         return response()->json([
             'statut' => 1,
             'data' => $city,
         ]);
     }
}
