# Speedaf WooCommerce Integration Guide

## Overview
The `SpeedafwController.php` provides a complete Laravel integration with Speedaf Express services, compatible with the WooCommerce Speedaf extension structure.

## Setup Instructions

### 1. Environment Configuration
Add the following to your `.env` file:

```env
# Speedaf API Credentials (get these from Speedaf)
SPEEDAF_APPCODE=880056
SPEEDAF_SECRETKEY=5oQpOLF7
SPEEDAF_CUSTOMERCODE=your_customer_code_here

# API URLs
SPEEDAF_BASE_URL=https://apis.speedaf.com/
SPEEDAF_VIP_URL=https://csp.speedaf.com/

# Default Sender Information
SPEEDAF_SENDER_NAME="Your Company Name"
SPEEDAF_SENDER_ADDRESS="Your Complete Address"
SPEEDAF_SENDER_PHONE="0123456789"
SPEEDAF_SENDER_CITY="Casablanca"
```

### 2. Route Registration
Add to your `routes/web.php` or `routes/api.php`:

```php
require __DIR__.'/speedafw.php';
```

### 3. Required Dependencies
Ensure you have Laravel Excel installed:
```bash
composer require maatwebsite/excel
```

## API Endpoints

### Order Management

#### Create Single Order
**POST** `/api/speedafw/orders/create`

```json
{
    "acceptName": "John Doe",
    "acceptMobile": "0612345678",
    "acceptAddress": "123 Main Street",
    "acceptCityName": "Casablanca",
    "acceptProvinceName": "Casablanca-Settat",
    "acceptCountryCode": "MA",
    "parcelWeight": 0.5,
    "goodsQTY": 1,
    "goodsName": "Product Name",
    "parcelValue": 299.00,
    "customOrderNo": "ORD-2024-001"
}
```

#### Create Batch Orders
**POST** `/api/speedafw/orders/batch-create`

```json
{
    "orders": [
        {
            "acceptName": "John Doe",
            "acceptMobile": "0612345678",
            // ... other order fields
        },
        {
            "acceptName": "Jane Smith",
            "acceptMobile": "0687654321",
            // ... other order fields
        }
    ]
}
```

#### Cancel Order
**POST** `/api/speedafw/orders/cancel`

```json
{
    "billCode": "SF123456789",
    "reason": "Customer requested cancellation"
}
```

### Tracking

#### Track Order
**POST** `/api/speedafw/track`

```json
{
    "trackingNoList": ["SF123456789", "SF987654321"]
}
```

### Import and Create Orders from Excel
**POST** `/api/speedafw/orders/import-create`

- Upload an Excel file with order data
- The file should have columns: Waybill, Nom, Téléphone, Zone, Adresse, S.O, Marchandise, Montant, Ouverture, Remarque
- First row (header) will be skipped automatically

### Label Printing
**POST** `/api/speedafw/print-label`

```json
{
    "billCodes": ["SF123456789", "SF987654321"],
    "labelType": 3
}
```

Label Types:
- 1: Triplicate form (76×203)
- 2: Double sheet without logo (10×18)
- 3: Double sheet with logo (10×18) - Default
- 5: Double sheet with logo (10×15)

### Sorting Code Services

#### Get Sorting Code by Waybill
**POST** `/api/speedafw/sorting-code/waybill`

```json
{
    "billCode": "SF123456789"
}
```

#### Get Sorting Code by Address
**POST** `/api/speedafw/sorting-code/address`

```json
{
    "acceptCountryCode": "MA",
    "acceptProvinceName": "Casablanca-Settat",
    "acceptCityName": "Casablanca",
    "acceptDistrictName": "Anfa"
}
```

## Step-by-Step: Adding Orders to Speedaf Platform

### Method 1: Single Order Creation

1. **Prepare Order Data**
   ```php
   $orderData = [
       'acceptName' => 'Customer Name',
       'acceptMobile' => '0612345678',
       'acceptAddress' => 'Full Address',
       'acceptCityName' => 'City',
       'acceptProvinceName' => 'Province',
       'acceptCountryCode' => 'MA',
       'parcelWeight' => 0.5,
       'goodsQTY' => 1,
       'goodsName' => 'Product Description',
       'parcelValue' => 299.00
   ];
   ```

2. **Send POST Request**
   ```bash
   curl -X POST http://yourdomain.com/api/speedafw/orders/create \
     -H "Content-Type: application/json" \
     -d '{"acceptName":"John Doe","acceptMobile":"0612345678",...}'
   ```

3. **Handle Response**
   ```json
   {
       "success": true,
       "data": {
           "billCode": "SF123456789",
           "trackingNo": "SF123456789",
           "sortingCode": "ABC123"
       },
       "message": "Order created successfully"
   }
   ```

### Method 2: Excel Import

1. **Prepare Excel File**
   - Column A: Waybill (leave empty)
   - Column B: Customer Name
   - Column C: Phone Number
   - Column D: City/Zone
   - Column E: Address
   - Column F: Order Code
   - Column G: Product Description
   - Column H: Amount
   - Column I: Allow Opening (Yes/No)
   - Column J: Remarks

2. **Upload via API**
   ```bash
   curl -X POST http://yourdomain.com/api/speedafw/orders/import-create \
     -F "file=@orders.xlsx"
   ```

3. **Response will include**:
   - Count of successfully imported orders
   - Count of created orders on Speedaf
   - List of errors (if any)
   - Details of created orders with tracking numbers

### Method 3: Batch Creation

1. **Prepare Multiple Orders**
   ```json
   {
       "orders": [
           {
               "acceptName": "Customer 1",
               "acceptMobile": "0612345678",
               // ... other fields
           },
           {
               "acceptName": "Customer 2", 
               "acceptMobile": "0687654321",
               // ... other fields
           }
       ]
   }
   ```

2. **Send Batch Request**
   ```bash
   curl -X POST http://yourdomain.com/api/speedafw/orders/batch-create \
     -H "Content-Type: application/json" \
     -d @batch_orders.json
   ```

## Error Handling

All endpoints return standardized responses:

**Success Response:**
```json
{
    "success": true,
    "data": { /* API response data */ },
    "message": "Operation completed successfully"
}
```

**Error Response:**
```json
{
    "success": false,
    "error": "Error description",
    "message": "Operation failed"
}
```

## Testing

1. **Test Single Order Creation**
   ```bash
   php artisan tinker
   ```
   ```php
   $controller = new App\Http\Controllers\SpeedafwController();
   $request = new Illuminate\Http\Request([
       'acceptName' => 'Test Customer',
       'acceptMobile' => '0612345678',
       'acceptAddress' => 'Test Address',
       'acceptCityName' => 'Casablanca',
       'acceptProvinceName' => 'Casablanca-Settat',
       'acceptCountryCode' => 'MA',
       'parcelWeight' => 0.5,
       'goodsQTY' => 1
   ]);
   $response = $controller->createOrder($request);
   echo $response->getContent();
   ```

2. **Test with Postman**
   - Import the provided route collection
   - Set up environment variables
   - Test each endpoint individually

## Important Notes

1. **Customer Code**: You must obtain your customer code from Speedaf before using the API
2. **Country Codes**: Use ISO country codes (MA for Morocco, etc.)
3. **Weight**: Parcel weight should be in KG
4. **Phone Numbers**: Use full phone numbers with country code
5. **Address**: Provide complete, accurate addresses for better delivery success
6. **Testing**: Use the test environment first before going live

## Troubleshooting

### Common Errors

#### 1. "des decryption failed" (Error Code: 60008)
This error occurs when there's an encryption/decryption mismatch. Try these steps:

**Step 1: Test Encryption**
```bash
curl -X GET http://yourdomain.com/api/speedafw/debug/encryption
```

**Step 2: Test API Connection**
```bash
curl -X GET http://yourdomain.com/api/speedafw/debug/api-connection
```

**Step 3: Check Environment Variables**
```bash
php artisan tinker
```
```php
echo "APP_CODE: " . env('SPEEDAF_APPCODE') . "\n";
echo "SECRET_KEY: " . env('SPEEDAF_SECRETKEY') . "\n";
echo "CUSTOMER_CODE: " . env('SPEEDAF_CUSTOMERCODE') . "\n";
echo "BASE_URL: " . env('SPEEDAF_BASE_URL') . "\n";
```

**Step 4: Clear Config Cache**
```bash
php artisan config:clear
php artisan cache:clear
```

**Possible Causes:**
- Wrong SECRET_KEY (API Key) in environment
- Wrong APP_CODE in environment  
- OpenSSL not properly configured
- Character encoding issues in the secret key

#### 2. Authentication Errors
- Check your APP_CODE and SECRET_KEY
- Verify CUSTOMER_CODE is correct
- Ensure you're using production credentials for production URL

#### 3. Encryption Errors
- Ensure OpenSSL is properly configured
- Check PHP openssl extension is installed: `php -m | grep openssl`

#### 4. Address Errors
- Verify city and province names match Speedaf's database
- Use exact spelling for Moroccan cities

#### 5. Weight Errors
- Ensure weight is a positive decimal number
- Weight should be in KG

## Support

For Speedaf API issues, contact Speedaf support directly.
For Laravel integration issues, check the logs in `storage/logs/laravel.log`.