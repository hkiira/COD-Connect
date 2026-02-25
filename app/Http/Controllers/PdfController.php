<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PDF;
use Illuminate\Support\Facades\Http;

class PdfController extends Controller
{
    public function generatePdf(Request $request)
    {
        // Fetch data from the API
        $request = collect($request->query())->toArray();
        return $request;
        $associated=[];
        $model = 'App\\Models\\PaymentType';
        $data = FilterController::searchs(new Request($request),$model,['id','title'], false,$associated);
        // Pass data to the view
        $pdf = PDF::loadView('pdf.document', compact('data'));

        // Download the PDF file
        return $pdf->stream('documenst.pdf');
    }
}
