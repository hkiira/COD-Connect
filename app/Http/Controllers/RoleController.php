<?php
    
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        // Affiche la liste des permissions
        $request = collect($request->query())->toArray();
        $filters = HelperFunctions::filterColumns($request,[]);
        if(isset($filters['permissions']) && (count($filters['permissions'])>0)){
            if($filters['search']){
                $roles = Role::with('permissions')->whereHas('permissions', function ($query) use ($filters) {
                    $query->whereIn('id', $filters['permissions']);
                })
                ->orWhere('name','like',"%{$filters['search']}%")
                ->orderBy($filters['sort'][0]['column'], $filters['sort'][0]['order'])
                ->get();
            }else{
                $roles = Role::with('permissions')->whereHas('permissions', function ($query) use ($filters) {
                    $query->whereIn('id', $filters['permissions']);
                })
                ->orderBy($filters['sort'][0]['column'], $filters['sort'][0]['order'])
                ->get();
            }
        }else{
            $roles = Role::with('permissions')->where('name','like',"%{$filters['search']}%")->get();
        }
        
        $dataPagination =  HelperFunctions::getPagination(collect($roles), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        return $dataPagination;
    }

    public function create(Request $request)
    {
        $roles= [];

        if (isset($request['permissions']['inactive'])){ 
            $model = 'App\\Models\\Permission';
            //permet de récupérer la liste des regions inactive filtrés
            $roles['permissions']['inactive'] = FilterController::searchs(new Request($request['permissions']['inactive']),$model,['id','name'], true,[['model'=>'App\\Models\\Role','title'=>'roles','search'=>false]]);
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
                    RestoreController::renameRemovedRecords('role', 'name', $value);
                    $titleModel = Role::where('name', $value)->first();
                    if ($titleModel) {
                        $fail("exist"); 
                    }
                },
            ],
            '*.permissions.*' => 'exists:permissions,id',
            '*.role_type_id' => 'required|exists:role_types,id',
        ]);
        
        
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $roles = collect($request->all())->map(function ($role) {
            $data=collect($role);
            $role_only=collect($role)->only('name','statut','role_type_id','permissions');
            $role = Role::create($role_only->all());
            if(isset($data['permissions'])){
                foreach ($data['permissions'] as $key => $permissionId) {
                    $permission = Permission::find($permissionId);
                    if($permission){
                        $role->givePermissionTo($permission);
                    }
                }
            }
            return $role;
        });
    
        return response()->json([
            'statut' => 1,
            'data' => $roles,
        ]);
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data=[];
        $role = Role::find($id);
        if(!$role)
            return response()->json([
                'statut'=>0,
                'data'=> 'not exist'
            ]);
        if (isset($request['roleInfo'])){
            $info = collect($role->only('name', 'statut'))->toArray();
            $data["roleInfo"]['data']=$info;
        }

        if (isset($request['permissions']['active'])){
            $model = 'App\\Models\\Permission';
            $request['permissions']['active']['whereIn'][0]=['table'=>'roles','column'=>'role_id','value'=>$role->id];
            $data['permissions']['active'] = FilterController::searchs(new Request($request['permissions']['active']),$model,['id','name'], true,[['model'=>'App\\Models\\Role','title'=>'roles','search'=>false]]);
        }
        if (isset($request['permissions']['inactive'])){
            $model = 'App\\Models\\Permission';
            $request['permissions']['inactive']['whereNotIn'][0]=['table'=>'roles','column'=>'role_id','value'=>$role->id];
            $data['permissions']['inactive'] = FilterController::searchs(new Request($request['permissions']['inactive']),$model,['id','name'], true,[['model'=>'App\\Models\\Role','title'=>'roles','search'=>false]]);
        }
        return response()->json([
            'statut' => 1,
            'data' =>$data
        ]);
    }

    public function update(Request $request, $id,$local=false)
    {
        $validator = Validator::make($request->all(), [
            '*.id' => 'required|exists:roles,id|max:255',
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
                    $nameModel = Role::where('name', $value)->first(); // Find model by name
                    $idModel = Role::where('id', $id)->first(); // Find model by ID
                    
                    // Check if a Permission with the same name exists but with a different ID
                    if ($nameModel && $idModel && $nameModel->id !== $idModel->id) {
                        $fail("exist"); // Validation fails with custom message
                    }
                },
            ],
            '*.permissionsToActive.*' => 'exists:permissions,id',
            '*.permissionsToInactive.*' => 'exists:permissions,id',
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);       
        };
        $roles = collect($request->all())->map(function ($role) use ($id) {
            $role_all=collect($role)->all();
            $role = role::find($role_all['id']);
            if(isset($role_all['permissionsToActive'])){
                foreach ($role_all['permissionsToActive'] as $key => $permissionId) {
                    $permission = Permission::find($permissionId);
                    $role->givePermissionTo($permission);
                }
            }
            if(isset($role_all['permissionsToInactive'])){
                foreach ($role_all['permissionsToInactive'] as $key => $permissionId) {
                    $permission = Permission::find($permissionId);
                    $role->revokePermissionTo($permission);
                }
            }
            $role->update($role_all);
            return $role;
            
        });

        return response()->json([
            'statut' => 1,
            'data' => $roles,
        ]);
    }

    
    public function destroy($id)
    {
        $role = Role::find($id);
        $role->delete();
        return response()->json([
            'statut' => 1,
            'data ' => $role,
        ]);
    }
}