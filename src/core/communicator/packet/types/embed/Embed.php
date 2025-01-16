<?php

namespace core\communicator\packet\types\embed;

use core\communicator\packet\PacketSerializer;

class Embed
{
  public const TYPE_RICH = "rich";
  public const TYPE_IMAGE = "image";
  public const TYPE_VIDEO = "video";
  public const TYPE_GIFV = "gifv";
  public const TYPE_ARTICLE = "article";
  public const TYPE_LINK = "link";

  /**
   * @param Field[] $fields
   */
  public function __construct(
    public string    $title = "",
    public string    $description = "",
    public string    $url = "",
    public string    $type = "",
    public string    $timestamp = "",
    public int       $color = 0,
    public ?Footer   $footer = null,
    public ?Image    $image = null,
    public ?Image    $thumbnail = null,
    public ?Video    $video = null,
    public ?Provider $provider = null,
    public ?Author   $author = null,
    private array    $fields = [],
  )
  {
  }

  public function decode(PacketSerializer $serializer): void
  {
    $this->title = $serializer->getString();
    $this->description = $serializer->getString();
    $this->url = $serializer->getString();
    $this->type = $serializer->getString();
    $this->timestamp = $serializer->getString();
    $this->color = $serializer->getUnsignedVarInt();
    $this->footer = $serializer->getOptional(function () use ($serializer) {
      $footer = new Footer;
      $footer->decode($serializer);
    });
    $this->image = $serializer->getOptional(function () use ($serializer) {
      $image = new Image;
      $image->decode($serializer);
    });
    $this->thumbnail = $serializer->getOptional(function () use ($serializer) {
      $image = new Image;
      $image->decode($serializer);
    });
    $this->video = $serializer->getOptional(function () use ($serializer) {
      $video = new Video;
      $video->decode($serializer);
    });
    $this->provider = $serializer->getOptional(function () use ($serializer) {
      $provider = new Provider;
      $provider->decode($serializer);
    });
    $this->author = $serializer->getOptional(function () use ($serializer) {
      $author = new Author;
      $author->decode($serializer);
    });
    $this->fields = $serializer->getArray(function () use ($serializer) {
      $field = new Field;
      $field->decode($serializer);
    });
  }

  public function encode(PacketSerializer $serializer): void
  {
    $serializer->putString($this->title);
    $serializer->putString($this->description);
    $serializer->putString($this->url);
    $serializer->putString($this->type);
    $serializer->putString($this->timestamp);
    $serializer->putUnsignedVarInt($this->color);
    $serializer->putOptional($this->footer, function (Footer $footer) use ($serializer) {
      $footer->encode($serializer);
    });
    $serializer->putOptional($this->image, function (Image $image) use ($serializer) {
      $image->encode($serializer);
    });
    $serializer->putOptional($this->thumbnail, function (Image $image) use ($serializer) {
      $image->encode($serializer);
    });
    $serializer->putOptional($this->video, function (Video $video) use ($serializer) {
      $video->encode($serializer);
    });
    $serializer->putOptional($this->provider, function (Provider $provider) use ($serializer) {
      $provider->encode($serializer);
    });
    $serializer->putOptional($this->author, function (Author $author) use ($serializer) {
      $author->encode($serializer);
    });
    $serializer->putArray($this->fields, function (Field $field) use ($serializer) {
      $field->encode($serializer);
    });
  }

}