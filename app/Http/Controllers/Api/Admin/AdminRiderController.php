<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveRiderRequest;
use App\Http\Resources\RiderResource;
use App\Models\Rider;
use App\Services\RiderService;
use Illuminate\Http\Request;

class AdminRiderController extends Controller
{
    public function __construct(private readonly RiderService $riderService)
    {
    }

    public function index(Request $request)
    {
        $query = Rider::query()
            ->with(['user'])
            ->withCount('documents');

        if ($request->filled('kyc_status')) {
            $query->where('kyc_status', $request->string('kyc_status'));
        }

        if ($request->filled('is_online')) {
            $query->where('is_online', $request->boolean('is_online'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->whereHas('user', function ($userQuery) use ($search): void {
                $userQuery->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        return RiderResource::collection($query->latest()->paginate((int) ($request->input('per_page', 20))));
    }

    public function show(Rider $rider): RiderResource
    {
        return new RiderResource($rider->load('user', 'documents'));
    }

    public function approve(ApproveRiderRequest $request, Rider $rider): RiderResource
    {
        return new RiderResource($this->riderService->review(
            $rider,
            (bool) $request->validated('approved'),
            $request->validated('note'),
        )->load('user'));
    }
}
