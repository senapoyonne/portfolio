<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Auth;
use App\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Charge;
use App\Item;
use App\Saleshistory;
use Mail;
use App\Builder;

class PaymentsController extends Controller
{

    protected static function send(){

        $auths=Auth::user();
        $carts = Cart::content();
        $totalpay=Cart::total();
        $totalpay = str_replace(',', '',$totalpay);
        $totalpay = intval($totalpay)+1000;
        $totalpay = number_format($totalpay);
        $subtotalpay=Cart::subtotal();
        $tax=Cart::tax();


        $shippingzipcode=$auths->zipcode;
        $shippingaddress1=$auths->address1;
        $shippingaddress2=$auths->address2;
        $shippingname=$auths->name;


    	$data = [
            'carts' => $carts,
            'totalpay'=>$totalpay,
            'shippingzipcode' =>$auths->zipcode,
            'shippingaddress1'=>$auths->address1,
            'shippingaddress2'=>$auths->address2,
            'shippingname'    =>$auths->name,
                ];

        foreach ($carts as $cart) {
                    $item= Item::find($cart->id);
                    $builder= Builder::where('name',"$item->product_builder")->get();
                    
                    $data = [
                        'carts' => $carts,
                        'cart' => $cart,
                        'item'  => $item,
                        'totalpay'=>$totalpay,
                        'shippingzipcode' =>$auths->zipcode,
                        'shippingaddress1'=>$auths->address1,
                        'shippingaddress2'=>$auths->address2,
                        'shippingname'    =>$auths->name,
                        'builder'         =>$builder,
                            ];
                    Mail::send('emails.receiptbuilder', $data, function($message)use ($builder) { 
                        // dd($builder);                     
                        $message->to($builder[0]->email)
                        ->subject('????????????????????????????????????');
                    });        
                            //???????????????????????????
                    Mail::send('emails.receiptcustomer', $data, function($message)use ($auths,$builder){
                        $message->to("$auths->email")
                        ->subject('??????????????????????????????');
                    });
            
                            //????????????????????????
                    Mail::send('emails.receiptadmin', $data, function($message){
                        $message->to('nightmare.sena122@gmail.com')
                        ->subject('??????????????????????????????');
                    });
              }
              

    }///////SEND


    protected static function getDefaultcard($user)
    {
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));

        $default_card = null;
        
        if (!is_null($user->stripe_id)) {
            $customer = \Stripe\Customer::retrieve($user->stripe_id);
            // $card = \Stripe\Customer::retrieveSource($customer->id,$customer->default_source);
            // dd($card );
            if (isset($customer['default_source']) && $customer['default_source']) {
                $card = \Stripe\Customer::retrieveSource($customer->id,$customer->default_source);
                $default_card = [
                    'number' => str_repeat('*', 8) . $card->last4,
                    'brand' => $card->brand,
                    'exp_month' => $card->exp_month,
                    'exp_year' => $card->exp_year,
                    'name' => $card->name,
                    'id' => $card->id,
                ];
            }
        }
        return $default_card;
    }///GetDefaultcard


    public function paymentpage()
    {
        
        Stripe::setApiKey(env('STRIPE_SECRET'));
        $user=Auth::user();
        $defaultCard = self::getDefaultcard($user);
        // $card4=$defaultCard['number'];
        // dd($defaultCard);
        return view('shoppingcart.cardpayment', compact('user','defaultCard'));
    }

    //////PAYMENT
    public function payment(Request $request)
    {
    
        Stripe::setApiKey(env('STRIPE_SECRET'));
        $user=Auth::user();
        $carts = Cart::content();
        $totalpay=Cart::total();
        $totalpay = str_replace(',', '',$totalpay);
        $totalpay = intval($totalpay)+1000;
        $subtotalpay=Cart::subtotal();        
        $fullhistories="";

        if($request->usedefaultcard == "on"){//??????????????????????????????
            try {
                
            $charge = \Stripe\Charge::create([
                'amount' => $totalpay,
                'currency' => 'jpy',
                'customer' => $user->stripe_id,
            ]);
        } catch (Error $e) {
            return $e->getMessage();
        }

        ////////?????????????????????
        $hfulltotalpay=$totalpay;
        foreach ($carts as $cart) {
                        //?????????????????????
                        $item= Item::find($cart->id);
                        $hitemname=$item->product_name;
                        $hitemqty=$cart->qty;
                        $fullhistories=$fullhistories."<br>".$hitemname."(".$hitemqty."???)";
        }
        foreach ($carts as $cart) {
            $item= Item::find($cart->id);
            $item->product_stock= $item->product_stock-$cart->qty;
            $item->save(); 
            //?????????????????????New????????????
            $history= new \App\Saleshistory;
            $history->product_name=$item->product_name;     //????????????
            $history->product_price=$item->product_price;   //???????????? 
            $history->product_qty=$cart->qty;               //????????????
            $subtotal=$item->product_price*$cart->qty;//????????????
            $history->subtotalpay=$subtotal;
            $history->customer_name=$user->name;                //?????????
            $history->customer_email=$user->email;                //???????????????
            $history->customer_addredss="$user->zipcode $user->address1 $user->address2";//??????????????????
            $history->fullhistory=$fullhistories;
            $history->fullhistory_totalpay=$totalpay;
            $history->save(); 
        }
        ////////?????????????????????
        /////////?????????????????????????????????
        // dd($fullhistories,$hfulltotalpay,$hitemname,$hitemprice,$hitemqty,$hitemsubtotal,$huser,$hmail,$haddress);
        self::send();
        Cart::destroy();
        return view('shoppingcart.complete');
        }//??????????????????????????????



        try {
            if (!empty($request->stripeToken)) {
                $stripeToken = $request->stripeToken;
            }
            if (!empty($request->email)) {
                $email = $request->email;
            }
            if (!empty($request->name)) {
                $name = $request->name;
            }
            
            $customer = \Stripe\Customer::create([
                'source' => $stripeToken,
                'email'  => $user->email,
                'name'   => $user->name,
            ]);
        
            $charge = \Stripe\Charge::create([
                'amount' => $totalpay,
                'currency' => 'jpy',
                'customer' => $customer->id,
            ]);

        } catch (Error $e) {
            return $e->getMessage();
            
        }

        if($request->remember == "on"){//????????????????????????????????????
            $user->stripe_id=$customer->id;
            $user->update();
        }

        foreach ($carts as $cart) {
            $item= Item::find($cart->id);
            $item->product_stock= $item->product_stock-$cart->qty;
            $item->save();            
        }

        ////////?????????????????????
        $hfulltotalpay=$totalpay;
        foreach ($carts as $cart) {
                        //?????????????????????
                        $item= Item::find($cart->id);
                        $hitemname=$item->product_name;
                        $hitemqty=$cart->qty;
                        $fullhistories=$fullhistories."<br>".$hitemname."(".$hitemqty."???)";
        }
        foreach ($carts as $cart) {
            $item= Item::find($cart->id);
            $item->product_stock= $item->product_stock-$cart->qty;
            $item->save(); 
            //?????????????????????New????????????
            $history= new \App\Saleshistory;
            $history->product_name=$item->product_name;     //????????????
            $history->product_price=$item->product_price;   //???????????? 
            $history->product_qty=$cart->qty;               //????????????
            $subtotal=$item->product_price*$cart->qty;//????????????
            $history->subtotalpay=$subtotal;
            $history->customer_name=$user->name;                //?????????
            $history->customer_email=$user->email;                //???????????????
            $history->customer_addredss="$user->zipcode $user->address1 $user->address2";//??????????????????
            $history->fullhistory=$fullhistories;
            $history->fullhistory_totalpay=$totalpay;
            $history->save(); 
        }
        ////////?????????????????????
        /////////?????????????????????????????????

        self::send();
        Cart::destroy();
        return view('shoppingcart.complete');
    
    }



    public function complete()
    {
        return view('complete');
    }




}