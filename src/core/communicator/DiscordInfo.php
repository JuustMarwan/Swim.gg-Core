<?php

namespace core\communicator;

class DiscordInfo {

    public function __construct
    (
      public string $boosterRole = "",
      public string $youtubeRole = "",
      public string $helperRole = "",
      public string $modRole = "",
      public string $ownerRole = "",
      public string $acChannel = "",
      public string $linkAlertsChannel = ""
    )
    {

    }

}