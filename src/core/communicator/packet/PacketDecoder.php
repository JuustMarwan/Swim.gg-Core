<?php
namespace core\communicator\packet;

use core\communicator\Communicator;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;

class PacketDecoder {

    private string $buf = "";

    public function decodeFromString(string $buf, Communicator $communicator): array
    {
        $packets = [];

        $buf = $this->buf . $buf;

        $numRead = 0;
        $totalLen = strlen($buf);
        while ($numRead < $totalLen) {
            if (strlen($buf) < 2) {
                $this->buf = $buf;
                return $packets;
            }
            $len = Binary::readShort($buf);

            $numRead += $len + 2;
            if (strlen($buf) < $len+2) {
                $this->buf = $buf;
                return $packets;
            }
            $buf = substr($buf, 2);

            $data = substr($buf, 0, $len);
            $this->buf = "";
            $packets[] = Packet::decode(new PacketSerializer($data), $communicator);

            $buf = substr($buf, $len);
        }

        return $packets;
    }

}