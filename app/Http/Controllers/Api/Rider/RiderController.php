<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rider\ApplyRiderRequest;
use App\Http\Requests\Rider\UpdateRiderLocationRequest;
use App\Http\Requests\Rider\UpdateRiderOnlineRequest;
use App\Http\Requests\Rider\UploadRiderDocumentRequest;
use App\Http\Resources\RiderResource;
use App\Models\Rider;
use App\Services\RiderService;
use Illuminate\Http\Request;

class RiderController extends Controller
{
    public function __construct(private readonly RiderService $riderService)
    {
    }

    public function apply(ApplyRiderRequest $request): RiderResource
    {
        $rider = $this->riderService->apply($request->user(), $request->validated());

        return new RiderResource($rider->load('user'));
    }

    public function me(Request $request): RiderResource
    {
        $rider = $this->riderFor($request);

        return new RiderResource($rider->load('user', 'documents'));
    }

    public function update(ApplyRiderRequest $request): RiderResource
    {
        $rider = $this->riderFor($request);

        return new RiderResource($this->riderService->updateProfile($rider, $request->validated())->load('user'));
    }

    public function uploadDocument(UploadRiderDocumentRequest $request): \App\Http\Resources\RiderDocumentResource
    {
        $rider = $this->riderFor($request);

        $document = $this->riderService->addDocument($rider, $request->validated());

        return new \App\Http\Resources\RiderDocumentResource($document);
    }

    public function setOnline(UpdateRiderOnlineRequest $request): RiderResource
    {
        $rider = $this->riderFor($request);

        return new RiderResource($this->riderService->setOnline($rider, (bool) $request->validated('is_online'))->load('user'));
    }

    public function location(UpdateRiderLocationRequest $request): RiderResource
    {
        $rider = $this->riderFor($request);

        // Server rate-cap (plan §8.2): at most one accepted write per 5s.
        if ($rider->location_updated_at !== null && $rider->location_updated_at->gt(now()->subSeconds(5))) {
            abort(429, 'Location write throttled.');
        }

        return new RiderResource($this->riderService->updateLocation(
            $rider,
            (float) $request->validated('latitude'),
            (float) $request->validated('longitude'),
        )->load('user'));
    }

    private function riderFor(Request $request): Rider
    {
        $rider = Rider::query()->where('user_id', $request->user()->id)->first();

        abort_unless($rider, 404, 'No rider profile for this account. Apply first.');

        return $rider;
    }
}
