<?php

namespace App\Modules\Complaints\Actions;

use App\Modules\Complaints\Enums\ComplaintEventType;
use App\Modules\Complaints\Enums\ComplaintStatus;
use App\Modules\Complaints\Models\Complaint;
use App\Modules\Complaints\Repositories\ComplaintEventRepository;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\NotificationDispatcher;
use App\Modules\Notifications\Support\NotificationPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SubmitComplaint
{
    public function __construct(private readonly ComplaintEventRepository $events, private readonly NotificationDispatcher $dispatcher) {}

    /** @param array{name:string,topic:string,orderNumber:?string,message:string,contactMethod:string,phone:?string,email:?string} $submission */
    public function execute(array $submission): Complaint
    {
        $complaint = DB::transaction(function () use ($submission): Complaint {
            $customer = null;
            if ($submission['phone'] !== null) {
                $customer = DB::table('customers')->where('phone_e164', $submission['phone'])->first();
            }
            if ($customer === null && $submission['email'] !== null) {
                $customer = DB::table('customers')->whereRaw('LOWER(email) = ?', [$submission['email']])->first();
            }

            $order = null;
            $entered = $submission['orderNumber'];
            if ($entered !== null) {
                $candidate = DB::table('orders')->where('order_number', $entered)->first();
                if ($candidate !== null) {
                    $snapshot = json_decode((string) $candidate->customer_snapshot_json, true) ?: [];
                    $phoneMatches = $submission['phone'] !== null && ($snapshot['phoneE164'] ?? null) === $submission['phone'];
                    $emailMatches = $submission['email'] !== null && mb_strtolower((string) ($snapshot['email'] ?? '')) === $submission['email'];
                    $linkedCustomerMatches = $submission['phone'] !== null && DB::table('customers')->where('id', $candidate->customer_id)->where('phone_e164', $submission['phone'])->exists();
                    $linkedCustomerMatches = $linkedCustomerMatches || ($submission['email'] !== null && DB::table('customers')->where('id', $candidate->customer_id)->whereRaw('LOWER(email) = ?', [$submission['email']])->exists());
                    if ($phoneMatches || $emailMatches || $linkedCustomerMatches) {
                        $order = $candidate;
                    }
                }
            }

            $complaint = Complaint::query()->create([
                'name' => $submission['name'], 'topic' => $submission['topic'], 'message' => $submission['message'],
                'contact_method' => $submission['contactMethod'], 'phone_e164' => $submission['phone'], 'email' => $submission['email'],
                'order_number_entered' => $entered, 'order_id' => $order?->id, 'customer_id' => $customer?->id,
                'status' => ComplaintStatus::NEW,
            ]);
            $complaint->reference = 'C-'.str_pad((string) $complaint->id, 6, '0', STR_PAD_LEFT);
            $complaint->save();
            $this->events->insert(['complaint_id' => $complaint->id, 'type' => ComplaintEventType::SUBMITTED->value, 'from_status' => null, 'to_status' => ComplaintStatus::NEW->value, 'created_at' => now()]);

            return $complaint;
        });

        try {
            $method = $submission['contactMethod'];
            $occurrence = 'complaint:'.$complaint->id;
            $payload = new NotificationPayload('complaint-received', ['reference' => $complaint->reference], 'complaint', $complaint->id);
            if ($method === 'EMAIL' && $submission['email'] !== null) {
                $channel = NotificationChannel::EMAIL;
                $recipient = $submission['email'];
            } else {
                $channel = NotificationChannel::SMS;
                $recipient = $submission['phone'];
            }
            if ($recipient !== null) {
                $this->dispatcher->once(NotificationDispatcher::key($occurrence, $channel, $recipient), $channel, $recipient, $payload);
            }
        } catch (\Throwable $e) {
            Log::warning('Complaint acknowledgement could not be queued.', ['complaint_id' => $complaint->id, 'error' => class_basename($e)]);
        }

        return $complaint;
    }
}
