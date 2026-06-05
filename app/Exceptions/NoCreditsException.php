<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class NoCreditsException extends Exception
{
    /**
     * Render the exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function render($request)
    {
        return response()->json([
            'success' => false,
            'message' => 'there are no credit in scrape.do.'
        ]);
    }
}
