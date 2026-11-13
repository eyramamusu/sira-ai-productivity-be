<?php

namespace App\Push;

use App\Models\DeviceToken;

interface PushSender
{
    public function send(DeviceToken $device, PushMessage $message): PushResult;
}
