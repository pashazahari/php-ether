<?php
namespace phpEther;

use BitWasp\Buffertools\Buffer;
use phpEther\Tools\Hex;
class Filter
{
	private $fromBlock, $toBlock, $address, $topics;
	
	function __construct($fromBlock, $toBlock, $address, $topics)
	{
		if(is_null($fromBlock)) 
		$this->fromBlock = 'latest';
		elseif(is_int($fromBlock)) 
		$this->fromBlock = Buffer::int($fromBlock);
		else
		$this->fromBlock = $fromBlock;
		if(is_null($toBlock)) 
		$this->toBlock = 'latest';
		elseif(is_int($toBlock)) 
		$this->toBlock = Buffer::int($toBlock);
		else
		$this->toBlock = $toBlock;
		
		$this->address = $address;
		$this->topics = $topics;
	}
	
	function toArray()
	{
		return [$this->getArray()];
	}
	function getArray()
	{
		return [
			'fromBlock'=>($this->fromBlock instanceof Buffer)?Hex::getHex($this->fromBlock):$this->fromBlock,
			'toBlock'=>($this->toBlock instanceof Buffer)?Hex::getHex($this->toBlock):$this->toBlock,
			'address'=>($this->address instanceof Buffer)?Hex::getHex($this->address):$this->address,
			'topics'=>($this->topics instanceof Buffer)?Hex::getHex($this->topics):$this->topics
		];
	}
	
	function getIntArray() // return from and To as intergers not hex.
	{
		return [
			'fromBlock'=>($this->fromBlock instanceof Buffer)?$this->fromBlock->getInt():$this->fromBlock,
			'toBlock'=>($this->toBlock instanceof Buffer)? $this->toBlock->getInt():$this->toBlock,
			'address'=>($this->address instanceof Buffer)?Hex::getHex($this->address):$this->address,
			'topics'=>($this->topics instanceof Buffer)?Hex::getHex($this->topics):$this->topics
		];
	}
}

