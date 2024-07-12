<?php

namespace DTApi\Contracts\Repositories;

use Illuminate\Http\Request;

interface BookingRepositoryInterface extends BaseRepositoryInterface
{
    public function getUsersJobs($userId);

    public function getAll(Request $request, $limit = null);

    public function updateJob($id, array $data, $user);

    public function acceptJob(array $data, $user);

    public function acceptJobWithId($jobId, $user);

    public function store($user, $data);

    public function storeJobEmail($data);

    public function getUsersJobsHistory($user_id, Request $request);

    public function cancelJobAjax($data, $user);

    public function endJob($data);

    public function customerNotCall($post_data);

    public function getPotentialJobs($cuser);

    public function distanceFeed($post_data);

    public function reopen($data);

    public function jobToData($job);

    public function sendNotificationTranslator($job, $data = [], $exclude_user_id = []);

    public function sendSMSNotificationToTranslator($job);
}
