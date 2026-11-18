<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadImportMapping;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class LeadImportService
{
    private const MAX_ROWS = 5000;

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
        'appointment_scheduled' => ['appointment scheduled', 'appointment', 'rendez-vous', 'موعد'],
        'ai_overview' => ['ai overview', 'generated overview', 'نظرة عامة منشأة بالذكاء الاصطناعي'],
    ];

    private const CORE_FIELDS = ['external_id', 'lead_date', 'name', 'phone', 'email', 'city', 'address'];

    /** @param array<string, mixed>|null $providedMapping */
    public function preview(UploadedFile $file, int $accountId, string $source, ?array $providedMapping = null): array
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

        $counts = ['ready' => 0, 'duplicate' => 0, 'invalid' => 0, 'phone_warnings' => 0];
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
            'phone_duplicate_count' => $counts['phone_warnings'],
            'preview_rows' => array_map(fn (array $record) => [
                'row_number' => $record['row_number'],
                'name' => $record['data']['name'],
                'phone' => $record['data']['phone'],
                'city' => $record['data']['city'],
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
    public function import(UploadedFile $file, int $accountId, string $source, ?array $providedMapping = null): array
    {
        $preview = $this->preview($file, $accountId, $source, $providedMapping);
        if (($preview['mapping']['phone'] ?? null) === null) {
            throw new \InvalidArgumentException('Map a phone number column before importing leads.');
        }

        $imported = 0;
        $skippedDuplicates = 0;
        $skippedInvalid = 0;
        $phoneWarnings = 0;

        DB::transaction(function () use ($preview, $accountId, $source, $file, &$imported, &$skippedDuplicates, &$skippedInvalid, &$phoneWarnings) {
            foreach ($preview['_records'] as $record) {
                if ($record['phone_duplicate_warning']) {
                    $phoneWarnings++;
                }
                if ($record['state'] === 'duplicate') {
                    $skippedDuplicates++;
                    continue;
                }
                if ($record['state'] === 'invalid') {
                    $skippedInvalid++;
                    continue;
                }

                Lead::create([
                    ...$record['data'],
                    'account_id' => $accountId,
                    'source' => $source,
                    'status' => 'new',
                    'imported_file' => mb_substr($file->getClientOriginalName(), 0, 255),
                ]);
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
                ]
            );
        });

        return [
            'imported' => $imported,
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

        return [
            'row_number' => $rowNumber,
            'state' => $phone === '' ? 'invalid' : 'ready',
            'message' => $phone === '' ? ($phoneRaw === '' ? 'missing_phone' : 'invalid_phone') : 'ready',
            'phone_duplicate_warning' => false,
            'data' => [
                'external_id' => $externalId !== '' ? mb_substr($externalId, 0, 191) : null,
                'lead_date' => $this->parseDate($mapped['lead_date'] ?? null),
                'name' => $name !== '' ? mb_substr($name, 0, 255) : null,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? mb_substr($email, 0, 255) : null,
                'city' => $city !== '' ? mb_substr($city, 0, 255) : null,
                'address' => $address !== '' ? $address : null,
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

        $existingIds = $externalIds->isEmpty()
            ? collect()
            : Lead::query()->where('account_id', $accountId)->where('source', $source)->whereIn('external_id', $externalIds)->pluck('external_id')->map(fn ($id) => (string) $id)->flip();
        $existingPhones = $phones->isEmpty()
            ? collect()
            : Lead::query()->where('account_id', $accountId)->where('source', $source)->whereIn('phone', $phones)->pluck('phone')->map(fn ($phone) => (string) $phone)->flip();

        $seenIds = [];
        $seenFallbackPhones = [];
        $seenPhones = [];
        foreach ($records as &$record) {
            $externalId = $record['data']['external_id'];
            $phone = $record['data']['phone'];

            if ($phone !== null && (isset($existingPhones[$phone]) || isset($seenPhones[$phone]))) {
                $record['phone_duplicate_warning'] = true;
            }
            if ($phone !== null) {
                $seenPhones[$phone] = true;
            }
            if ($record['state'] === 'invalid') {
                continue;
            }

            if ($externalId !== null) {
                if (isset($existingIds[$externalId]) || isset($seenIds[$externalId])) {
                    $record['state'] = 'duplicate';
                    $record['message'] = 'duplicate_lead_id';
                }
                $seenIds[$externalId] = true;
                continue;
            }

            if (isset($existingPhones[$phone]) || isset($seenFallbackPhones[$phone])) {
                $record['state'] = 'duplicate';
                $record['message'] = 'duplicate_phone';
            }
            $seenFallbackPhones[$phone] = true;
        }
        unset($record);
    }
}
