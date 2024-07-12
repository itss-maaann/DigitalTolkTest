<?php

namespace DTApi\Contracts\Services;

use Illuminate\Http\Request;

interface BookingServiceInterface extends BaseServiceInterface
{
    public function getUserJobs(Request $request);

    public function getJobWithRelations($id);

    public function storeJob(Request $request);

    public function updateJob($id, Request $request);

    public function storeJobEmail(Request $request);

    public function getUserJobHistory(Request $request);

    public function acceptJob(Request $request);

    public function acceptJobWithId(Request $request);

    public function cancelJob(Request $request);

    public function endJob(Request $request);

    public function customerNotCall(Request $request);

    public function getPotentialJobs(Request $request);

    public function distanceFeed(Request $request);

    public function reopenJob(Request $request);

    public function resendNotifications(Request $request);

    public function resendSMSNotifications(Request $request);
}
