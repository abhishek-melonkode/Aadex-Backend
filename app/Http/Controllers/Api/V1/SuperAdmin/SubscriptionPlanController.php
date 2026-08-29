<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Domain\SuperAdmin\Models\SubscriptionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreSubscriptionPlanRequest;
use App\Http\Requests\SuperAdmin\UpdateSubscriptionPlanRequest;
use App\Http\Resources\SuperAdmin\SubscriptionPlanResource;
use Illuminate\Http\JsonResponse;

class SubscriptionPlanController extends Controller
{
    public function index(): JsonResponse
    {
        return SubscriptionPlanResource::collection(
            SubscriptionPlan::query()->latest()->paginate(15)
        )->response();
    }

    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $plan = SubscriptionPlan::create($request->validated());

        return (new SubscriptionPlanResource($plan->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function show(SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        return (new SubscriptionPlanResource($subscriptionPlan))->response();
    }

    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $subscriptionPlan->update($request->validated());

        return (new SubscriptionPlanResource($subscriptionPlan->refresh()))->response();
    }

    public function destroy(SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $subscriptionPlan->delete();

        return response()->json(null, 204);
    }
}
