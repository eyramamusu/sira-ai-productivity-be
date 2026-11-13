<?php

namespace App\Push;

use App\Models\DeviceToken;

/** Picks the sender for each device's platform; platforms without credentials fall back to logging. */
class PushGateway implements PushSender
{
    public function send(DeviceToken $device, PushMessage $message): PushResult
    {
        return $this->senderFor($device->platform)->send($device, $message);
    }

    private function senderFor(string $platform): PushSender
    {
        $config = config('sira.push');
        if ($config['driver'] === 'native') {
            if ($platform === 'android' && ($account = FcmSender::account($config['fcm']['service_account_json']))) {
                return new FcmSender($account);
            }
            if ($platform === 'ios' && ApnsSender::configured($config['apns'])) {
                return new ApnsSender($config['apns']);
            }
        }

        return new LogSender;
    }
}
