<?php

namespace App\Services;

use App\Models\AccountUser;
use App\Models\Order;
use App\Models\OrderStatus;
use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Google\Service\Sheets\AppendCellsRequest;
use Google\Service\Sheets\RowData;
use Google\Service\Sheets\CellData;
use Google\Service\Sheets\ExtendedValue;
use Google\Service\Sheets\CellFormat;
use Google\Service\Sheets\Color;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
    protected ?Sheets $service = null;
    protected string $spreadsheetId = '';
    protected string $sheetName = '';
    protected bool $enabled = false;

    public function __construct()
    {
        $this->enabled = (bool) config('google-sheets.enabled');
        $this->spreadsheetId = (string) config('google-sheets.spreadsheet_id');
        $this->sheetName = (string) config('google-sheets.sheet_name');

        if (!$this->enabled || $this->spreadsheetId === '' || $this->sheetName === '') {
            return;
        }

        try {
            $client = new Client();
            $client->setApplicationName((string) config('google-sheets.app_name'));
            $client->setScopes([Sheets::SPREADSHEETS]);
            $client->setAuthConfig((string) config('google-sheets.credentials_path'));
            $client->setAccessType('offline');
            $this->service = new Sheets($client);
        } catch (\Throwable $e) {
            Log::warning('Google Sheets init failed: ' . $e->getMessage());
        }
    }

    public function appendOrderStatusRow(Order $order, ?OrderStatus $status, ?AccountUser $actor, ?string $note = null): void
    {
        if (!$this->service) {
            return;
        }

        $customer = $order->customer;
        $customerName = $customer ? $customer->name."-".$order->code : null;

        $phone = null;
        if ($customer && $customer->phones) {
            $phone = optional($customer->phones->first())->title;
        }
        if (!$phone && $order->phones) {
            $phone = optional($order->phones->first())->title;
        }

        $address = null;
        $city = null;
        if ($customer && $customer->addresses) {
            $lastAddress = $customer->addresses->first();
            $address = optional($lastAddress)->title;
            $city = optional(optional($lastAddress)->city)->title;
        }
        if (!$city && $order->city) {
            $city = $order->city->title;
        }
        if (!$address) {
            $address = $order->adresse;
        }

        $price = method_exists($order, 'calculateActivePvasTotalValue') ? $order->calculateActivePvasTotalValue()-$order->discount+$order->shipping_price : null;
        $quantity = method_exists($order, 'calculateActivePvasQte') ? $order->calculateActivePvasQte() : null;
        $productRef = null;
        $firstPva = $order->activeOrderPvas->first();
        if ($firstPva && $firstPva->productVariationAttribute && $firstPva->productVariationAttribute->product) {
            $productRef = $firstPva->productVariationAttribute->product->reference;
        }

        $values = [
            [
                $customerName,
                $phone,
                $city,
                $address,
                $price,
                implode(" \n ", collect($order->orderPvaTtitle()->map(function ($item) {
                    return $item['product'] . ' ' . implode(' ', $item['attributes']);
                }))->map(fn($item) => $item)->toArray()),
                $quantity,
                $order->code,
                $order->is_change ? 1 : 0,
                1,
                null,
            ]
        ];

        $body = new ValueRange([
            'values' => $values,
        ]);

        try {
            $currentRowCount = $this->getRowCount();

            $this->service->spreadsheets_values->append(
                $this->spreadsheetId,
                $this->sheetName . '!A:K',
                $body,
                [
                    'valueInputOption' => 'USER_ENTERED',
                    'insertDataOption' => 'INSERT_ROWS',
                ]
            );

            try {
                $this->formatRowsWhiteBackground($currentRowCount);
            } catch (\Throwable $e) {
                Log::warning('Google Sheets formatting failed: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::warning('Google Sheets sync failed: ' . $e->getMessage());
        }
    }

    protected function getRowCount(): int
    {
        if (!$this->service) {
            return 0;
        }

        try {
            $response = $this->service->spreadsheets_values->get(
                $this->spreadsheetId,
                $this->sheetName . '!A:A'
            );
            $values = $response->getValues();
            return $values ? count($values) : 0;
        } catch (\Throwable $e) {
            Log::warning('Failed to get row count: ' . $e->getMessage());
            return 0;
        }
    }

    protected function formatRowsWhiteBackground(int $startRow): void
    {
        if (!$this->service) {
            return;
        }

        try {
            $sheetId = $this->getSheetId();
            if ($sheetId === null) {
                return;
            }

            $formatRequest = new SheetsRequest([
                'repeatCell' => [
                    'range' => [
                        'sheetId' => $sheetId,
                        'startRowIndex' => $startRow,
                        'endRowIndex' => $startRow + 1,
                    ],
                    'cell' => [
                        'userEnteredFormat' => [
                            'backgroundColor' => [
                                'red' => 1.0,
                                'green' => 1.0,
                                'blue' => 1.0,
                                'alpha' => 1.0,
                            ],
                        ],
                    ],
                    'fields' => 'userEnteredFormat.backgroundColor',
                ],
            ]);

            $batchUpdate = new BatchUpdateSpreadsheetRequest([
                'requests' => [$formatRequest],
            ]);

            $this->service->spreadsheets->batchUpdate($this->spreadsheetId, $batchUpdate);
        } catch (\Throwable $e) {
            Log::warning('Google Sheets format update failed: ' . $e->getMessage());
        }
    }

    protected function getSheetId(): ?int
    {
        try {
            $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
            foreach ($spreadsheet->getSheets() as $sheet) {
                if ($sheet->getProperties()->getTitle() === $this->sheetName) {
                    return $sheet->getProperties()->getSheetId();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Google Sheets sheet lookup failed: ' . $e->getMessage());
        }

        return null;
    }
}
