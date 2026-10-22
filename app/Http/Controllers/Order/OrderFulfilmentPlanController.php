<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Controller;
use App\Services\OrderFulfilmentPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderFulfilmentPlanController extends Controller
{
    public function __invoke(Request $request, OrderFulfilmentPlanner $planner): JsonResponse
    {
        $data = $request->validate(OrderFulfilmentPlanner::rules());

        return response()->json($planner->plan($request->user(), $data['items']));
    }
}
