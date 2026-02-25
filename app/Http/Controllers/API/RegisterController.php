<?php
   
namespace App\Http\Controllers\API;
   
use Illuminate\Http\Request;
use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\User;
use App\Models\account;
use App\Models\city;
use App\Models\account_user;
use App\Models\account_city;
use App\Models\account_code;
use Validator;
use Auth;

class RegisterController extends BaseController
{
    /* les fonctions necessaire dans ce controlleur sont:
        la creation d'un compte
        la creation d'un utilisateur
        la connexion d'un utilisateur
        la deconnexion j pense
        
    */

    // they register an new user that have already an account id
    public function register_new_user(Request $request,int $is_account=0,$account=null )
    {
        
        $input = $request->all();
        $input['password'] = bcrypt($input['password']);

        if($is_account==1){
            $user = User::create($input);
            $account_user = $account->users()->attach($user);
            return $user->id;   
        }else{
            $validator = Validator::make($request->all(), [
                'name' => 'required',
                'email' => 'required|email|unique:users',
                'firstname' => 'required',
                'lastname' => 'required',
                'cin' => 'required',
                'birthday' => 'required',
                'password' => 'required',
                'c_password' => 'required|same:password',
            ]);
            if($validator->fails()){
                return $this->sendError('Validation Error.', $validator->errors());       
            }
            $user = User::create($input);
            $account = User::find(Auth::user()->id)->accounts->first();
            $account_user = $account->users()->attach($user);   

            return response()->json([
                "status"=>1,
                "data"=>"votre compte utilisateur a été bien crée"
            ], 200); 
        }
    }
   
    // they register an account with default user admin
    public function register_new_account(Request $request)
    {
        // test commit abder
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'statut' => '',
            'status' => '',
            'firstname' => 'required',
            'lastname' => 'required',
            'cin' => 'required',
            'birthday' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required',
            'c_password' => 'required|same:password',
        ]);
        if($validator->fails()){
            return $this->sendError('Validation Error.', $validator->errors());       
        }
        //creation du compte
        $account = account::create($request->only('name' ,'statut'));
        //creation de l'utilisateur
        $user_request=new Request($request->only('name', 'email', 'password', 'c_password', 'firstname', 'lastname', 'cin', 'birthday', 'status','is_account'));
        $this->register_new_user($user_request,1,$account);
        //creation des account_city
        $cities=city::get();
        foreach($cities as $city){
            $account_city = $account->cities()->attach($city);
        }

        //creation des depots
        $account->depots()->createMany([
            [
                'code'=>'STK1',
                'name'=>'STOCK PRINCIPALE',
                'maximum'=>200000,
                'statut'=>1
            ],
            [
                'code'=>'STK2',
                'name'=>'STOCK ENDOMAGEE',
                'maximum'=>2000,
                'statut'=>2
            ],
        ]);

        $account->account_codes()->createMany([
            [
                'name'=>'Commandes',
                'controleur'=>'Orders',
                'prefixe'=>'CMD',
                'compteur'=>0,
                'statut'=>1
            ],
            [
                'name'=>'Bons de rammassage',
                'controleur'=>'Pickups',
                'prefixe'=>'PK',
                'compteur'=>0,
                'statut'=>1
            ],
            [
                'name'=>'Factures',
                'controleur'=>'Invoices',
                'prefixe'=>'INV',
                'compteur'=>0,
                'statut'=>1
            ],
            [
                'name'=>'Commande Fournisseur',
                'controleur'=>'SupplierOrders',
                'prefixe'=>'SCMD',
                'compteur'=>0,
                'statut'=>1
            ],
            [
                'name'=>'Bon de réception Fournisseur',
                'controleur'=>'SupplierReceipts',
                'prefixe'=>'SREC',
                'compteur'=>0,
                'statut'=>1
            ],
            [
                'name'=>'Factures Fournisseur',
                'controleur'=>'SupplierBillings',
                'prefixe'=>'SBIL',
                'compteur'=>0,
                'statut'=>1
            ],
            [
                'name'=>'Paeiments Commission',
                'controleur'=>'PaymentCommissions',
                'prefixe'=>'COM',
                'compteur'=>0,
                'statut'=>1
            ]
            
        ]);
        
        return response()->json([
            "status"=>1,
            "data"=>"votre compte a été bien crée"
        ], 200); 
    }

    public function login(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required'
        ]);
        if($validator->fails()){
            return response()->json([
                'statut' => 0,
                'Validation Error.' => $request->all()
            ]);       
        }
        if (!auth()->attempt($request->all())) {
            return response()->json([
                'statut' => 0,
                'error_message' => 'Incorrect Details,Please try again'],200);
                
        }
        $token = auth()->user()->createToken('API Token')->accessToken;

        return response()->json([
            'statut' => 1,
            'user' => auth()->user(),
            'token' => $token
        ]);

    }
}