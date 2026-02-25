<html>

<head>
    <style>
        @page {
            margin: 0.25cm 0.25cm;
            border: 2px solid black;
        }

        body {
            font-family: Arial, sans-serif;
            padding: 0;
        }

        .section-left {
            float: left;
            width: 50%;
            display: inline-block;
            vertical-align: top;
        }

        .section-right {
            float: right;
            width: 50%;
            display: inline-block;
            vertical-align: top;
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
            font-size: 40px;
            font-weight: bold;
        }

        .td {
            border-bottom: 1px solid black;
            border-left: 1px solid black;
            padding: 5px;
        }

        .td_last {
            border-left: 1px solid black;
            padding: 5px;
        }
    </style>
</head>

<body style="page-break-after: always;">
    <div style="border: 3px solid black; padding: 20px 20px 20px 20px;  border-radius:10px; height:100%;">
        <div class="section">
            <div class="section-left">
                <div class="section-left" style="width: 40%;">
                    <h3 style="text-align: left;">Livré par :</h3>
                </div>
                <div class="section-right" style="width: 60%;">
                    <h3 style="text-align: left;"> {{ $shippedBy }} </h3>
                </div>
                <div class="section-left" style="width: 40%;">
                    <h3 style="text-align: left;">Compte :</h3>
                </div>
                <div class="section-right" style="width: 60%;">
                    <h3 style="text-align: left;"> {{ $account }} </h3>
                </div>
                <div class="section-left" style="width: 40%;">
                    <h3 style="text-align: left;">Date :</h3>
                </div>
                <div class="section-right" style="width: 60%;">
                    <h3 style="text-align: left;"> {{ date('d/m/Y') }} </h3>
                </div>
            </div>

            <div class="section-right">
                <div class="section-left">
                    <h3 style="text-align: left;">Numéro de Bon :</h3>
                </div>
                <div class="section-right">
                    <h3 style="text-align: right;"> {{ $code }} </h3>
                </div>
                <div class="section-left">
                    <h3 style="text-align: left;">Nombre de colis :</h3>
                </div>
                <div class="section-right">
                    <h3 style="text-align: right;"> {{ $countOrders }} </h3>
                </div>
                <div class="section-left">
                    <h3 style="text-align: left;">Total :</h3>
                </div>
                <div class="section-right">
                    <h3 style="text-align: right;"> {{ $total }} DH</h3>
                </div>
            </div>
        </div>
        <div style="border:1px solid black; border-radius:15px ">
            <table style="width: 100%;" cellspacing="0">
                <tr style="border-bottom: 1px solid black; width: 5%; padding: 5px;font-family: sans;">
                    <td class="td" style="width:5%; border-left:0px;"><b>N°</b></td>
                    <td class="td" style=" width: 30%;"><b>Client</b></td>
                    <td class="td" style=" width: 40%;"><b>Produits</b></td>
                    <td class="td" style=" width: 10%;"><b>Total</b></td>
                    <td class="td" style=" width: 15%;"><b>Code</b></td>
                </tr>
                @foreach ($datas as $index => $data)
                    @php
                        $keys = array_keys($datas);
                        $islast = $index == end($keys) ? 'td_last' : 'td';
                    @endphp
                    <tr>
                        <td class="{{ $islast }}" style=" border-left:0px; text-align: center;">
                            {{ $index + 1 }} </td>
                        <td class="{{ $islast }}"> {{ $data['customer'] }}<br>
                            @foreach ($data['phones'] as $phone)
                                {{ $phone }}</br>
                            @endforeach
                            <br>{{ $data['city'] }}

                        </td>
                        <td class="{{ $islast }}">
                            @foreach ($data['products'] as $product)
                                {{ $product }} <br>
                            @endforeach
                            @if ($data['comment'])
                                <b>NB: {{ $data['comment'] }}</b>
                            @endif
                        </td>
                        <td class="{{ $islast }}" style="text-align: center;"> {{ $data['total'] }} </td>
                        <td class="{{ $islast }}" style="text-align: center;"> {{ $data['code'] }} </td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
</body>

</html>
