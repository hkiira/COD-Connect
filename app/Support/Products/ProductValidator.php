<?php

namespace App\Support\Products;

use App\Http\Controllers\RestoreController;
use App\Models\AccountUser;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Image;
use App\Models\Imageable;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductVariationAttribute;
use App\Models\Supplier;
use App\Models\Taxonomy;
use App\Models\Warehouse;
use App\Support\ProductOwnership;
use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;

/**
 * Validation of the product create / update payloads (an array of products, hence the "*." keys).
 *
 * Every id a payload refers to (brand, category, warehouse, supplier, offer, image, attribute) must
 * belong to the current account. Kept out of the controller so the rules can be read and tested alone.
 */
class ProductValidator
{
    private const IMAGE_RULE = 'image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048';

    /** @var int[] ids of the active account users of the account */
    private array $users;

    public function __construct(private readonly int $accountId)
    {
        $this->users = AccountUser::where(['account_id' => $accountId, 'statut' => 1])->pluck('id')->all();
    }

    public static function forCurrentAccount(): self
    {
        return new self((int) getAccountUser()->account_id);
    }

    /**
     * @param bool $uniqueReference refuse a reference already used by another product of the account.
     *                              Off for machine imports, which may legitimately repeat references.
     */
    public function forCreate(array $payload, bool $uniqueReference = false): ValidatorContract
    {
        $reference = ['required', 'string', 'max:255'];
        if ($uniqueReference) {
            $reference[] = $this->uniqueReference($payload);
        }

        return Validator::make($payload, [
            '*.default_measurement_id' => 'exists:measurements,id',
            '*.product_type_id' => 'exists:product_types,id',
            '*.measurements.*.id' => 'exists:measurements,id',
            '*.measurements.*.quantity' => 'numeric',
            '*.price' => 'required|numeric',
            '*.title' => ['required', 'string', 'max:255'],
            '*.reference' => $reference,
            '*.warehouses.*' => [$this->inAccount(Warehouse::class, ['warehouse_type_id' => 1])],
            '*.brands.*' => [$this->inAccount(Brand::class, ['statut' => 1])],
            '*.categories.*' => [$this->inUsers(Taxonomy::class)],
            '*.suppliers' => 'array',
            '*.suppliers.*.id' => ['sometimes', 'required', $this->inAccount(Supplier::class)],
            '*.suppliers.*.price' => 'sometimes|required|numeric',
            '*.attributes.*' => [$this->inUsers(Attribute::class)],
            '*.productVariationAttributes.id' => [$this->inAccount(ProductVariationAttribute::class)],
            '*.productVariationAttributes.quantity' => 'numeric',
            '*.offers.*' => [
                'string',
                $this->inAccount(Offer::class, [], fn ($query) => $query->where('offer_type_id', '!=', 1)),
            ],
            '*.images.*' => ['string', $this->availableImage(Product::class)],
            '*.imageVariations.*.image' => ['string', $this->availableImage(ProductVariationAttribute::class)],
            '*.imageVariations.*.attribute.*' => ['string', $this->inUsers(Attribute::class)],
            '*.imageVariations.*.attributes.*' => ['string', $this->inUsers(Attribute::class)],
            '*.principalImage' => ['string', $this->availableImage(Product::class)],
            '*.statut' => 'required',
            '*.newImages.*' => self::IMAGE_RULE,
            '*.newPrincipalImage' => self::IMAGE_RULE,
        ]);
    }

    public function forUpdate(array $payload): ValidatorContract
    {
        return Validator::make($payload, [
            '*.id' => [
                'required',
                'exists:products,id',
                // a product can only be changed by the account that owns it
                fn ($attribute, $value, $fail) => ProductOwnership::owns($value) ?: $fail('not exist'),
            ],
            '*.reference' => ['max:255', $this->uniqueReference($payload, onlyWhenChanged: true)],
            '*.title' => ['max:255', $this->uniqueTitle($payload)],
            '*.warehousesToActive.*' => [$this->inAccount(Warehouse::class, ['warehouse_type_id' => 1])],
            '*.warehousesToInactive.*' => [$this->inAccount(Warehouse::class, ['warehouse_type_id' => 1])],
            '*.taxonomiesToActive.*' => [$this->exists(Taxonomy::class)],
            '*.taxonomiesToInactive.*' => [$this->exists(Taxonomy::class)],
            '*.suppliersToActive.*.price' => 'required',
            '*.suppliersToActive.*.id' => [$this->inAccount(Supplier::class)],
            '*.suppliersToInactive.*' => [$this->inAccount(Supplier::class)],
            '*.attributes.*' => [$this->inUsers(Attribute::class)],
            '*.brandsToActive.*' => [$this->inAccount(Brand::class)],
            '*.brandsToInactive.*' => [$this->inAccount(Brand::class)],
            '*.offersToActive.*' => [$this->inAccount(Offer::class)],
            '*.offersToInactive.*' => [$this->inAccount(Offer::class)],
            '*.imageVariations.*.image' => ['string', $this->inAccount(Image::class)],
            '*.imageVariations.*.attributes.*' => [$this->exists(Attribute::class)],
            '*.images.*' => ['string', $this->inAccount(Image::class)],
            '*.principalImage' => ['string', $this->inAccount(Image::class)],
            '*.newImages.*' => self::IMAGE_RULE,
            '*.newPrincipalImage' => self::IMAGE_RULE,
        ]);
    }

    /**
     * Soft-deleted products keep their title; rename them so a title can be reused.
     * This writes, so it runs before validation instead of inside a rule.
     */
    public function releaseRemovedTitles(array $payload): void
    {
        foreach ($payload as $product) {
            if (is_array($product) && ! empty($product['title']) && is_string($product['title'])) {
                RestoreController::renameRemovedRecords('Product', 'title', $product['title']);
            }
        }
    }

    // region rule builders

    private function exists(string $model, ?callable $scope = null): Closure
    {
        return function ($attribute, $value, $fail) use ($model, $scope) {
            $query = $model::query()->where('id', $value);
            if ($scope) {
                $scope($query);
            }
            if (! $query->exists()) {
                $fail('not exist');
            }
        };
    }

    private function inAccount(string $model, array $extraConditions = [], ?callable $scope = null): Closure
    {
        return $this->exists($model, function ($query) use ($extraConditions, $scope) {
            $query->where('account_id', $this->accountId)->where($extraConditions);
            if ($scope) {
                $scope($query);
            }
        });
    }

    private function inUsers(string $model): Closure
    {
        return $this->exists($model, fn ($query) => $query->whereIn('account_user_id', $this->users));
    }

    /** The image must belong to the account and not already be attached to another record of that type. */
    private function availableImage(string $imageableType): Closure
    {
        return function ($attribute, $value, $fail) use ($imageableType) {
            $image = Image::where(['id' => $value, 'account_id' => $this->accountId])->first();
            if (! $image) {
                $fail('not exist');

                return;
            }

            if (Imageable::where('image_id', $image->id)->where('imageable_type', $imageableType)->exists()) {
                $fail('exist');
            }
        };
    }

    /** Index of the product a rule applies to: "2.reference" => 2. */
    private static function indexOf(string $attribute): string
    {
        return explode('.', $attribute)[0];
    }

    private function uniqueReference(array $payload, bool $onlyWhenChanged = false): Closure
    {
        return function ($attribute, $value, $fail) use ($payload, $onlyWhenChanged) {
            $id = data_get($payload, self::indexOf($attribute) . '.id');

            // an unchanged reference of an already duplicated product must not block other edits
            if ($onlyWhenChanged && $id && Product::where('id', $id)->value('reference') === $value) {
                return;
            }

            $clash = Product::where('reference', $value)->whereIn('account_user_id', $this->users)
                ->when($id, fn ($query) => $query->where('id', '!=', $id))->exists();

            if ($clash) {
                $fail('exist');
            }
        };
    }

    private function uniqueTitle(array $payload): Closure
    {
        return function ($attribute, $value, $fail) use ($payload) {
            $id = data_get($payload, self::indexOf($attribute) . '.id');
            $clash = Product::where('title', $value)->whereIn('account_user_id', $this->users)
                ->when($id, fn ($query) => $query->where('id', '!=', $id))->exists();

            if ($clash) {
                $fail('exist');
            }
        };
    }

    // endregion
}
