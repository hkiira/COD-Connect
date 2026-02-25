<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestoreController extends Controller
{
    public function restore($entity, $id)
    {
        // Determine the model class based on the entity name
        $modelClass = 'App\\Models\\' . ucfirst($entity);

        // Check if the model class exists
        if (!class_exists($modelClass)) {
            abort(404, 'Entity not found');
        }

        // Restore the soft-deleted entity
        $modelClass::withTrashed()->findOrFail($id)->restore();
        return response()->json([
            'statut' => 1,
            'data ' => "Entity restored successfully",
        ]);
    }
    public static function renameRemovedRecords($entity, $column,$value)
    {
        $modelClass = 'App\\Models\\' . ucfirst($entity);
        $duplicateNames = $modelClass::onlyTrashed()->select($column)
            ->groupBy($column)
            ->where($column,$value)
            ->pluck($column);
        foreach ($duplicateNames as $name) {
            // Récupérer tous les pays avec le même nom
            $objects = $modelClass::onlyTrashed()->where($column, $name)->get();

            // Ajouter "(copy)" au nom du nouvel enregistrement
            $newName = $name . ' (copy)';

            // Vérifier si le nom "(copy)" est déjà utilisé
            if($modelClass::onlyTrashed()->where($column, $newName)->first()) {
                $newName = $name . ' (copy)';
            }
            foreach ($objects as $key => $object) {
                $object->{$column} = $newName;
                $object->save();
                $newName = $newName . ' (copy)';
            }
        }
    }
}
