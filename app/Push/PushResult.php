<?php

namespace App\Push;

enum PushResult
{
    case Delivered;
    /** The store says the token is dead (app uninstalled, token rotated); delete it. */
    case InvalidToken;
    case Failed;
}
