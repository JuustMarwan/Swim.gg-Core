<?php
namespace core\communicator\packet;

enum PacketId : int {
    case UNKNOWN = 0;
    case SERVER_INFO = 1;
    case PLAYER_LIST_REQUEST = 2;
    case PLAYER_LIST_RESPONSE = 3;
    case DISCORD_COMMAND_EXECUTE = 4;
    case DISCORD_COMMAND_MESSAGE = 5;
    case DISCONNECT = 6;
    case DISCORD_USER_REQUEST = 7;
    case DISCORD_USER_RESPONSE = 8;
    case DISCORD_LINK_REQUEST = 9;
    case DISCORD_LINK_INFO = 10;
    case OTHER_REGIONS = 11;
    case DISCORD_INFO = 12;
    case DISCORD_EMBED_SEND = 13;
    case UPDATE_DISCORD_ROLES = 14;
}