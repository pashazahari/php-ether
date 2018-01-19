<?php
namespace phpEther\Web3\Api\Eth;
use BitWasp\Buffertools\Buffer;
use phpEther\Encoder\Keccak;
use phpEther\Tools\Hex;

class Contract
{
    const ABI_TYPE_CONSTRUCTOR = 'constructor';
    const ABI_TYPE_FUNCTION = 'function';
    const ABI_TYPE_EVENT = 'event';

    protected $bin;
    protected $abi;

    public function __construct(\phpEther\Web3\Api\Eth $eth, $abi)
    {
        // abi can be json  or Array
		$abiarray = is_array($abi)?$abi:json_decode($abi, true);
        $this->abi = $this->parseAbi($abiarray);
		$this->eth = $eth;
    }
	
	
		
	public function deploy($bin,\phpEther\Transaction $tx){
		$this->bin = $bin;
		$this->tx = $tx;
		return $this;
	}
	
	
	public function __call($method, $arguments)
    {
		if (isset($this->abi[self::ABI_TYPE_FUNCTION][$method])) {
			$abi = $this->abi[self::ABI_TYPE_FUNCTION][$method];
			$tx = new\phpEther\Transaction();
			if(count($arguments) > count($abi["inputs"])){
				$tx = $arguments[count($abi["inputs"])];
				if($tx instanceof \phpEther\Transaction){
					$payload = $tx;
				}
			}
			else
			{
				
				foreach($arguments as $arg){
					if($arg instanceof \phpEther\Account )
						$tx = new\phpEther\Transaction($arg);
					else
						$tx = new\phpEther\Transaction();
				}
			}
			
			
			$payload = $tx ->setWeb3($this->eth->web3);
			$arguments = $this->getArgumentBuffer($arguments);
			$payload->setTo(Hex::buffer($this->address));
			$payload->setData($this->getMethodBin($method, $arguments));	
			if($abi["constant"]){
				return  $this->decodeMethodResponse($method, $this->eth->call($payload));
			}else {
				return $payload->setGasLimit(Hex::buffer(30000))->setGasPrice()->send();
			}
        }elseif (isset($this->abi[self::ABI_TYPE_EVENT][$method])) {
			$payload = $arguments;
			$payload["to"] = $this->address;
			$payload["data"] = $this->contract->getEventBin($method);	
			return $this->eth->call($payload);
        }else{
			throw new \Exception("Method does not exists in abi");
		}
        return null;
    }
	
	public function at(string $address)
    {
		$this->address = $address;
        return $this;
    }

    public function constructor(array $arguments)
    {
		$arguments = $this->getArgumentBuffer($arguments);
		$abi = $this->abi[self::ABI_TYPE_CONSTRUCTOR];
		$data = $this->parseInputs($abi['inputs'], $arguments);
		die(var_dump($data));
		return $this->tx->setTo($this->address)->setData($data)->prefill()->send();;	
    }
	
	protected function getArgumentBuffer($arguments){
		return  array_map(function($arg){
			if($arg instanceof \phpEther\Account )
				return \BitWasp\Buffertools\Buffer::hex(\phpEther\Tools\Hex::cleanPrefix($arg->address));
			if($arg instanceof \phpEther\Transaction )
				return $arg;	
			if($arg instanceof \BitWasp\Buffertools\Buffer === false && substr($arg,0,2) === '0x')
				return \phpEther\Tools\Hex::buffer($arg);
			if(is_int($arg))
				return  \BitWasp\Buffertools\Buffer::int($arg);
			if(is_string($arg)){
				return \phpEther\Tools\Hex::buffer($arg);
			}
			if(is_array($arg)||is_object($arg)){
				return\phpEther\Tools\Hex::buffer($arg);
			}
			return $arg;
		},$arguments);
	}

    public function getConstructBin(array $args = [])
    {
        return Buffer::hex($this->bin . $this->parseInputs($this->abi[self::ABI_TYPE_CONSTRUCTOR]['inputs'], $args));
    }

    public function getMethodBin($method, array $args = [])
    {
        if (!isset($this->abi[self::ABI_TYPE_FUNCTION][$method])) {
            throw new \Exception("Method does not exists in abi");
        }

        return Buffer::hex(substr($this->abi[self::ABI_TYPE_FUNCTION][$method]['prototype'], 0, 8) . $this->parseInputs($this->abi[self::ABI_TYPE_FUNCTION][$method]['inputs'], $args));
    }

    public function getEventBin($event)
    {
        if (!isset($this->abi[self::ABI_TYPE_EVENT][$event])) {
            throw new \Exception("Method does not exists in abi");
        }
        return Buffer::hex($this->abi[self::ABI_TYPE_EVENT][$event]['prototype']);
    }

    public function decodeMethodResponse($method, $raw)
    {
        if (!isset($this->abi[self::ABI_TYPE_FUNCTION][$method])) {
            throw new \Exception("Method does not exists in abi");
        }
        $raw = Hex::cleanPrefix($raw);
        return $this->parseOutputs($this->abi[self::ABI_TYPE_FUNCTION][$method]['outputs'], $raw);
    }

    public function decodeEventResponse(array $values)
    {
        // If topics does not set , return $values
        if (!isset($values['topics']) || !isset($values['topics'][0])) {
            return $values;
        }

        $topic = Hex::cleanPrefix($values['topics'][0]);

        if(!isset($this->abi['prototype'][$topic])) {
            throw new \Exception("Event does not exists in abi");
        }
        $event = $this->abi['prototype'][$topic];
        $values['eventName'] = $event;
        $values['data'] = $this->parseOutputs($this->abi[self::ABI_TYPE_EVENT][$event]['inputs'], Hex::cleanPrefix($values['data']));

        return $values;
    }

    public function getEvents(): array
    {
        return $this->abi[self::ABI_TYPE_EVENT] ?? [];
    }

    protected function parseInputs(array $abiInputs, array $values)
    {
        $values = array_values($values);
        $result = '';
        // check $args match expected ones
		$inputCount = count($abiInputs);
        if (count($values) < $inputCount ) {
            throw new \Exception("Argument count less than  abi Requirements");
        }
		
		$headers =[];
		$data = [];
        foreach ($abiInputs as $i => $input) {
            $type = $input['type'];
			if(stripos($type,'[]')!==false ){
				 if(!is_array($values[$i]))throw new \Exception('Invalid input for '.$type.'. Expected Buffer array');
				 $header[] = str_pad(Buffer::int( ($inputCount * 32)+($i * 64))->getHex(), 64, '0', STR_PAD_LEFT);
				 $regex = '/([a-zA-Z0-9]*)(\[\])?/';
				 preg_match($regex, $type, $matches, PREG_OFFSET_CAPTURE, 0);
				 $data[] = str_pad(Buffer::int(count($values[$i]))->getHex(), 64, '0', STR_PAD_LEFT);
				 $data[] = $this->encodeParam($matches[1], $values[$i]);
				 continue;
			}
			if(in_array($type,['string','bytes'])){
				$header[] =  str_pad(Buffer::int( ($inputCount * 32)+($i * 64))->getHex(), 64, '0', STR_PAD_LEFT);
				$data[] = str_pad(Buffer::int($values[$i]->getInternalSize())->getHex(), 64, '0', STR_PAD_LEFT);
				$data[] = $this->encodeParam($type, $values[$i],STR_PAD_RIGHT);
				continue;
			}
            $header[] = $this->encodeParam($type, $values[$i]);
        }
        return implode("",$header).implode("",$data);
    }
	
	protected function parseDynamicInputs(array $abiInputs, array $values)
    {
        
        foreach ($abiInputs as $i => $input) {
            $type = $input['type'];
            $result .= $this->encodeParam($type, $values[$i]);
        }
        return $result;
    }

    protected function parseOutputs(array $abiOutputs, $raw)
    {
        $result = [];
		if(count($abiOutputs)>1){
			foreach ($abiOutputs as $i => $output) {
				$type = $output['type'];
				$result [$output['name']] = $this->decodeParam($type, $raw);
			}
		}else{
			$type = $abiOutputs[0]['type'];
			$result = $this->decodeParam($type, $raw);
		}

        return $result;
    }

    protected function parseAbi(array $abi)
    {
        $return = [];
        foreach($abi as $abiRaw)
        {
            $type = $abiRaw['type'];

            switch ($type) {
                case self::ABI_TYPE_CONSTRUCTOR:
                    $return[$type] = $abiRaw;
                    continue;
                case self::ABI_TYPE_FUNCTION:
                    $return[$type][$abiRaw['name']] = $abiRaw;
                    $return[$type][$abiRaw['name']]['prototype'] = $this->getPrototype($abiRaw)->getHex();
                    continue;
                case self::ABI_TYPE_EVENT:
                    $prototype = $this->getPrototype($abiRaw)->getHex();
                    $return[$type][$abiRaw['name']] = $abiRaw;
                    $return[$type][$abiRaw['name']]['prototype'] = $prototype;
                    $return['prototype'][$prototype] = $abiRaw['name'];
                    continue;
                default:
                    $return[$type] = $abiRaw;
            }
        }
        return $return;
    }

    /**
     * @param string $type
     * @param array|Buffer $value
     * @return string
     * @throws \Exception
     */
    protected function encodeParam($type, $value , $align = STR_PAD_LEFT)
    {
		
        // Detect and format an array type
        preg_match('/([a-zA-Z0-9]*)(\[([0-9]|[1-2][0-9]|3[1-2])\])?/',$type,$match);
        if(count($match) == 4) {

            if(count($value) != $match[3]) {
                throw new \Exception("Value count does not match expected type");
            }

            $return = BufferInt;
            foreach($value as $key => $val) {
                $return.= $this->encodeParam($match[1],$val,STR_PAD_RIGHT);
            }
            return $return;
        }

        if ($value instanceof Buffer === false) {
			var_dump($value, $type);
            throw new \Exception("Value must be an Buffer");
        }

        switch ($type) {

            case 'uint8':
            case 'uint256':
                if (strlen($value->getHex()) > 64) {
                    throw new \Exception("$type cannot exeed 64 chars");
                }

                return str_pad($value->getHex(), 64, '0', $align);

            case 'address':
                if (strlen($value->getHex()) !== 40) {
                    throw new \Exception("Address must be 40 chars");
                }

                return str_pad($value->getHex(), 64, '0', $align);
            case 'bool':
                return str_pad($value->getHex(), 64, '0', $align);
			case 'string':
                return str_pad($value->getHex(), 64, '0', $align);

            case 'bytes32':
                if (strlen($value->getHex()) != 64) {
                    throw new \Exception("bytes32 must be 64 chars");
                }
                return str_pad($value->getHex(), 64, '0', $align);

            default:
                throw new \Exception("Unknown input type {$type}");

        }
    }

    /**
     * @param $type
     * @param $raw
     * @return Buffer|bool|string
     * @throws \Exception
     */
    protected function decodeParam($type, &$raw)
    {
        switch ($type) {

            case 'uint8':
            case 'uint256':
                $result = Buffer::hex(substr($raw, 0, 64));
                $raw = substr($raw, 64);
                break;
			case 'string':
				$name = Buffer::hex(substr($raw, 2));
				$name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name->getBinary()));
                $raw = substr($raw, 64);
				$result = new Buffer($name);
                break;

            case 'address':
                $result = Buffer::hex(substr($raw, 64-40, 40));
                $raw = substr($raw, 64);
                break;

            case 'bool':
                $result = Buffer::hex(substr($raw, 0, 64))->getInt() ? true : false;
                $raw = substr($raw, 64);
                break;

            case 'bytes32':
                $result = Buffer::hex(substr($raw, 0, 64));
                $raw = substr($raw, 64);
                break;
            default:
                throw new \Exception('Unknown input type ' . $type);
        }

        return $result;

    }

    /**
     * @param array $abiRaw
     * @return Buffer
     */
    protected function getPrototype(array $abiRaw)
    {
        $types = [];
        foreach ($abiRaw['inputs'] as $input) {
            $types[] = $input['type'];
        }

        $prototype = new Buffer(sprintf('%s(%s)', $abiRaw['name'], implode(',', $types)));
        return Keccak::hash($prototype->getBinary(), 256);
    }
}