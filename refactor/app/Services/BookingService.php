<?php

namespace DTApi\Services;

use Illuminate\Http\Request;
use DTApi\Contracts\Services\BookingServiceInterface;
use DTApi\Contracts\Repositories\BookingRepositoryInterface;

class BookingService extends BaseService implements BookingServiceInterface
{
    protected $bookingRepository;

    public function __construct(BookingRepositoryInterface $bookingRepository)
    {
        $this->bookingRepository = $bookingRepository;
    }

    public function getUserJobs(Request $request)
    {
        $user_id = $request->get('user_id');
        $user_type = $request->__authenticatedUser->user_type;

        if ($user_id) {
            return $this->bookingRepository->getUsersJobs($user_id);
        } elseif (in_array($user_type, [env('ADMIN_ROLE_ID'), env('SUPERADMIN_ROLE_ID')])) {
            return $this->bookingRepository->getAll($request);
        }

        return [];
    }

    public function getJobWithRelations($id)
    {
        return $this->bookingRepository->with('translatorJobRel.user')->find($id);
    }

    public function storeJob(Request $request)
    {
        $data = $request->all();
        return $this->bookingRepository->store($request->__authenticatedUser, $data);
    }

    public function updateJob($id, Request $request)
    {
        $data = $request->all();
        $cuser = $request->__authenticatedUser;
        return $this->bookingRepository->updateJob($id, $data, $cuser);
    }

    public function storeJobEmail(Request $request)
    {
        return $this->bookingRepository->storeJobEmail($request->all());
    }

    public function getUserJobHistory(Request $request)
    {
        $user_id = $request->get('user_id');
        return $this->bookingRepository->getUsersJobsHistory($user_id, $request);
    }

    public function acceptJob(Request $request)
    {
        return $this->bookingRepository->acceptJob($request->all(), $request->__authenticatedUser);
    }

    public function acceptJobWithId(Request $request)
    {
        return $this->bookingRepository->acceptJobWithId($request->get('job_id'), $request->__authenticatedUser);
    }

    public function cancelJob(Request $request)
    {
        return $this->bookingRepository->cancelJobAjax($request->all(), $request->__authenticatedUser);
    }

    public function endJob(Request $request)
    {
        return $this->bookingRepository->endJob($request->all());
    }

    public function customerNotCall(Request $request)
    {
        return $this->bookingRepository->customerNotCall($request->all());
    }

    public function getPotentialJobs(Request $request)
    {
        return $this->bookingRepository->getPotentialJobs($request->__authenticatedUser);
    }

    public function distanceFeed(Request $request)
    {
        return $this->bookingRepository->distanceFeed($request->all());
    }

    public function reopenJob(Request $request)
    {
        return $this->bookingRepository->reopen($request->all());
    }

    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->bookingRepository->find($data['jobid']);
        $job_data = $this->bookingRepository->jobToData($job);
        $this->bookingRepository->sendNotificationTranslator($job, $job_data, '*');
        return ['success' => 'Push sent'];
    }

    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->bookingRepository->find($data['jobid']);
        $job_data = $this->bookingRepository->jobToData($job);

        try {
            $this->bookingRepository->sendSMSNotificationToTranslator($job);
            return ['success' => 'SMS sent'];
        } catch (\Exception $e) {
            return ['success' => $e->getMessage()];
        }
    }
}
