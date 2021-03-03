<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Response;
use App\Models\GlobalSettings;
use App\Repositories\CampaignStatistics;
use App\Services\CloudOneService;
use App\Services\FacebookAdsService;
use DB;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Company;
use App\Models\LeadTag;
use App\Models\Campaign;
use App\Models\EmailLog;
use App\Models\Recipient;
use App\Models\PhoneNumber;
use Illuminate\Http\Request;
use App\Http\Requests\NewCampaignRequest;
use App\Http\Resources\Campaign as CampaignResource;
use Spatie\Activitylog\Models\Activity;

const SECONDS_PER_HOUR = 3600;
const SECONDS_PER_DAY = 86400;

class CampaignController extends Controller
{
    private $emailLog;

    private $campaign;

    private $company;

    private $recipient;

    private $cloudOneService;

    public function __construct(CloudOneService $cloudOneService, Campaign $campaign, Company $company, EmailLog $emailLog, Recipient $recipient)
    {
        $this->cloudOneService = $cloudOneService;
        $this->campaign = $campaign;
        $this->company = $company;
        $this->emailLog = $emailLog;
        $this->recipient = $recipient;
    }

    public function index(Request $request)
    {
        return view('campaigns.index', []);
    }

    /**
     * Load Campaign Console Page
     *
     * @param Request  $request
     * @param Campaign $campaign
     *
     * @return \Illuminate\View\View
     */
    public function console(Request $request, Campaign $campaign, $filter = null)
    {
        $counters = [];
        $counters['total'] = $campaign->leads()->count();
        $counters['new'] = $campaign->leads()->new()->count();
        $counters['open'] = $campaign->leads()->open()->count();
        $counters['closed'] = $campaign->leads()->closed()->count();
        $counters['calls'] = $campaign->leads()->whereHas('responses', function ($q) { $q->whereType('phone'); })->count();
        $counters['email'] = $campaign->leads()->whereHas('responses', function ($q) { $q->whereType('email'); })->count();
        $counters['sms'] = $campaign->leads()->whereHas('responses', function ($q) { $q->whereType('text'); })->count();

        $positiveTags = LeadTag::whereIn('campaign_id', [0, $campaign->id])
            ->whereIn('indication', ['positive', 'neutral'])
            ->select(['name', 'text'])
            ->get();
        $textToValueRequestedTag = LeadTag::where('name', LeadTag::VEHICLE_VALUE_REQUESTED_TAG)
            ->select(['name', 'text'])
            ->first();
        $checkedInTextToValueTag = LeadTag::where('name', LeadTag::CHECKED_IN_FROM_TEXT_TO_VALUE_TAG)
            ->select(['name', 'text'])
            ->first();
        $negativeTags = LeadTag::whereIn('campaign_id', [0, $campaign->id])
            ->whereIn('indication', ['negative', 'neutral'])
            ->select(['name', 'text'])
            ->get();
        $leadTags = LeadTag::whereIn('campaign_id', [0, $campaign->id])
            ->orderBy('text', 'ASC')
            ->select(['name', 'text'])
            ->get();

        $data = [
            'counters' => $counters,
            'campaign' => $campaign,
            'leadTags' => $leadTags,
            'checkedInTextToValueTag' => $checkedInTextToValueTag,
            'textToValueRequestedTag' => $textToValueRequestedTag,
            'positiveTags' => $positiveTags,
            'negativeTags' => $negativeTags
        ];

        if ($filter) {
            $data['filterApplied'] = $filter;
        }
        return view('campaigns.console', $data);
    }

    /**
     * Return all campaigns for user display
     * @param Request $request
     * @return mixed
     */
    public function getForUserDisplay(Request $request)
    {
        $campaignQuery = Campaign::searchByRequest($request);
        $campaigns = $campaignQuery
            ->orderBy('status', 'asc')
            ->orderBy('campaigns.id', 'desc')
            ->paginate(15);

        foreach ($campaigns as $campaign) {
            $counters = [];
            $counters['total'] = $campaign->leads()->count();
            $counters['new'] = $campaign->leads()->new()->count();
            $counters['open'] = $campaign->leads()->open()->count();
            $counters['closed'] = $campaign->leads()->closed()->count();
            $campaign['counters'] = $counters;
        }

        return $campaigns;
    }

    public function getList(Request $request)
    {
        $campaigns = $this->campaign->with(['client', 'mailers'])
            ->selectRaw("
                (select count(distinct(recipient_id)) from recipients where campaign_id = campaigns.id) as recipientCount),
                (select count(distinct(recipient_id)) from responses where campaign_id = campaigns.id and type='phone' and recording_sid is not null) as phoneCount,
                (select count(distinct(recipient_id)) from responses where campaign_id = campaigns.id and type='email') as emailCount,
                (select count(distinct(recipient_id)) from responses where campaign_id = campaigns.id and type='text') as textCount,
                users.id as client_id
            ")
            ->get();

        return $campaigns->toJson();
    }

    /**
     * Show a specific campaign
     *
     * @param \App\Models\Campaign $campaign
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function show(Campaign $campaign)
    {
        $viewData = [];

        $viewData['campaign'] = $campaign;

        $emailStats = $campaign->getEmailLogsStats();
        $responseStats = $campaign->getRecipientStats();

        $viewData['emailCount'] = $emailStats->count();
        $viewData['emailStats'] = $emailStats->first();
        $viewData['responseCount'] = $responseStats->count();
        $viewData['responseStats'] = $responseStats->first();

        return view('campaigns.dashboard', $viewData);
    }

    public function details(Campaign $campaign)
    {
        $campaign->with('phones');
        return view('campaigns.details', [
            'campaign' => $campaign
        ]);
    }

    public function create()
    {
        $dealerships = Company::getDealerships();
        $agencies = Company::getAgencies();
        $viewData = [
            'dealerships' => $dealerships,
            'agencies' => $agencies,
        ];

        return view('campaigns.create', $viewData);
    }

    public function store(NewCampaignRequest $request)
    {
        $expires_at = null;
        $starts_at = (new Carbon($request->start, \Auth::user()->timezone))->timezone('UTC')->toDateTimeString();
        $ends_at = (new Carbon($request->end, \Auth::user()->timezone))->timezone('UTC')->toDateTimeString();

        if (! empty($request->input('expires'))) {
            $expires_at = (new Carbon($request->expires, \Auth::user()->timezone))->timezone('UTC')->toDateTimeString();
        } else {
            $expires_at = (new Carbon($request->end, \Auth::user()->timezone))->timezone('UTC')->addWeeks(2);
        }

        $status = $request->status;
        if ($expires_at <= \Carbon\Carbon::now('UTC')) {
            $status = 'Expired';
        }

        $campaign = new Campaign([
            'name' => $request->input('name'),
            'status' => $status,
            'order_id' => $request->input('order'),
            'starts_at' => $starts_at,
            'ends_at' => $ends_at,
            'agency_id' => $request->input('agency'),
            'dealership_id' => $request->input('dealership'),
            'enable_text_to_value' => (bool) $request->input('enable_text_to_value', false),
            'adf_crm_export' => (bool) $request->input('adf_crm_export'),
            'adf_crm_export_email' => $request->input('adf_crm_export_email', []),
            'client_passthrough' => (bool) $request->input('client_passthrough'),
            'client_passthrough_email' => $request->input('client_passthrough_email', []),
            'lead_alerts' => (bool) $request->input('lead_alerts'),
            'lead_alert_email' => $request->input('lead_alert_emails', []),
            'service_dept' => (bool) $request->input('service_dept'),
            'service_dept_email' => $request->input('service_dept_email', []),
            'enable_call_center' => $request->input('enable_call_center', false),
            'enable_facebook_campaign' => $request->input('enable_facebook_campaign', false),
            'sms_on_callback' => (bool) $request->input('service_dept'),
            'sms_on_callback_number' => $request->input('sms_on_callback_number', []),
            'text_to_value_message' => $request->input('text_to_value_message', '')
        ]);

        /**
         * Verify existence of mailer phone number to enable text_to_value
         */
        if ($campaign->hasTextToValueEnabled()) {
            $hasMailerPhoneNumber = false;
            foreach ((array)($request->input('phone_number_ids') ?? []) as $phoneNumberId) {
                $phoneNumber = PhoneNumber::find($phoneNumberId);
                if ($phoneNumber->isMailer()) {
                    $hasMailerPhoneNumber = true;
                    break;
                }
            }
            if (!$hasMailerPhoneNumber) {
                abort(422, 'You must add a mailer phone number to enable Text To Value feature.');
            }
        }

        if ($campaign->enable_call_center) {
            if ($request->has('phone_number_ids') && count((array) $request->input('phone_number_ids')) === 0) {
                abort(422, 'You must add a phone number to enable Call Center feature.');
            }

            $this->validateDuplicatedCloudOneCampaignId($campaign, $request->input('cloud_one_campaign_id'));

            $campaign->cloud_one_campaign_id = $request->input('cloud_one_campaign_id', '');

            $this->setCloudOnePhoneNumber($campaign);
        }

        if ($campaign->enable_facebook_campaign) {

            $campaign->facebook_campaign_id = $request->input('facebook_campaign_id', '');
        }

        if (! $campaign->expires_at) {
            $campaign->expires_at = $campaign->ends_at->addMonth();
        }

        $campaign->save();

        if ($request->has('phone_number_ids')) {
            foreach ((array)$request->input('phone_number_ids') as $phone_number_id) {
                PhoneNumber::find($phone_number_id)->update(['campaign_id' => $campaign->id]);
            }
        }

        return response()->json(['message' => 'Resource created']);
    }

    public function edit(Campaign $campaign)
    {

        $dealerships = Company::getDealerships();
        $agencies = Company::getAgencies();
        $viewData = [
            'campaign' => new CampaignResource($campaign),
            'cannedResponses' => $campaign->cannedResponses,
            'dealerships' => $dealerships,
            'agencies' => $agencies,
        ];

        return view('campaigns.edit', $viewData);
    }

    public function stats(Campaign $campaign)
    {
        return view('campaigns.stats', [
            'campaign' => $campaign
        ]);
    }

    public function getStatsData(Campaign $campaign, Request $request)
    {
        $startDate = Carbon::createFromFormat('Y-m-d', $request->input('start_date') ?? Carbon::now()->subMonths(1)->toDateString());
        $endDate = Carbon::createFromFormat('Y-m-d', $request->input('end_date') ?? Carbon::now()->toDateString());

        $campaignStats = new CampaignStatistics($campaign, $startDate, $endDate);
        $stats = $campaignStats->all();

        $viewData = [
            'campaign' => $campaign,
            'newLeadsOverTime' => $stats->newLeadsOverTime,
            'leadsOpenOverTime' => $stats->leadsOpenOverTime,
            'leadsClosedOverTime' => $stats->leadsClosedOverTime,
            'appointmentsOverTime' => $stats->appointmentsOverTime,
            'callbacksOverTime' => $stats->callbacksOverTime,
            'averageTimeToOpen' => $stats->averageTimeToOpen,
            'averageTimeToClose' => $stats->averageTimeToClose,
            'outcomes' => $stats->outcomes,
            'leadsClosedByTime' => $stats->leadsClosedByTime,
            'leadsOpenByTime' => $stats->leadsOpenByTime,
            'leadsByEmail' => $stats->leadsByEmail,
            'leadsByPhone' => $stats->leadsByPhone,
            'leadsBySms' => $stats->leadsBySms,
            'ranking' => $stats->ranking
        ];

        return $viewData;
    }

    public function facebookCampaign(Campaign $campaign)
    {
        return view('campaigns.facebook-campaign', [
            'campaign' => $campaign
        ]);
    }

    public function getfacebookCampaignData(Campaign $campaign, Request $request)
    {
        /** @var User $user */
        $user = auth()->user();
        if (!$this->authorize('view', $campaign)) {
            abort(401);
        }

        $metrics = (object)[];
        if($campaign->facebook_campaign_id) {
            $globalSettings = GlobalSettings::where('name', 'facebook_access_token')->first();
            $access_token = $globalSettings->value;
            $facebookAdsService = new FacebookAdsService($access_token);
            $metrics = $facebookAdsService->getCampaignMetrics($campaign->facebook_campaign_id);
        }

        return $metrics;
    }

    public function update(Campaign $campaign, NewCampaignRequest $request)
    {
        if ($request->filled('phone_number_id') || $request->filled('forward')) {
            $phone = \App\Models\PhoneNumber::findOrFail($request->phone_number_id);
            $phone->fill(['forward' => $request->forward]);
            $phone->save();
        }

        $expires_at = null;
        $starts_at = (new Carbon($request->start, \Auth::user()->timezone))->timezone('UTC')->toDateTimeString();
        $ends_at = (new Carbon($request->end, \Auth::user()->timezone))->timezone('UTC')->toDateTimeString();

        if (! empty($request->input('expires'))) {
            $expires_at = (new Carbon($request->expires, \Auth::user()->timezone))->timezone('UTC')->toDateTimeString();
        } else {
            $expires_at = (new Carbon($request->end, \Auth::user()->timezone))->timezone('UTC')->addWeeks(2);
        }

        $status = $request->status;
        if (! $expires_at || ($expires_at && $expires_at <= \Carbon\Carbon::now('UTC'))) {
            $status = 'Expired';
        }

        $campaign->fill([
            'adf_crm_export' => (bool) $request->input('adf_crm_export'),
            'adf_crm_export_email' => $request->input('adf_crm_export_email', []),
            'agency_id' => $request->input('agency'),
            'client_passthrough' => (bool) $request->input('client_passthrough'),
            'client_passthrough_email' => $request->input('client_passthrough_email', []),
            'dealership_id' => $request->input('dealership'),
            'ends_at' => $ends_at,
            'expires_at' => $expires_at,
            'lead_alerts' => (bool) $request->input('lead_alerts'),
            'lead_alert_email' => $request->input('lead_alert_emails', []),
            'name' => $request->input('name'),
            'order_id' => $request->input('order'),
            'service_dept' => (bool) $request->input('service_dept'),
            'service_dept_email' => $request->input('service_dept_email', []),
            'sms_on_callback' => (bool) $request->input('sms_on_callback'),
            'sms_on_callback_number' => $request->input('sms_on_callback_number', []),
            'text_to_value_message' => $request->input('text_to_value_message', ''),
            'enable_call_center' => $request->input('enable_call_center', false),
            'enable_facebook_campaign' => $request->input('enable_facebook_campaign', false),
            'starts_at' => $starts_at,
            'status' => $status
        ]);


        if (!$campaign->hasTextToValueEnabled() && $request->input('enable_text_to_value')) {
            if ($campaign->phones()->where('call_source_name',  PhoneNumber::$callSources['mailer'])->count() === 0) {
                abort(422, 'You must add a mailer phone number to enable Text To Value feature.');
            }
            $campaign->enable_text_to_value = $request->input('enable_text_to_value');
        }

        if ($campaign->enable_call_center) {
            if ($campaign->phones()->count() === 0) {
                abort(422, 'You must add a phone number to enable Call Center feature.');
            }

            $this->validateDuplicatedCloudOneCampaignId($campaign, $request->input('cloud_one_campaign_id'));

            $campaign->cloud_one_campaign_id = $request->input('cloud_one_campaign_id', '');
            $campaign->cloud_one_phone_number = $this->cloudOneService->getCampaignPhoneNumber($campaign->cloud_one_campaign_id);
            $campaign->save();
        }

        if ($campaign->enable_facebook_campaign && $request->filled('facebook_campaign_id')) {
            $campaign->facebook_campaign_id = $request->input('facebook_campaign_id', '');
            $globalSettings = GlobalSettings::where('name', 'facebook_access_token')->first();
            $accessToken = $globalSettings->value;
            $facebookAdsService = new FacebookAdsService($accessToken);
            if (!$facebookAdsService->hasAccessToCampaign($campaign->facebook_campaign_id)) {
                abort(422, "You don't have access to the Facebook campaign $campaign->facebook_campaign_id, please check your facebook account.");
            }
        }

        $campaign->save();

        return response()->json(['message' => 'Resource updated.']);
    }

    public function delete(Campaign $campaign)
    {
        $campaign->delete();

        return redirect()->route('campaigns.index');
    }

    public function toggleCampaignUserAccess(Campaign $campaign, User $user)
    {
        $campaign->users()->toggle($user->id);

        return response()->json(['message' => 'Resource updated.']);
    }

    private function setCloudOnePhoneNumber(Campaign $campaign)
    {
        $existCloudOneCampaign = Campaign::where('cloud_one_campaign_id', $campaign->cloud_one_campaign_id)
            ->where('id', '!=', $campaign->id)
            ->first();

        if ($existCloudOneCampaign)  {
            abort(422, 'Campaign ' . $existCloudOneCampaign->id . ' is already using Cloud One Campaign Id ' . $campaign->cloud_one_campaign_id);
        }

        $campaign->cloud_one_phone_number = $this->cloudOneService->getCampaignPhoneNumber($campaign->cloud_one_campaign_id);
        $campaign->save();
    }

    private function validateDuplicatedCloudOneCampaignId(Campaign $campaign, $cloudOneCampaignId)
    {
        if ($cloudOneCampaignId !== $campaign->cloud_one_campaign_id) {
            $existingCloudOneCampaign = Campaign::where('cloud_one_campaign_id', $cloudOneCampaignId)->first();
            if ($existingCloudOneCampaign) {
                abort(422, 'Campaign ' . $existingCloudOneCampaign->id . ' is already using Cloud One Campaign Id ' . $cloudOneCampaignId);
            }
        }
    }
}
