<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CreditCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditCardController extends Controller
{
    public function __construct(private readonly CreditCardService $cards) {}

    /** Every card the account holds, as a spending instrument. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->cards->overview($request->user())]);
    }
}
