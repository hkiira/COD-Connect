<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Some attribute titles imported from WooCommerce in March 2025 were stored as literal "?"
 * (Arabic and accented characters lost at insert time). The original name is still in the
 * attribute's meta JSON, so the title can be restored from it.
 *
 * Dry run by default; --apply writes the changes after saving a CSV backup of the old titles.
 */
class RepairCorruptedAttributeTitles extends Command
{
    protected $signature = 'catalog:repair-attribute-titles {--apply : Write the changes (default is a dry run)}';

    protected $description = 'Restore attribute titles damaged by a bad charset import, from the WooCommerce name kept in meta';

    public function handle(): int
    {
        $candidates = DB::table('attributes')
            ->where('title', 'like', '%?%')
            ->whereNotNull('meta')
            ->get(['id', 'title', 'meta']);

        $fixes = [];
        $unrecoverable = 0;

        foreach ($candidates as $attribute) {
            $name = json_decode($attribute->meta, true)['name'] ?? null;

            if (! is_string($name) || $name === '' || $name === $attribute->title || str_contains($name, '?')) {
                $unrecoverable++;
                continue;
            }

            $fixes[] = ['id' => $attribute->id, 'old' => $attribute->title, 'new' => $name];
        }

        $this->table(['id', 'stored', 'restored'], array_map(fn ($f) => [$f['id'], $f['old'], $f['new']], $fixes));
        $this->info(count($fixes) . ' title(s) can be restored, ' . $unrecoverable . ' have no usable original.');

        if (! $this->option('apply')) {
            $this->comment('Dry run. Re-run with --apply to write the changes.');

            return self::SUCCESS;
        }

        $backup = 'backups/attribute-titles-' . now()->format('Ymd-His') . '.csv';
        Storage::disk('local')->put($backup, implode("\n", array_map(fn ($f) => $f['id'] . ',"' . str_replace('"', '""', $f['old']) . '"', $fixes)));

        DB::transaction(function () use ($fixes) {
            foreach ($fixes as $fix) {
                DB::table('attributes')->where('id', $fix['id'])->update(['title' => $fix['new'], 'updated_at' => now()]);
            }
        });

        $this->info('Restored. Previous titles saved to storage/app/' . $backup);

        return self::SUCCESS;
    }
}
