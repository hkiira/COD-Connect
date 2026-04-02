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
    // Query-string payloads can turn nested objects into strings.
    // Normalize to arrays before accessing offsets.
    if (!is_array($filters)) {
      $filters = [];
    }

    $columnFilters = (isset($filters['filters']) && is_array($filters['filters'])) ? $filters['filters'] : [];
    $pagination = (isset($filters['pagination']) && is_array($filters['pagination'])) ? $filters['pagination'] : [];
    $sort = (isset($filters['sort']) && is_array($filters['sort']))
      ? $filters['sort']
      : ((isset($filters['multiSort']) && is_array($filters['multiSort'])) ? $filters['multiSort'] : []);
    $firstSort = (isset($sort[0]) && is_array($sort[0])) ? $sort[0] : [];

    // Set the default value for the 'search' filter
    $filters['search'] = isset($filters['search']) ? $filters['search'] : null;
    // Loop through each column and set default values for their filters
    foreach ($columns as $column) {
      $columnFilters[$column] = isset($columnFilters[$column]) ? $columnFilters[$column] : null;
    }
    $filters['filters'] = $columnFilters;

    // Set default values for date range filters
    $filters['startDate'] = isset($filters['startDate']) ? $filters['startDate'] : null;
    $filters['endDate'] = isset($filters['endDate']) ? $filters['endDate'] : null;

    // Set default values for pagination
    $pagination['current_page'] = isset($pagination['current_page']) ? $pagination['current_page'] : 0;
    $pagination['per_page'] = isset($pagination['per_page']) ? $pagination['per_page'] : 10;
    $firstSort['column'] = isset($firstSort['column']) ? $firstSort['column'] : "created_at";
    $firstSort['order'] = isset($firstSort['order']) ? $firstSort['order'] : "DESC";

    $filters['pagination'] = $pagination;
    $filters['sort'] = [$firstSort];

    // Return the filtered array of columns
    return $filters;
  }

  public static function getPagination($data, $per_page, $current_page)
  {
    $total_rows = $data->count();
    $per_page = ($per_page == null or $per_page == 0) ? 10 : (int) $per_page;
    $current_page = ($current_page == null) ? 1 : (int) $current_page + 1;
    $data = $data->forpage($current_page, $per_page)->values();
    return [
      'statut' => 1,
      'data'   => $data,
      'meta'   => [
        'total'        => $total_rows,
        'per_page'     => $per_page,
        'current_page' => $current_page,
      ],
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
