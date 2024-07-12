<?php

namespace DTApi\Repository;

use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use DTApi\Models\Translator;
use DTApi\Events\SessionEnded;
use DTApi\Events\JobWasCreated;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCanceled;
use DTApi\Helpers\SendSMSHelper;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Http\Request;
use DTApi\Contracts\Repositories\BookingRepositoryInterface;

class BookingRepository extends BaseRepository implements BookingRepositoryInterface
{
    protected $mailer;
    protected $logger;

    public function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model); //Job model is already injected via the constructor and passed to the BaseRepository, so we shound avoind instantiatance of job model thorughout the class again and again
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
    
        if (!$cuser) {
            return ['emergencyJobs' => [], 'normalJobs' => [], 'cuser' => null, 'usertype' => ''];
        }
    
        $jobs = $this->getUserJobs($cuser);
        $usertype = $cuser->is('customer') ? 'customer' : 'translator';
    
        $categorizedJobs = $this->categorizeJobs($jobs, $user_id);
    
        return [
            'emergencyJobs' => $categorizedJobs['emergencyJobs'],
            'normalJobs' => $categorizedJobs['normalJobs'],
            'cuser' => $cuser,
            'usertype' => $usertype
        ];
    }
    
    private function getUserJobs($cuser)
    {
        if ($cuser->is('customer')) {
            return $cuser->jobs()
                         ->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback'])
                         ->whereIn('status', ['pending', 'assigned', 'started'])
                         ->orderBy('due', 'asc')
                         ->get();
        }
    
        if ($cuser->is('translator')) {
            return $this->model->getTranslatorJobs($cuser->id, 'new')->pluck('jobs')->all();
        }
    
        return collect();
    }
    
    private function categorizeJobs($jobs, $user_id)
    {
        $emergencyJobs = [];
        $normalJobs = [];
    
        foreach ($jobs as $job) {
            if ($job->immediate == 'yes') {
                $emergencyJobs[] = $job;
            } else {
                $normalJobs[] = $job;
            }
        }
    
        $normalJobs = collect($normalJobs)->each(function ($item) use ($user_id) {
            $item['usercheck'] = $this->model->checkParticularJob($user_id, $item);
        })->sortBy('due')->all();
    
        return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs];
    }
    

    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page', 1);
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];
        
        if (!$cuser) {
            return ['status' => 'fail', 'message' => 'User not found'];
        }
    
        if ($cuser->is('customer')) {
            $jobs = $this->getCustomerJobsHistory($cuser);
            $usertype = 'customer';
            return $this->formatJobsHistoryResponse($emergencyJobs, $normalJobs, $jobs, $cuser, $usertype, $page);
        } 
    
        if ($cuser->is('translator')) {
            $jobs_ids = $this->getTranslatorJobsHistory($cuser, $page);
            $usertype = 'translator';
            return $this->formatJobsHistoryResponse($emergencyJobs, $jobs_ids, $jobs_ids, $cuser, $usertype, $page, $jobs_ids->total());
        }
    
        return ['status' => 'fail', 'message' => 'Invalid user type'];
    }
    
    private function getCustomerJobsHistory($cuser)
    {
        return $cuser->jobs()
                     ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                     ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                     ->orderBy('due', 'desc')
                     ->paginate(15);
    }
    
    private function getTranslatorJobsHistory($cuser, $page)
    {
        return $this->model->getTranslatorJobsHistoric($cuser->id, 'historic', $page);
    }
    
    private function formatJobsHistoryResponse($emergencyJobs, $normalJobs, $jobs, $cuser, $usertype, $page, $totalJobs = null)
    {
        $numPages = $totalJobs ? ceil($totalJobs / 15) : $jobs->lastPage();
        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'jobs' => $jobs,
            'cuser' => $cuser,
            'usertype' => $usertype,
            'numpages' => $numPages,
            'pagenum' => $page
        ];
    }    

    public function store($user, $data)
    {
        $this->validateBookingData($data);
    
        if ($user->user_type != env('CUSTOMER_ROLE_ID')) {
            return ['status' => 'fail', 'message' => "Translator cannot create booking"];
        }
    
        $data = $this->prepareJobData($data, $user);
        $job = $user->jobs()->create($data);
    
        $this->handleJobCreation($job, $data, $user);
    
        return ['status' => 'success', 'id' => $job->id];
    }
    
    private function validateBookingData($data)
    {
        $rules = [
            'from_language_id' => 'required|integer',
            'immediate' => 'required|in:yes,no',
            'due_date' => 'required_if:immediate,no|date_format:m/d/Y',
            'due_time' => 'required_if:immediate,no|date_format:H:i',
            'customer_phone_type' => 'required_without:customer_physical_type|in:yes,no',
            'customer_physical_type' => 'required_without:customer_phone_type|in:yes,no',
            'duration' => 'required|integer',
            'job_for' => 'required|array',
            'job_for.*' => 'in:male,female,normal,certified,certified_in_law,certified_in_health'
        ];
    
        $messages = [
            'from_language_id.required' => 'You must fill in all fields',
            'immediate.required' => 'You must specify if the job is immediate',
            'due_date.required_if' => 'You must provide a due date for non-immediate jobs',
            'due_time.required_if' => 'You must provide a due time for non-immediate jobs',
            'customer_phone_type.required_without' => 'You must choose either phone or physical type',
            'customer_physical_type.required_without' => 'You must choose either phone or physical type',
            'duration.required' => 'You must specify the job duration',
            'job_for.required' => 'You must specify job requirements',
            'job_for.*.in' => 'Invalid value for job requirements'
        ];
    
        $validator = Validator::make($data, $rules, $messages);
    
        if ($validator->fails()) {
            $errors = $validator->errors();
            throw new \Exception($errors->first());
        }
    }
    
    private function prepareJobData($data, $user)
    {
        $immediatetime = 5;
    
        if ($data['immediate'] == 'yes') {
            $due_carbon = Carbon::now()->addMinutes($immediatetime);
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
            $data['customer_phone_type'] = 'yes';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            if ($due_carbon->isPast()) {
                throw new \Exception("Cannot create booking in the past");
            }
            $data['due'] = $due_carbon->format('Y-m-d H:i:s');
        }
    
        $data['b_created_at'] = Carbon::now()->format('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($data['due'], $data['b_created_at']);
        $data['by_admin'] = $data['by_admin'] ?? 'no';
    
        $data['gender'] = $this->determineGender($data['job_for']);
        $data['certified'] = $this->determineCertification($data['job_for']);
        $data['job_type'] = $this->determineJobType($user->userMeta->consumer_type);
    
        return $data;
    }
    
    private function determineGender($job_for)
    {
        if (in_array('male', $job_for)) {
            return 'male';
        } elseif (in_array('female', $job_for)) {
            return 'female';
        }
        return null;
    }
    
    private function determineCertification($job_for)
    {
        if (in_array('certified_in_law', $job_for)) {
            return 'law';
        } elseif (in_array('certified_in_health', $job_for)) {
            return 'health';
        } elseif (in_array('certified', $job_for)) {
            return 'yes';
        } elseif (in_array('normal', $job_for)) {
            return 'normal';
        }
        return null;
    }
    
    private function handleJobCreation($job, $data, $user)
    {
        $data['job_for'] = [];
    
        if ($job->gender) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }
        if ($job->certified) {
            $data['job_for'][] = $this->getCertification($job->certified);
        }
    
        $data['customer_town'] = $user->userMeta->city;
        $data['customer_type'] = $user->userMeta->customer_type;
    }
    
    private function getCertification($certified)
    {
        return match ($certified) {
            'both' => ['normal', 'certified'],
            'yes' => 'certified',
            'law' => 'certified_in_law',
            'health' => 'certified_in_health',
            default => $certified
        };
    } 

    public function storeJobEmail($data)
    {
        $job = $this->model->findOrFail($data['user_email_job_id']);
        $user = $job->user()->first();
    
        $updateData = [
            'user_email' => $data['user_email'] ?? $job->user_email,
            'reference' => $data['reference'] ?? '',
            'address' => $data['address'] ?? $user->userMeta->address,
            'instructions' => $data['instructions'] ?? $user->userMeta->instructions,
            'town' => $data['town'] ?? $user->userMeta->city,
        ];
    
        $job->update($updateData);
    
        $this->sendJobEmail($job, $user);
    
        return ['type' => $data['user_type'], 'job' => $job, 'status' => 'success'];
    }
    
    private function sendJobEmail($job, $user)
    {
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'We have received your booking. Booking No: #' . $job->id;
        $send_data = ['user' => $user, 'job' => $job];
    
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);
    
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));
    }
    
    public function jobToData($job)
    {
        $due_date = explode(" ", $job->due);
    
        return [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
            'due_date' => $due_date[0],
            'due_time' => $due_date[1],
            'job_for' => $this->getJobForArray($job)
        ];
    }
    
    private function getJobForArray($job)
    {
        $job_for = [];
        if ($job->gender) {
            $job_for[] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }
        if ($job->certified) {
            $job_for[] = match ($job->certified) {
                'both' => ['normal', 'certified'],
                'yes' => 'certified',
                'law' => 'certified_in_law',
                'health' => 'certified_in_health',
                default => $job->certified
            };
        }
        return $job_for;
    }

    public function acceptJob($data, $user)
    {
        $job = $this->model->findOrFail($data['job_id']);
    
        if (!$this->isJobAvailableForUser($job, $user)) {
            return ['status' => 'fail', 'message' => 'Booking already accepted by someone else'];
        }
    
        if ($job->status === 'pending' && $this->model->insertTranslatorJobRel($user->id, $job->id)) {
            $job->update(['status' => 'assigned']);
            $this->sendJobAcceptedEmail($job);
    
            $jobs = $this->getPotentialJobs($user);
    
            return ['list' => json_encode(['jobs' => $jobs, 'job' => $job], true), 'status' => 'success'];
        } else {
            return ['status' => 'fail', 'message' => 'You already have a booking at that time!'];
        }
    }
    
    private function isJobAvailableForUser($job, $user)
    {
        return !$this->model->isTranslatorAlreadyBooked($job->id, $user->id, $job->due);
    }
    
    private function sendJobAcceptedEmail($job)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Confirmation - Translator has accepted your booking (Booking # ' . $job->id . ')';
        $data = ['user' => $user, 'job' => $job];
    
        $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
    }
    
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = $this->determineJobType($cuser_meta->translator_type);
        $languages = UserLanguages::where('user_id', $cuser->id)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
    
        $job_ids = $this->model->getJobs($cuser->id, $job_type, 'pending', $languages, $gender, $translator_level);
    
        return $job_ids->filter(function ($job) use ($cuser) {
            return $this->isJobEligibleForUser($cuser, $job);
        })->values();
    }
    
    private function determineJobType($translator_type)
    {
        return match ($translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            default => 'unpaid',
        };
    }

    public function cancelJobAjax($data, $user)
    {
        $job_id = $data['job_id'];
        $job = $this->model->findOrFail($job_id);
        $translator = $this->model->getJobsAssignedTranslatorDetail($job);

        if ($user->is('customer')) {
            return $this->cancelJobByCustomer($job, $translator);
        } else {
            return $this->cancelJobByTranslator($job, $translator);
        }
    }

    private function cancelJobByCustomer($job, $translator)
    {
        $job->update(['withdraw_at' => Carbon::now()]);

        if ($job->withdraw_at->diffInHours($job->due) >= 24) {
            $job->update(['status' => 'withdrawbefore24']);
        } else {
            $job->update(['status' => 'withdrawafter24']);
        }

        Event::fire(new JobWasCanceled($job));
        $this->sendCancelNotificationToTranslator($job, $translator);
        return ['status' => 'success', 'jobstatus' => 'success'];
    }

    private function cancelJobByTranslator($job, $translator)
    {
        if ($job->due->diffInHours(Carbon::now()) > 24) {
            $job->update(['status' => 'pending', 'created_at' => date('Y-m-d H:i:s'), 'will_expire_at' => TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'))]);
            $this->model->deleteTranslatorJobRel($translator->id, $job->id);
            $this->sendNotificationTranslator($job, $this->jobToData($job), $translator->id);
            return ['status' => 'success'];
        } else {
            return ['status' => 'fail', 'message' => 'Cannot cancel a booking within 24 hours via DigitalTolk. Please call us.'];
        }
    }

    private function sendCancelNotificationToTranslator($job, $translator)
    {
        if ($translator) {
            $data = ['notification_type' => 'job_cancelled'];
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $msg_text = ["en" => 'The customer has cancelled the booking for ' . $language . ' translator, ' . $job->duration . ' min, ' . $job->due . '. Please check your previous bookings for details.'];
            $this->sendPushNotificationToSpecificUsers([$translator], $job->id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));
        }
    }

    private function isJobEligibleForUser($user, $job)
    {
        $is_specific_job = $this->model->assignedToPaticularTranslator($user->id, $job->id);
        $can_accept_job = $this->model->checkParticularJob($user->id, $job);
        $is_in_same_town = $this->model->checkTowns($job->user_id, $user->id);

        return ($is_specific_job != 'SpecificJob' || $can_accept_job != 'userCanNotAcceptJob') && ($job->customer_physical_type != 'yes' || $is_in_same_town);
    }

    public function endJob($data)
    {
        $completed_date = Carbon::now();
        $job = $this->model->with('translatorJobRel')->findOrFail($data["job_id"]);
        $job->update(['end_at' => $completed_date, 'status' => 'completed', 'session_time' => $this->calculateJobDuration($job->due, $completed_date)]);

        $this->sendJobCompletionEmail($job, $data['user_id']);
        $this->updateTranslatorJobRel($job, $completed_date, $data['user_id']);
        return ['status' => 'success'];
    }

    private function calculateJobDuration($start, $end)
    {
        $diff = date_diff(date_create($end), date_create($start));
        return $diff->h . ':' . $diff->i . ':' . $diff->s;
    }

    private function sendJobCompletionEmail($job, $user_id)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_time = $this->formatSessionTime($job->session_time);

        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $session_time,
            'for_text' => 'faktura'
        ];
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $translator = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first()->user()->first();
        $data['for_text'] = 'lön';
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.session-ended', $data);
    }

    private function formatSessionTime($session_time)
    {
        $session_explode = explode(':', $session_time);
        return $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
    }

    private function updateTranslatorJobRel($job, $completed_date, $user_id)
    {
        $translator_job_rel = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
        $translator_job_rel->update(['completed_at' => $completed_date, 'completed_by' => $user_id]);
    }

    public function updateJob($id, array $data)
    {
        $job = $this->model->find($id);

        $current_translator = $job->translatorJobRel->where('cancel_at', null)->first();
        if (is_null($current_translator))
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', null)->first();

        $log_data = [];

        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->info('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
        }
    }

    public function acceptJobWithId($job_id, $cuser)
    {
        $job = $this->model->findOrFail($job_id);
        $response = [];
    
        if ($this->model->isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            return $this->generateFailResponse('Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning');
        }

        $language = '';
    
        if ($job->status !== 'pending' || !$this->model->insertTranslatorJobRel($cuser->id, $job_id)) {
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            return $this->generateFailResponse('Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning');
        }
    
        $job->update(['status' => 'assigned']);
    
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
    
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $data = ['user' => $user, 'job' => $job];
    
        $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
    
        $this->sendJobAcceptedNotification($job, $user);
    
        return $this->generateSuccessResponse($job, $language);
    }
    
    private function generateFailResponse($message)
    {
        return ['status' => 'fail', 'message' => $message];
    }
    
    private function generateSuccessResponse($job, $language)
    {
        return [
            'status' => 'success',
            'list' => ['job' => $job],
            'message' => 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due
        ];
    }
    
    private function sendJobAcceptedNotification($job, $user)
    {
        $data = ['notification_type' => 'job_accepted'];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
        ];
    
        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }
    

    public function customerNotCall($data)
    {
        $completed_date = date('Y-m-d H:i:s');
        $job_id = $data["job_id"];
        $job_detail = $this->model->with('translatorJobRel')->findOrFail($job_id);
        $job_detail->status = 'not_carried_out_customer';
        $job_detail->admin_comments = $data["admincomment"];
        $job_detail->save();

        $user = $job_detail->user()->get()->first();
        $mailer = new AppMailer();

        $subject = 'Ej utförd tolkning för bokning # ' . $job_detail->id;
        $data = [
            'user' => $user,
            'job'  => $job_detail,
            'session_time' => $completed_date
        ];

        $email = $user->email;
        $name = $user->name;
        $mailer->send($email, $name, $subject, 'emails.job-not-carried-out-customer', $data);

        $translatorJobRel = $job_detail->translatorJobRel->where('completed_at', NULL)->where('cancel_at', NULL)->first();
        $translator = $translatorJobRel->user()->first();
        $email = $translator->email;
        $name = $translator->name;
        $subject = 'Information om ej utförd tolkning för bokning # ' . $job_detail->id;
        $data = [
            'user' => $translator,
            'job'  => $job_detail
        ];
        $mailer->send($email, $name, $subject, 'emails.job-not-carried-out-translator', $data);

        Event::fire(new SessionEnded($job_detail, ($data["sesion_time"] == '' ? 0 : $data["sesion_time"])));
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestData = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumerType = $cuser->consumer_type;
        $userType = $cuser->user_type;

        $allJobs = $this->model->query();

        if ($this->isSuperAdmin($userType)) {
            $this->applySuperAdminFilters($allJobs, $requestData);

            if (isset($requestData['count']) && $requestData['count'] == 'true') {
                return ['count' => $allJobs->count()];
            }

            $allJobs = $this->finalizeQuery($allJobs, $limit);
        } else {
            $this->applyRegularUserFilters($allJobs, $requestData, $consumerType);
            
            if (isset($requestData['count']) && $requestData['count'] != 'false') {
                return ['count' => $allJobs->count()];
            }

            $allJobs = $this->finalizeQuery($allJobs, $limit);
        }

        return $allJobs;
    }

    private function isSuperAdmin($userType)
    {
        return $userType == env('SUPERADMIN_ROLE_ID');
    }

    private function applySuperAdminFilters($allJobs, $requestData)
    {
        $this->applyCommonFilters($allJobs, $requestData);

        if (isset($requestData['feedback']) && $requestData['feedback'] != 'false') {
            $allJobs->where('ignore_feedback', '0')
                    ->whereHas('feedback', function ($q) {
                        $q->where('rating', '<=', '3');
                    });
        }

        if (isset($requestData['customer_email']) && !empty($requestData['customer_email'])) {
            $users = DB::table('users')->whereIn('email', $requestData['customer_email'])->get();
            if ($users->isNotEmpty()) {
                $allJobs->whereIn('user_id', $users->pluck('id'));
            }
        }

        if (isset($requestData['translator_email']) && !empty($requestData['translator_email'])) {
            $users = DB::table('users')->whereIn('email', $requestData['translator_email'])->get();
            if ($users->isNotEmpty()) {
                $allJobIDs = DB::table('translator_job_rel')
                            ->whereNull('cancel_at')
                            ->whereIn('user_id', $users->pluck('id'))
                            ->pluck('job_id');
                $allJobs->whereIn('id', $allJobIDs);
            }
        }

        if (isset($requestData['salary']) && $requestData['salary'] == 'yes') {
            $allJobs->whereDoesntHave('user.salaries');
        }

        if (isset($requestData['consumer_type']) && $requestData['consumer_type'] != '') {
            $allJobs->whereHas('user.userMeta', function ($q) use ($requestData) {
                $q->where('consumer_type', $requestData['consumer_type']);
            });
        }
    }

    private function applyRegularUserFilters($allJobs, $requestData, $consumerType)
    {
        $this->applyCommonFilters($allJobs, $requestData);

        if ($consumerType == 'RWS') {
            $allJobs->where('job_type', 'rws');
        } else {
            $allJobs->where('job_type', 'unpaid');
        }

        if (isset($requestData['customer_email']) && $requestData['customer_email'] != '') {
            $user = DB::table('users')->where('email', $requestData['customer_email'])->first();
            if ($user) {
                $allJobs->where('user_id', $user->id);
            }
        }
    }

    private function applyCommonFilters($allJobs, $requestData)
    {
        if (isset($requestData['id']) && !empty($requestData['id'])) {
            $allJobs->whereIn('id', (array) $requestData['id']);
        }

        if (isset($requestData['lang']) && !empty($requestData['lang'])) {
            $allJobs->whereIn('from_language_id', $requestData['lang']);
        }

        if (isset($requestData['status']) && !empty($requestData['status'])) {
            $allJobs->whereIn('status', $requestData['status']);
        }

        if (isset($requestData['expired_at']) && $requestData['expired_at'] != '') {
            $allJobs->where('expired_at', '>=', $requestData['expired_at']);
        }

        if (isset($requestData['will_expire_at']) && $requestData['will_expire_at'] != '') {
            $allJobs->where('will_expire_at', '>=', $requestData['will_expire_at']);
        }

        if (isset($requestData['filter_timetype']) && in_array($requestData['filter_timetype'], ['created', 'due'])) {
            $this->applyTimeFilters($allJobs, $requestData);
        }

        if (isset($requestData['job_type']) && !empty($requestData['job_type'])) {
            $allJobs->whereIn('job_type', $requestData['job_type']);
        }

        if (isset($requestData['physical'])) {
            $allJobs->where('customer_physical_type', $requestData['physical'])
                    ->where('ignore_physical', 0);
        }

        if (isset($requestData['phone'])) {
            $allJobs->where('customer_phone_type', $requestData['phone']);
            if (isset($requestData['physical'])) {
                $allJobs->where('ignore_physical_phone', 0);
            }
        }

        if (isset($requestData['flagged'])) {
            $allJobs->where('flagged', $requestData['flagged'])
                    ->where('ignore_flagged', 0);
        }

        if (isset($requestData['distance']) && $requestData['distance'] == 'empty') {
            $allJobs->whereDoesntHave('distance');
        }

        if (isset($requestData['booking_type'])) {
            if ($requestData['booking_type'] == 'physical') {
                $allJobs->where('customer_physical_type', 'yes');
            } elseif ($requestData['booking_type'] == 'phone') {
                $allJobs->where('customer_phone_type', 'yes');
            }
        }
    }

    private function applyTimeFilters($allJobs, $requestData)
    {
        $filterType = $requestData['filter_timetype'];
        $from = $requestData['from'] ?? '';
        $to = isset($requestData['to']) ? $requestData['to'] . " 23:59:00" : '';

        if ($filterType == 'created') {
            if ($from != '') {
                $allJobs->where('created_at', '>=', $from);
            }
            if ($to != '') {
                $allJobs->where('created_at', '<=', $to);
            }
            $allJobs->orderBy('created_at', 'desc');
        } elseif ($filterType == 'due') {
            if ($from != '') {
                $allJobs->where('due', '>=', $from);
            }
            if ($to != '') {
                $allJobs->where('due', '<=', $to);
            }
            $allJobs->orderBy('due', 'desc');
        }
    }

    private function finalizeQuery($allJobs, $limit)
    {
        $allJobs->orderBy('created_at', 'desc')
                ->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        return $limit == 'all' ? $allJobs->get() : $allJobs->paginate(15);
    }

    public function distanceFeed($post_data)
    {
        $jobId = $post_data['jobid'];
        $distance = $this->sanitizeNumeric($post_data['distance']);
        $time = $this->sanitizeNumeric($post_data['time']);
        $session = $post_data['session'];
        $flagged = $post_data['flagged'];
        $adminComment = $post_data['admincomment'];
        $manuallyHandled = $post_data['manually_handled'];
        $byAdmin = $post_data['by_admin'];
    
        if ($this->requiresAdminComment($flagged, $adminComment)) {
            return "Please, add comment";
        }
    
        $this->updateDistance($jobId, $distance, $time);
    
        $this->updateJobData($jobId, $adminComment, $flagged, $session, $manuallyHandled, $byAdmin);
    
        return "Record updated!";
    }
    
    private function sanitizeNumeric($value)
    {
        return is_numeric($value) ? $value : 0;
    }
    
    private function requiresAdminComment($flagged, $adminComment)
    {
        return $flagged === 'true' && empty($adminComment);
    }
    
    private function updateDistance($jobId, $distance, $time)
    {
        if ($distance || $time) {
            Distance::where('job_id', $jobId)->update([
                'distance' => $distance,
                'time' => $time,
            ]);
        }
    }
    
    private function updateJobData($jobId, $adminComment, $flagged, $session, $manuallyHandled, $byAdmin)
    {
        if ($adminComment || $session || $flagged || $manuallyHandled || $byAdmin) {
            $this->model->where('id', $jobId)->update([
                'admin_comments' => $adminComment,
                'flagged' => $flagged,
                'session_time' => $session,
                'manually_handled' => $manuallyHandled,
                'by_admin' => $byAdmin,
            ]);
        }
    }    

    public function reopen($data)
    {
        $job_id = $data["jobid"];
        $user_id = $data["userid"];
        $due = $data["due"];
        $job_detail = $this->model->with('translatorJobRel')->findOrFail($job_id);
        $data = array();
        $data['jobid'] = $job_id;
        $data['userid'] = $user_id;
        $data['due'] = $due;

        $job = $this->model->create($data);
        $job->status = 'pending';
        $job->created_at = date('Y-m-d H:i:s');
        $job->admin_comments = $data['admincomment'];
        $job->save();

        $log_data = [];
        $log_data[] = [
            'old_status' => $job_detail->status,
            'new_status' => $job->status
        ];

        $cuser = User::find($user_id);

        $this->logger->info('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has reopened booking <a class="openjob" href="/admin/jobs/' . $job->id . '">#' . $job->id . '</a> with data:  ', $log_data);
    }

    public function sendNotificationTranslator($job, $data = [], $exclude_user_id = [])
    {
        $translator_array = $this->getPotentialTranslators($this->model->find($job->id));
        $job->user->not_getting_emails = 0;
        $users = User::where('not_getting_emails', 0)->whereIn('id', $translator_array)->whereNotIn('id', $exclude_user_id)->get();

        foreach ($users as $oneUser) {
            if ($oneUser->id == $exclude_user_id)
                continue;

            $mailer = new AppMailer();
            $email = $oneUser->email;
            $name = $oneUser->name;
            $subject = 'Ny bokning tillgänglig';
            $data = [
                'user' => $oneUser,
                'job' => $job
            ];
            $mailer->send($email, $name, $subject, 'emails.new-job', $data);

            if ($this->isNeedToSendPush($oneUser->id)) {
                $users_array = array($oneUser);
                $data['notification_type'] = 'job_posted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'En ny tolkning har lagts till för ' . $language . ' tolk ' . $job->duration . 'min ' . $job->due
                );
                $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($oneUser->id));
            }
        }
    }

    public function sendSMSNotificationToTranslator($job)
    {
        $translators = User::whereHas('languages', function ($query) use ($job) {
            $query->where('lang_id', '=', $job->from_language_id);
        })->get();

        foreach ($translators as $translator) {
            $jobuser = new Job();
            $jobuser->job_id = $job->id;
            $jobuser->user_id = $translator->id;
            $jobuser->created_at = date('Y-m-d H:i:s');
            $jobuser->save();

            $nPush = $this->isNeedToSendPush($translator->id);
            if ($nPush) {
                $msg = "Ny bokning: Job ID: " . $job->id . ", Language: " . TeHelper::fetchLanguageFromJobId($job->from_language_id) . ", Duration: " . $job->duration . "min, Due: " . $job->due;
                $response = SendSMSHelper::send($translator->phone, $msg);
            }
        }
    }

    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;
        $log_data = [];
        $new_translator = null;
    
        $translator_id = $this->getTranslatorId($data);
    
        if ($this->shouldChangeTranslator($current_translator, $data, $translator_id)) {
            if (!is_null($current_translator)) {
                $new_translator = $this->replaceCurrentTranslator($current_translator, $translator_id);
                $log_data = $this->logTranslatorChange($current_translator, $new_translator);
            } else {
                $new_translator = $this->assignNewTranslator($translator_id, $job->id);
                $log_data = $this->logTranslatorChange(null, $new_translator);
            }
    
            $translatorChanged = true;
        }
    
        return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
    }
    
    private function getTranslatorId($data)
    {
        if (isset($data['translator_email']) && $data['translator_email'] != '') {
            $translator = User::where('email', $data['translator_email'])->first();
            return $translator ? $translator->id : null;
        }
    
        return $data['translator'] ?? null;
    }
    
    private function shouldChangeTranslator($current_translator, $data, $translator_id)
    {
        return !is_null($current_translator) || $translator_id != 0 || isset($data['translator_email']);
    }
    
    private function replaceCurrentTranslator($current_translator, $translator_id)
    {
        $new_translator_data = $current_translator->toArray();
        $new_translator_data['user_id'] = $translator_id;
        unset($new_translator_data['id']);
    
        $new_translator = Translator::create($new_translator_data);
        $current_translator->cancel_at = Carbon::now();
        $current_translator->save();
    
        return $new_translator;
    }
    
    private function assignNewTranslator($translator_id, $job_id)
    {
        return Translator::create(['user_id' => $translator_id, 'job_id' => $job_id]);
    }
    
    private function logTranslatorChange($old_translator, $new_translator)
    {
        return [
            'old_translator' => $old_translator ? $old_translator->user->email : null,
            'new_translator' => $new_translator->user->email
        ];
    }
    
    private function changeDue($old_due, $new_due)
    {
        if ($old_due == $new_due) {
            return ['dateChanged' => false];
        }
    
        $log_data = [
            'old_due' => $old_due,
            'new_due' => $new_due
        ];
    
        return ['dateChanged' => true, 'log_data' => $log_data];
    }    

    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $new_status = $data['status'];
    
        if ($old_status === $new_status) {
            return ['statusChanged' => false];
        }
    
        $statusChanged = match ($old_status) {
            'timedout' => $this->changeTimedoutStatus($job, $data, $changedTranslator),
            'completed' => $this->changeCompletedStatus($job, $data),
            'started' => $this->changeStartedStatus($job, $data),
            'pending' => $this->changePendingStatus($job, $data, $changedTranslator),
            'withdrawafter24' => $this->changeWithdrawafter24Status($job, $data),
            'assigned' => $this->changeAssignedStatus($job, $data),
            default => false,
        };
    
        if ($statusChanged) {
            $log_data = [
                'old_status' => $old_status,
                'new_status' => $new_status,
            ];
            return ['statusChanged' => true, 'log_data' => $log_data];
        }
    
        return ['statusChanged' => false];
    }
    

    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
    
        $this->sendNotificationEmail($email, $name, $subject, 'emails.job-changed-date', $data);
    
        $translator = $this->model->getJobsAssignedTranslatorDetail($job);
        $data['user'] = $translator;
        $this->sendNotificationEmail($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }
    
    private function sendNotificationEmail($email, $name, $subject, $template, $data)
    {
        $this->mailer->send($email, $name, $subject, $template, $data);
    }    

    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;
        $data = [
            'user' => $user,
            'job'  => $job
        ];
    
        $this->sendNotificationEmail($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
    
        if ($current_translator) {
            $this->sendTranslatorNotification($current_translator, $subject, 'emails.job-changed-translator-old-translator', $data);
        }
    
        $this->sendTranslatorNotification($new_translator, $subject, 'emails.job-changed-translator-new-translator', $data);
    }
    
    private function sendTranslatorNotification($translator, $subject, $template, $data)
    {
        $user = $translator->user;
        $data['user'] = $user;
        $this->sendNotificationEmail($user->email, $user->name, $subject, $template, $data);
    }    

    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
    
        $this->sendNotificationEmail($email, $name, $subject, 'emails.job-changed-lang', $data);
    
        $translator = $this->model->getJobsAssignedTranslatorDetail($job);
        $this->sendNotificationEmail($translator->email, $translator->name, $subject, 'emails.job-changed-lang', $data);
    }

    public function getPotentialTranslators(Job $job)
    {
        $translator_type = $this->determineTranslatorType($job->job_type);
        $translator_level = $this->determineTranslatorLevel($job->certified);

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translator_type, $job->from_language_id, $job->gender, $translator_level, $blacklist);

        return $users;
    }

    private function determineTranslatorType($job_type)
    {
        switch ($job_type) {
            case 'paid':
                return 'professional';
            case 'rws':
                return 'rwstranslator';
            case 'unpaid':
                return 'volunteer';
            default:
                return null;
        }
    }

    private function determineTranslatorLevel($certified)
    {
        $translator_level = [];
        if (empty($certified)) {
            return [
                'Certified',
                'Certified with specialisation in law',
                'Certified with specialisation in health care',
                'Layman',
                'Read Translation courses'
            ];
        }

        switch ($certified) {
            case 'yes':
            case 'both':
                $translator_level = [
                    'Certified',
                    'Certified with specialisation in law',
                    'Certified with specialisation in health care',
                    'Layman',
                    'Read Translation courses'
                ];
                break;
            case 'law':
            case 'n_law':
                $translator_level = ['Certified with specialisation in law'];
                break;
            case 'health':
            case 'n_health':
                $translator_level = ['Certified with specialisation in health care'];
                break;
            case 'normal':
                $translator_level = ['Layman', 'Read Translation courses'];
                break;
        }

        return $translator_level;
    }

    public function isNeedToSendPush($user_id)
    {
        return TeHelper::getUsermeta($user_id, 'not_get_notification') !== 'yes';
    }

    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = $this->getPushLogger();
        $logger->info('Push send for job ' . $job_id, compact('users', 'data', 'msg_text', 'is_need_delay'));
    
        $onesignalAppID = config('app.' . env('APP_ENV') . 'OnesignalAppID');
        $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.' . env('APP_ENV') . 'OnesignalApiKey'));
    
        $user_tags = $this->getUserTagsStringFromArray($users);
        $data['job_id'] = $job_id;
    
        $ios_sound = $android_sound = 'default';
        if ($data['notification_type'] == 'suitable_job') {
            $sound = $data['immediate'] == 'no' ? 'normal_booking' : 'emergency_booking';
            $android_sound = $sound;
            $ios_sound = $sound . '.mp3';
        }
    
        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        ];
    
        if ($is_need_delay) {
            $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
        }
    
        $response = $this->sendCurlRequest($fields, $onesignalRestAuthKey);
        $logger->info('Push send for job ' . $job_id . ' curl answer', [$response]);
    }
    
    private function getPushLogger()
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        return $logger;
    }
    
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = array_map(function ($user) {
            return [
                'operator' => 'OR',
                'key' => 'email',
                'relation' => '=',
                'value' => strtolower($user->email)
            ];
        }, $users);
    
        array_shift($user_tags);
        return json_encode($user_tags);
    }
    
    private function sendCurlRequest($fields, $onesignalRestAuthKey)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
        $response = curl_exec($ch);
        curl_close($ch);
    
        return $response;
    }    

    public function isNeedToDelayPush($user_id)
    {
        return DateTimeHelper::isNightTime() && TeHelper::getUsermeta($user_id, 'not_get_nighttime') == 'yes';
    }    

    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $dataEmail = ['user' => $user, 'job' => $job];
    
        if ($data['status'] == 'pending') {
            $this->reopenJob($job);
            $this->notifyCustomer($email, $name, 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id, 'emails.job-change-status-to-customer', $dataEmail);
            $this->sendNotificationTranslator($job, $this->jobToData($job), '*');
            return true;
        }
    
        if ($changedTranslator) {
            $job->save();
            $this->notifyCustomer($email, $name, 'Bekräftelse - tolk har accepterat er bokning (bokning #' . $job->id . ')', 'emails.job-accepted', $dataEmail);
            return true;
        }
    
        return false;
    }
    
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout' && empty($data['admin_comments'])) {
            return false;
        }
        $job->admin_comments = $data['admin_comments'] ?? null;
        $job->save();
        return true;
    }
    
    private function changeStartedStatus($job, $data)
    {
        if (empty($data['admin_comments'])) {
            return false;
        }
    
        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];
    
        if ($data['status'] == 'completed') {
            if (empty($data['sesion_time'])) {
                return false;
            }
    
            $user = $job->user()->first();
            $session_time = $this->calculateSessionTime($data['sesion_time']);
            $this->completeJob($job, $data['sesion_time'], $session_time);
            $this->sendCompletionEmails($job, $user, $session_time);
        }
    
        $job->save();
        return true;
    }
    
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        if ($data['status'] == 'timedout' && empty($data['admin_comments'])) {
            return false;
        }
    
        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'] ?? null;
        $user = $job->user()->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $dataEmail = ['user' => $user, 'job' => $job];
    
        if ($data['status'] == 'assigned' && $changedTranslator) {
            $job->save();
            $this->notifyCustomer($email, $name, 'Bekräftelse - tolk har accepterat er bokning (bokning #' . $job->id . ')', 'emails.job-accepted', $dataEmail);
            $translator = $this->model->getJobsAssignedTranslatorDetail($job);
            $this->notifyCustomer($translator->email, $translator->name, 'Bekräftelse - tolk har accepterat er bokning (bokning #' . $job->id . ')', 'emails.job-changed-translator-new-translator', $dataEmail);
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        }
    
        $this->notifyCustomer($email, $name, 'Avbokning av bokningsnr: #' . $job->id, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
        $job->save();
        return true;
    }

    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }
    
    private function changeWithdrawafter24Status($job, $data)
    {
        if ($data['status'] == 'timedout' && !empty($data['admin_comments'])) {
            $job->status = $data['status'];
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }
    
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout']) && !empty($data['admin_comments'])) {
            $job->status = $data['status'];
            $job->admin_comments = $data['admin_comments'];
            $user = $job->user()->first();
            $email = $job->user_email ?? $user->email;
            $name = $user->name;
            $dataEmail = ['user' => $user, 'job' => $job];
    
            $this->notifyCustomer($email, $name, 'Information om avslutad tolkning för bokningsnummer #' . $job->id, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
    
            $translator = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
            $this->notifyCustomer($translator->user->email, $translator->user->name, 'Information om avslutad tolkning för bokningsnummer # ' . $job->id, 'emails.job-cancel-translator', $dataEmail);
    
            $job->save();
            return true;
        }
        return false;
    }
    
    private function reopenJob($job)
    {
        $job->created_at = now();
        $job->emailsent = 0;
        $job->emailsenttovirpal = 0;
        $job->save();
    }
    
    private function notifyCustomer($email, $name, $subject, $template, $data)
    {
        $this->mailer->send($email, $name, $subject, $template, $data);
    }
    
    private function completeJob($job, $session_time, $formatted_session_time)
    {
        $job->end_at = now();
        $job->session_time = $session_time;
    }
    
    private function calculateSessionTime($interval)
    {
        $diff = explode(':', $interval);
        return $diff[0] . ' tim ' . $diff[1] . ' min';
    }
    
    private function sendCompletionEmails($job, $user, $session_time)
    {
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $dataEmail = ['user' => $user, 'job' => $job, 'session_time' => $session_time, 'for_text' => 'faktura'];
        $this->notifyCustomer($email, $name, 'Information om avslutad tolkning för bokningsnummer #' . $job->id, 'emails.session-ended', $dataEmail);
    
        $translator = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();
        $email = $translator->user->email;
        $name = $translator->user->name;
        $dataEmail['for_text'] = 'lön';
        $this->notifyCustomer($email, $name, 'Information om avslutad tolkning för bokningsnummer # ' . $job->id, 'emails.session-ended', $dataEmail);
    }    

}