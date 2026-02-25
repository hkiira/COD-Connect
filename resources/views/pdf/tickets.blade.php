<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Tickets</title>
    <style>

        body {
            font-family: Arial, sans-serif;
            padding: 0;
        }

        .container {
            width: 98%;
            padding: 2%;
            margin-bottom: 20px;
            border-radius: 10px;
        }

        .container.page-break {
            page-break-after: always;
        }

        .section-left,
        .section-right {
            width: 40%;
            display: inline-block;
            vertical-align: top;
        }

        .section-left {
            float: left;
            width: 52%;
            margin-right: 2%;
        }

        .section-right {
            float: right;
            width: 40%;
        }

        .section h3 {
            margin: 0;
            font-size: 16px;
        }

        .section h2 {
            margin: 0px 0px 0px 0;
            font-size: 18px;
            font-weight: bold;
        }

        .section p {
            margin: 0;
            font-size: 12px;
        }

        .qr-code {
            color: black;
            width: 150px;
            margin-top: 0px;
            margin-left: 28px;
            border-radius: 15px;
        }
    </style>
</head>

<body>
    @foreach ($datas as $index => $data)
        <div class="container {{ $index < count($datas) - 1 ? 'page-break' : '' }}">
            <div class="section">
                <div class="section-left">
                    <h3>Destinataire:</h3>
                    <p>{{ $data['customer'] }}-{{ $data['code'] }}<br>{{ $data['address'] }}</p>
                    @foreach ($data['phones'] as $phone)
                        <h2>{{ $phone }}</h2>
                    @endforeach
                </div>
                <div class="section-right">
                    <img src="{{ asset('qrcodes/') }}/{{ $data['qr_code'] }}" class="qr-code">
                </div>
            </div>
            <div class="section">
                <div class="section-left">
                    <h3>Total:</h3>
                    <h2>{{ $data['total'] }}</h2>
                </div>
                <div class="section-right" style="text-align: right;">
                    <h3>{{ date('d/m/Y') }}</h3>
                    <h2>{{ $data['code'] }}</h2>
                </div>
            </div>
            <div class="section">
                <table style="width: 100%; text-align: right; border-radius: 10px;">
                    <tr >
                        <th style="width: 20%; font-size: 10px; line-height:1.5;">Reporté</th>
                        <td style="border-top: 1px dashed black; width: 27%;"></td>
                        <td style="border-top: 1px dashed black; width: 27%;"></td>
                        <td style="border-top: 1px dashed black; width: 27%;"></td>
                    </tr>
                    <tr>
                        <th style="border-top: 1px dashed black; width: 10%; font-size: 10px;line-height:1.5;">
                            Injoignable</th>
                        <td style="border-top: 1px dashed black; width: 18%;"></td>
                        <td style="border-top: 1px dashed black; width: 18%;"></td>
                        <td style="border-top: 1px dashed black; width: 18%;"></td>
                    </tr>
                    <tr>
                        <th style="border-top: 1px dashed black; width: 10%; font-size: 10px;line-height:1.5;">Refusé
                        </th>
                        <td style="border-top: 1px dashed black; width: 18%;"></td>
                        <td style="border-top: 1px dashed black; width: 18%;"></td>
                        <td style="border-top: 1px dashed black; width: 18%;"></td>
                    </tr>
                    <tr>
                        <th style="border-top: 1px dashed black; width: 10%; font-size: 10px;line-height:1.5;">Annulé
                        </th>
                        <td style="border-top: 1px dashed black; width: 18%;"></td>
                        <td style="border-top: 1px dashed black; width: 18%;"></td>
                        <td style="border-top: 1px dashed black; width: 18%;"></td>
                    </tr>
                </table>
                <h3>Produits:</h3>
                @foreach ($data['products'] as $product)
                    <p>{{ $product }}</p>
                @endforeach
                <h3>Remarques:</h3>
            </div>
        </div>
    @endforeach
</body>

</html>
