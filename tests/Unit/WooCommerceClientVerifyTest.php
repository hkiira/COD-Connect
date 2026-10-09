<?php

namespace Tests\Unit;

use App\Models\WooCommerce\Store;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Http\Client\PendingRequest;
use Tests\TestCase;

/** Which certificate check the client asks for: a CA bundle that is not a file on this machine must not break calls. */
class WooCommerceClientVerifyTest extends TestCase
{
    private function verifyOptionOf(Store $store): mixed
    {
        $method = new \ReflectionMethod(WooCommerceClient::class, 'http');
        $method->setAccessible(true);

        /** @var PendingRequest $request */
        $request = $method->invoke(WooCommerceClient::fromStore($store));

        $options = new \ReflectionProperty(PendingRequest::class, 'options');
        $options->setAccessible(true);

        return $options->getValue($request)['verify'] ?? null;
    }

    private function store(array $attributes): Store
    {
        return (new Store())->forceFill($attributes + ['base_url' => 'https://shop.test/wp-json/wc/v3', 'consumer_key' => 'k', 'consumer_secret' => 's', 'verify_ssl' => true]);
    }

    public function test_a_missing_bundle_falls_back_to_the_system_certificates(): void
    {
        config(['services.woocommerce.ca_bundle' => '/no/such/dir/ca-bundle.crt']);

        $this->assertTrue($this->verifyOptionOf($this->store(['ca_bundle' => 'C:/nowhere/ca.crt'])));
    }

    public function test_an_existing_bundle_is_used_and_the_store_one_wins(): void
    {
        $storeBundle = tempnam(sys_get_temp_dir(), 'ca');
        $configBundle = tempnam(sys_get_temp_dir(), 'ca');
        config(['services.woocommerce.ca_bundle' => $configBundle]);

        try {
            $this->assertSame($storeBundle, $this->verifyOptionOf($this->store(['ca_bundle' => $storeBundle])));
            $this->assertSame($configBundle, $this->verifyOptionOf($this->store(['ca_bundle' => null])));
        } finally {
            @unlink($storeBundle);
            @unlink($configBundle);
        }
    }

    public function test_verification_can_be_turned_off_explicitly(): void
    {
        $this->assertFalse($this->verifyOptionOf($this->store(['verify_ssl' => false])));
    }
}
