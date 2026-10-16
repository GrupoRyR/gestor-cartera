<?php

namespace App\Modules\Core\Http\Controllers\Portfolio;

use App\Modules\Core\Application\Activity\DeleteActivity;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use Illuminate\Http\RedirectResponse;

/**
 * Entries of a client's log (core.gestiones.*), removed from the pages of
 * other modules (the client page of Cartera), so every action goes back to
 * where it came from with the "status" flash.
 */
final class ActivityController
{
    /**
     * DELETE /gestiones/{activity}: soft delete, for portfolio managers and
     * analysts (core.activities.delete). {activity} finds only entries that
     * are not removed; one whose client is out of the user's reach is a 404.
     */
    public function destroy(ActivityRecord $activity, DeleteActivity $delete): RedirectResponse
    {
        try {
            $delete->handle($activity);
        } catch (RecordNotFound) {
            abort(404);
        }

        return back()->with('status', __('core::bitacora.mensajes.eliminada', ['resumen' => $activity->summary]));
    }
}
