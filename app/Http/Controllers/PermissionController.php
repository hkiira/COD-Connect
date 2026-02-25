<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Permission;
use App\Models\Role;

class PermissionController extends Controller
{
    public function index(Request $request)
    {
        // Affiche la liste des permissions
        $request = collect($request->query())->toArray();
        $filters = HelperFunctions::filterColumns($request,[]);
        if(isset($filters['roles']) && (count($filters['roles'])>0)){
            if($filters['search']){
                $permissions = Permission::with('roles')->whereHas('roles', function ($query) use ($filters) {
                    $query->whereIn('id', $filters['roles']);
                })
                ->orWhere('name','like',"%{$filters['search']}%")
                ->orderBy($filters['sort'][0]['column'], $filters['sort'][0]['order'])
                ->get();
            }else{
                $permissions = Permission::with('roles')->whereHas('roles', function ($query) use ($filters) {
                    $query->whereIn('id', $filters['roles']);
                })
                ->orderBy($filters['sort'][0]['column'], $filters['sort'][0]['order'])
                ->get();
            }
        }else{
            $permissions = Permission::with('roles')->where('name','like',"%{$filters['search']}%")->get();
        }
        
        $dataPagination =  HelperFunctions::getPagination(collect($permissions), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        return $dataPagination;
    }

    public function create(Request $request)
    {
        $permissions= [];

        if (isset($request['roles']['inactive'])){ 
            $model = 'App\\Models\\Role';
            //permet de récupérer la liste des regions inactive filtrés
            $roles['roles']['inactive'] = FilterController::searchs(new Request($request['roles']['inactive']),$model,['id','name'], true,[['model'=>'App\\Models\\Permission','title'=>'permissions','search'=>false]]);
        }
        
        return response()->json([
            'statut' => 1,
            'data' => $roles,
        ]);
    }

    public function store(Request $request)
    {
        
        $validator = Validator::make($request->all(), [
            '*.name' => [ // Validate title field
                'required', // Title is required
                'max:255', // Title should not exceed 255 characters
                function ($attribute, $value, $fail){ // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('permission', 'name', $value);
                    $titleModel = Permission::where('name', $value)->first();
                    if ($titleModel) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.roles.*' => 'exists:roles,id',
            '*.permission_type_id' => 'required|exists:permission_types,id',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $permissions = collect($request->all())->map(function ($permission) {
            $request_all=collect($permission);
            $permission_only=collect($permission)->only('name','statut','permission_type_id');
            $permission = permission::create($permission_only->all());
            if(isset($request_all['roles'])){
                foreach ($request_all['roles'] as $key => $roleId) {
                    $role = Role::find($roleId);
                    $role->givePermissionTo($permission);
                }
            }
            return $permission;
        });

        return response()->json([
            'statut' => 1,
            'data' =>  $permissions,
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
        $permission = Permission::find($id);
        if(!$permission)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        if (isset($request['permissionInfo'])){
            $info = collect($permission->only('name', 'statut'))->toArray();
            $data["permissionInfo"]['data']=$info;
        }

        if (isset($request['roles']['active'])){
            $model = 'App\\Models\\Role';
            $request['roles']['active']['whereIn'][0]=['table'=>'permissions','column'=>'permission_id','value'=>$permission->id];
            $data['roles']['active'] = FilterController::searchs(new Request($request['roles']['active']),$model,['id','name'], true,[['model'=>'App\\Models\\Permission','title'=>'permissions','search'=>false]]);
        }
        if (isset($request['roles']['inactive'])){
            $model = 'App\\Models\\Role';
            $request['roles']['inactive']['whereNotIn'][0]=['table'=>'permissions','column'=>'permission_id','value'=>$permission->id];
            $data['roles']['inactive'] = FilterController::searchs(new Request($request['roles']['inactive']),$model,['id','name'], true,[['model'=>'App\\Models\\Permission','title'=>'permissions','search'=>false]]);
        }
        return response()->json([
            'statut' => 1,
            'data' =>$data
        ]);
    }


    public function update(Request $request, $id,$local=false)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:permissions,id',
            '*.name' => [ // Validate name field
                'required', // name is required
                'max:255', // name should not exceed 255 characters
                function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                    // Call the function to rename removed records
                    RestoreController::renameRemovedRecords('permission', 'name', $value);
                    
                    // Extract index from attribute name
                    $index = str_replace(['*', '.name'], '', $attribute);
                    
                    // Get the ID and name from the request
                    $id = $request->input("{$index}.id"); // Get ID from request
                    $nameModel = Permission::where('name', $value)->first(); // Find model by name
                    $idModel = Permission::where('id', $id)->first(); // Find model by ID
                    
                    // Check if a Permission with the same name exists but with a different ID
                    if ($nameModel && $idModel && $nameModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.rolesToActive.*' => 'exists:roles,id',
            '*.rolesToInactive.*' => 'exists:roles,id',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $permissions = collect($request->all())->map(function ($permission) use ($id) {
            $permission_all=collect($permission)->all();
            $permission = Permission::find($permission_all['id']);
            if(isset($permission_all['rolesToInactive'])){
                foreach ($permission_all['rolesToInactive'] as $key => $roleId) {
                    $role = Role::find($roleId);
                    $role->revokePermissionTo($permission);
                }
            }
            if(isset($permission_all['rolesToActive'])){
                foreach ($permission_all['rolesToActive'] as $key => $roleId) {
                    $role = Role::find($roleId);
                    $role->givePermissionTo($permission);
                }
            }
            $permission->update($permission_all);
            return $permission;
        });
        
        return response()->json([
            'statut' => 1,
            'data' => $permissions,
        ]);
    }

    public function destroy($id)
    {
        $permission = Permission::find($id);
        $permission->delete();
        return response()->json([
            'statut' => 1,
            'data ' => $permission,
        ]);
    }
}