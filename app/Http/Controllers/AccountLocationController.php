<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Account;
use App\Models\Country;
use App\Models\Region;
use App\Models\City;
use App\Models\Sector;
class AccountLocationController extends Controller
{
    public static function attachLocation($locationType, $locationId,$accountId=null)
    {
        
        $accountId=($accountId)?$accountId:getAccountUser()->account_id;
        $account = Account::find($accountId);
        $locationModel = self::getLocationModel($locationType);
        $location = $locationModel::find($locationId);
        $account->{$locationType}()->syncWithoutDetaching([
            $location->id => [
                'statut' => 1,
                'created_at' => now(),
                'updated_at' => now()
                ]
        ]);

        return response()->json(['message' => ucfirst($locationType) . ' attached to account successfully']);
    }

    public static function detachLocation( $locationType, $locationId, $accountId=null)
    {
        $accountId=($accountId)?$accountId:getAccountUser()->account_id;
        $account = Account::find($accountId);
        $locationModel = self::getLocationModel($locationType);
        $location = $locationModel::find($locationId);
        $account->{$locationType}()->detach($location);

        return response()->json(['message' => ucfirst($locationType) . ' detached from account successfully']);
    }

    private static function getLocationModel($locationType)
    {
        switch ($locationType) {
            case 'countries':
                return Country::class;
            case 'regions':
                return Region::class;
            case 'cities':
                return City::class;
            case 'sectors':
                return Sector::class;
            default:
                abort(404, 'Invalid location type');
        }
    }
}
