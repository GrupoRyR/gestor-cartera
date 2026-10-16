<?php

namespace App\Modules\Ingesta\Console;

use App\Modules\Ingesta\Http\Controllers\ManualUploadController;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * ingesta:limpiar-cargas-manuales: deletes the manual cartera uploads that
 * nobody confirmed or discarded, i.e. the files directly under the upload
 * directory (ManualUploadController::DISK and ::DIRECTORY) last modified
 * more than ingesta.manual_upload_ttl_days days ago. Subdirectories and
 * anything outside that directory are never touched.
 * IngestaServiceProvider schedules it every day at 03:00 in Bogotá;
 * running it again by hand is safe.
 */
final class PruneManualUploadsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ingesta:limpiar-cargas-manuales';

    /**
     * @var string
     */
    protected $description = 'Borra las cargas manuales de cartera que nadie confirmó ni descartó';

    public function handle(): int
    {
        $disk = Storage::disk(ManualUploadController::DISK);
        // At least a day, so a misconfigured 0 never deletes an upload
        // someone is looking at.
        $days = max(1, (int) config('ingesta.manual_upload_ttl_days', 7));
        $cutoff = CarbonImmutable::now()->subDays($days)->getTimestamp();
        $deleted = 0;

        foreach ($disk->files(ManualUploadController::DIRECTORY) as $path) {
            if ($disk->lastModified($path) < $cutoff && $disk->delete($path)) {
                $deleted++;
            }
        }

        $this->info(trans_choice('ingesta::manual.limpieza.borradas', $deleted, ['count' => $deleted]));

        return self::SUCCESS;
    }
}
