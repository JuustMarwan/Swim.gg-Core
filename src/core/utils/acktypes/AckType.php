<?php

namespace core\utils\acktypes;

enum AckType {
	case ENTITY_POSITION;
	case KNOCKBACK;
	case ENTITY_REMOVAL;
	case NO_AI;
	case GAMEMODE_CHANGE;
}
