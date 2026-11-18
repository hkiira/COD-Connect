<?php

namespace App\Services;

use App\Http\Controllers\OrderController;
use App\Models\Lead;
use App\Models\LeadImportMapping;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class LeadImportService
{
    private const MAX_ROWS = 5000;

    public function __construct(private readonly LeadOrderResolver $orderResolver)
    {
    }

    /** Canonical field => aliases in Arabic, French, and English. */
    private const FIELD_ALIASES = [
        'external_id' => ['lead id', 'lead_id', 'id lead', 'leadid', 'tiktok lead id', 'معرف العميل المحتمل', 'رقم العميل المحتمل'],
        'lead_date' => ['created time', 'created_time', 'created at', 'date', 'submitted at', 'date de creation', 'date de création', 'date de soumission', 'تاريخ الإنشاء', 'تاريخ الارسال'],
        'name' => ['name', 'full name', 'fullname', 'contact name', 'nom', 'nom complet', 'الاسم', 'اسم العميل'],
        'phone' => ['phone', 'phone number', 'telephone', 'téléphone', 'mobile', 'mobile phone', 'numéro de téléphone', 'numero de telephone', 'رقم الهاتف', 'الهاتف', 'تلفون'],
        'email' => ['email', 'email address', 'e-mail', 'courriel', 'البريد الإلكتروني', 'البريد الالكتروني'],
        'city' => ['city', 'town', 'ville', 'المدينة', 'مدينة'],
        'address' => ['address', 'full address', 'adresse', 'العنوان'],
        'lead_source' => ['source', 'lead source', 'المصدر'],
        'source_action' => ['source action', 'action source', 'إجراء المصدر'],
        'ad_type' => ['ad type', 'نوع الإعلان', 'نوع الاعلان'],
        'ad_account' => ['ad account', 'ad account name', 'حساب إعلانات', 'حساب الاعلانات'],
        'business_account' => ['business account', 'الحساب التجاري'],
        'campaign_id' => ['campaign id', 'campaign_id', 'campaignid'],
        'campaign_name' => ['campaign name', 'campaign_name', 'campaign'],
        'adgroup_id' => ['adgroup id', 'ad group id', 'adgroup_id', 'adset id'],
        'adgroup_name' => ['adgroup name', 'ad group name', 'adgroup_name', 'adset name'],
        'ad_id' => ['ad id', 'ad_id', 'adid'],
        'ad_name' => ['ad name', 'ad_name'],
        'form_id' => ['form id', 'form_id', 'formid'],
        'form_name' => ['form name', 'form_name'],
        'requested_size' => ['size', 'requested size', 'اختر القياس الذي تريد', 'إختر القياس الذي تريد', 'اختر المقاس', 'المقاس', 'القياس'],
        'requested_color' => ['color', 'colour', 'requested color', 'اختر اللون الذي تريد', 'إختر اللون الذي تريد', 'اختر اللون', 'اللون'],
        'product_reference' => ['product', 'product name', 'product id', 'product code', 'product reference', 'reference', 'sku', 'item', 'article', 'المنتج', 'اسم المنتج', 'مرجع المنتج'],
        'quantity' => ['quantity', 'qty', 'quantity ordered', 'عدد', 'الكمية'],
        'price' => ['price', 'unit price', 'selling price', 'prix', 'الثمن', 'السعر'],
        'appointment_scheduled' => ['appointment scheduled', 'appointment', 'rendez-vous', 'موعد'],
        'ai_overview' => ['ai overview', 'generated overview', 'نظرة عامة منشأة بالذكاء الاصطناعي'],
    ];

    private const CORE_FIELDS = ['external_id', 'lead_date', 'name', 'phone', 'email', 'city', 'address'];

    /** @param array<string, mixed>|null $providedMapping */
    public function preview(
        UploadedFile $file,
        int $accountId,
        string $source,
        ?array $providedMapping = null,
        ?array $providedProductMapping = null,
        ?array $providedVariantMapping = null,
        ?array $providedCityMapping = null,
    ): array
    {
        [$headers, $rows] = $this->readFile($file);
        if (count($rows) > self::MAX_ROWS) {
            throw new \InvalidArgumentException('A maximum of 5,000 leads can be imported per file.');
        }
        if ($rows === []) {
            throw new \InvalidArgumentException('No lead rows were found below the header row.');
        }

        $fingerprint = $this->fingerprint($headers);
        $mappingSource = 'automatic';
        if ($providedMapping !== null) {
            $mapping = $this->sanitizeMapping($providedMapping, count($headers));
            $mappingSource = 'manual';
        } else {
            $saved = LeadImportMapping::query()
                ->where('account_id', $accountId)
                ->where('source', $source)
                ->where('fingerprint', $fingerprint)
                ->first();
            if ($saved) {
                $mapping = $this->sanitizeMapping($saved->mapping ?? [], count($headers));
                $mappingSource = 'saved';
            } else {
                $mapping = $this->detectMapping($headers);
            }
        }

        $records = [];
        foreach ($rows as $index => $values) {
            $records[] = $this->mapRow($headers, $values, $mapping, $index + 2);
        }
        $this->classifyRows($records, $accountId, $source);

        $savedProfile = LeadImportMapping::query()
            ->where('account_id', $accountId)
            ->where('source', $source)
            ->where('fingerprint', $fingerprint)
            ->first();
        $productMapping = $providedProductMapping ?? ($savedProfile?->product_mapping ?? []);
        $variantMapping = $providedVariantMapping ?? ($savedProfile?->variant_mapping ?? []);
        $cityMapping = $providedCityMapping ?? ($savedProfile?->city_mapping ?? []);
        $resolutions = $this->orderResolver->resolve($records, $accountId, $productMapping, $variantMapping, $cityMapping);

        $counts = [
            'ready' => 0,
            'duplicate' => 0,
            'invalid' => 0,
            'product_unresolved' => 0,
            'variant_unresolved' => 0,
            'city_unresolved' => 0,
            'phone_warnings' => 0,
        ];
        foreach ($records as $record) {
            $counts[$record['state']]++;
            if ($record['phone_duplicate_warning']) {
                $counts['phone_warnings']++;
            }
        }

        return [
            'headers' => array_map(
                fn (string $header, int $index) => ['index' => $index, 'label' => $header],
                $headers,
                array_keys($headers)
            ),
            'mapping' => $mapping,
            'mapping_source' => $mappingSource,
            'header_fingerprint' => $fingerprint,
            'total_rows' => count($records),
            'ready_count' => $counts['ready'],
            'duplicate_count' => $counts['duplicate'],
            'invalid_count' => $counts['invalid'],
            'product_unresolved_count' => $counts['product_unresolved'],
            'variant_unresolved_count' => $counts['variant_unresolved'],
            'city_unresolved_count' => $counts['city_unresolved'],
            'phone_duplicate_count' => $counts['phone_warnings'],
            'product_groups' => $resolutions['product_groups'],
            'variant_groups' => $resolutions['variant_groups'],
            'city_groups' => $resolutions['city_groups'],
            'product_mapping' => $productMapping,
            'variant_mapping' => $variantMapping,
            'city_mapping' => $cityMapping,
            'preview_rows' => array_map(fn (array $record) => [
                'row_number' => $record['row_number'],
                'name' => $record['data']['name'],
                'phone' => $record['data']['phone'],
                'city' => $record['data']['city'],
                'product_key' => $record['product_key'] ?? '',
                'product_title' => $record['product_title'] ?? null,
                'variant_label' => $record['variant_label'] ?? null,
                'city_title' => $record['city_title'] ?? null,
                'state' => $record['state'],
                'message' => $record['message'],
                'phone_duplicate_warning' => $record['phone_duplicate_warning'],
            ], array_slice($records, 0, 12)),
            '_headers' => $headers,
            '_fingerprint' => $fingerprint,
            '_records' => $records,
        ];
    }

    /** @param array<string, mixed>|null $providedMapping */
    public function import(
        UploadedFile $file,
        int $accountId,
        string $source,
        ?array $providedMapping,
        ?array $providedProductMapping,
        ?array $providedVariantMapping,
        ?array $providedCityMapping,
        int $brandSourceId,
        int $warehouseId,
        float $carrierPrice = 0,
    ): array {
        $preview = $this->preview(
            $file,
            $accountId,
            $source,
            $providedMapping,
            $providedProductMapping,
            $providedVariantMapping,
            $providedCityMapping,
        );
        if (($preview['mapping']['phone'] ?? null) === null) {
            throw new \InvalidArgumentException('Map a phone number column before importing orders.');
        }

        if (($preview['product_unresolved_count'] + $preview['variant_unresolved_count'] + $preview['city_unresolved_count']) > 0) {
            throw new \InvalidArgumentException('Resolve all products, variations, and cities in the preview before importing orders.');
        }

        $readyRecords = array_values(array_filter($preview['_records'], fn (array $record) => $record['state'] === 'ready'));
        if ($readyRecords === []) {
            throw new \InvalidArgumentException('No ready rows can be converted to orders.');
        }

        $imported = 0;
        $skippedDuplicates = 0;
        $skippedInvalid = 0;
        $phoneWarnings = 0;

        DB::transaction(function () use (
            $preview,
            $readyRecords,
            $accountId,
            $source,
            $file,
            $brandSourceId,
            $warehouseId,
            $carrierPrice,
            &$imported,
            &$skippedDuplicates,
            &$skippedInvalid,
            &$phoneWarnings,
        ) {
            foreach ($preview['_records'] as $record) {
                if ($record['phone_duplicate_warning']) {
                    $phoneWarnings++;
                }
                if ($record['state'] === 'duplicate') {
                    $skippedDuplicates++;
                } elseif ($record['state'] === 'invalid') {
                    $skippedInvalid++;
                }
            }

            $ordersPayload = [];
            foreach ($readyRecords as $record) {
                $externalId = $record['data']['external_id'];
                $ordersPayload[] = [
                    'account_id' => $accountId,
                    'brand_source_id' => $brandSourceId,
                    'warehouse_id' => $warehouseId,
                    'payment_type_id' => 1,
                    'payment_method_id' => 1,
                    'order_status_id' => 1,
                    'city_id' => $record['city_id'],
                    'carrier_price' => $carrierPrice,
                    'discount' => 0,
                    'type' => 'sale',
                    'sync_google_sheet' => true,
                    'note' => mb_substr(
                        'Imported ' . strtoupper($source) . ' lead' . ($externalId ? ' ' . $externalId : '') . ($record['product_title'] ? ' · ' . $record['product_title'] : ''),
                        0,
                        1000
                    ),
                    'customer' => [
                        'name' => $record['data']['name'] ?: 'Client',
                        'customer_type_id' => 1,
                        'phones' => [[
                            'title' => $record['data']['phone'],
                            'principal' => true,
                        ]],
                        'addresses' => [[
                            'title' => $record['data']['address'],
                            'city_id' => $record['city_id'],
                            'principal' => true,
                        ]],
                    ],
                    'products' => [[
                        'id' => $record['product_id'],
                        'quantity' => $record['quantity'],
                        'price' => $record['price'] ?? $record['product_price'],
                        'attributes' => $record['variant_attribute_ids'],
                    ]],
                ];
            }

            $response = OrderController::store(new HttpRequest($ordersPayload));
            $orderResponse = $response instanceof \Illuminate\Http\JsonResponse ? $response->getData(true) : [];
            if (($orderResponse['statut'] ?? 0) !== 1) {
                $message = $orderResponse['data'] ?? 'Orders could not be created.';
            if (is_string($message) && $message !== '') {
                throw new \InvalidArgumentException($message);
            }

            $messages = array_filter(array_map('strval', \Illuminate\Support\Arr::flatten($message)));
            throw new \InvalidArgumentException(
                $messages !== [] ? implode(' ', $messages) : 'Orders could not be created. Review the order details and try again.'
            );
            }

            $orderIds = array_values($orderResponse['data'] ?? []);
            if (count($orderIds) !== count($readyRecords)) {
                throw new \RuntimeException('The order service returned an unexpected number of order IDs.');
            }

            foreach ($readyRecords as $index => $record) {
                $lead = isset($record['existing_lead_id'])
                    ? Lead::query()->where('account_id', $accountId)->findOrFail($record['existing_lead_id'])
                    : new Lead();
                $lead->fill([
                    ...$record['data'],
                    'account_id' => $accountId,
                    'source' => $source,
                    'status' => 'converted',
                    'order_id' => (int) $orderIds[$index],
                    'imported_file' => mb_substr($file->getClientOriginalName(), 0, 255),
                ]);
                $lead->save();
                $imported++;
            }

            LeadImportMapping::query()->updateOrCreate(
                [
                    'account_id' => $accountId,
                    'source' => $source,
                    'fingerprint' => $preview['_fingerprint'],
                ],
                [
                    'headers' => $preview['_headers'],
                    'mapping' => $preview['mapping'],
                    'product_mapping' => $preview['product_mapping'],
                    'variant_mapping' => $preview['variant_mapping'],
                    'city_mapping' => $preview['city_mapping'],
                ]
            );
        });

        return [
            'imported' => $imported,
            'orders_created' => $imported,
            'skipped_duplicates' => $skippedDuplicates,
            'skipped_invalid' => $skippedInvalid,
            'phone_duplicate_warnings' => $phoneWarnings,
        ];
    }

    /** @return array{0: array<int, string>, 1: array<int, array<int, mixed>>} */
    private function readFile(UploadedFile $file): array
    {
        $delimiter = in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)
            ? $this->detectCsvDelimiter($file)
            : ',';

        $import = new class($delimiter) implements \Maatwebsite\Excel\Concerns\ToCollection, \Maatwebsite\Excel\Concerns\WithCustomCsvSettings {
            public function __construct(private readonly string $delimiter)
            {
            }

            public function collection(Collection $rows)
            {
            }

            public function getCsvSettings(): array
            {
                return ['delimiter' => $this->delimiter, 'input_encoding' => 'UTF-8'];
            }
        };

        $sheet = Excel::toCollection($import, $file)->first();
        if (! $sheet || $sheet->isEmpty()) {
            throw new \InvalidArgumentException('The file is empty or could not be read.');
        }

        $headers = [];
        foreach ($this->rowValues($sheet->first()) as $index => $value) {
            $header = trim($this->stringValue($value));
            $headers[] = $header !== '' ? $header : 'Column ' . ($index + 1);
        }
        if ($headers === []) {
            throw new \InvalidArgumentException('The first row must contain column headers.');
        }

        $rows = [];
        foreach ($sheet->slice(1) as $row) {
            $values = array_pad(array_slice($this->rowValues($row), 0, count($headers)), count($headers), null);
            if (collect($values)->every(fn ($value) => $this->stringValue($value) === '')) {
                continue;
            }
            $rows[] = $values;
        }

        return [$headers, $rows];
    }

    private function detectCsvDelimiter(UploadedFile $file): string
    {
        $line = '';
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle) {
            $line = (string) fgets($handle);
            fclose($handle);
        }

        $counts = [',' => 0, ';' => 0, "\t" => 0];
        $quoted = false;
        for ($i = 0, $length = strlen($line); $i < $length; $i++) {
            if ($line[$i] === '"') {
                if ($quoted && $i + 1 < $length && $line[$i + 1] === '"') {
                    $i++;
                } else {
                    $quoted = ! $quoted;
                }
            } elseif (! $quoted && array_key_exists($line[$i], $counts)) {
                $counts[$line[$i]]++;
            }
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /** @return array<int, mixed> */
    private function rowValues(mixed $row): array
    {
        return $row instanceof Collection ? $row->values()->all() : array_values((array) $row);
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_float($value) && abs($value) >= 100000000) {
            return sprintf('%.0f', $value);
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return trim(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /** @param array<int, string> $headers
     *  @return array<string, int|null>
     */
    private function detectMapping(array $headers): array
    {
        $aliases = [];
        foreach (self::FIELD_ALIASES as $field => $names) {
            $aliases[$field] = array_fill_keys(array_map(fn ($name) => $this->normalizeHeader($name), $names), true);
        }

        $mapping = array_fill_keys(array_keys(self::FIELD_ALIASES), null);
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader($header);
            foreach ($aliases as $field => $fieldAliases) {
                if ($mapping[$field] === null && $normalized !== '' && isset($fieldAliases[$normalized])) {
                    $mapping[$field] = $index;
                }
            }
        }

        return $mapping;
    }

    /** @param array<string, mixed> $mapping
     *  @return array<string, int|null>
     */
    private function sanitizeMapping(array $mapping, int $columnCount): array
    {
        $clean = array_fill_keys(array_keys(self::FIELD_ALIASES), null);
        foreach ($clean as $field => $_value) {
            $index = $mapping[$field] ?? null;
            if (is_numeric($index) && (int) $index >= 0 && (int) $index < $columnCount) {
                $clean[$field] = (int) $index;
            }
        }

        return $clean;
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim(str_replace("\u{FEFF}", '', $header)));
        $header = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $header);
        $header = strtr($header, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c']);
        $header = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $header) ?? $header;

        return preg_replace('/[^\pL\pN]+/u', '', $header) ?? $header;
    }

    /** @param array<int, string> $headers
     *  @param array<int, mixed> $values
     *  @param array<string, int|null> $mapping
     *  @return array<string, mixed>
     */
    private function mapRow(array $headers, array $values, array $mapping, int $rowNumber): array
    {
        $mapped = [];
        foreach ($mapping as $field => $index) {
            if ($index !== null) {
                $mapped[$field] = $this->stringValue($values[$index] ?? null);
            }
        }

        $rawData = [];
        foreach ($headers as $index => $header) {
            $value = $this->stringValue($values[$index] ?? null);
            if ($value === '') {
                continue;
            }
            $key = $header;
            $suffix = 2;
            while (array_key_exists($key, $rawData)) {
                $key = $header . ' (' . $suffix++ . ')';
            }
            $rawData[$key] = $value;
        }

        $phoneRaw = trim((string) ($mapped['phone'] ?? ''));
        $phone = $phoneRaw !== '' ? CustomerIdentityService::normalizePhone($phoneRaw) : '';
        $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
            $phone = '';
        }

        $metadata = [];
        foreach (self::FIELD_ALIASES as $field => $_aliases) {
            if (in_array($field, self::CORE_FIELDS, true)) {
                continue;
            }
            $value = trim((string) ($mapped[$field] ?? ''));
            if ($value !== '') {
                $metadata[$field] = $value;
            }
        }

        $externalId = trim((string) ($mapped['external_id'] ?? ''));
        $name = trim((string) ($mapped['name'] ?? ''));
        $email = trim((string) ($mapped['email'] ?? ''));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        $city = trim((string) ($mapped['city'] ?? ''));
        $address = trim((string) ($mapped['address'] ?? ''));
        $productKey = '';
        foreach (['product_reference', 'ad_name', 'campaign_name', 'adgroup_name'] as $candidate) {
            $candidateValue = trim((string) ($mapped[$candidate] ?? ''));
            if ($candidateValue !== '') {
                $productKey = $candidateValue;
                break;
            }
        }
        $quantityRaw = trim((string) ($mapped['quantity'] ?? ''));
        $quantityValue = $quantityRaw === '' ? 1 : (is_numeric($quantityRaw) ? (float) $quantityRaw : 0);
        $quantity = $quantityValue >= 1 && floor($quantityValue) === $quantityValue ? (int) $quantityValue : 0;
        $priceRaw = trim((string) ($mapped['price'] ?? ''));
        $normalizedPrice = str_replace(',', '.', $priceRaw);
        $priceInvalid = $priceRaw !== '' && ! is_numeric($normalizedPrice);
        $price = $priceRaw !== '' && ! $priceInvalid
            ? (float) $normalizedPrice
            : null;

        $state = 'ready';
        $message = 'ready';
        if ($phone === '') {
            $state = 'invalid';
            $message = $phoneRaw === '' ? 'missing_phone' : 'invalid_phone';
        } elseif ($address === '') {
            $state = 'invalid';
            $message = 'missing_address';
        } elseif ($quantity < 1) {
            $state = 'invalid';
            $message = 'invalid_quantity';
        } elseif ($priceInvalid) {
            $state = 'invalid';
            $message = 'invalid_price';
        } elseif ($price !== null && $price < 0) {
            $state = 'invalid';
            $message = 'invalid_price';
        }

        return [
            'row_number' => $rowNumber,
            'state' => $state,
            'message' => $message,
            'product_key' => $productKey,
            'quantity' => $quantity,
            'price' => $price,
            'phone_duplicate_warning' => false,
            'data' => [
                'external_id' => $externalId !== '' ? mb_substr($externalId, 0, 191) : null,
                'lead_date' => $this->parseDate($mapped['lead_date'] ?? null),
                'name' => $name !== '' ? mb_substr($name, 0, 255) : null,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? mb_substr($email, 0, 255) : null,
                'city' => $city !== '' ? mb_substr($city, 0, 255) : null,
                'address' => $address !== '' ? mb_substr($address, 0, 255) : null,
                'metadata' => $metadata ?: null,
                'raw_data' => $rawData ?: null,
            ],
        ];
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateTimeString();
        }
        if (is_numeric($value) && (float) $value >= 20000 && (float) $value < 100000) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateTimeString();
            } catch (Throwable) {
                return null;
            }
        }
        try {
            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    private function fingerprint(array $headers): string
    {
        $normalized = array_map(fn ($header) => $this->normalizeHeader($header), $headers);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /** @param array<int, array<string, mixed>> $records */
    private function classifyRows(array &$records, int $accountId, string $source): void
    {
        $externalIds = collect($records)->pluck('data.external_id')->filter()->unique()->values();
        $phones = collect($records)->pluck('data.phone')->filter()->unique()->values();

        $existingLeads = $externalIds->isEmpty()
            ? collect()
            : Lead::query()->where('account_id', $accountId)->where('source', $source)->whereIn('external_id', $externalIds)->get(['id', 'external_id', 'order_id'])->keyBy(fn (Lead $lead) => (string) $lead->external_id);
        $existingPhoneLeads = $phones->isEmpty()
            ? collect()
            : Lead::query()->where('account_id', $accountId)->where('source', $source)->whereIn('phone', $phones)->get(['id', 'phone', 'order_id'])->groupBy('phone');

        $seenIds = [];
        $seenFallbackPhones = [];
        $seenPhones = [];
        foreach ($records as &$record) {
            $externalId = $record['data']['external_id'];
            $phone = $record['data']['phone'];

            if ($phone !== null && ($existingPhoneLeads->has($phone) || isset($seenPhones[$phone]))) {
                $record['phone_duplicate_warning'] = true;
            }
            if ($phone !== null) {
                $seenPhones[$phone] = true;
            }
            if ($record['state'] === 'invalid') {
                continue;
            }

            if ($externalId !== null) {
                $existing = $existingLeads->get((string) $externalId);
                if ($existing && ! $existing->order_id && ! isset($seenIds[$externalId])) {
                    $record['existing_lead_id'] = (int) $existing->id;
                } elseif ($existing || isset($seenIds[$externalId])) {
                    $record['state'] = 'duplicate';
                    $record['message'] = 'duplicate_lead_id';
                }
                $seenIds[$externalId] = true;
                continue;
            }

            $existingPhone = $existingPhoneLeads->get($phone)?->first(fn (Lead $lead) => ! $lead->order_id);
            if ($existingPhone && ! isset($seenFallbackPhones[$phone])) {
                $record['existing_lead_id'] = (int) $existingPhone->id;
            } elseif ($existingPhoneLeads->has($phone) || isset($seenFallbackPhones[$phone])) {
                $record['state'] = 'duplicate';
                $record['message'] = 'duplicate_phone';
            }
            $seenFallbackPhones[$phone] = true;
        }
        unset($record);
    }
}
