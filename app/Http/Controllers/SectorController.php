<?php

namespace App\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use App\Models\City;
use App\Models\Sector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SectorController extends Controller
{

    public static function index(Request $request)
    {
        $request = collect($request->query())->toArray();
        $associated=[];
        $model = 'App\\Models\\Sector';
        //permet de récupérer la liste des regions inactive filtrés
        if(isset($request['filters']['cities']) && array_filter($request['filters']['cities'], function($value) {
            return $value !== null;
        })){
            $associated[]=[
                'model'=>'App\\Models\\City',
                'title'=>'city',
                'search'=>true,
                'column'=>'title',
                'foreignKey'=>'city_id',
                'parent'=>['column'=>'title','key'=>'id'],
                'select'=>array_filter($request['filters']['cities'], function($value) {
                    return $value !== null;
                }),
            ];
        }else{
            $associated[]=[
                'model'=>'App\\Models\\City',
                'title'=>'city',
                'search'=>true,
            ];
        }
        
        $datas = FilterController::searchs(new Request($request),$model,['id','title'], true,$associated);
        return $datas;
    }
    public function create(Request $request)
    {
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use($request){ // Custom validation rule
                    $index = str_replace(['*', '.title'], '', $attribute);
                    $city_id = $request->input("{$index}.city_id"); // Get ID from request
                    HelperFunctions::uniqueStoreBelongTo('sector', 'title', $value,'city_id',$city_id,$fail);
                },
            ],
            '*.statut' => 'required',
            '*.city_id' => 'required|exists:cities,id',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $sectors = collect($request->all())->map(function ($sector) {
            $sector_only=collect($sector)->only('title','statut','city_id');
            $sector = Sector::create($sector_only->all());
            return $sector;
        });

        return response()->json([
            'statut' => 1,
            'data' =>  $sectors,
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
        $sector = Sector::with('city')->find($id);
        if(!$sector)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        if (isset($request['sectorInfo'])){
            $info = collect($sector->only('title', 'statut','city'))->toArray();
            $data["sectorInfo"]['data']=$info;
        }

        return response()->json([
            'statut' => 1,
            'data' =>$data
        ]);
    }



    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:sectors,id',
            '*.title' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail) use($request){ // Custom validation rule
                    $index = str_replace(['*', '.title'], '', $attribute);
                    $city_id = $request->input("{$index}.city_id"); // Get ID from request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    HelperFunctions::uniqueUpdateBelongTo('sector', 'title', $value,'city_id',$city_id,$id,$fail);
                },
            ],
            '*.city_id' => 'required|exists:cities,id',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $sectors = collect($request->all())->map(function ($sectorData){
            
            $sector_all=collect($sectorData)->all();
            $sector = Sector::find($sector_all['id']);
            $enabled=isset($sectorData['enabled'])?$sectorData['enabled']:1;
            if($enabled==0){
                $updateAccountCyity=AccountLocationController::attachLocation("sectors",$sector->id);
            }else{
                $updateAccountCyity=AccountLocationController::detachLocation("sectors",$sector->id);
            }
            $sector->update($sector_all);
            return $sector;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $sectors,
        ]);
    }

    public function destroy($id)
    {
        $Sector = Sector::find($id);
        $Sector->delete();
        return response()->json([
            'statut' => 1,
            'data' => $Sector,
        ]);
    }
}
