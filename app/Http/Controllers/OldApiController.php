<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Attribute;
use App\Models\BrandSource;
use App\Models\Carrier;
use App\Models\Comment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderComment;
use App\Models\OrderStatus;
use App\Models\Pickup;
use App\Models\ProductVariationAttribute;
use App\Models\Product;
use App\Models\Shipment;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Auth;
use App\Models\User; // Le modèle User

class OldApiController extends Controller
{
    public function old(Request $requests,$entity, $id = null)
    {
        
        if ($entity == 'countOrders') {
            return $this->countOrders($id);
        }elseif ($entity == 'sync') {
            return $this->sync();
        }elseif ($entity == 'double') {
            return $this->double();
        }elseif ($entity == 'apporders') {
            return $this->appOrders($requests);
        }elseif ($entity == 'pickups') {
            return $this->pickups($id);
        }elseif ($entity == 'customers') {
            return $this->customers();
        }elseif ($entity == 'product') {
            return $this->products();
        }elseif ($entity == 'syncpickup') {
            return $this->syncPickup($id);
        } else {
            return "productsuppliers";
        }

        return response()->json([
            'statut' => 1,
            'data ' => "Entity restored successfully",
        ]);
    }
    public function countOrders($statut,$searchValue=null){
        if($statut==0){
            $orders=Order::where('account_id',getAccountUser()->account_id)->get();
        }elseif($statut==7){
            $orders=Order::where('account_id',getAccountUser()->account_id)->whereIn('order_status_id',[7,8])->get();
        }elseif($statut==8){
            $orders=Order::where('account_id',getAccountUser()->account_id)->whereIn('order_status_id',[8,9,11])->get();
        }elseif($statut==4){
            $orders=Order::where('account_id',getAccountUser()->account_id)->whereIn('order_status_id',[4,5])->get();
        }else{
            $orders=Order::where('account_id',getAccountUser()->account_id)->where('order_status_id',$statut)->get();
        }
        return response()->json([
            'statut' => 1,
            'data' => $orders->count(),
        ]);
    }
    }
