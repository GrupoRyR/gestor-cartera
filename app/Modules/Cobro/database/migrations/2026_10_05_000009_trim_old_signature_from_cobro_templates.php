<?php

use App\Modules\Cobro\Domain\Templates\OldSignature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Every Cobro mail now ends with the company's signature
 * (cobro::correos.firma), so the written signature at the end of a saved
 * template would appear twice. This cuts it off the saved texts
 * (cobro_templates.closing) only when it is exactly one of the old default
 * signatures (OldSignature). A template whose closing still says
 * "Cordialmente" otherwise was edited by someone: it stays as it is, with a
 * warning in the log and the console, to fix in /cobro/plantillas.
 *
 * It updates the rows directly: no audit entry and no change notice
 * (audit S-08), as this is not an edit of the texts by a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('cobro_templates')->orderBy('id')->get(['id', 'company', 'kind', 'closing']) as $template) {
            $closing = (string) $template->closing;
            $cut = OldSignature::cut($closing);

            if ($cut !== null) {
                DB::table('cobro_templates')->where('id', $template->id)->update(['closing' => $cut]);

                continue;
            }

            if (OldSignature::seemsSigned($closing)) {
                $context = ['company' => (string) $template->company, 'kind' => (string) $template->kind];
                Log::warning('Cobro: a saved template keeps a written signature that is not the old default; remove it in /cobro/plantillas.', $context);
                $this->warn("La plantilla {$context['kind']} de {$context['company']} conserva una firma escrita que alguien editó: quítala en /cobro/plantillas, porque el correo ya lleva la firma de la empresa.");
            }
        }
    }

    public function down(): void
    {
        // The cut signatures are not restored: the mails sign with cobro::correos.firma.
    }

    private function warn(string $message): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln("<comment>{$message}</comment>");
        }
    }
};
