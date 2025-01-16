<?php

namespace core\communicator\packet\types;

enum DisconnectReason: int
{
    case SERVER_SHUTDOWN = 0;
    case SERVER_CRASH = 1;
}