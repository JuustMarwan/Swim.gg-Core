<?php

namespace core\utils\raklib;


use raklib\generic\Session;
use raklib\protocol\MessageIdentifiers;
use raklib\protocol\OpenConnectionReply2;
use raklib\protocol\PacketSerializer;
use raklib\utils\InternetAddress;

class MTUOpenConnectionReply2  extends OpenConnectionReply2{

	private int $ipHeaderSize;

	public static function create(int $serverId, InternetAddress $clientAddress, int $mtuSize, bool $serverSecurity, int $ipHeaderSize = 0) : self{
		$result = new self;
		$result->serverID = $serverId;
		$result->clientAddress = $clientAddress;
		$result->mtuSize = $mtuSize;
		$result->serverSecurity = $serverSecurity;
		$result->ipHeaderSize = $ipHeaderSize;
		return $result;
	}
	protected function encodePayload(PacketSerializer $out) : void{
		$this->writeMagic($out);
		$out->putLong($this->serverID);
		$out->putAddress($this->clientAddress);
		$out->putShort($this->mtuSize);
		$out->putByte($this->serverSecurity ? 1 : 0);
		if ($this->mtuSize > Session::MIN_MTU_SIZE) {
        	$out->put(str_repeat("\x00", $this->mtuSize-$this->ipHeaderSize-8-strlen($out->getBuffer())));
		}
	}

}
