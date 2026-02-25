<html>
    <head>
    	<style>
    	@page {
                margin: 0.5cm 0.5cm;
                border: 2px solid black;
            }
        body {
            font-family: Arial, sans-serif;
            padding: 0;
        }
        .section-left, .section-right {
            width: 50%;
            display: inline-block;
            vertical-align: top;
        }
        .section-left {
            float: left;
        }
        .section-right {
            float: right;
        }
        .section {
            margin-bottom: 20px; 
            float: left; 
            width: 100%; 
        }
        .section h3 {
            font-family: sans;
            font-weight: bold;
            margin-top: 0.2em;
            margin-bottom: 0.2em;
            font-size: 15px;
        }
        .section h2 {
            margin: 0px 0px 0px 0;
            font-size: 30px;
            font-weight: bold;
        }
        .td{
            border-bottom: 1px solid black;
            border-left: 1px solid black;
            padding: 5px;
        }
        .td_last{
            border-left: 1px solid black;
            padding: 5px;
        }
    </style>

    <body style="page-break-after: always;">
        <div style="border: 3px solid black; padding: 20px 20px 20px 20px;  border-radius:10px; height:100%;">
            <div class="section">
                <div class="section-left" style="border-radius:15px; border:1px solid black;width:47%; padding:1%; ">
                    <div class="section-left">
                        <h3 style="text-align: left;">Fournisseur :</h3> 
                    </div>
                    <div class="section-right">
                        <h3 style="text-align: left;"> {{$orderFor}} </h3>
                    </div>
                    <div class="section-left">
                        <h3 style="text-align: left;">Compte :</h3> 
                    </div>
                    <div class="section-right">
                        <h3 style="text-align: left;"> {{$account}} </h3>
                    </div>
                    <div class="section-left">
                        <h3 style="text-align: left;">Date :</h3> 
                    </div>
                    <div class="section-right">
                        <h3 style="text-align: left;"> {{date("d/m/Y")}} </h3>
                    </div>
                </div>

                <div class="section-right" style="border-radius:15px; border:1px solid black;width:48%; padding:1%; ">
                    <div class="section-left">
                        <h3 style="text-align: left;">Numéro de Bon :</h3> 
                    </div>
                    <div class="section-right">
                        <h3 style="text-align: right;"> {{$code}} </h3>
                    </div>
                    <div class="section-left">
                        <h3 style="text-align: left;">Nombre de colis :</h3> 
                    </div>
                    <div class="section-right">
                        <h3 style="text-align: right;"> {{$countOrders}} </h3>
                    </div>
                    <div class="section-left">
                        <h3 style="text-align: left;">Total :</h3> 
                    </div>
                    <div class="section-right">
                        <h3 style="text-align: right;"> {{$total}} DH</h3>
                    </div>
                </div>
            </div>
            <div style="border:1px solid black; border-radius:15px ">
            <table style="width: 100%;" cellspacing="0">
                <tr style="border-bottom: 1px solid black; width: 5%; padding: 5px;font-family: sans;">
                    <td class="td" style="width:1%; border-left:0px;"><b>N°</b></td>
                    <td class="td" style=" width: 15%;"><b>Image</b></td>
                    <td class="td" style=" width: 35%;"><b>Produit</b></td>
                    <td class="td" style=" width: 34%;"><b>quantité</b></td>
                    <td class="td" style=" width: 15%;"><b>Prix</b></td>
                </tr>
                @foreach($datas as $index => $data)
                    @php
                        $keys = array_keys($datas);
                        $islast = $index == end($keys) ? "td_last" : "td";
                    @endphp
                    <tr >
                        <td class="{{ $islast }}" style=" border-left:0px;" rowspan="{{count($data['pvas'])+1}}"> {{$index+1}} </td>
                        <td class="{{ $islast }}" rowspan="{{count($data['pvas'])+1}}">
                            @if(isset($data['image']))
                             <img src="{{ asset('')}}/{{$data['image']}}" height="100"> 
                            @endif
                        </td>
                        <td class="{{ $islast }}" rowspan="{{count($data['pvas'])+1}}"> {{$data['product']}} </td>
                    </tr>
                    @foreach($data['pvas'] as $pva)
                        <tr>
                        <td class="{{ $islast }}" style="border-top:1px solid black;">
                                    {{$pva['quantity']}} * {{$pva['variation']}}</br>
                        </td>
                        <td class="{{ $islast }}" style="border-top:1px solid black;">{{$pva['price']}}</td>
                        </tr>
                    @endforeach    
                @endforeach
            </table>
            </div>
        </div>
    </body>
</html>

