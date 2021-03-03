<?php

namespace App\Repositories;

use Carbon\Carbon;
use App\Models\Lead;
use App\Models\Campaign;
use App\Models\Appointment;
use App\Models\LeadActivity;
use Spatie\Activitylog\Models\Activity;

class CampaignStatistics
{
    const SECONDS_PER_HOUR = 3600;
    const SECONDS_PER_DAY = 86400;

    public $campaign;
    public $startDate;
    public $endDate;

    public function __construct(Campaign $campaign, Carbon $startDate, Carbon $endDate)
    {
        $this->campaign = $campaign;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function all()
    {
        return collect([
            'newLeadsOverTime' => $this->getNewLeadsOverTime(),
            'leadsOpenOverTime' => $this->getLeadsOpenOverTime(),
            'leadsClosedOverTime' => $this->getLeadsClosedOverTime(),
            'appointmentsOverTime' => $this->getAppointmentsOverTime(),
            'callbacksOverTime' => $this->getCallbacksOverTime(),
            'averageTimeToOpen' => $this->getAverageTimeToOpen(),
            'averageTimeToClose' => $this->getAverageTimeToClose(),
            'outcomes' => $this->getOutcomes(),
            'leadsClosedByTime' => $this->getLeadsClosedByTime(),
            'leadsOpenByTime' => $this->getLeadsOpenByTime(),
            'leadsByEmail' => $this->getLeadsByEmail(),
            'leadsByPhone' => $this->getLeadsByPhone(),
            'leadsBySms' => $this->getLeadsBySms(),
            'ranking' => $this->getRanking(),
        ]);
    }

    public function getNewLeadsOverTime()
    {
        return $this->campaign->leads()
            ->new()
            ->selectRaw('DATE(last_status_changed_at) as date, COUNT(id) as total')
            ->groupBy(DB::raw('DATE(last_status_changed_at)'))
            ->whereDate('last_status_changed_at', '>=', $this->startDate)
            ->whereDate('last_status_changed_at', '<=', $this->endDate)
            ->orderBy('last_status_changed_at', 'ASC')
            ->get();
    }

    public function getLeadsOpenOverTime()
    {
        return $this->campaign->leads()
            ->open()
            ->selectRaw('DATE(last_status_changed_at) as date, COUNT(id) as total')
            ->groupBy(DB::raw('DATE(last_status_changed_at)'))
            ->whereDate('last_status_changed_at', '>=', $this->startDate)
            ->whereDate('last_status_changed_at', '<=', $this->endDate)
            ->orderBy('last_status_changed_at', 'ASC')
            ->get();
    }

    public function getLeadsClosedOverTime()
    {
        return $this->campaign->leads()
            ->closed()
            ->selectRaw('DATE(last_status_changed_at) as date, COUNT(id) as total')
            ->groupBy(DB::raw('DATE(last_status_changed_at)'))
            ->whereDate('last_status_changed_at', '>=', $this->startDate)
            ->whereDate('last_status_changed_at', '<=', $this->endDate)
            ->orderBy('last_status_changed_at', 'ASC')
            ->get();
    }

    public function getAppointmentsOverTime()
    {
        return $this->campaign->appointments() ->selectRaw('DATE(appointment_at) as date, COUNT(id) as total')
            ->groupBy(DB::raw('DATE(appointment_at)'))
            ->whereDate('appointment_at', '>=', $this->startDate)
            ->whereDate('appointment_at', '<=', $this->endDate)
            ->where('type', Appointment::TYPE_APPOINTMENT)
            ->orderBy('appointment_at', 'ASC')
            ->get();
    }

    public function getCallbacksOverTime()
    {
        return $this->campaign->appointments()
            ->selectRaw('DATE(appointment_at) as date, COUNT(id) as total')
            ->groupBy(DB::raw('DATE(appointment_at)'))
            ->whereDate('appointment_at', '>=', $this->startDate)
            ->whereDate('appointment_at', '<=', $this->endDate)
            ->where('type', Appointment::TYPE_CALLBACK)
            ->orderBy('appointment_at', 'ASC')
            ->get();
    }

    public function getAverageTimeToOpen()
    {
        $firstResponsePerRecipient = $this->getFirstResponsePerRecipient();
        $firstOpenActivityPerRecipient = $this->getFirstOpenActivityPerRecipient();

        $averageTimeToOpen = DB::query()
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, q1.created_at, q2.created_at)) AS total')
            ->from(DB::raw("({$firstResponsePerRecipient->toSql()}) as q1"))
            ->mergeBindings($firstResponsePerRecipient->getQuery()->getQuery())
            ->join(DB::raw("({$firstOpenActivityPerRecipient->toSql()}) as q2"), function ($join) {
                $join->on('q1.recipient_id', '=', 'q2.recipient_id');
            })
            ->mergeBindings($firstOpenActivityPerRecipient->getQuery())
            ->first();

        if (! $averageTimeToOpen->total) {
            return 0;
        }

        return $averageTimeToOpen->total;
    }

    public function getAverageTimeToClose()
    {
        $firstResponsePerRecipient = $this->getFirstResponsePerRecipient();
        $lastCloseActivityPerRecipient = $this->getLastCloseActivityPerRecipient();

        $averageTimeToClose = DB::query()
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, q1.created_at, q2.created_at)) AS total')
            ->from(DB::raw("({$firstResponsePerRecipient->toSql()}) as q1"))
            ->mergeBindings($firstResponsePerRecipient->getQuery()->getQuery())
            ->join(DB::raw("({$lastCloseActivityPerRecipient->toSql()}) as q2"), function ($join) {
                $join->on('q1.recipient_id', '=', 'q2.recipient_id');
            })
            ->mergeBindings($lastCloseActivityPerRecipient->getQuery())
            ->first();

        if (! $averageTimeToClose->total) {
            return 0;
        }

        return $averageTimeToClose->total;
    }

    public function getOutcomes()
    {
        $leadsWithOutcome = $this->campaign->leads()
            ->whereDate('last_status_changed_at', '>=', $this->startDate)
            ->whereDate('last_status_changed_at', '<=', $this->endDate)
            ->whereNotNull('recipients.outcome')
            ->get();

        $resumeOutcomes = [
            Lead::POSITIVE_OUTCOME => [
                'total' => 0,
                'tags' => (object) []
            ],
            Lead::NEGATIVE_OUTCOME => [
                'total' => 0,
                'tags' => (object) []
            ]
        ];

        foreach ($leadsWithOutcome as $ld) {
            $resumeOutcomes[$ld->outcome]['total']++;
            foreach ($ld->tags as $tag) {
                if (!property_exists($resumeOutcomes[$ld->outcome]['tags'], $tag)) {
                    $resumeOutcomes[$ld->outcome]['tags']->$tag = 0;
                }
                $resumeOutcomes[$ld->outcome]['tags']->$tag++;
            }
        }

        return $resumeOutcomes;
    }

    public function getRanking()
    {
        return $this->campaign->getOverallRanking();
    }

    public function getLeadsByEmail()
    {
        return $this->campaign->leads()
        ->join('responses', 'responses.recipient_id', '=', 'recipients.id')
        ->where('responses.type', 'email')
        ->whereDate('responses.created_at', '>=', $this->startDate)
        ->whereDate('responses.created_at', '<=', $this->endDate)
        ->groupBy('recipients.id')
        ->selectRaw('recipients.id')
        ->count();
    }

    public function getLeadsByPhone()
    {
        return $this->campaign->leads()
            ->join('responses', 'responses.recipient_id', '=', 'recipients.id')
            ->where('responses.type', 'phone')
            ->whereDate('responses.created_at', '>=', $this->startDate)
            ->whereDate('responses.created_at', '<=', $this->endDate)
            ->groupBy('recipients.id')
            ->selectRaw('recipients.id')
            ->count();
    }

    public function getLeadsBySms()
    {
        return $this->campaign->leads()
            ->join('responses', 'responses.recipient_id', '=', 'recipients.id')
            ->where('responses.type', 'text')
            ->whereDate('responses.created_at', '>=', $this->startDate)
            ->whereDate('responses.created_at', '<=', $this->endDate)
            ->groupBy('recipients.id')
            ->selectRaw('recipients.id')
            ->get()
            ->count();
    }

    public function getLeadsOpenByTime()
    {
        $firstResponsePerRecipient = $this->getFirstResponsePerRecipient();
        $firstOpenActivityPerRecipient = $this->getFirstOpenActivityPerRecipient();

        $leadsOpenedWithTime = DB::query()
            ->selectRaw('TIMESTAMPDIFF(SECOND, q1.created_at, q2.created_at) AS total')
            ->from(DB::raw("({$firstResponsePerRecipient->toSql()}) as q1"))
            ->mergeBindings($firstResponsePerRecipient->getQuery()->getQuery())
            ->join(DB::raw("({$firstOpenActivityPerRecipient->toSql()}) as q2"), function ($join) {
                $join->on('q1.recipient_id', '=', 'q2.recipient_id');
            })
            ->mergeBindings($firstOpenActivityPerRecipient->getQuery())
            ->get();

        $leadsOpenByTime = array_fill(0, 12, 0);

        foreach ($leadsOpenedWithTime as $lead) {
            $hours = floor(abs($lead->total) / self::SECONDS_PER_HOUR);
            if ($hours < 0) continue;
            if ($hours > 11) {
                $hours = 11;
            }
            $leadsOpenByTime[$hours]++;
        }

        return $leadsOpenByTime;
    }

    public function getLeadsClosedByTime()
    {
        $firstResponsePerRecipient = $this->getFirstResponsePerRecipient();
        $lastCloseActivityPerRecipient = $this->getLastCloseActivityPerRecipient();

        $leadsClosedWithTime = DB::query()
            ->selectRaw('TIMESTAMPDIFF(SECOND, q1.created_at, q2.created_at) AS total')
            ->from(DB::raw("({$firstResponsePerRecipient->toSql()}) as q1"))
            ->mergeBindings($firstResponsePerRecipient->getQuery()->getQuery())
            ->join(DB::raw("({$lastCloseActivityPerRecipient->toSql()}) as q2"), function ($join) {
                $join->on('q1.recipient_id', '=', 'q2.recipient_id');
            })
            ->mergeBindings($lastCloseActivityPerRecipient->getQuery())
            ->get();

        $leadsClosedByTime = array_fill(0, 7, 0);

        foreach ($leadsClosedWithTime as $lead) {
            $days = floor(abs($lead->total) / self::SECONDS_PER_DAY);
            if ($days > 6) {
                $days = 6;
            }
            $leadsClosedByTime[$days]++;
        }

        return $leadsClosedByTime;
    }

    protected function getFirstResponsePerRecipient()
    {
        return $this->campaign->responses()
        ->selectRaw('MIN(created_at) as created_at, recipient_id')
        ->groupBy('recipient_id');
    }

    protected function getFirstOpenActivityPerRecipient()
    {
        return Activity::selectRaw('MIN(created_at) as created_at, subject_id as recipient_id')
            ->where('subject_type', Lead::class)
            ->where('description', LeadActivity::OPENED)
            ->whereIn('subject_id', function ($query) {
                $query->select('id')
                    ->from('recipients')
                    ->where('recipients.campaign_id', $this->campaign->id);
            })
            ->groupBy('subject_id');
    }

    protected function getLastCloseActivityPerRecipient()
    {
        return Activity::selectRaw('MAX(created_at) as created_at, subject_id as recipient_id')
            ->where('subject_type', Lead::class)
            ->where('description', LeadActivity::CLOSED)
            ->whereIn('subject_id', function ($query) {
                $query->select('id')
                    ->from('recipients')
                    ->where('recipients.campaign_id', $this->campaign->id);
            })
            ->groupBy('subject_id');
    }
}
