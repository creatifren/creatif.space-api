<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * The creator's own offers — what the Space Editor's Settings tab writes.
 */
class OfferController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'space' => ['sometimes', 'string', 'max:26'],
        ]);

        $query = $request->user()->offers()->with('space');

        if (isset($validated['space'])) {
            $space = $request->user()->spaces()
                ->where('ulid', $validated['space'])
                ->firstOrFail();
            $query->where('space_id', $space->id);
        }

        return OfferResource::collection($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request, creating: true);
        $user = $request->user();

        $spaceId = $this->resolveSpaceId($request, $validated);

        $offer = $user->offers()->create([
            ...$this->attributes($validated),
            'space_id' => $spaceId,
        ]);

        // refresh(): is_active and price_from come from column defaults, and
        // `buyable` is derived from them.
        return (new OfferResource($offer->refresh()->load('space')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Offer $offer): OfferResource
    {
        abort_unless($offer->user_id === $request->user()->id, 404);

        $validated = $this->validated($request, creating: false);

        if (array_key_exists('space_id', $validated)) {
            $offer->space_id = $this->resolveSpaceId($request, $validated);
        }

        $offer->fill($this->attributes($validated))->save();

        return new OfferResource($offer->refresh()->load('space'));
    }

    /**
     * Soft delete: an order placed last month must still be able to say
     * what it was for.
     */
    public function destroy(Request $request, Offer $offer): JsonResponse
    {
        abort_unless($offer->user_id === $request->user()->id, 404);

        $offer->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'type' => [$required, 'string', 'in:service,product,booking,tip'],
            'title' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Null is legitimate: a tip has no set price.
            'price' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'price_from' => ['sometimes', 'boolean'],
            'show_on_space' => ['sometimes', 'boolean'],
            'show_on_profile' => ['sometimes', 'boolean'],
            'details' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'space_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        return array_intersect_key($validated, array_flip([
            'type', 'title', 'description', 'price', 'price_from',
            'show_on_space', 'show_on_profile', 'details', 'is_active',
            'sort_order',
        ]));
    }

    /**
     * An offer may hang off one of your own Spaces, or off nothing at all
     * (the profile's Hire list).
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveSpaceId(Request $request, array $validated): ?int
    {
        $ulid = $validated['space_id'] ?? null;

        if ($ulid === null) {
            return null;
        }

        $space = Space::query()
            ->where('ulid', $ulid)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($space === null) {
            throw ValidationException::withMessages([
                'space_id' => 'That Space isn’t yours.',
            ]);
        }

        return $space->id;
    }
}
