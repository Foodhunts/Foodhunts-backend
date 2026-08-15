<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreAddressRequest;
use App\Http\Requests\Customer\UpdateAddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // An address referenced by an order or a buy-for-me request cannot be
        // hard-deleted: buy_for_me_requests.delivery_address_id is ON DELETE
        // RESTRICT (would throw a FK violation), and orders.delivery_address_id
        // is ON DELETE CASCADE (would silently delete the order). Block the
        // delete and tell the user why instead of surfacing a 500.
        $inUse = DB::table('orders')->where('delivery_address_id', $address->id)->exists()
            || DB::table('buy_for_me_requests')->where('delivery_address_id', $address->id)->exists();

        if ($inUse) {
            return response()->json([
                'success' => false,
                'message' => 'This address is linked to a past order and cannot be deleted.',
                'code' => 'ADDRESS_IN_USE',
            ], 409);
        }

        $address->delete();

        return response()->json(['success' => true, 'message' => 'Address deleted']);
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
