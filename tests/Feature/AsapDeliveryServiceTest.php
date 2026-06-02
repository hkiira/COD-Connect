<?php

namespace Tests\Feature;

use App\Exceptions\AsapDeliveryException;
use App\Exceptions\ParcelNotFoundException;
use App\Services\AsapDeliveryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AsapDeliveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Mock the config values
        config([
            'asapdelivery.base_url' => 'https://api.asapdelivery.ma',
            'asapdelivery.token' => 'test_token',
            'asapdelivery.secret_key' => 'test_secret',
        ]);
    }

    /** @test */
    public function it_can_get_cities()
    {
        Http::fake([
            'api.asapdelivery.ma/cities.php' => Http::response([['id' => 1, 'name' => 'Casablanca']], 200),
        ]);

        $service = new AsapDeliveryService();
        $cities = $service->getCities();

        $this->assertIsArray($cities);
        $this->assertEquals('Casablanca', $cities[0]['name']);
    }

    /** @test */
    public function it_can_track_a_parcel_successfully()
    {
        $trackingCode = 'TEST12345';
        Http::fake([
            "api.asapdelivery.ma/track.php*" => Http::response(['status' => 'Delivered'], 200),
        ]);

        $service = new AsapDeliveryService();
        $trackingInfo = $service->trackParcel($trackingCode);

        $this->assertEquals('Delivered', $trackingInfo['status']);
    }

    /** @test */
    public function it_throws_exception_for_non_existent_parcel()
    {
        $this->expectException(ParcelNotFoundException::class);

        Http::fake([
            'api.asapdelivery.ma/track.php*' => Http::response("Code doesn't exist", 200),
        ]);

        $service = new AsapDeliveryService();
        $service->trackParcel('nonexistent');
    }

    /** @test */
    public function it_can_add_a_parcel_successfully()
    {
        Http::fake([
            'api.asapdelivery.ma/addcolis.php*' => Http::response('Package added successfully: NEWCODE123', 200),
        ]);

        $service = new AsapDeliveryService();
        $parcelData = [
            'fullname' => 'John Doe',
            'phone' => '0612345678',
            'city' => 'Rabat',
            'address' => '123 Main St',
            'price' => 250,
            'product' => 'T-shirt',
            'qty' => '1',
        ];
        $code = $service->addParcel($parcelData);

        $this->assertEquals('NEWCODE123', $code);
    }

    /** @test */
    public function it_throws_exception_when_adding_parcel_fails()
    {
        $this->expectException(AsapDeliveryException::class);

        Http::fake([
            'api.asapdelivery.ma/addcolis.php*' => Http::response('Some parameters are missing', 200),
        ]);

        $service = new AsapDeliveryService();
        $service->addParcel([]);
    }

    /** @test */
    public function it_can_list_parcels()
    {
        Http::fake([
            'api.asapdelivery.ma/colislist.php*' => Http::response([['code' => 'PCL1'], ['code' => 'PCL2']]),
        ]);

        $service = new AsapDeliveryService();
        $parcels = $service->listParcels();

        $this->assertCount(2, $parcels);
        $this->assertEquals('PCL1', $parcels[0]['code']);
    }

    /** @test */
    public function it_can_update_parcel_status()
    {
        Http::fake([
            'api.asapdelivery.ma/editstate.php*' => Http::response('Package has been updated successfully.'),
        ]);

        $service = new AsapDeliveryService();
        $result = $service->updateParcelStatus('TESTCODE', 'Livré', '01/06/2026');

        $this->assertTrue($result);
    }
}
