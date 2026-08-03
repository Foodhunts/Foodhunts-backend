<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreAddressRequest;
use App\Http\Requests\Customer\UpdateAddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return AddressResource::collection(
            $request->user()->addresses()->latest()->paginate((int) ($validated['per_page'] ?? 20))
        );
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $address = $request->user()->addresses()->create($request->validated());

        return response()->json(new AddressResource($address), 201);
    }

    public function update(UpdateAddressRequest $request, Address $address): AddressResource
    {
        abort_unless($address->user_id === $request->user()->id, 403);

        $address->update($request->validated());

        return new AddressResource($address->refresh());
    }

    public function destroy(Request $request, Address $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 403);

        $address->delete();

        return response()->json(['message' => 'Address deleted']);
    }

    public function setDefault(Request $request): JsonResponse
    {
        $request->validate(['address_id' => ['required', 'uuid', 'exists:addresses,id']]);
        $address = $request->user()->addresses()->findOrFail($request->string('address_id'));
        $request->user()->addresses()->update(['is_default' => false]);
        $address->update(['is_default' => true]);
        return response()->json(['success' => true, 'message' => 'Default address updated successfully', 'data' => new AddressResource($address->refresh())]);
    }
}
