<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Phone;
use App\Models\User;
use App\Models\Account;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class PhoneController extends Controller
{

    public function index(Request $request)
    {
        $account = getAccountUser()->account_id;
        $phones = account::find($account)->phones;

        return response()->json([
            'statut' => 1,
            'data' => $phones,
        ]);
    }

    public function create(Request $request)
    {
    }
    public static function store(Request $requests, $local = 0, $model = null)
    {
        // Format phone numbers before validation
        $input = $requests->all();
        foreach ($input as $key => $value) {
            if (is_array($value) && isset($value['title'])) {
                $input[$key]['title'] = formatPhoneNumber($value['title']);
            }
        }
        $requests->replace($input);

        if ($local == 0) {
            $validator = Validator::make($requests->except('_method'), [
                '*.title' => [ // Validate title field
                    'required', // Title is required
                    'max:255', // Title should not exceed 255 characters
                    function ($attribute, $value, $fail) use ($requests) { // Custom validation rule
                        // Call the function to rename removed records
                        RestoreController::renameRemovedRecords('phone', 'title', $value);
                        $account_id = getAccountUser()->account_id;
                        $titleModel = Phone::where(['title' => $value, 'account_id' => $account_id])->first();
                        if ($titleModel) {
                            $fail("exist");
                        }
                    },
                ],
                '*.phoneTypes.*' => 'required|exists:phone_types,id,account_id,' . getAccountUser()->account_id,
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'statut' => 0,
                    'data' => $validator->errors(),
                ]);
            };
        }
        $phones = collect($requests->except('_method'))->map(function ($request) use ($model, $local) {
            $account = getAccountUser()->account_id;
            $phoneData = new Request($request);
            $phone = Phone::create([
                'title' => $phoneData->title,
                'account_id' => $account,
            ]);
            if ($request['phoneTypes']) {
                foreach ($request['phoneTypes'] as $key => $phoneTypeId) {
                    $phone->PhoneTypes()->syncWithoutDetaching([
                        $phoneTypeId => [
                            'account_id' => $phone->account_id,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]
                    ]);
                }
            }
            if ($local == 1)
                $model->phones()->attach($phone->id, ['created_at' => now(), 'updated_at' => now(), 'statut' => 1]);
            return $phone;
            return $phone;
        });

        return response()->json([
            'statut' => 1,
            'phone' => $phones,
        ]);
    }


    public function show($id)
    {
        //
    }

    public function edit($id)
    {
        $account = User::find(Auth::user()->id)->accounts->first();
        $phone = account::where(['account_id' => $account->id, 'id' => $id])
            ->first();

        return response()->json([
            'statut' => 1,
            'data' => $phone
        ]);
    }


    public static function update(Request $request, $id, $local = 0, $model = null, $modelType = "customers")
    {
        // Format phone numbers before validation
        $input = $request->all();
        foreach ($input as $key => $value) {
            if (is_array($value) && isset($value['title'])) {
                $input[$key]['title'] = formatPhoneNumber($value['title']);
            }
        }
        $request->replace($input);

        $account = User::find(Auth::user()->id)->accounts->first();
        if ($local == 0) {
            $validator = Validator::make($request->all(), [
                '*.id' => 'exists:phones,id',
                '*.title' => [ // Validate title field
                    'required', // Title is required
                    'max:255', // Title should not exceed 255 characters
                    function ($attribute, $value, $fail) use ($request) { // Custom validation rule
                        // Call the function to rename removed records
                        RestoreController::renameRemovedRecords('phone', 'title', $value);

                        // Extract index from attribute name
                        $index = str_replace(['*', '.title'], '', $attribute);
                        // Get the ID and title from the request
                        $id = $request->input("{$index}.id"); // Get ID from request
                        $account_id = getAccountUser()->account_id;
                        $titleModel = Phone::where('title', $value)->where('account_id', $account_id)->first();
                        $idModel = Phone::where('id', $id)->where('account_id', $account_id)->first(); // Find model by ID

                        // Check if a country with the same title exists but with a different ID
                        if ($titleModel && $idModel && $titleModel->id !== $idModel->id) {
                            $fail("exist"); // Validation fails with custom message
                        }
                    },
                ],
                'phone_type_id' => 'exists:phone_types,id,account_id,' . $account->id,
            ]);


            if ($validator->fails()) {
                return response()->json([
                    'statut' => 0,
                    'data' => $validator->errors(),
                ]);
            };
            $phones = collect($request->all())->map(function ($phone) {
                $phone_all = collect($phone)->all();
                $phone_updated = Phone::find($phone_all['id']);
                $phone_updated->update($phone_all);
                $phone = Phone::with('PhoneTypes')->find($phone_updated->id);
                return $phone;
            });
            return response()->json([
                'statut' => 1,
                'data' => $phones,
            ]);
        } else {
            $phones = collect($request->all())->map(function ($phone) use ($model, $modelType) {
                $phone_all = collect($phone)->all();
                $phone = Phone::where('title', $phone_all['title'])->first();
                if ($phone) {
                    foreach ($phone_all['phoneTypes'] as $phoneType) {
                        $phone->PhoneTypes()->syncWithoutDetaching([
                            $phoneType => [
                                'account_id' => $phone->account_id,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]
                        ]);
                    }
                    //hna kanqleb 3La les ids li mawasslonich f array bach n7ayadhom
                    $intersections = array_diff($phone->phoneTypes->pluck('id')->toArray(), $phone_all['phoneTypes']);
                    foreach ($intersections as $key => $intersection) {
                        $phone->PhoneTypes()->detach($intersection);
                    }

                    $phone->{$modelType}()->syncWithoutDetaching([
                        $model->id => ['created_at' => now(), 'updated_at' => now(), 'statut' => 1]
                    ]);
                    return (isset($phone_all['principal'])) ? $phone : null;
                } else {
                    $account = getAccountUser()->account_id;
                    $phone = Phone::create([
                        'title' => $phone_all['title'],
                        'account_id' => $account,
                    ]);
                    if ($phone_all['phoneTypes']) {
                        foreach ($phone_all['phoneTypes'] as $key => $phoneTypeId) {
                            $phone->PhoneTypes()->syncWithoutDetaching([
                                $phoneTypeId => [
                                    'account_id' => $phone->account_id,
                                    'created_at' => now(),
                                    'updated_at' => now()
                                ]
                            ]);
                        }
                    }
                    $phone->customers()->syncWithoutDetaching([
                        $model->id => ['created_at' => now(), 'updated_at' => now(), 'statut' => 1]
                    ]);
                    return (isset($phone_all['principal'])) ? $phone : null;
                }
            })->filter();
            return $phones;
        }
    }


    public function destroy($id)
    {
        $Phone = Phone::find($id);
        $Phone->delete();
        return response()->json([
            'statut' => 1,
            'data' => $Phone,
        ]);
    }
}
