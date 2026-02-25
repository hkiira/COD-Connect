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
            width: 45%;
            display: inline-block;
            vertical-align: top;
            border:1px solid black;
            border-radius:15px;
            padding:10px;
        }
        .section-left {
            float: left;
        }
        .section-right {
            float: right;
        }
        .section {
            margin-bottom: 50px; 
            float: left; 
            width: 100%; 
        }
        .section p{
            text-align:right;
            line-height:0.1;
            font-size:14px;
        }
        .section h3 {
            font-family: sans;
            font-weight: bold;
            margin-top: 0.2em;
            margin-bottom: 0.2em;
            font-size: 20px;
            text-align:center;
        }
        .section h2 {
            margin: 0px 0px 0px 0;
            font-size: 30px;
            text-align:right;
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
                <h2>Bon de Déplacement : {{ $code }}</h2>
                <p>Par: {{$user}}</p>
            </div>
            <div class="section">
                <div class="section-left" >
                    <h3>du :</h3> 
                    <h3> {{$fromWarehouse}} </h3>
                </div>

                <div class="section-right">
                        <h3>au :</h3> 
                        <h3> {{$toWarehouse}} </h3>
                </div>
            </div>
            <div style="border:1px solid black; border-radius:15px ">
                <table style="width: 100%;" cellspacing="0">
                    <tr style="border-bottom: 1px solid black; width: 5%; padding: 5px;font-family: sans;">
                        <td class="td" style="width:10px; border-left:0px;"><b>N°</b></td>
                        <td class="td" style=" width: 10%;"><b>Nom</b></td>
                        <td class="td" style=" width: 10%;"><b>quantité</b></td>
                        <td class="td" style=" width: 10%;"><b>prix</b></td>
                    </tr>
                    @foreach($products as $index => $product)
                        @php
                            $keys = array_keys($products);
                            $islast = $index == end($keys) ? "td_last" : "td";
                        @endphp
                        <tr >
                            <td class="{{ $islast }}" style=" border-left:0px;"> {{$index+1}} </td>
                            <td class="{{ $islast }}"> {{$product['title']}} </td>
                            <td class="{{ $islast }}"> {{$product['quantity']}} </td>
                            <td class="{{ $islast }}"> {{$product['price']}} </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>
    </body>
</html>

