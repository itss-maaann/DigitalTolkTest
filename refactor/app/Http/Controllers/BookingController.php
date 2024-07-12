<?php

namespace DTApi\Http\Controllers;

use Illuminate\Http\Request;
use DTApi\Contracts\Services\BookingServiceInterface;

class BookingController extends Controller
{
    protected $bookingService;

    public function __construct(BookingServiceInterface $bookingService)
    {
        $this->bookingService = $bookingService;
    }

    public function index(Request $request)
    {
        $response = $this->bookingService->getUserJobs($request);
        return response()->json($response);
    }

    public function show($id)
    {
        $job = $this->bookingService->getJobWithRelations($id);
        return response()->json($job);
    }

    public function store(Request $request)
    {
        $response = $this->bookingService->storeJob($request);
        return response()->json($response);
    }

    public function update($id, Request $request)
    {
        $response = $this->bookingService->updateJob($id, $request);
        return response()->json($response);
    }

    public function immediateJobEmail(Request $request)
    {
        $response = $this->bookingService->storeJobEmail($request);
        return response()->json($response);
    }

    public function getHistory(Request $request)
    {
        $response = $this->bookingService->getUserJobHistory($request);
        return response()->json($response);
    }

    public function acceptJob(Request $request)
    {
        $response = $this->bookingService->acceptJob($request);
        return response()->json($response);
    }

    public function acceptJobWithId(Request $request)
    {
        $response = $this->bookingService->acceptJobWithId($request);
        return response()->json($response);
    }

    public function cancelJob(Request $request)
    {
        $response = $this->bookingService->cancelJob($request);
        return response()->json($response);
    }

    public function endJob(Request $request)
    {
        $response = $this->bookingService->endJob($request);
        return response()->json($response);
    }

    public function customerNotCall(Request $request)
    {
        $response = $this->bookingService->customerNotCall($request);
        return response()->json($response);
    }

    public function getPotentialJobs(Request $request)
    {
        $response = $this->bookingService->getPotentialJobs($request);
        return response()->json($response);
    }

    public function distanceFeed(Request $request)
    {
        $response = $this->bookingService->distanceFeed($request);
        return response()->json($response);
    }

    public function reopen(Request $request)
    {
        $response = $this->bookingService->reopenJob($request);
        return response()->json($response);
    }

    public function resendNotifications(Request $request)
    {
        $response = $this->bookingService->resendNotifications($request);
        return response()->json($response);
    }

    public function resendSMSNotifications(Request $request)
    {
        $response = $this->bookingService->resendSMSNotifications($request);
        return response()->json($response);
    }
}