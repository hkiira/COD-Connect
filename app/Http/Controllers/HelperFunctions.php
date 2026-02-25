<?php

namespace App\Http\Controllers;

class HelperFunctions extends Controller
{


  public static function generateCases($attributes)
  {
    $cases = [[]];
    foreach ($attributes as $attributeValues) {
      $newCases = [];
      foreach ($cases as $case) {
        foreach ($attributeValues as $value) {
          $newCases[] = array_merge($case, [$value]);
        }
      }
      $cases = $newCases;
    }
    return $cases;
  }

  public static function filterExisting($filterData, $dataIDS)
  {
    // Vérifie si des données de filtre sont fournies
    $dataExisting = $filterData != null ? (count($filterData) == 0 ? true : false) : true;

    // Vérifie si les données existent et si l'intersection entre les données de filtre et les données fournies est égale aux données de filtre
    if ($dataExisting == false and array_intersect($filterData, $dataIDS->toArray()) == $filterData) {
      // Si les conditions sont remplies, cela signifie que les données existent après le filtrage
      return true;
    }

    // Si les conditions ci-dessus ne sont pas remplies, retourne simplement l'indicateur de données existantes
    return $dataExisting;
  }


  /**
   * Filter the given array of columns based on the provided filters.
   *
   * @param array $filters The array of filters to apply.
   * @param array $columns The array of columns to filter.
   *
   * @return array The filtered array of columns.
   */
  public static function filterColumns($filters, $columns)
  {
    // Set the default value for the 'search' filter
    $filters['search'] = isset($filters['search']) ? $filters['search'] : null;
    // Loop through each column and set default values for their filters
    foreach ($columns as $column) {
      $filters['filters'][$column] = isset($filters['filters'][$column]) ? $filters['filters'][$column] : null;
    }

    // Set default values for date range filters
    $filters['startDate'] = isset($filters['startDate']) ? $filters['startDate'] : null;
    $filters['endDate'] = isset($filters['endDate']) ? $filters['endDate'] : null;

    // Set default values for pagination
    $filters['pagination']['current_page'] = isset($filters['pagination']['current_page']) ? $filters['pagination']['current_page'] : 0;
    $filters['pagination']['per_page'] = isset($filters['pagination']['per_page']) ? $filters['pagination']['per_page'] : 10;
    $filters['sort'][0]['column'] = isset($filters['sort'][0]['column']) ? $filters['sort'][0]['column'] : "created_at";
    $filters['sort'][0]['order'] = isset($filters['sort'][0]['order']) ? $filters['sort'][0]['order'] : "DESC";

    // Return the filtered array of columns
    return $filters;
  }

  public static function getPagination($data, $per_page, $current_page)
  {
    $total_rows = $data->count();
    $per_page = ($per_page == null or $per_page == 0) ? 10 : $per_page;
    $pages = ceil($total_rows / $per_page) != 0 ? range(0, ceil($total_rows / $per_page) - 1) : [0];
   // $current_page = ($current_page == null or $current_page > end($pages))  ? 1 : $current_page + 1;
    $current_page = ($current_page == null)  ? 1 : $current_page + 1;
    $data = $data->forpage($current_page, $per_page)->values();
    return [
      'statut' => 1,
      'data' => $data,
      'per_page' => $per_page,
      'current_page' => $current_page,
      'total' => $total_rows
    ];
  }

  public static function getInactiveData($all_data, $active_data)
  {
    $allData = collect($all_data);
    $activeData = collect($active_data);
    $filteredData = $allData->reject(function ($data) use ($activeData) {
      return $activeData->contains($data);
    });
    return $filteredData;
  }

  public static function uniqueStoreBelongTo($entity, $title, $value, $foreingkey, $foreingkeyValue, $fail)
  {
    RestoreController::renameRemovedRecords($entity, $title, $value);
    $modelClass = 'App\\Models\\' . ucfirst($entity);
    $uniqueValue = $modelClass::where([$title => $value, $foreingkey => $foreingkeyValue])->first();
    if ($uniqueValue) {
      $fail("exist");
    }
  }

  public static function uniqueUpdateBelongTo($entity, $title, $value, $foreingkey, $foreingkeyValue, $id, $fail)
  {
    RestoreController::renameRemovedRecords($entity, $title, $value);
    $modelClass = 'App\\Models\\' . ucfirst($entity);
    $uniqueValue = $modelClass::where([$title => $value, $foreingkey => $foreingkeyValue])->where('id', '!=', $id)->first();
    if ($uniqueValue) {
      $fail("exist");
    }
  }
}
