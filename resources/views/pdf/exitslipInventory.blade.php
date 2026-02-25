<html>
    <head>
        <style>

		    @page chapter2 {
		        odd-header-name: html_Chapter2HeaderOdd;
		        even-header-name: html_Chapter2HeaderEven;
		        odd-footer-name: html_Chapter2FooterOdd;
		        even-footer-name: html_Chapter2FooterEven;
		    }

		    @page noheader {
		        odd-header-name: _blank;
		        even-header-name: _blank;
		        odd-footer-name: _blank;
		        even-footer-name: _blank;
		    }

		    div.chapter2 {
		        page-break-before: right;
		        page: chapter2;
		    }

		    div.noheader {
		        page-break-before: right;
		        page: noheader;
		    }

			h4 {
				font-family: sans;
				margin-top: 1em;
				margin-bottom: 0.2em;
				font-size: 13px;
			}

			h5 {
				font-family: sans;
				font-weight: bold;
				margin-top: 1em;
				margin-bottom: 0.2em;
				font-size: 14px;
			}

			h3 {
				font-family: sans;
				font-weight: bold;
				margin-top: 0.5em;
				margin-bottom: 0.2em;
				font-size: 15px;
			}

			h2 {
				font-family: sans;
				font-weight: bold;
				font-size: 20px;
				text-align: center;
			}

			h1 {
				font-family: sans;
				font-weight: bold;
				font-size: 30px;
				text-align: center;
			}

			.table {
			    border-spacing: 0;
			    width: 100%;
			    border: 1px solid #CCCCCC;
			    border-radius: 6px 6px 6px 6px;
			    -moz-border-radius: 6px 6px 6px 6px;
			    -webkit-border-radius: 6px 6px 6px 6px;
			    box-shadow: 0 1px 1px #CCCCCC;
			}        

			.table th:first-child {
			    border-radius: 6px 0 0 0;
			    -moz-border-radius: 6px 0 0 0;
			    -webkit-border-radius: 6px 0 0 0;
			}

			.table th:last-child {
			    border-radius: 0 6px 0 0;
			    -moz-border-radius: 0 6px 0 0;
			    -webkit-border-radius: 0 6px 0 0;
			}

			.table th {
			    background-color: #DCE9F9;
			    background-image: -moz-linear-gradient(center top , #F8F8F8, #ECECEC);
			    background-image: -webkit-gradient(linear, 0 0, 0 bottom, from(#F8F8F8), to(#ECECEC), color-stop(.4, #F8F8F8));
			    border-top: medium none;
			    box-shadow: 0 1px 0 rgba(255, 255, 255, 0.8) inset;
			    text-shadow: 0 1px 0 rgba(255, 255, 255, 0.5);
			}

			.table td, table th {
			    border-left: 1px solid #CCCCCC;
			    border-top: 1px solid #CCCCCC;
			    padding: 10px;
				font-size: 10px;
				font-family: sans;
			    text-align: left;
			}

        </style>
    </head>
	<body>
		<div class="noheader">
			<div style="margin-bottom:1.5CM; border:1px solid black; border-radius:15px;">
		                <h1>Historique du mouvement pour le bon N°:{{$code}}</h1>
			</div>
            <h2>Dépot départ: {{$fromWarehouse['title']}}</h2>
            <table class="table" style="margin-bottom:0.5cm;">
                    <tr>
                        <th style="font-size: 13px; width: 40%;">Articles</th>
                        <th style="font-size: 13px; width: 20%;">Stock départ</th>
                        <th style="font-size: 13px; width: 20%;">Stock finale</th>
                        <th style="font-size: 13px; width: 20%;">Qté commandé</th>
                    </tr>
                    @foreach($fromWarehouse['products'] as $product):
                        <tr>
                            <td style="font-size: 13px; ">{{$product['title']}}</td>
                            <td style="font-size: 13px; ">{{$product['before']}}</td>
                            <td style="font-size: 13px; ">{{$product['after']}}</td>
                            <td style="font-size: 13px; ">{{$product['quantity']}}</td>
                        </tr>
                    @endforeach;
            </table>
		</div>

		<htmlpagefooter name="myFooter1">
	        <table width="100%">
	            <tr>
	                <td width="66%" align="center" style="font-weight: bold; font-style: italic;"></td>
	                <td width="33%" style="text-align: right; font-style: italic;">
	                    {PAGENO}/{nbpg}
	                </td>
	            </tr>
	        </table>
	    </htmlpagefooter>

	    <htmlpagefooter name="myFooter2" style="display:none">
	        <table width="100%">
	            <tr>
	                <td width="33%">My document</td>
	                <td width="33%" align="center">{PAGENO}/{nbpg}</td>
	                <td width="33%" style="text-align: right;">{DATE j-m-Y}</td>
	            </tr>
	        </table>
	    </htmlpagefooter>
	    <htmlpageheader name="myHeader1" style="display:none">
    </htmlpageheader>

    <htmlpageheader name="myHeader2" style="display:none">
    </htmlpageheader>

    <htmlpageheader name="Chapter2HeaderOdd" style="display:none">
    </htmlpageheader>

    <htmlpageheader name="Chapter2HeaderEven" style="display:none">
    </htmlpageheader>


    <htmlpagefooter name="Chapter2FooterEven" style="display:none">
        <div>Chapter 2 Footer</div>
    </htmlpagefooter>

	</body>
</html>



