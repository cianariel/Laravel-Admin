<?php

namespace App\Events;

use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Recipient;
use App\Models\Response;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class CampaignResponseUpdated implements ShouldBroadcast
{
    use SerializesModels;

    /** @var  Recipient */
    private $recipient;

    /**
     * Create a new event instance.
     *
     * @param Campaign  $campaign  Campaign
     * @param Recipient $recipient Recipient
     */
    public function __construct(Recipient $recipient)
    {
        $this->recipient = $recipient;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('campaign.' . $this->campaign->id);
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'response.' . $this->recipient->id . '.updated';
    }

    public function broadcastWith()
    {
        return [
            'appointments' => $this->getAppointments(),
            'emailthreads' => $this->getEmailThreads(),
            'textthreads'  => $this->getTextThreads(),
            'phonethreads' => $this->getPhoneThreads(),
            'recipient'    => $this->recipient->toArray(),
        ];
    }

    private function getAppointments()
    {
        return Appointment::where('recipient_id', $this->recipient->id)->get()->toArray();
    }

    private function getEmailThreads()
    {
        return Response::where('campaign_id', $this->recipient->campaign_id)
            ->where('id', $this->recipient->id)
            ->where('type', 'email')
            ->get()
            ->toArray();
    }

    private function getTextThreads()
    {
        return Response::where('campaign_id', $this->recipient->campaign_id)
            ->where('id', $this->recipient->id)
            ->where('type', 'text')
            ->get()
            ->toArray();
    }

    private function getPhoneThreads()
    {
        return Response::where('campaign_id', $this->recipient->campaign_id)
            ->where('id', $this->recipient->id)
            ->where('type', 'phone')
            ->get()
            ->toArray();
    }
}
