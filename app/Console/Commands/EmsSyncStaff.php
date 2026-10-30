<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * FR-30: staff accounts linked to an EMS employee_no follow EMS nightly.
 * EMS has no Admission roles, so roles stay with the Admin; EMS only says
 * who still works here. Inactive or unknown in EMS → account disabled; the
 * name follows EMS. Never re-enables (the Admin may have disabled it).
 */
class EmsSyncStaff extends Command
{
    protected $signature = 'ems:sync-staff';

    protected $description = 'Disable staff accounts whose employee is inactive or gone in EMS; keep names in step';

    public function handle(AuditLogger $audit): int
    {
        if (! config('admission.ems.base_url')) {
            $this->info('EMS is not connected (EMS_BASE_URL is empty).');

            return self::SUCCESS;
        }

        $ems = Http::baseUrl(config('admission.ems.base_url'))->withToken((string) config('admission.ems.token'))
            ->acceptJson()->timeout((int) config('admission.ems.timeout'))->retry([1000, 4000, 16000], when: fn ($e) => ! $e instanceof RequestException || $e->response->serverError() || $e->response->status() === 429, throw: false);
        $disabled = $renamed = 0;

        foreach (User::whereNotNull('employee_id')->where('is_active', true)->get() as $user) {
            $response = $ems->get('/employees/'.rawurlencode($user->employee_id));
            if ($response->failed() && $response->status() !== 404) {
                // An EMS outage must never lock staff out: stop, try again next night
                $this->error("EMS answered {$response->status()} for {$user->employee_id}; stopped.");
                report(new RequestException($response));

                return self::FAILURE;
            }

            $employee = $response->status() === 404 ? null : $response->json('data');
            if (! $employee || $employee['status'] === 'inactive') {
                $user->forceFill(['is_active' => false])->save();
                $audit->record('account.disabled', "Disabled {$user->email}: employee {$user->employee_id} is ".($employee ? 'inactive' : 'not found').' in EMS', $user,
                    meta: ['before' => ['active' => true], 'after' => ['active' => false]], actorName: 'API · ems');
                $disabled++;
            } elseif ($employee['full_name'] !== $user->name) {
                $audit->record('account.updated', "Renamed {$user->email} from EMS", $user,
                    meta: ['before' => ['name' => $user->name], 'after' => ['name' => $employee['full_name']]], actorName: 'API · ems');
                $user->forceFill(['name' => $employee['full_name']])->save();
                $renamed++;
            }
        }

        $this->info("EMS sync: {$disabled} disabled, {$renamed} renamed.");

        return self::SUCCESS;
    }
}
